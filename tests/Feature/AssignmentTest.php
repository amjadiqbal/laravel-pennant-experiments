<?php

use AmjadIqbal\PennantExperiments\Facades\Experiments;
use AmjadIqbal\PennantExperiments\Tests\Fixtures\Team;
use AmjadIqbal\PennantExperiments\Tests\Fixtures\User;
use AmjadIqbal\PennantExperiments\Visitor;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

it('registers the experiment as a Pennant feature returning the variant name', function () {
    Experiments::define('checkout-button', ['control' => 50, 'green' => 50]);
    $user = $this->user();

    $variant = Feature::for($user)->value('checkout-button');
    expect($variant)->toBeIn(['control', 'green'])
        ->and(Experiments::variant('checkout-button', $user))->toBe($variant)
        ->and(Feature::defined())->toContain('checkout-button');
});

it('stores the first answer, so assignment is sticky across requests even if the weights change', function () {
    Experiments::define('exp', ['control' => 50, 'b' => 50]);
    $users = collect(range(1, 40))->map(fn ($n) => $this->user($n));
    $first = $users->mapWithKeys(fn ($u) => [$u->id => Experiments::variant('exp', $u)]);

    $this->nextRequest();
    Experiments::define('exp', ['control' => 1, 'b' => 99]);
    $users->each(fn ($u) => expect(Experiments::variant('exp', $u))->toBe($first[$u->id]));

    expect(DB::table('features')->where('name', 'exp')->count())->toBe(40);
});

it('is sticky without storage too (array driver): the hash decides', function () {
    config(['pennant.default' => 'array']);
    Feature::forgetDrivers();
    Experiments::define('exp', ['control', 'b', 'c']);
    $user = $this->user();
    $first = Experiments::variant('exp', $user);

    for ($i = 0; $i < 3; $i++) {
        $this->nextRequest();
        Feature::forgetDrivers();
        Experiments::define('exp', ['control', 'b', 'c']);
        expect(Experiments::variant('exp', $user))->toBe($first);
    }
});

it('works for any scope: teams, strings, guests', function () {
    Experiments::define('team-pricing', ['control', 'annual-first']);
    $team = Team::query()->create(['name' => 'Acme']);

    expect(Experiments::variant('team-pricing', $team))->toBeIn(['control', 'annual-first'])
        ->and(Experiments::variant('team-pricing', 'tenant-42'))->toBeIn(['control', 'annual-first'])
        ->and(Experiments::variant('team-pricing', new Visitor('8b1c6d0e-2f3a-4b5c-9d6e-7f8091a2b3c4')))->toBeIn(['control', 'annual-first']);
    expect(DB::table('features')->where('name', 'team-pricing')->pluck('scope')->all())
        ->toContain(Team::class.'|1', 'tenant-42', 'visitor|8b1c6d0e-2f3a-4b5c-9d6e-7f8091a2b3c4');
});

it('uses the signed-in user, else a visitor id from a long-lived cookie', function () {
    Experiments::define('exp', ['control', 'b']);
    $visitor = Experiments::participant();
    expect($visitor)->toBeInstanceOf(Visitor::class)
        ->and(Visitor::isValidId($visitor->id))->toBeTrue();

    $queued = Cookie::queued('pennant_experiments_visitor');
    expect($queued)->not->toBeNull()
        ->and($queued->getValue())->toBe($visitor->id)
        ->and($queued->getExpiresTime())->toBeGreaterThan(time() + 300 * 86400)
        ->and($queued->isHttpOnly())->toBeTrue();

    $user = $this->user();
    $this->actingAs($user);
    expect(Experiments::participant()->is($user))->toBeTrue();

    config(['pennant-experiments.participant' => 'visitor']);
    expect(Experiments::participant())->toBeInstanceOf(Visitor::class);
});

it('reads an existing visitor cookie instead of making a new one', function () {
    $id = '8b1c6d0e-2f3a-4b5c-9d6e-7f8091a2b3c4';
    $this->app['request']->cookies->set('pennant_experiments_visitor', $id);
    expect(Experiments::visitor()->id)->toBe($id)->and(Cookie::queued('pennant_experiments_visitor'))->toBeNull();

    $this->manager()->flush();
    $this->app['request']->cookies->set('pennant_experiments_visitor', 'not-a-uuid');
    expect(Experiments::visitor()->id)->not->toBe('not-a-uuid');
});

it('lets the app pick the participant (e.g. the current team)', function () {
    $team = Team::query()->create(['name' => 'Acme']);
    Experiments::resolveScopeUsing(fn () => $team);
    expect(Experiments::participant())->toBe($team);
});

it('gives ineligible scopes the control', function () {
    Experiments::define('exp', ['control' => 0, 'b' => 1], ['eligible' => fn ($scope) => $scope instanceof User && $scope->id !== 2]);
    expect(Experiments::variant('exp', $this->user(1)))->toBe('b')
        ->and(Experiments::variant('exp', $this->user(2)))->toBe('control');
});

it('shows bots the control and never touches Pennant for them', function () {
    Experiments::define('exp', ['control' => 0, 'b' => 1]);
    $this->app['request']->headers->set('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)');
    expect(Experiments::variant('exp'))->toBe('control')
        ->and(DB::table('features')->count())->toBe(0);
});

it('falls back to the control when the stored value is not a variant', function () {
    Experiments::define('exp', ['control' => 0, 'b' => 1]);
    $user = $this->user();
    Feature::for($user)->activate('exp'); // true, e.g. from a dashboard toggle
    expect(Experiments::variant('exp', $user))->toBe('control');
});

it('throws a clear error for an unknown experiment', function () {
    expect(fn () => Experiments::variant('nope'))->toThrow(InvalidArgumentException::class, 'Unknown experiment [nope]');
});
