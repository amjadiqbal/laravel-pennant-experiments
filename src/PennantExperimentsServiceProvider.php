<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments;

use AmjadIqbal\PennantExperiments\Console\EndCommand;
use AmjadIqbal\PennantExperiments\Console\ResultsCommand;
use AmjadIqbal\PennantExperiments\Http\Authorize;
use AmjadIqbal\PennantExperiments\Http\VerifyOrigin;
use Composer\InstalledVersions;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class PennantExperimentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pennant-experiments.php', 'pennant-experiments');
        $this->app->singleton(Repository::class);
        $this->app->singleton(ExperimentManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pennant-experiments');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/pennant-experiments.php' => config_path('pennant-experiments.php')], 'pennant-experiments-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'pennant-experiments-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/pennant-experiments')], 'pennant-experiments-views');
            $this->commands([ResultsCommand::class, EndCommand::class]);
        }

        if (config('pennant-experiments.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Each gate is local-only until the app defines it (app providers boot after this one, so theirs win).
        // Defining only the view gate does not open "end" and "start over": manage stays local-only on its own.
        if (! Gate::has('viewPennantExperiments')) {
            Gate::define('viewPennantExperiments', fn ($user = null): bool => $this->app->environment('local'));
        }
        if (! Gate::has('managePennantExperiments')) {
            Gate::define('managePennantExperiments', fn ($user = null): bool => $this->app->environment('local'));
        }

        $this->registerBlade();

        if (config('pennant-experiments.merge_visitor_on_login', true)) {
            Event::listen(Login::class, function (Login $event): void {
                $manager = $this->app->make(ExperimentManager::class);
                if (($visitor = $manager->existingVisitor()) !== null) {
                    $manager->mergeVisitor($visitor, $event->user);
                }
            });
        }

        // The manager is a singleton (definitions live for the process); its per-request memos are not.
        Event::listen(RequestHandled::class, fn () => $this->app->make(ExperimentManager::class)->flush());

        if (config('pennant-experiments.enabled', true)) {
            $this->registerRoutes();
        }
        if (config('pennant-experiments.tracking.enabled', true)) {
            Route::group([
                'prefix' => trim((string) config('pennant-experiments.tracking.path', '_experiments'), '/'),
                'middleware' => [...array_values(array_filter((array) config('pennant-experiments.tracking.middleware', ['web']), 'is_string')), VerifyOrigin::class],
            ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/tracking.php'));
        }

        $this->registerDashboardTab();

        AboutCommand::add('Pennant Experiments', fn (): array => [
            'Version' => self::version(),
            'Experiments' => (string) count($this->app->make(ExperimentManager::class)->definitions()),
            'Results' => config('pennant-experiments.enabled', true) ? '/'.self::path() : 'off',
            'Support' => 'https://amjad.com.pk/contact?utm_source=artisan&utm_medium=about&utm_campaign=laravel-pennant-experiments',
        ]);
    }

    /** The results path: configured, or next to the Pennant dashboard. */
    public static function path(): string
    {
        $path = config('pennant-experiments.path');
        if (is_string($path) && trim($path, '/') !== '') {
            return trim($path, '/');
        }

        return trim((string) config('pennant-dashboard.path', 'pennant'), '/').'/experiments';
    }

    private function registerRoutes(): void
    {
        $middleware = array_values(array_filter((array) config('pennant-experiments.middleware', ['web']), 'is_string'));

        Route::group([
            'prefix' => self::path(),
            'domain' => config('pennant-experiments.domain'),
            'middleware' => [...$middleware, Authorize::class],
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }

    private function registerBlade(): void
    {
        // @variant('checkout-button', 'green') ... @elsevariant('checkout-button', 'blue') ... @else ... @endvariant
        // Evaluating it records the exposure.
        Blade::if('variant', fn (string $experiment, string $variant, mixed $scope = null): bool => $this->app->make(ExperimentManager::class)->expose($experiment, $scope) === $variant);

        Blade::directive('experimentsScript', fn (): string => "<?php echo view('pennant-experiments::script')->render(); ?>");
    }

    /**
     * An "Experiments" tab in amjadiqbal/laravel-pennant-dashboard when it is installed (v1.1+ renders
     * config('pennant-dashboard.tabs')). Neither package requires the other.
     */
    private function registerDashboardTab(): void
    {
        if (! config('pennant-experiments.dashboard_tab', true) || ! config('pennant-experiments.enabled', true)
            || ! class_exists('AmjadIqbal\\PennantDashboard\\PennantDashboardServiceProvider')) {
            return;
        }

        $tabs = array_values(array_filter((array) config('pennant-dashboard.tabs', []), fn ($tab) => ! is_array($tab) || ($tab['route'] ?? null) !== 'pennant-experiments.index'));
        $tabs[] = ['label' => 'Experiments', 'route' => 'pennant-experiments.index', 'active' => 'pennant-experiments.*'];
        config(['pennant-dashboard.tabs' => $tabs]);
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('amjadiqbal/laravel-pennant-experiments') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
