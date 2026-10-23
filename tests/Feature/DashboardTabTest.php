<?php

use AmjadIqbal\PennantExperiments\PennantExperimentsServiceProvider;

it('adds no tab when the dashboard is not installed', function () {
    expect(class_exists('AmjadIqbal\\PennantDashboard\\PennantDashboardServiceProvider', false))->toBeFalse()
        ->and(config('pennant-dashboard.tabs'))->toBeNull()
        ->and(PennantExperimentsServiceProvider::path())->toBe('pennant/experiments');
});

it('adds an Experiments tab (once) when the dashboard is installed, and follows its path', function () {
    require_once dirname(__DIR__).'/Fixtures/Dashboard/PennantDashboardServiceProvider.php';
    config(['pennant-dashboard.path' => 'admin/flags', 'pennant-dashboard.tabs' => [['label' => 'Docs', 'route' => 'docs']]]);

    $provider = new PennantExperimentsServiceProvider($this->app);
    $provider->boot();
    $provider->boot();

    expect(config('pennant-dashboard.tabs'))->toBe([
        ['label' => 'Docs', 'route' => 'docs'],
        ['label' => 'Experiments', 'route' => 'pennant-experiments.index', 'active' => 'pennant-experiments.*'],
    ])->and(PennantExperimentsServiceProvider::path())->toBe('admin/flags/experiments');
});
