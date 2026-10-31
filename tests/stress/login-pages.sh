#!/usr/bin/env bash
# The community's login pages under stress, on the tests site (port 8892): log in, join,
# forgot password, an expired login link and a wrong password, loaded with every plugin below
# switched on by itself, then all at once. Looks for PHP errors on the page and in debug.log,
# and that each page still shows its form.
#
#   tests/stress/login-pages.sh            every plugin installed on the tests site
#   tests/stress/login-pages.sh wordfence  just these
set -uo pipefail
cd "$(dirname "$0")/../.."
WP="./node_modules/.bin/wp-env run tests-cli wp"
quiet() { grep -vE '^(ℹ|✔|✖) |^$'; }
base="http://localhost:8892/community"
plugins=("$@")
[ ${#plugins[@]} -eq 0 ] && plugins=($($WP plugin list --status=inactive --field=name 2>/dev/null | quiet | grep -vE '^membuild-community-pro$'))
[ ${#plugins[@]} -eq 0 ] && { echo "Couldn't list the tests site's plugins (wp-env didn't answer). Try again." >&2; exit 1; }
$WP eval 'update_option( "users_can_register", 1 ); echo 1;' >/dev/null 2>&1
failed=0
pages() { # one line: each page ok, or what went wrong
  local out="" body code path
  for path in login join forgot-password "login-link?u=1&t=x"; do
    body=$(curl -s -w '\n%{http_code}' "$base/$path")
    code=$(tail -n1 <<<"$body")
    if grep -qiE "fatal error|critical error|<b>(warning|notice|deprecated)</b>" <<<"$body"; then out+=" $path=PHP-ERROR"; failed=1
    elif [ "$code" != 200 ] || ! grep -q '<form' <<<"$body"; then out+=" $path=FAIL($code)"; failed=1
    fi
  done
  # A wrong password, posted with the page's own security code: still a plain answer.
  local jar; jar=$(mktemp); body=$(curl -s -c "$jar" "$base/login")
  local nonce; nonce=$(grep -o 'name="_mbcom_login" value="[^"]*"' <<<"$body" | sed 's/.*value="//;s/"//')
  body=$(curl -s -b "$jar" -H "Origin: http://localhost:8892" --data-urlencode "log=nobody@example.test" --data-urlencode "pwd=wrong-wrong" --data-urlencode "_mbcom_login=$nonce" "$base/login"); rm -f "$jar"
  if grep -qiE "fatal error|critical error|<b>(warning|notice|deprecated)</b>" <<<"$body"; then out+=" wrong-password=PHP-ERROR"; failed=1
  elif ! grep -qE "isn’t right|Too many|timed out" <<<"$body"; then out+=" wrong-password=NO-ANSWER"; failed=1
  fi
  echo "${out:- all 5 ok}"
}
run() {
  $WP eval 'file_put_contents( WP_CONTENT_DIR . "/debug.log", "" ); global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_transient%mbcom_%\"" );' >/dev/null 2>&1
  local result log
  result=$(pages)
  log=$($WP eval 'echo @file_get_contents( WP_CONTENT_DIR . "/debug.log" );' 2>/dev/null | quiet | grep -iE "login|mbcom|membuild|fatal" | sed 's/^\[[^]]*\] //' | sort | uniq -c | head -5)
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
echo
[ $failed = 0 ] && echo "All login-page checks passed" || echo "Some login-page checks failed"
exit $failed
