#!/usr/bin/env bash
# Runs tests/integration.php inside the wp-env *tests* site, so the demo site is untouched.
# WP_PATH=/var/www/html/wp64 npm test  runs it against the WordPress 6.4 copy there instead.
set -uo pipefail
cd "$(dirname "$0")/.."
# The theme contract: text sizes and corners must come from the tokens (no Docker needed).
node tests/check-styles.mjs || exit 1
# The file is copied into the container (it's too long to pass on the command line).
# This folder's own tests container: wp-env names its containers after this folder's install path,
# so a site started from another checkout or project is never picked.
env_id="$(basename "$(./node_modules/.bin/wp-env install-path 2>/dev/null | grep -m1 '/\.wp-env/')")"
container="$env_id-tests-cli-1"
if ! docker ps --format '{{.Names}}' | grep -qxF -- "$container"; then
  echo "This folder's wp-env tests site isn't running. Start it with ./node_modules/.bin/wp-env start" >&2
  exit 1
fi
docker cp tests/integration.php "$container:/tmp/mbcom-integration.php" >/dev/null
./node_modules/.bin/wp-env run tests-cli wp ${WP_PATH:+--path="$WP_PATH"} eval-file /tmp/mbcom-integration.php 2>&1 | grep -vE '^(ℹ|✔|✖) |^Command failed'
status="${PIPESTATUS[0]}"
# Changing the community's address, over real web requests.
bash tests/address-check.sh "$container" || status=1
# Private uploads can't be found by a logged-out visitor, whichever way they ask.
bash tests/private-files-check.sh "$container" || status=1
# The login pages in a real Chrome, on the local site (port 8891), when it's up, built and this folder's own.
if [ ! -f tests/login/login-check.cjs ]; then
  :
elif ! docker port "$env_id-wordpress-1" 80 2>/dev/null | grep -q ':8891$'; then
  echo "Login check skipped: it only runs on port 8891, and that site isn't this folder's."
elif curl -s -o /dev/null --max-time 5 http://localhost:8891/community/login; then
  node tests/login/login-check.cjs || status=1
else
  echo "Login check skipped: the local site (localhost:8891) isn't running."
fi
exit "$status"
