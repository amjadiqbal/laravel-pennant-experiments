<?php

use AmjadIqbal\PennantExperiments\Facades\Experiments;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Experiments::define('exp', ['control' => 1, 'b' => 1], ['goals' => ['signup', 'purchase']]);
});

it('counts a scope only when it is exposed, once', function () {
    $user = $this->user();
    Experiments::variant('exp', $user); // logic only, not shown
    expect(DB::table('pennant_experiment_exposures')->count())->toBe(0);

    $variant = Experiments::expose('exp', $user);
    Experiments::expose('exp', $user);
    $this->nextRequest();
    Experiments::expose('exp', $user);

    $rows = DB::table('pennant_experiment_exposures')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->variant)->toBe($variant)
        ->and($rows[0]->scope)->toBe($user::class.'|'.$user->id)
        ->and(DB::table('pennant_experiments')->where('name', 'exp')->value('status'))->toBe('running');
});

it('records conversions only for exposed scopes, once per goal, with the exposed variant', function () {
    $seen = $this->user(1);
    $unseen = $this->user(2);
    $variant = Experiments::expose('exp', $seen);

    expect(Experiments::convert('exp', 'signup', $unseen))->toBeFalse()
        ->and(Experiments::convert('exp', 'signup', $seen))->toBeTrue()
        ->and(Experiments::convert('exp', 'signup', $seen))->toBeFalse()
        ->and(Experiments::convert('exp', 'purchase', $seen))->toBeTrue();

    expect(DB::table('pennant_experiment_conversions')->count())->toBe(2)
        ->and(DB::table('pennant_experiment_conversions')->distinct()->pluck('variant')->all())->toBe([$variant]);
});

it('refuses goals that are not defined', function () {
    expect(fn () => Experiments::convert('exp', 'refund', $this->user()))->toThrow(InvalidArgumentException::class, 'no goal [refund]');
});

it('does not count ineligible scopes or bots', function () {
    Experiments::define('picky', ['control', 'b'], ['eligible' => fn ($scope) => false]);
    expect(Experiments::expose('picky', $this->user()))->toBe('control')
        ->and(DB::table('pennant_experiment_exposures')->count())->toBe(0);

    $this->app['request']->headers->set('User-Agent', 'Mozilla/5.0 (compatible; bingbot/2.0)');
    Experiments::expose('exp');
    expect(DB::table('pennant_experiment_exposures')->count())->toBe(0)
        ->and(Experiments::convert('exp', 'signup'))->toBeFalse();
});

it('records the exposure from the @variant Blade directive', function () {
    $user = $this->user();
    $variant = Experiments::variant('exp', $user);
    $other = $variant === 'b' ? 'control' : 'b';

    $html = Blade::render("@variant('exp', '{$variant}', \$u) SHOWN @elsevariant('exp', '{$other}', \$u) OTHER @endvariant", ['u' => $user]);

    expect(trim($html))->toBe('SHOWN')
        ->and(DB::table('pennant_experiment_exposures')->where('variant', $variant)->count())->toBe(1);
});

it('tracks from JavaScript with the server-side participant, never one from the body', function () {
    $user = $this->user();
    $this->actingAs($user);

    $variant = $this->withHeader('Origin', 'http://localhost')->postJson('/_experiments/expose', ['experiment' => 'exp', 'scope' => 'App\Models\User|999'])
        ->assertOk()->json('variant');
    $this->withHeader('Origin', 'http://localhost')->postJson('/_experiments/convert', ['experiment' => 'exp', 'goal' => 'purchase'])
        ->assertOk()->assertJson(['recorded' => true]);
    $this->withHeader('Origin', 'http://localhost')->postJson('/_experiments/convert', ['experiment' => 'exp', 'goal' => 'refund'])->assertStatus(422);
    $this->withHeader('Origin', 'http://localhost')->postJson('/_experiments/expose', ['experiment' => 'nope'])->assertNotFound();
    $this->getJson('/_experiments/variant/exp')->assertOk()->assertJson(['variant' => $variant]);

    expect(DB::table('pennant_experiment_exposures')->pluck('scope')->all())->toBe([$user::class.'|'.$user->id]);
});

it('gives guests a visitor cookie on the first tracking call', function () {
    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Test', 'Origin' => 'http://localhost'])->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertOk();
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'pennant_experiments_visitor');
    expect($cookie)->not->toBeNull()
        ->and(DB::table('pennant_experiment_exposures')->value('scope'))->toStartWith('visitor|');
});

it('renders the JavaScript helper', function () {
    $html = Blade::render('@experimentsScript');
    expect($html)->toContain('window.PennantExperiments')->toContain('_experiments\\/convert')->toContain('X-CSRF-TOKEN');
});

it('refuses tracking writes from another origin, or with no origin at all', function () {
    $this->flushHeaders()->withHeader('Origin', 'https://evil.example')->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertForbidden();
    $this->flushHeaders()->withHeader('Origin', 'http://localhost:8080')->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertForbidden();
    $this->flushHeaders()->withHeader('Origin', 'https://localhost')->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertForbidden();
    $this->flushHeaders()->postJson('/_experiments/convert', ['experiment' => 'exp', 'goal' => 'signup'])->assertForbidden();
    $this->flushHeaders()->withHeader('Referer', 'http://localhost/shop?x=1')->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertOk();
    $this->flushHeaders()->withHeader('Origin', 'http://LOCALHOST:80')->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertOk();
    $this->flushHeaders()->getJson('/_experiments/variant/exp')->assertOk(); // reads are not checked

    config(['pennant-experiments.tracking.check_origin' => false]);
    $this->flushHeaders()->postJson('/_experiments/expose', ['experiment' => 'exp'])->assertOk();
});

it('leaves out bots and privacy signals for request participants, signed in or not', function (array $headers) {
    $user = $this->user();
    $this->actingAs($user);
    foreach ($headers as $name => $value) {
        $this->app['request']->headers->set($name, $value);
    }

    expect(Experiments::expose('exp'))->toBe('control')
        ->and(Experiments::convert('exp', 'signup'))->toBeFalse()
        ->and(DB::table('pennant_experiment_exposures')->count())->toBe(0)
        ->and(DB::table('features')->count())->toBe(0);

    // A scope passed explicitly (e.g. from a job) is not judged by the request.
    Experiments::expose('exp', $user);
    expect(DB::table('pennant_experiment_exposures')->count())->toBe(1);
})->with([
    'bot' => [['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']],
    'DNT' => [['DNT' => '1']],
    'GPC' => [['Sec-GPC' => '1']],
]);

it('sets no visitor cookie for a privacy signal, and counts again when it is not honoured', function () {
    $this->app['request']->headers->set('Sec-GPC', '1');
    Experiments::expose('exp');
    expect(Illuminate\Support\Facades\Cookie::queued('pennant_experiments_visitor'))->toBeNull()
        ->and(DB::table('pennant_experiment_exposures')->count())->toBe(0);

    config(['pennant-experiments.respect_privacy_signals' => false]);
    $this->manager()->flush();
    Experiments::expose('exp');
    expect(DB::table('pennant_experiment_exposures')->count())->toBe(1);
});

it('marks the visitor cookie Secure on HTTPS or when session.secure is on', function () {
    $this->get('https://localhost/_experiments/variant/exp')->assertOk()
        ->assertCookie('pennant_experiments_visitor');
    $https = collect($this->get('https://localhost/_experiments/variant/exp', ['User-Agent' => 'Mozilla/5.0 Test'])->headers->getCookies())
        ->firstWhere(fn ($c) => $c->getName() === 'pennant_experiments_visitor');
    expect($https?->isSecure())->toBeTrue();

    $http = collect($this->get('http://localhost/_experiments/variant/exp', ['User-Agent' => 'Mozilla/5.0 Test'])->headers->getCookies())
        ->firstWhere(fn ($c) => $c->getName() === 'pennant_experiments_visitor');
    expect($http?->isSecure())->toBeFalse();

    config(['session.secure' => true]);
    $forced = collect($this->get('http://localhost/_experiments/variant/exp', ['User-Agent' => 'Mozilla/5.0 Test'])->headers->getCookies())
        ->firstWhere(fn ($c) => $c->getName() === 'pennant_experiments_visitor');
    expect($forced?->isSecure())->toBeTrue();
});
