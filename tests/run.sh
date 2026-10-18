#!/usr/bin/env bash
# Runs tests/integration.php inside the wp-env *tests* site, so the demo site is untouched.
# WP_PATH=/var/www/html/wp64 npm test  runs it against the WordPress 6.4 copy there instead.
set -uo pipefail
cd "$(dirname "$0")/.."
# The file is copied into the container (it's too long to pass on the command line).
# This project's tests container, not another wp-env's: the one whose compose file names this folder.
container=""
for c in $(docker ps --format '{{.Names}}' | grep -- '-tests-cli-1$'); do
  dir="$(docker inspect "$c" --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' 2>/dev/null)"
  # The path may be folded over two lines in the YAML, so match the plugin mount, which is one token.
  if grep -qF "/membuild-community:/var/www/html/wp-content/plugins/membuild-community" "$dir/docker-compose.yml" 2>/dev/null; then container="$c"; break; fi
done
if [ -z "$container" ]; then
  echo "The wp-env tests site isn't running. Start it with ./node_modules/.bin/wp-env start" >&2
  exit 1
fi
docker cp tests/integration.php "$container:/tmp/mbcom-integration.php" >/dev/null
./node_modules/.bin/wp-env run tests-cli wp ${WP_PATH:+--path="$WP_PATH"} eval-file /tmp/mbcom-integration.php 2>&1 | grep -vE '^(ℹ|✔|✖) |^Command failed'
exit "${PIPESTATUS[0]}"
