#!/usr/bin/env bash
# Runs a PHP file from tests/stress inside the wp-env tests site: run-php.sh <file> [args...]
set -uo pipefail
cd "$(dirname "$0")/../.."
file="$1"; shift
args="$(printf "'%s'," "$@")"
code="$( { echo "\$args = array(${args%,});"; tail -n +2 "$file"; } | base64 | tr -d '\n')"
./node_modules/.bin/wp-env run tests-cli wp eval "eval(base64_decode('$code'));" 2>&1 | grep -vE '^(ℹ|✔|✖) |^Command failed'
exit "${PIPESTATUS[0]}"
