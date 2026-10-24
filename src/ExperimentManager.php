<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Defines experiments as Pennant features and records who saw and who converted.
 *
 * Assignment: a hash of (experiment, scope) picks a variant by weight, so it is sticky with or
 * without storage; with Pennant's database driver the first answer is also stored, so it stays
 * put even if the weights change later. Ended experiments answer the winner for everyone.
 */
final class ExperimentManager
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    private ?Closure $scopeResolver = null;

    /** @var array<string, string|null> per-request memo: "experiment|scope" => recorded variant or null */
    private array $exposed = [];

    /** @var array<string, array{status: string, winner: ?string, started_at: ?string, ended_at: ?string, ended_by: ?string}|null> */
    private array $states = [];

    private ?Visitor $visitor = null;

    public function __construct(
        private readonly Container $container,
        private readonly Repository $repository,
    ) {}

    /**
     * Define an experiment and register it as a Pennant feature of the same name.
     *
     * @param  array<int|string, string|int>  $variants  ['control', 'green'] or ['control' => 80, 'green' => 20]; the first is the control
     * @param  array<string, mixed>  $options  goals, eligible, store, description, minimum_detectable_effect, sample_size, salt
     */
    public function define(string $name, array $variants, array $options = []): Definition
    {
        $definition = Definition::make($name, $variants, $options);
        $this->definitions[$name] = $definition;

        Feature::store($definition->store)->define($name, fn (mixed $scope = null): string => $this->assign($definition, $scope));

        return $definition;
    }

    /** @return array<string, Definition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $name): Definition
    {
        return $this->definitions[$name] ?? throw new InvalidArgumentException("Unknown experiment [{$name}]. Define it with Experiments::define() in a service provider.");
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * The variant this scope gets (null scope = the current participant). Does not count as an
     * exposure: use it for logic, and expose() (or @variant) where the variant is shown.
     */
    public function variant(string $name, mixed $scope = null): string
    {
        $definition = $this->definition($name);

        if (($winner = $this->winner($name)) !== null) {
            return $winner;
        }

        if ($this->excludedFromRequest($scope)) {
            return $definition->control();
        }
        $scope ??= $this->participant();

        $value = Feature::store($definition->store)->for($scope)->value($name);

        // A value set outside the experiment (e.g. "on for everyone" in a dashboard) is not a variant: show the control.
        return $definition->hasVariant($value) ? $value : $definition->control();
    }

    /**
     * The variant this scope gets, recorded as seen. Only exposed scopes are counted in the results.
     */
    public function expose(string $name, mixed $scope = null): string
    {
        $definition = $this->definition($name);
        if ($this->excludedFromRequest($scope)) {
            return $this->winner($name) ?? $definition->control();
        }
        $scope ??= $this->participant();
        $variant = $this->variant($name, $scope);

        if ($this->isRunning($name) && $this->eligible($definition, $scope)) {
            $key = $name.'|'.$this->serialize($scope);
            if (! array_key_exists($key, $this->exposed)) {
                $this->repository->touch($name);
                $this->repository->recordExposure($name, $variant, $this->serialize($scope));
                $this->exposed[$key] = $variant;
            }
        }

        return $variant;
    }

    /**
     * Record that this scope reached a goal. Counted once per scope and goal, and only when the
     * scope was exposed first (conversions without an exposure would inflate the rates).
     * Returns true when a new conversion was recorded.
     */
    public function convert(string $name, string $goal = 'conversion', mixed $scope = null): bool
    {
        $definition = $this->definition($name);
        if (! $definition->hasGoal($goal)) {
            throw new InvalidArgumentException("Experiment [{$name}] has no goal [{$goal}]. Its goals: ".implode(', ', $definition->goals).'.');
        }

        if (! $this->isRunning($name) || $this->excludedFromRequest($scope)) {
            return false;
        }
        $scope ??= $this->participant();

        $serialized = $this->serialize($scope);
        $variant = $this->repository->exposedVariant($name, $serialized);
        if ($variant === null) {
            return false;
        }

        return $this->repository->recordConversion($name, $goal, $variant, $serialized);
    }

    /**
     * End the experiment: everyone gets $winner from now on (stored Pennant values are rewritten
     * too), and nothing more is recorded.
     */
    public function end(string $name, string $winner, ?string $by = null): void
    {
        $definition = $this->definition($name);
        if (! $definition->hasVariant($winner)) {
            throw new InvalidArgumentException("Experiment [{$name}] has no variant [{$winner}].");
        }

        $this->repository->end($name, $winner, $by);
        unset($this->states[$name]);

        Feature::store($definition->store)->activateForEveryone($name, $winner);
        Feature::store($definition->store)->flushCache();
    }

    /**
     * Start the experiment over from zero: its exposures and conversions are deleted, stored
     * Pennant values for the feature are purged (so every scope is assigned afresh), and it runs
     * again from now. The results before this point are gone.
     */
    public function restart(string $name): void
    {
        $definition = $this->definition($name);
        $this->repository->reset($name);
        unset($this->states[$name]);
        $this->exposed = array_filter($this->exposed, fn (string $key): bool => ! str_starts_with($key, $name.'|'), ARRAY_FILTER_USE_KEY);

        Feature::store($definition->store)->purge($name);
        Feature::store($definition->store)->flushCache();
    }

    public function results(string $name): Results
    {
        return new Results(
            $this->definition($name),
            $this->repository->exposureCounts($name),
            $this->repository->conversionCounts($name),
        );
    }

    public function winner(string $name): ?string
    {
        $state = $this->state($name);

        return $state !== null && $state['status'] === 'ended' ? $state['winner'] : null;
    }

    public function isRunning(string $name): bool
    {
        return $this->winner($name) === null;
    }

    /** @return array{status: string, winner: ?string, started_at: ?string, ended_at: ?string, ended_by: ?string}|null */
    public function state(string $name): ?array
    {
        if (! array_key_exists($name, $this->states)) {
            try {
                $this->states[$name] = $this->repository->state($name);
            } catch (Throwable) {
                // Tables not migrated yet: behave as a running experiment instead of breaking pages.
                $this->states[$name] = null;
            }
        }

        return $this->states[$name];
    }

    /**
     * The resolver behind the Pennant feature: winner when ended, control when not eligible,
     * otherwise the weighted, hash-based pick.
     */
    public function assign(Definition $definition, mixed $scope): string
    {
        if (($winner = $this->winner($definition->name)) !== null) {
            return $winner;
        }

        if (! $this->eligible($definition, $scope)) {
            return $definition->control();
        }

        return $definition->pick($definition->bucket($this->serialize($scope)));
    }

    public function eligible(Definition $definition, mixed $scope): bool
    {
        return $definition->eligible === null || (bool) ($definition->eligible)($scope);
    }

    // Participants

    /** @param  Closure(): mixed  $resolver */
    public function resolveScopeUsing(Closure $resolver): void
    {
        $this->scopeResolver = $resolver;
    }

    /** The current participant: your resolver, else the signed-in user, else the visitor cookie. */
    public function participant(): mixed
    {
        if ($this->scopeResolver !== null && ($scope = ($this->scopeResolver)()) !== null) {
            return $scope;
        }

        if (config('pennant-experiments.participant', 'user_or_visitor') !== 'visitor') {
            $user = $this->container->make(AuthFactory::class)->guard(config('pennant-experiments.guard'))->user();
            if ($user !== null) {
                return $user;
            }
        }

        return $this->visitor();
    }

    /** The guest id from the cookie, created (and queued as a cookie) on first use. */
    public function visitor(): Visitor
    {
        if ($this->visitor !== null) {
            return $this->visitor;
        }

        $request = $this->request();
        $name = (string) config('pennant-experiments.cookie.name', 'pennant_experiments_visitor');
        $id = $request?->cookie($name);

        if (! Visitor::isValidId($id)) {
            $id = (string) Str::uuid();
            // Secure when the session cookie is, or when the request itself is HTTPS (either one is enough).
            $secure = (bool) config('session.secure') || ($request?->isSecure() ?? false);
            $this->container->make(CookieJar::class)->queue(
                $name, $id, (int) config('pennant-experiments.cookie.minutes', 525600), null, null, $secure, true, false, 'lax'
            );
            $request?->cookies->set($name, $id);
        }

        return $this->visitor = new Visitor((string) $id);
    }

    /** The visitor id already in the request, without creating one. */
    public function existingVisitor(): ?Visitor
    {
        if ($this->visitor !== null) {
            return $this->visitor;
        }
        $id = $this->request()?->cookie((string) config('pennant-experiments.cookie.name', 'pennant_experiments_visitor'));

        return Visitor::isValidId($id) ? new Visitor((string) $id) : null;
    }

    /**
     * After sign-in: move the guest's exposures and conversions to the user and keep their variant,
     * so the person is counted once and sees the same thing as before.
     *
     * @return array<string, string> experiment => variant moved
     */
    public function mergeVisitor(Visitor $visitor, mixed $user): array
    {
        $moved = $this->repository->moveScope($visitor->featureScopeSerialize(), $this->serialize($user));

        foreach ($moved as $experiment => $variant) {
            if ($this->has($experiment) && $this->isRunning($experiment)) {
                Feature::store($this->definition($experiment)->store)->for($user)->activate($experiment, $variant);
            }
        }
        foreach (array_keys($this->exposed) as $key) {
            if (str_ends_with($key, '|'.$visitor->featureScopeSerialize())) {
                unset($this->exposed[$key]);
            }
        }

        return $moved;
    }

    /**
     * The current request opted out (Do Not Track or Global Privacy Control), when honoured.
     */
    public function hasPrivacySignal(): bool
    {
        if (! config('pennant-experiments.respect_privacy_signals', true)) {
            return false;
        }
        $request = $this->request();

        return $request !== null && ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1');
    }

    /**
     * Participants taken from the current request (no scope passed, or a Visitor) are left out
     * for bots and for requests with a privacy signal: they see the control (the winner once the
     * experiment has ended), get no visitor
     * cookie and are never counted. Scopes you pass yourself (users, teams, ...) are not checked.
     */
    private function excludedFromRequest(mixed $scope): bool
    {
        return ($scope === null || $scope instanceof Visitor) && ($this->isBot() || $this->hasPrivacySignal());
    }

    public function isBot(): bool
    {
        if (! config('pennant-experiments.exclude_bots', true)) {
            return false;
        }
        $agent = (string) $this->request()?->userAgent();

        return $agent === '' || preg_match((string) config('pennant-experiments.bot_pattern'), $agent) === 1;
    }

    public function serialize(mixed $scope): string
    {
        return Feature::serializeScope($scope);
    }

    /** Forget per-request memos (for long-running workers and tests). */
    public function flush(): void
    {
        $this->exposed = [];
        $this->states = [];
        $this->visitor = null;
    }

    private function request(): ?Request
    {
        return $this->container->bound('request') ? $this->container->make('request') : null;
    }
}
