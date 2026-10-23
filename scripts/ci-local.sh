#!/usr/bin/env bash
# Local CI ("CI is local", no GitHub workflow runs): every PR runs this and pastes the
# summary into its body. PHP 8.3/8.4/8.5 ×
# Laravel 12/13, Testbench pinned per Laravel major, composer update, a check that the intended Laravel major
# resolved, then Pest, Pint --test and PHPStan. Then one --prefer-lowest run (PHP 8.3, Laravel 12) so the lowest
# laravel/pennant we allow is tested too (skip with LOWEST=0).
#   scripts/ci-local.sh                                     the full 3×2 matrix
#   PHP_VERSIONS=8.4 LARAVEL_VERSIONS=13 scripts/ci-local.sh one cell
# Finds php8.x on PATH, or Homebrew's php@8.x. composer.json is restored after each cell.
set -uo pipefail
cd "$(dirname "$0")/.."

PHP_VERSIONS=${PHP_VERSIONS:-"8.3 8.4 8.5"}
LARAVEL_VERSIONS=${LARAVEL_VERSIONS:-"12 13"}
RESULTS=(); FAILED=0
step() { local name=$1; shift; printf '\n== %s\n' "$name"; if "$@"; then RESULTS+=("pass  $name"); else RESULTS+=("FAIL  $name"); FAILED=1; fi; }

php_bin() {
  local v=$1 p
  for p in "$(command -v "php$v" 2>/dev/null)" "$(brew --prefix 2>/dev/null)/opt/php@$v/bin/php"; do
    [ -n "$p" ] && [ -x "$p" ] && "$p" -r 'exit(PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION === $argv[1] ? 0 : 1);' "$v" && { echo "$p"; return; }
  done
  return 1
}

COMPOSER=$(command -v composer)
SHIM=$(mktemp -d)
cp composer.json "$SHIM/composer.json.orig"
cleanup() { cp "$SHIM/composer.json.orig" composer.json; rm -f composer.lock; rm -rf "$SHIM"; }
trap cleanup EXIT
BASE_PATH=$PATH

for v in $PHP_VERSIONS; do
  PHP=$(php_bin "$v") || { RESULTS+=("FAIL  PHP $v not found"); FAILED=1; continue; }
  # vendor/bin scripts start with "#!/usr/bin/env php": a wrapper puts this version (opcache on, as in CI) first on PATH.
  printf '#!/bin/sh\nexec "%s" -d opcache.enable=1 -d opcache.enable_cli=1 "$@"\n' "$PHP" > "$SHIM/php"; chmod +x "$SHIM/php"
  export PATH="$SHIM:$BASE_PATH"
  for l in $LARAVEL_VERSIONS; do
    cell="PHP $v / Laravel $l"
    cp "$SHIM/composer.json.orig" composer.json; rm -f composer.lock
    case $l in 12) tb='^10.0' ;; 13) tb='^11.0' ;; *) RESULTS+=("FAIL  $cell: unknown Laravel"); FAILED=1; continue ;; esac
    step "$cell active php"    sh -c "php -r 'exit(PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION === \"$v\" ? 0 : 1);'"
    step "$cell pin testbench" php "$COMPOSER" require --dev "orchestra/testbench:$tb" --no-update -W
    step "$cell composer update" php "$COMPOSER" update --no-interaction --prefer-dist --prefer-stable --no-progress || continue
    step "$cell laravel $l resolved" sh -c "php '$COMPOSER' show laravel/framework | grep -E '^versions' | grep -q '\* v$l\.'"
    step "$cell pest"    php "$COMPOSER" test
    step "$cell pint"    php "$COMPOSER" pint
    step "$cell phpstan" php "$COMPOSER" analyse
  done
done

if [ "${LOWEST:-1}" != 0 ] && PHP=$(php_bin 8.3); then
  printf '#!/bin/sh\nexec "%s" "$@"\n' "$PHP" > "$SHIM/php"; chmod +x "$SHIM/php"
  export PATH="$SHIM:$BASE_PATH"
  cp "$SHIM/composer.json.orig" composer.json; rm -f composer.lock
  step "lowest pin testbench" php "$COMPOSER" require --dev "orchestra/testbench:^10.0" --no-update -W
  if step "lowest composer update" php "$COMPOSER" update --no-interaction --prefer-dist --prefer-lowest --prefer-stable --no-progress; then
    step "lowest pennant $(php "$COMPOSER" show laravel/pennant | grep -E '^versions' | awk '{print $NF}')" true
    step "lowest pest" php "$COMPOSER" test
  fi
fi

printf '\n== Summary (%s, %s)\n' "$(git rev-parse --short HEAD)" "$(date '+%Y-%m-%d %H:%M')"
printf '%s\n' "${RESULTS[@]}"
exit $FAILED
