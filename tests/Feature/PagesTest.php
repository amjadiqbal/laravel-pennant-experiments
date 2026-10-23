<?php

use AmjadIqbal\PennantExperiments\Facades\Experiments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Experiments::define('checkout-button', ['control' => 50, 'green' => 50], ['goals' => ['purchase'], 'description' => 'Green buy button', 'sample_size' => 1000]);
});

it('denies access by default outside the local environment', function () {
    $this->get('/pennant/experiments')->assertForbidden();
    $this->get('/pennant/experiments/checkout-button')->assertForbidden();
    $this->post('/pennant/experiments/checkout-button/end', ['winner' => 'green'])->assertForbidden();
    expect(Experiments::isRunning('checkout-button'))->toBeTrue();
});

it('follows the app gates: view-only users cannot end experiments', function () {
    $admin = $this->user(1);
    $viewer = $this->user(2);
    Gate::define('viewPennantExperiments', fn ($user = null) => $user !== null);
    Gate::define('managePennantExperiments', fn ($user = null) => $user?->id === 1);

    $this->get('/pennant/experiments')->assertForbidden(); // guest
    $this->actingAs($viewer)->get('/pennant/experiments')->assertOk()->assertSee('read-only');
    $this->actingAs($viewer)->post('/pennant/experiments/checkout-button/end', ['winner' => 'green'])->assertForbidden();
    $this->actingAs($admin)->get('/pennant/experiments')->assertOk()->assertDontSee('read-only');
});

it('lists experiments and shows results', function () {
    Gate::define('viewPennantExperiments', fn ($user = null) => true);
    foreach (range(1, 6) as $n) {
        Experiments::expose('checkout-button', $this->user($n));
    }

    $this->get('/pennant/experiments')->assertOk()
        ->assertSee('checkout-button')->assertSee('Green buy button')->assertSee('running');
    $this->get('/pennant/experiments/checkout-button')->assertOk()
        ->assertSee('Goal:', false)->assertSee('purchase')->assertSee('Too early to read')
        ->assertSee('planned sample 1,000 per variant')->assertSee('End and roll out');
    $this->get('/pennant/experiments/nope')->assertNotFound();
});

it('ends the experiment from the page and shows the winner', function () {
    Gate::define('viewPennantExperiments', fn ($user = null) => true);
    $user = $this->user();
    Experiments::expose('checkout-button', $user);

    $this->post('/pennant/experiments/checkout-button/end', ['winner' => 'purple'])->assertSessionHasErrors('winner');
    $this->actingAs($user)->post('/pennant/experiments/checkout-button/end', ['winner' => 'green'])
        ->assertRedirect('/pennant/experiments/checkout-button');

    expect(DB::table('pennant_experiments')->value('ended_by'))->toBe('User 1');
    $this->get('/pennant/experiments/checkout-button')->assertSee('winner')->assertSee('Run again');
    $this->post('/pennant/experiments/checkout-button/end', ['winner' => 'control'])->assertSessionHasErrors('winner');

    $this->post('/pennant/experiments/checkout-button/restart')->assertRedirect();
    expect(Experiments::isRunning('checkout-button'))->toBeTrue();
});

it('is read-only when configured, whatever the gates say', function () {
    Gate::define('viewPennantExperiments', fn ($user = null) => true);
    config(['pennant-experiments.read_only' => true]);
    $this->get('/pennant/experiments/checkout-button')->assertOk()->assertSee('disabled', false);
    $this->post('/pennant/experiments/checkout-button/end', ['winner' => 'green'])->assertForbidden();
});

it('shows experiments with data but no definition any more', function () {
    Gate::define('viewPennantExperiments', fn ($user = null) => true);
    DB::table('pennant_experiments')->insert(['name' => 'old-promo', 'status' => 'ended', 'winner' => 'b']);
    $this->get('/pennant/experiments')->assertSee('Recorded but no longer defined')->assertSee('old-promo');
});

it('reports itself in php artisan about', function () {
    $this->artisan('about')->expectsOutputToContain('Pennant Experiments')->assertSuccessful();
});
