#!/usr/bin/env bash
# Runs tests/integration.php inside the wp-env *tests* site, so the demo site is untouched.
# WP_PATH=/var/www/html/wp64 npm test  runs it against the WordPress 6.4 copy there instead.
set -uo pipefail
cd "$(dirname "$0")/.."
code="$(tail -n +2 tests/integration.php | base64 | tr -d '\n')" # everything after "<?php", as one line
# WP_PATH runs it against another WordPress in the tests container, e.g. an older release.
./node_modules/.bin/wp-env run tests-cli wp ${WP_PATH:+--path="$WP_PATH"} eval "eval(base64_decode('$code'));" 2>&1 | grep -vE '^(ℹ|✔|✖) |^Command failed'
exit "${PIPESTATUS[0]}"
