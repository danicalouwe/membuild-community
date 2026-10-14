#!/usr/bin/env bash
# Activates each plugin on its own next to MemBuild - Community on the tests site,
# then runs the integration suite and loads the community pages over HTTP.
# Usage: tests/stress/conflicts.sh plugin-a plugin-b ...   (or "all" for everything at once)
set -uo pipefail
cd "$(dirname "$0")/../.."
WP="./node_modules/.bin/wp-env run tests-cli wp"
quiet() { grep -vE '^(ℹ|✔|✖) |^$'; }
base="http://localhost:8892"
# A signed-in admin, to load the app itself (made once; the session lasts the whole run).
cookie=$($WP eval '$u = get_user_by( "login", "mbct_admin" ) ?: get_users( array( "role" => "administrator", "number" => 1 ) )[0]; $e = time() + 3 * HOUR_IN_SECONDS; echo LOGGED_IN_COOKIE, "=", wp_generate_auth_cookie( $u->ID, $e, "logged_in", WP_Session_Tokens::get_instance( $u->ID )->create( $e ) );' 2>/dev/null | quiet | tail -n1)
page() { # label, path, text the page must contain, [cookie]
  local body code
  body=$(curl -s -w '\n%{http_code}' ${4:+-H "Cookie: $4"} "$base$2")
  code=$(tail -n1 <<<"$body")
  if grep -qiE "fatal error|critical error|<b>(warning|notice|deprecated)</b>" <<<"$body"; then echo -n " $1=PHP-ERROR"
  elif [ "$code" = 200 ] && grep -q "$3" <<<"$body"; then echo -n " $1=ok"
  else echo -n " $1=FAIL($code)"; fi
}
check_pages() {
  page login /community/login/ 'Log into'
  page app /community/ 'membuildCommunity' "$cookie"
  page home / '<html'
  echo
}
run() {
  local label="$1"
  $WP eval 'file_put_contents( WP_CONTENT_DIR . "/debug.log", "" );' >/dev/null 2>&1
  result=$(npm test 2>&1 | grep -E "✗|passed" | tr '\n' ' ')
  pages=$(check_pages)
  log=$($WP eval 'echo @file_get_contents( WP_CONTENT_DIR . "/debug.log" );' 2>/dev/null | quiet | grep -iE "membuild|mbcom|fatal" | sort | uniq -c | head -5)
  printf "%-26s %s |%s\n" "$label" "$result" "$pages"
  [ -n "$log" ] && echo "$log" | sed 's/^/      log: /'
}
if [ "$1" = "all" ]; then
  shift; $WP plugin activate "$@" 2>&1 | quiet | grep -iE "error|warning|fatal"; run "ALL TOGETHER"; $WP plugin deactivate "$@" >/dev/null 2>&1; exit
fi
for p in "$@"; do
  act=$($WP plugin activate "$p" 2>&1 | quiet | grep -iE "error|warning|fatal")
  [ -n "$act" ] && echo "$p activation: $act"
  run "$p"
  $WP plugin deactivate "$p" >/dev/null 2>&1
done
