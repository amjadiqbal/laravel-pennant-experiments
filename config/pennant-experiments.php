<?php

return [

    /*
    | Who takes part. 'user_or_visitor': the signed-in user (from the guard below), otherwise
    | a guest identified by a random id in a long-lived cookie. 'visitor': always the cookie id.
    | For teams or any other model, pass the scope yourself: Experiments::variant('x', $team),
    | or set a resolver with Experiments::resolveScopeUsing(fn () => auth()->user()?->team).
    */
    'participant' => env('PENNANT_EXPERIMENTS_PARTICIPANT', 'user_or_visitor'),

    'guard' => null, // null = the default guard

    'cookie' => [
        'name' => 'pennant_experiments_visitor',
        'minutes' => 60 * 24 * 365,
    ],

    // When a guest signs in, move their exposures and conversions to the user and keep their variant.
    'merge_visitor_on_login' => true,

    /*
    | Participants taken from the current request (no scope passed, or a visitor) are left out
    | when the request is from a bot or carries a privacy signal (DNT: 1 or Sec-GPC: 1): they see
    | the control, get no visitor cookie and are never counted. Scopes you pass yourself
    | (a user, a team, ...) are not checked.
    */
    'respect_privacy_signals' => true,

    // Crawlers get the control variant and are never counted.
    'exclude_bots' => true,
    'bot_pattern' => '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|quora link preview|outbrain|pinterest|vkshare|w3c_validator|whatsapp|preview|headless|lighthouse|pingdom|uptime/i',

    /*
    | Statistics. alpha is the false-positive rate for the z-test; with more than one challenger
    | it is split between them (Bonferroni). Results are marked "too early" until every variant
    | has at least min_exposures and min_conversions.
    */
    'statistics' => [
        'alpha' => 0.05,
        'power' => 0.8,
        'min_exposures' => 100,
        'min_conversions' => 10,
    ],

    /*
    | The results pages live at /{path}. null = next to the Pennant dashboard
    | ({pennant-dashboard.path}/experiments) or /pennant/experiments without it.
    | The gates viewPennantExperiments and managePennantExperiments are checked on top.
    */
    'enabled' => env('PENNANT_EXPERIMENTS_ENABLED', true),
    'path' => env('PENNANT_EXPERIMENTS_PATH'),
    'domain' => env('PENNANT_EXPERIMENTS_DOMAIN'),
    'middleware' => ['web'],
    'read_only' => env('PENNANT_EXPERIMENTS_READ_ONLY', false),

    // Endpoints for the JavaScript helper (@experimentsScript): exposures and conversions from the browser.
    'tracking' => [
        'enabled' => true,
        'path' => '_experiments',
        'middleware' => ['web', 'throttle:120,1'],
        // Writes must come from this site's pages: Origin (or Referer) = the request's scheme, host and port.
        'check_origin' => true,
    ],

    // Add an "Experiments" tab to amjadiqbal/laravel-pennant-dashboard (v1.1+) when it is installed.
    'dashboard_tab' => true,

    'connection' => env('PENNANT_EXPERIMENTS_DB_CONNECTION'),

    'tables' => [
        'experiments' => 'pennant_experiments',
        'exposures' => 'pennant_experiment_exposures',
        'conversions' => 'pennant_experiment_conversions',
    ],

    // Load the package migrations automatically. Set false after publishing them.
    'run_migrations' => true,
];
