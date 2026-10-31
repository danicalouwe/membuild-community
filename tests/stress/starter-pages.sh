#!/usr/bin/env bash
# The main website's starter pages under stress, on the tests site (port 8892, nearly empty:
# no courses, events or paid plans unless a test made them). Makes the nine pages, then loads
# each one with every plugin below switched on by itself, then all at once, then with a
# classic theme, and looks for PHP errors on the page and in debug.log. Removes them at the end.
#
#   tests/stress/starter-pages.sh            every plugin installed on the tests site
#   tests/stress/starter-pages.sh elementor  just these
set -uo pipefail
cd "$(dirname "$0")/../.."
WP="./node_modules/.bin/wp-env run tests-cli wp"
quiet() { grep -vE '^(ℹ|✔|✖) |^$'; }
base="http://localhost:8892"
plugins=("$@")
[ ${#plugins[@]} -eq 0 ] && plugins=($($WP plugin list --status=inactive --field=name 2>/dev/null | quiet | grep -vE '^membuild-community-pro$'))
[ ${#plugins[@]} -eq 0 ] && { echo "Couldn't list the tests site's plugins (wp-env didn't answer). Try again." >&2; exit 1; }
slugs=$($WP eval 'wp_set_current_user( get_users( array( "role" => "administrator", "number" => 1 ) )[0]->ID ); MemBuild\Community\Starter_Pages::rest_remove(); $r = new WP_REST_Request( "POST", "/membuild-community/v1/starter-pages" ); $r->set_header( "content-type", "application/json" ); $r->set_body( wp_json_encode( array( "pages" => array_keys( MemBuild\Community\Starter_Pages::pages() ), "front" => true, "parts" => true ) ) ); $d = rest_do_request( $r )->get_data(); echo implode( " ", array_map( fn( $p ) => wp_parse_url( $p["view"], PHP_URL_PATH ), $d["pages"] ) );' 2>/dev/null | quiet | tail -n1)
echo "Pages: $slugs"
failed=0
pages() { # one line: each page ok, or what went wrong
  local out="" body code
  for path in $slugs; do
    body=$(curl -s -w '\n%{http_code}' "$base$path")
    code=$(tail -n1 <<<"$body")
    if grep -qiE "fatal error|critical error|<b>(warning|notice|deprecated)</b>" <<<"$body"; then out+=" $path=PHP-ERROR"; failed=1
    elif [ "$code" != 200 ] || ! grep -q 'mbs-sec' <<<"$body"; then out+=" $path=FAIL($code)"; failed=1
    fi
  done
  echo "${out:- all $(wc -w <<<"$slugs" | tr -d ' ') pages ok}"
}
run() {
  $WP eval 'file_put_contents( WP_CONTENT_DIR . "/debug.log", "" );' >/dev/null 2>&1
  local result log
  result=$(pages)
  log=$($WP eval 'echo @file_get_contents( WP_CONTENT_DIR . "/debug.log" );' 2>/dev/null | quiet | grep -iE "starter|mbs-|membuild|mbcom|fatal" | sed 's/^\[[^]]*\] //' | sort | uniq -c | head -5)
  printf "%-24s %s\n" "$1" "$result"
  if [ -n "$log" ]; then echo "$log" | sed 's/^/      log: /'; failed=1; fi
}
run "on its own"
for p in "${plugins[@]}"; do
  act=$($WP plugin activate "$p" 2>&1 | quiet | grep -iE "error|warning|fatal")
  [ -n "$act" ] && echo "$p activation: $act"
  run "$p"
  $WP --skip-plugins plugin deactivate "$p" >/dev/null 2>&1
done
$WP plugin activate "${plugins[@]}" >/dev/null 2>&1; run "ALL TOGETHER"; $WP --skip-plugins plugin deactivate "${plugins[@]}" >/dev/null 2>&1
theme=$($WP theme list --status=active --field=name 2>/dev/null | quiet | tail -n1)
$WP theme activate twentytwentyone >/dev/null 2>&1; run "classic theme (2021)"; $WP theme activate "$theme" >/dev/null 2>&1
$WP eval 'wp_set_current_user( get_users( array( "role" => "administrator", "number" => 1 ) )[0]->ID ); MemBuild\Community\Starter_Pages::rest_remove(); echo "removed";' >/dev/null 2>&1
echo
[ $failed = 0 ] && echo "All starter-page checks passed" || echo "Some starter-page checks failed"
exit $failed
