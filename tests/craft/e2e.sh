#!/usr/bin/env bash
# End-to-end check of the CP download tool against a disposable Craft install
# (general-craft-4/5-test-container). Uses the stub yt-dlp, so nothing is
# downloaded from the internet and no project assets are touched.
#
#   tests/craft/e2e.sh ~/Development/Claude/general-craft-5-test-container
set -uo pipefail

H="${1:?usage: e2e.sh <harness-dir>}"
PORT=$(grep -E '^HTTP_PORT=' "$H/.env" | cut -d= -f2)
ADMIN_PW=$(grep -E '^CRAFT_ADMIN_PASSWORD=' "$H/.env" | cut -d= -f2-)
BASE="http://localhost:${PORT}"
SCRIPT=/workspace/Claude/craft-video-downloader/tests/craft/fixtures.php
VD_PW="vd-$(openssl rand -hex 12)"
TMP=$(mktemp -d)
pass=0; fail=0
check() { if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  ✓ $1"; else fail=$((fail+1)); echo "  ✗ $1 ${3:-}"; fi; }
fx() { (cd "$H" && docker compose exec -T -e VD_PW="$VD_PW" php php "$SCRIPT" "$@"); }

login() { # $1 jar, $2 user, $3 pw
  local page tok
  page=$(curl -s -c "$1" -b "$1" "$BASE/admin/login")
  tok=$(printf '%s' "$page" | grep -oE '"csrfTokenValue":"[^"]+"' | head -1 | cut -d'"' -f4)
  curl -s -c "$1" -b "$1" -H 'Accept: application/json' -H "X-CSRF-Token: $tok" \
    --data-urlencode "loginName=$2" --data-urlencode "password=$3" \
    "$BASE/index.php?p=admin/actions/users/login" >/dev/null
}
csrf() { curl -s -b "$1" -c "$1" -H 'Accept: application/json' "$BASE/index.php?p=admin/actions/users/session-info" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrfTokenValue"]??"";'; }
post() { # $1 jar, $2 action, rest: --data-urlencode args ; prints body, status in $TMP/code
  local jar=$1 act=$2; shift 2; local tok; tok=$(csrf "$jar")
  curl -s -o "$TMP/body" -w '%{http_code}' -b "$jar" -c "$jar" -H 'Accept: application/json' -H "X-CSRF-Token: $tok" "$@" "$BASE/index.php?p=admin/actions/$act" > "$TMP/code"
  cat "$TMP/body"
}
code() { cat "$TMP/code"; }
get() { curl -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -b "$1" "$BASE/$2"; }

echo "== fixtures ($H)"
fx setup || exit 1

A="$TMP/admin.jar"; E="$TMP/editor.jar"; N="$TMP/noaccess.jar"
login "$A" admin "$ADMIN_PW"; login "$E" vd-editor "$VD_PW"; login "$N" vd-noaccess "$VD_PW"

echo "== nav + page"
c=$(get "$A" "admin/video-downloader"); check "admin: tool page 200" "$([ "$c" = 200 ] && echo 1)" "($c)"
check "admin: page has tool markup" "$(grep -q 'id="vdt"' "$TMP/body" && echo 1)"
check "admin: nav item present" "$(grep -q 'admin/video-downloader"' "$TMP/body" && echo 1)"
check "admin: tool.js loaded" "$(grep -q 'tool.js' "$TMP/body" && echo 1)"
c=$(get "$E" "admin/video-downloader"); check "editor (with permission): page 200" "$([ "$c" = 200 ] && echo 1)" "($c)"
c=$(get "$N" "admin/video-downloader"); check "no-permission user: page 403" "$([ "$c" = 403 ] && echo 1)" "($c)"
get "$N" "admin/dashboard" >/dev/null; check "no-permission user: nav item hidden" "$(grep -q 'admin/video-downloader"' "$TMP/body" || echo 1)"

echo "== settings page"
c=$(get "$A" "admin/settings/plugins/video-downloader"); check "settings page 200" "$([ "$c" = 200 ] && echo 1)" "($c)"
check "settings: JS runtime field + status" "$(grep -q 'JS runtime (YouTube)' "$TMP/body" && grep -qE 'Using: |No JS runtime found' "$TMP/body" && echo 1)"
check "settings: cookies file field" "$(grep -q 'Cookies file' "$TMP/body" && echo 1)"

echo "== inspect"
post "$A" video-downloader/tool/inspect --data-urlencode "url=https://videos.example-cdn.test/v/1" > "$TMP/inspect.json"
labels=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_column($d["options"]??[],"label"));' "$TMP/inspect.json")
check "inspect lists resolutions" "$([ "$labels" = '4K,1080p,360p' ] && echo 1)" "($labels / $(code))"
k4=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo json_encode($d["options"][0]["allowed"]??null);' "$TMP/inspect.json")
check "4K disabled by the 1080 ceiling" "$([ "$k4" = false ] && echo 1)"
post "$N" video-downloader/tool/inspect --data-urlencode "url=https://videos.example-cdn.test/v/1" >/dev/null
check "no-permission user: inspect 403" "$([ "$(code)" = 403 ] && echo 1)" "($(code))"
post "$A" video-downloader/tool/inspect --data-urlencode "url=http://169.254.169.254/latest/meta-data" >/dev/null
check "metadata IP rejected (400)" "$([ "$(code)" = 400 ] && echo 1)" "($(code))"
post "$A" video-downloader/tool/create --data-urlencode "url=https://videos.example-cdn.test/v/1" --data-urlencode "preset=evil" >/dev/null
check "unknown preset rejected (400)" "$([ "$(code)" = 400 ] && echo 1)" "($(code))"

echo "== download"
JOB=$(post "$A" video-downloader/tool/create --data-urlencode "url=https://videos.example-cdn.test/v/1" \
  --data-urlencode "preset=compatible" --data-urlencode "resolution=2160" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["jobId"]??"";')
check "create queued a job (202)" "$([ -n "$JOB" ] && [ "$(code)" = 202 ] && echo 1)" "($(code))"
c=$(get "$A" "index.php?p=admin/actions/video-downloader/tool/file&jobId=$JOB"); check "file before done: 404" "$([ "$c" = 404 ] && echo 1)" "($c)"
(cd "$H" && bin/craft queue/run >/dev/null 2>&1)
st=$(post "$A" video-downloader/download/status --data-urlencode "jobId=$JOB" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo ($d["status"]??"")."|".($d["result"]["filename"]??"")."|".($d["error"]??"");')
check "job done after queue run" "$([[ "$st" == done\|* ]] && echo 1)" "($st)"
c=$(get "$A" "index.php?p=admin/actions/video-downloader/tool/file&jobId=$JOB")
check "owner fetches file (200)" "$([ "$c" = 200 ] && echo 1)" "($c)"
check "served as attachment" "$(grep -qi '^content-disposition: attachment' "$TMP/headers" && echo 1)"
check "file bytes intact" "$([ "$(wc -c < "$TMP/body" | tr -d ' ')" = 1000 ] && echo 1)"
c=$(get "$E" "index.php?p=admin/actions/video-downloader/tool/file&jobId=$JOB"); check "other user can't fetch it (404)" "$([ "$c" = 404 ] && echo 1)" "($c)"
post "$E" video-downloader/download/status --data-urlencode "jobId=$JOB" >/dev/null; check "other user can't poll it (404)" "$([ "$(code)" = 404 ] && echo 1)" "($(code))"
argv=$(cd "$H" && docker compose exec -T php sh -c 'ls /var/www/html/storage/video-downloader/files/'"$JOB"'/ 2>/dev/null')
check "file stored under storage/video-downloader/files" "$([ -n "$argv" ] && echo 1)"

echo "== tool disabled"
fx tool off >/dev/null
c=$(get "$A" "admin/video-downloader"); check "disabled: page 404" "$([ "$c" = 404 ] && echo 1)" "($c)"
get "$A" "admin/dashboard" >/dev/null; check "disabled: nav item hidden" "$(grep -q 'admin/video-downloader"' "$TMP/body" || echo 1)"
fx tool on >/dev/null

echo "== teardown"
fx teardown
rm -rf "$TMP"
echo; echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
