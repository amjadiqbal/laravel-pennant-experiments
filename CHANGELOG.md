# Changelog

All notable changes to `laravel-pennant-experiments` are documented in this file, in [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

## [Unreleased]

## [0.1.2] - 2026-10-24

### Fixed
- The visitor cookie is now `Secure` on every HTTPS request. In 0.1.1 an explicit `SESSION_SECURE_COOKIE=false` switched it off even on HTTPS; now either `session.secure` or HTTPS is enough, as documented.
- The Bayesian probability switches to the normal approximation only with more than 20,000 challenger conversions, as documented (0.1.1 switched at exactly 20,000).

### Documentation
- Excluded requests (bots, DNT, GPC) see the control while an experiment runs and the winner after it ends.

## [0.1.1] - 2026-10-24

A review of the 0.1.0 README against the code. Behaviour changes are marked.

### Changed
- **Security:** the `managePennantExperiments` gate is now local-only by default on its own. In 0.1.0 it followed `viewPennantExperiments`, so defining only the view gate also let those users end and reset experiments. If you relied on that, define the manage gate.
- **Run again is now "Start over" and resets completely** (with a confirmation): the experiment's exposures and conversions are deleted, stored Pennant values purged, and every scope is assigned and counted afresh. In 0.1.0 it kept the old data, and already-exposed scopes kept their first variant.
- Bots are now left out for every participant taken from the current request, signed-in users included (0.1.0 only checked guests). Scopes you pass yourself are not checked.

### Added
- Do Not Track and Global Privacy Control are honoured (`respect_privacy_signals`, on by default): such requests see the control, get no visitor cookie and are not counted.
- The tracking endpoints check that `Origin` (or `Referer`) matches the request's scheme, host and port (`tracking.check_origin`), on top of CSRF and the rate limit.
- The visitor cookie is `Secure` when `session.secure` is on or the request is HTTPS.

### Documentation
- What moves when a guest signs in (only experiments the user has not seen; the variant is kept only while running), that intervals use the Bonferroni-adjusted level, when the Bayesian sum switches to the normal approximation (more than 20,000 challenger conversions), and that a planned sample from `minimum_detectable_effect` follows the control's observed rate.

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
