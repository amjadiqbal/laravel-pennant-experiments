# Changelog

All notable changes to `laravel-pennant-experiments` are documented in this file, in [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

## [Unreleased]

## [0.1.0] - 2026-10-23

First release.

### Added
- `Experiments::define()`: experiments with weighted variants (the first is the control) and goals, registered as Pennant features whose value is the variant.
- Sticky, hash-based assignment for any scope (users, teams, strings, guests); stored by Pennant's database driver on first use.
- Guests identified by a random id in a long-lived, http-only cookie; on `Login` their exposure, conversions and variant move to the user.
- Exposure tracking (`Experiments::expose()`, the `@variant` Blade directive) and conversion goals (`Experiments::convert()`), counted once per scope and goal, only for exposed scopes.
- JavaScript helper (`@experimentsScript`, `PennantExperiments.expose()/convert()`, `data-experiment-convert`) with CSRF-protected, rate-limited endpoints; the participant always comes from the server.
- Statistics: two-proportion z-test with a difference interval, Bayesian probability to beat control (exact Beta sum), Wilson intervals, Bonferroni correction, minimum-data rule and planned sample size (set, or derived from the minimum detectable effect). Unit-tested against textbook values.
- Results pages (`/pennant/experiments`), `pennant-experiments:results` and `pennant-experiments:end` commands, winner rollout that rewrites stored Pennant values, and "run again".
- Eligibility rules, bot exclusion, gates `viewPennantExperiments` / `managePennantExperiments`, read-only mode, `php artisan about` entry.
- Optional "Experiments" tab in amjadiqbal/laravel-pennant-dashboard (v1.1+); neither package requires the other.
