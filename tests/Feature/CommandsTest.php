<?php

use AmjadIqbal\PennantExperiments\Facades\Experiments;

beforeEach(function () {
    Experiments::define('exp', ['control', 'b'], ['goals' => ['signup']]);
});

it('lists experiments and prints results', function () {
    Experiments::expose('exp', $this->user());
    $this->artisan('pennant-experiments:results')->expectsOutputToContain('exp')->assertSuccessful();
    $this->artisan('pennant-experiments:results', ['experiment' => 'exp'])
        ->expectsOutputToContain('Goal: signup')
        ->expectsOutputToContain('Not enough data yet')
        ->assertSuccessful();
    $this->artisan('pennant-experiments:results', ['experiment' => 'nope'])->assertFailed();
});

it('ends an experiment from the console', function () {
    $this->artisan('pennant-experiments:end', ['experiment' => 'exp'])->assertFailed();
    $this->artisan('pennant-experiments:end', ['experiment' => 'exp', '--winner' => 'b', '--force' => true])->assertSuccessful();
    expect(Experiments::winner('exp'))->toBe('b');
    $this->artisan('pennant-experiments:end', ['experiment' => 'exp', '--winner' => 'b', '--force' => true])->assertFailed();
});
