<?php

use AmjadIqbal\PennantExperiments\Facades\Experiments;
use AmjadIqbal\PennantExperiments\Visitor;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Experiments::define('exp', ['control' => 1, 'b' => 1]);
});

it('ends the experiment: everyone gets the winner, stored values too, and nothing more is recorded', function () {
    $users = collect(range(1, 30))->map(fn ($n) => $this->user($n));
    $users->each(fn ($u) => Experiments::expose('exp', $u));
    expect(DB::table('features')->where('name', 'exp')->where('value', json_encode('control'))->count())->toBeGreaterThan(0);

    Experiments::end('exp', 'b', 'admin');
    $this->nextRequest();

    expect(DB::table('features')->where('name', 'exp')->pluck('value')->unique()->all())->toBe([json_encode('b')]);
    $users->each(fn ($u) => expect(Experiments::variant('exp', $u))->toBe('b'));
    // Never-seen scopes and direct Pennant reads get the winner too.
    $new = $this->user(99);
    expect(Experiments::variant('exp', $new))->toBe('b')
        ->and(Feature::for($this->user(100))->value('exp'))->toBe('b')
        ->and(Experiments::expose('exp', $new))->toBe('b')
        ->and(Experiments::convert('exp', 'conversion', $users[0]))->toBeFalse()
        ->and(DB::table('pennant_experiment_exposures')->count())->toBe(30);

    $state = DB::table('pennant_experiments')->where('name', 'exp')->first();
    expect($state->status)->toBe('ended')->and($state->winner)->toBe('b')->and($state->ended_by)->toBe('admin');
});

it('refuses a winner that is not a variant', function () {
    expect(fn () => Experiments::end('exp', 'purple'))->toThrow(InvalidArgumentException::class);
});

it('starts over from zero: data deleted, stored values purged, scopes counted afresh', function () {
    Experiments::define('other', ['control', 'b']);
    $user = $this->user();
    Experiments::expose('exp', $user);
    Experiments::convert('exp', 'conversion', $user);
    Experiments::expose('other', $user);
    Experiments::end('exp', 'b');

    Experiments::restart('exp');
    $this->nextRequest();

    expect(Experiments::isRunning('exp'))->toBeTrue()
        ->and(DB::table('features')->where('name', 'exp')->count())->toBe(0)
        ->and(DB::table('pennant_experiment_exposures')->where('experiment', 'exp')->count())->toBe(0)
        ->and(DB::table('pennant_experiment_conversions')->where('experiment', 'exp')->count())->toBe(0)
        ->and(DB::table('pennant_experiment_exposures')->where('experiment', 'other')->count())->toBe(1);

    // The same scope is exposed and counted again, with a fresh assignment.
    $variant = Experiments::expose('exp', $user);
    expect(DB::table('pennant_experiment_exposures')->where('experiment', 'exp')->value('variant'))->toBe($variant);
});

it('does not keep a guest\'s variant on the user for an ended experiment', function () {
    $id = '8b1c6d0e-2f3a-4b5c-9d6e-7f8091a2b3c4';
    $this->app['request']->cookies->set('pennant_experiments_visitor', $id);
    Experiments::expose('exp');
    Experiments::end('exp', 'b');
    $user = $this->user();
    event(new Login('web', $user, false));
    $this->nextRequest();

    expect(DB::table('pennant_experiment_exposures')->value('scope'))->toBe($user::class.'|'.$user->id)
        ->and(Experiments::variant('exp', $user))->toBe('b');
});

it('moves a guest to the user on login: counted once, same variant', function () {
    $id = '8b1c6d0e-2f3a-4b5c-9d6e-7f8091a2b3c4';
    $this->app['request']->cookies->set('pennant_experiments_visitor', $id);
    $guestVariant = Experiments::expose('exp');
    Experiments::convert('exp');
    expect(DB::table('pennant_experiment_exposures')->value('scope'))->toBe('visitor|'.$id);

    $user = $this->user();
    event(new Login('web', $user, false));
    $this->nextRequest();

    expect(DB::table('pennant_experiment_exposures')->pluck('scope')->all())->toBe([$user::class.'|'.$user->id])
        ->and(DB::table('pennant_experiment_conversions')->pluck('scope')->all())->toBe([$user::class.'|'.$user->id])
        ->and(Experiments::variant('exp', $user))->toBe($guestVariant);
});

it('keeps the user\'s own exposure when they already had one', function () {
    $user = $this->user();
    $userVariant = Experiments::expose('exp', $user);
    $visitor = new Visitor('8b1c6d0e-2f3a-4b5c-9d6e-7f8091a2b3c4');
    Experiments::expose('exp', $visitor);

    $this->manager()->mergeVisitor($visitor, $user);

    expect(DB::table('pennant_experiment_exposures')->count())->toBe(2)
        ->and(Experiments::variant('exp', $user))->toBe($userVariant);
});
