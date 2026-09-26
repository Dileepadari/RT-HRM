#!/usr/bin/env bash
#
# Exercises the read-access gate over real HTTP against a real database.
#
# Nine read endpoints used to answer anyone who knew the URL, with
# Access-Control-Allow-Origin: *, including the live SSE streams. They serve
# continuous heart rate, arrhythmia episodes and history for one identifiable
# person. This script is what stops that coming back: it asserts each one
# refuses an anonymous caller, accepts the API key in the header, refuses a
# wrong key, and refuses a key passed in the query string.
#
# Needs: php, curl, and a database already loaded with schema.sql. Point it at
# one with the HR_DB_* variables, the same ones the server reads.
#
# Usage: server/tests/http-gate-test.sh

set -uo pipefail

SERVER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${TEST_PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
# Whatever config.php resolves - from .env if there is one, from the
# environment if there is not - so the test and the server never disagree.
KEY="$(HR_CONFIG="${SERVER_DIR}/config.php" php -r 'require getenv("HR_CONFIG"); echo API_KEY;')"
COOKIES="$(mktemp)"
FAILURES=0

cleanup() {
    [ -n "${PHP_PID:-}" ] && kill "$PHP_PID" 2>/dev/null
    rm -f "$COOKIES"
}
trap cleanup EXIT

php -S "127.0.0.1:${PORT}" -t "$SERVER_DIR" > /dev/null 2>&1 &
PHP_PID=$!

for _ in $(seq 1 30); do
    curl -s -o /dev/null "${BASE}/live.php" && break
    sleep 1
done

# status <expected> <label> <curl args...>
status() {
    local expected="$1" label="$2"; shift 2
    local got
    got="$(curl -s -o /dev/null -w '%{http_code}' "$@")"
    if [ "$got" = "$expected" ]; then
        printf 'ok   %-52s %s\n' "$label" "$got"
    else
        printf 'FAIL %-52s expected %s, got %s\n' "$label" "$expected" "$got"
        FAILURES=$((FAILURES + 1))
    fi
}

READ_ENDPOINTS="api/latest.php api/latest-all.php api/episodes.php api/arrhythmia.php
                api/today-count.php api/minute-summary.php api/chart-data.php
                api/sse.php api/sse-all.php"

echo "--- anonymous callers are refused ---"
status 401 "live.php" "${BASE}/live.php"
for ep in $READ_ENDPOINTS; do
    status 401 "$ep" "${BASE}/${ep}"
done

echo "--- the API key is accepted, in the header only ---"
for ep in api/latest.php api/episodes.php api/arrhythmia.php api/today-count.php; do
    status 200 "$ep with X-API-Key" -H "X-API-Key: ${KEY}" "${BASE}/${ep}"
done
status 401 "api/latest.php with a wrong key" -H "X-API-Key: wrong" "${BASE}/api/latest.php"
# The key used to be read from $_GET too, which puts it in the access log, in
# any Referer sent onward, and in the browser history of whoever opens the URL.
status 401 "api/latest.php with the key in the query string" "${BASE}/api/latest.php?api_key=${KEY}"

echo "--- signing in to the dashboard grants the session ---"
status 302 "POST live.php with the correct key" -c "$COOKIES" -d "hr_key=${KEY}" "${BASE}/live.php"
status 200 "live.php with the session cookie" -b "$COOKIES" "${BASE}/live.php"
status 200 "api/latest.php with the session cookie" -b "$COOKIES" "${BASE}/api/latest.php"
status 401 "POST live.php with a wrong key" -d "hr_key=wrong" "${BASE}/live.php"

echo "--- HR_PUBLIC_READ=1 restores anonymous reads ---"
kill "$PHP_PID" 2>/dev/null; wait "$PHP_PID" 2>/dev/null
HR_PUBLIC_READ=1 php -S "127.0.0.1:${PORT}" -t "$SERVER_DIR" > /dev/null 2>&1 &
PHP_PID=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "${BASE}/api/latest.php" && break; sleep 1; done
status 200 "api/latest.php anonymous with HR_PUBLIC_READ=1" "${BASE}/api/latest.php"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "all gate assertions passed"
else
    echo "${FAILURES} assertion(s) failed"
fi
exit "$FAILURES"
