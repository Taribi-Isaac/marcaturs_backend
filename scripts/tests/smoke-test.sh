#!/usr/bin/env bash
# Stubbed test harness for scripts/smoke.sh and its production workflow wiring (MH-OPS-014).
#
# smoke.sh runs inside a fresh temporary directory with a clean environment and a curl stub on
# PATH that serves canned API, SPA, CORS and WebSocket responses. Nothing touches the network or
# a real host, and no real credentials are used. Compatible with bash 3.2 (macOS) and 5.x (CI).
#
#   bash scripts/tests/smoke-test.sh
#
# Single-quoted `bash -c` bodies and expected strings are intentionally literal.
# shellcheck disable=SC2016
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SMOKE="$REPO/scripts/smoke.sh"
WORKFLOW="$REPO/.github/workflows/production.yml"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/mh-smoke-test.XXXXXX")"
WORK="$(cd "$WORK" && pwd -P)"
trap 'rm -rf "$WORK"' EXIT

STUB_BIN="$WORK/bin"
STUB_LOG="$WORK/curl.log"

KEY="harness-public-key"
SECRET="harness-reverb-secret-never-printed"
WS_PATH="/app/$KEY?protocol=7&client=smoke&version=1"

# Production-shaped configuration (the workflow's smoke step) against .test hosts.
BASE=(
  "API_URL=https://api.example.test"
  "APP_URL=https://app.example.test"
  "ADMIN_URL=https://admin.example.test"
  "WS_URL=wss://ws.example.test"
  "REVERB_APP_KEY=$KEY"
  "EXPECT_HSTS=1"
  "EXPECT_WS=1"
)

PASS=0
FAIL=0
NAME=""
OUT=""
RC=0

# ---------------------------------------------------------------- curl stub

mkdir -p "$STUB_BIN"

# STUB_FAIL holds space-separated failure modes; STUB_DROP_HEADER omits one health header.
cat >"$STUB_BIN/curl" <<'EOF'
#!/usr/bin/env bash
echo "curl $*" >>"$STUB_LOG"
dump="" out="" fmt="" method=GET origin="" upgrade="" url=""
while [ $# -gt 0 ]; do
  case "$1" in
    -D) dump="$2"; shift 2 ;;
    -o) out="$2"; shift 2 ;;
    -w) fmt="$2"; shift 2 ;;
    -X) method="$2"; shift 2 ;;
    --max-time) shift 2 ;;
    -H)
      case "$2" in
        "Origin: "*) origin="${2#Origin: }" ;;
        "Upgrade: websocket") upgrade=1 ;;
      esac
      shift 2 ;;
    -sS | -fsS | --http1.1) shift ;;
    -*) echo "curl stub: unexpected option $1" >&2; exit 99 ;;
    *) url="$1"; shift ;;
  esac
done

fails() { case " ${STUB_FAIL:-} " in *" $1 "*) return 0 ;; esac; return 1; }
headers() {
  if [ "$dump" = - ]; then printf '%s\r\n' "$@"; elif [ -n "$dump" ]; then printf '%s\r\n' "$@" >"$dump"; fi
}
body() { if [ -z "$out" ]; then printf '%s' "$1"; elif [ "$out" != /dev/null ]; then printf '%s' "$1" >"$out"; fi; }
code() { if [ -n "$fmt" ]; then printf '%b' "$(printf '%s' "$fmt" | sed "s/%{http_code}/$1/")"; fi; }
http_error() { echo "curl: (22) The requested URL returned error: $1" >&2; exit 22; }

host="${url#*://}"
host="${host%%/*}"
path="/${url#*://*/}"

if [ -n "$upgrade" ]; then
  [ "$host" = ws.example.test ] || { echo "curl stub: WebSocket request to unexpected host $host" >&2; exit 99; }
  if fails ws-refused; then echo "curl: (7) Failed to connect to $host port 443" >&2; exit 7; fi
  if fails ws-502; then headers "HTTP/1.1 502 Bad Gateway" "Server: nginx"; exit 0; fi
  headers "HTTP/1.1 101 Switching Protocols" "Upgrade: websocket" "Connection: Upgrade"
  exit 0
fi

case "$host$path" in
  api.example.test/api/v1/health)
    if [ "$method" = OPTIONS ]; then
      lines=("HTTP/1.1 204 No Content" "Vary: Origin")
      allow=""
      if [ "$origin" = https://app.example.test ]; then fails cors-deny-app || allow=1; else fails cors-allow-unknown && allow=1; fi
      if [ -n "$allow" ]; then
        lines+=("Access-Control-Allow-Origin: $origin")
        fails cors-no-credentials || lines+=("Access-Control-Allow-Credentials: true")
      fi
      headers "${lines[@]}"
      exit 0
    fi
    status=200
    json='{"success":true,"data":{"status":"ok","database":"ok","cache":"ok"}}'
    if fails health-503; then status=503; json='{"success":false,"data":{"status":"unavailable"}}'; fi
    if fails health-not-success; then json='{"success":false,"data":{"status":"ok"}}'; fi
    if fails health-leak; then json='{"success":true,"data":{"password":"redacted"}}'; fi
    lines=("HTTP/1.1 $status")
    for h in "X-Content-Type-Options: nosniff" "X-Frame-Options: DENY" \
      "Referrer-Policy: strict-origin-when-cross-origin" "Permissions-Policy: camera=()" \
      "Strict-Transport-Security: max-age=31536000"; do
      [ "${h%%:*}" = "${STUB_DROP_HEADER:-}" ] || lines+=("$h")
    done
    if fails powered-by; then lines+=("X-Powered-By: PHP/8.4"); fi
    headers "${lines[@]}"
    body "$json"
    code "$status"
    ;;
  api.example.test/up)
    if fails up-500; then http_error 500; fi
    code 200
    ;;
  app.example.test/)
    if fails app-522; then http_error 522; fi
    if fails app-no-root; then body '<!doctype html><title>Pages</title>'; else body '<!doctype html><div id="root"></div>'; fi
    ;;
  admin.example.test/)
    if fails admin-522; then http_error 522; fi
    if fails admin-no-noindex; then body '<!doctype html><div id="root"></div>'; else body '<!doctype html><meta name="robots" content="noindex, nofollow"><div id="root"></div>'; fi
    ;;
  *)
    echo "curl stub: unexpected request $method $url" >&2
    exit 99
    ;;
esac
EOF

chmod +x "$STUB_BIN/curl"

# ---------------------------------------------------------------- helpers

# run_smoke [-u NAME]... [NAME=value]...
# Starts from BASE, drops each -u NAME, then applies the overrides, in a clean environment.
run_smoke() {
  local drop=" " kv
  local vars=()
  while [[ $# -gt 0 && "$1" == -u ]]; do
    drop="$drop$2 "
    shift 2
  done
  for kv in "${BASE[@]}"; do
    case "$drop" in *" ${kv%%=*} "*) ;; *) vars+=("$kv") ;; esac
  done
  : >"$STUB_LOG"
  OUT="$(env -i PATH="$STUB_BIN:$PATH" HOME="$WORK" TMPDIR="$WORK" STUB_LOG="$STUB_LOG" \
    REVERB_APP_SECRET="$SECRET" ${vars[@]+"${vars[@]}"} "$@" bash "$SMOKE" 2>&1)"
  RC=$?
}

case_() {
  NAME="$1"
  echo "# $NAME"
}

ok() {
  PASS=$((PASS + 1))
  printf '  ok    %s\n' "$1"
}

bad() {
  FAIL=$((FAIL + 1))
  printf '  FAIL  %s: %s\n' "$NAME" "$1"
  printf '%s\n' "$OUT" | tail -n 12 | sed 's/^/        | /'
}

check() {
  local label="$1"
  shift
  if "$@"; then ok "$label"; else bad "$label"; fi
}

curl_calls() { wc -l <"$STUB_LOG" | tr -d ' '; }

assert_rc() { check "exit code $1" test "$RC" = "$1"; }
assert_failed() { check "exit code is non-zero" test "$RC" -ne 0; }
assert_out() { check "output mentions '$1'" grep -qF -- "$1" <<<"$OUT"; }
assert_not_out() { check "output omits '$1'" bash -c '! grep -qF -- "$1" <<<"$2"' _ "$1" "$OUT"; }
assert_log() { check "curl called with '$1'" grep -qF -- "$1" "$STUB_LOG"; }
assert_no_log() { check "curl never called with '$1'" bash -c '! grep -qF -- "$1" "$2"' _ "$1" "$STUB_LOG"; }
assert_calls() { check "$1 curl call(s)" test "$(curl_calls)" = "$1"; }

# Every run: no credential reaches the output.
assert_no_secret() { assert_not_out "$SECRET"; }

# A run that must not be reported as a successful smoke test.
assert_smoke_failed() {
  assert_failed
  assert_not_out "Smoke OK."
  assert_no_secret
}

# A configuration error stops before any check, so nothing could have passed.
assert_config_error() {
  assert_rc 2
  assert_out "smoke configuration error:"
  assert_not_out "Smoke OK."
  assert_calls 0
  assert_no_secret
}

# ---------------------------------------------------------------- WebSocket configuration contract

case_ "ws: URL and public key run every check including the existing WebSocket upgrade"
run_smoke
assert_rc 0
assert_out "Smoke OK."
assert_out "==> Reverb WebSocket upgrade wss://ws.example.test"
assert_out "HTTP/1.1 101 Switching Protocols"
assert_log "https://ws.example.test$WS_PATH"
assert_log "--http1.1 --max-time 5"
assert_log "-H Upgrade: websocket"
assert_log "-H Sec-WebSocket-Version: 13"
assert_log "-H Origin: https://app.example.test"
assert_calls 7
assert_not_out "$KEY"
assert_no_secret

case_ "ws: URL set, key unset fails before any check"
run_smoke -u REVERB_APP_KEY
assert_config_error
assert_out "WS_URL is set but REVERB_APP_KEY is empty or unset"
assert_out "Set REVERB_APP_KEY to the public Reverb app key"

case_ "ws: URL set, key empty fails before any check"
run_smoke REVERB_APP_KEY=
assert_config_error
assert_out "WS_URL is set but REVERB_APP_KEY is empty or unset"

case_ "ws: URL set, key whitespace-only fails before any check"
run_smoke "REVERB_APP_KEY=   "
assert_config_error
assert_out "WS_URL is set but REVERB_APP_KEY is empty or unset"

case_ "ws: URL set, key unset fails even without EXPECT_WS (manual / staging runs)"
run_smoke -u REVERB_APP_KEY -u EXPECT_WS -u EXPECT_HSTS
assert_config_error
assert_out "WS_URL is set but REVERB_APP_KEY is empty or unset"

case_ "ws: EXPECT_WS=1 with WS_URL unset fails before any check"
run_smoke -u WS_URL
assert_config_error
assert_out "EXPECT_WS=1 but WS_URL is empty or unset"

case_ "ws: EXPECT_WS=1 with WS_URL empty and key unset fails before any check"
run_smoke -u REVERB_APP_KEY WS_URL=
assert_config_error
assert_out "EXPECT_WS=1 but WS_URL is empty or unset"

case_ "ws: URL and key both unset keep the existing skip when not required"
run_smoke -u WS_URL -u REVERB_APP_KEY -u EXPECT_WS
assert_rc 0
assert_out "Smoke OK."
assert_not_out "Reverb WebSocket upgrade"
assert_no_log "Upgrade: websocket"
assert_calls 6

case_ "ws: key without URL keeps the existing skip when not required"
run_smoke -u WS_URL -u EXPECT_WS
assert_rc 0
assert_out "Smoke OK."
assert_no_log "Upgrade: websocket"
assert_calls 6

case_ "ws: trailing slash and ws:// scheme map to the same upgrade URL"
run_smoke WS_URL=ws://ws.example.test/
assert_rc 0
assert_log "http://ws.example.test$WS_PATH"
assert_no_log "ws.example.test//app"

case_ "ws: upgrade not answered with 101 fails"
run_smoke STUB_FAIL=ws-502
assert_rc 1
assert_smoke_failed
assert_out "HTTP/1.1 502 Bad Gateway"
assert_out "WebSocket upgrade failed"

case_ "ws: unreachable Reverb fails"
run_smoke STUB_FAIL=ws-refused
assert_rc 1
assert_smoke_failed
assert_out "WebSocket upgrade failed"

# ---------------------------------------------------------------- existing checks stay intact

case_ "config: API_URL is still required"
run_smoke -u API_URL
assert_rc 2
assert_out "API_URL is required"
assert_calls 0

case_ "health: non-200 fails"
run_smoke STUB_FAIL=health-503
assert_rc 1
assert_smoke_failed
assert_out "health HTTP not 200"

case_ "health: success!=true fails"
run_smoke STUB_FAIL=health-not-success
assert_rc 1
assert_smoke_failed
assert_out "health success!=true"

case_ "health: secret-looking response fails"
run_smoke STUB_FAIL=health-leak
assert_rc 1
assert_smoke_failed
assert_out "health response appears to leak secrets"

for header in X-Content-Type-Options X-Frame-Options Referrer-Policy Permissions-Policy Strict-Transport-Security; do
  case_ "headers: missing $header fails"
  run_smoke STUB_DROP_HEADER="$header"
  assert_rc 1
  assert_smoke_failed
  assert_out "missing header: $header"
done

case_ "headers: HSTS is only required with EXPECT_HSTS=1"
run_smoke -u EXPECT_HSTS STUB_DROP_HEADER=Strict-Transport-Security
assert_rc 0
assert_out "Smoke OK."

case_ "headers: X-Powered-By fails"
run_smoke STUB_FAIL=powered-by
assert_rc 1
assert_smoke_failed
assert_out "X-Powered-By header present"

case_ "up: /up error fails"
run_smoke STUB_FAIL=up-500
assert_smoke_failed
assert_log "https://api.example.test/up"

case_ "frontend: unreachable participant SPA fails"
run_smoke STUB_FAIL=app-522
assert_smoke_failed

case_ "frontend: participant SPA without root fails"
run_smoke STUB_FAIL=app-no-root
assert_rc 1
assert_smoke_failed
assert_out "participant SPA root missing"

case_ "frontend: unreachable admin SPA fails"
run_smoke STUB_FAIL=admin-522
assert_smoke_failed

case_ "frontend: admin SPA without noindex fails"
run_smoke STUB_FAIL=admin-no-noindex
assert_rc 1
assert_smoke_failed
assert_out "admin missing noindex"

case_ "cors: participant origin denied fails"
run_smoke STUB_FAIL=cors-deny-app
assert_rc 1
assert_smoke_failed
assert_out "CORS does not allow https://app.example.test"

case_ "cors: credentials not allowed fails"
run_smoke STUB_FAIL=cors-no-credentials
assert_rc 1
assert_smoke_failed
assert_out "CORS credentials not allowed"

case_ "cors: unknown origin allowed fails"
run_smoke STUB_FAIL=cors-allow-unknown
assert_rc 1
assert_smoke_failed
assert_out "CORS allowed an unknown origin"

# ---------------------------------------------------------------- production workflow wiring

# Prints a workflow step from its `id:` line up to the next step.
step_block() { awk -v id="        id: $1" '$0 == id {f = 1; next} f && /^      - name:/ {exit} f' "$WORKFLOW"; }

for step in smoke post_rollback_smoke; do
  case_ "workflow: $step requires the WebSocket check"
  block="$(step_block "$step")"
  check "$step exists" test -n "$block"
  check "$step sets EXPECT_WS=1" grep -qF 'EXPECT_WS: "1"' <<<"$block"
  check "$step sets EXPECT_HSTS=1" grep -qF 'EXPECT_HSTS: "1"' <<<"$block"
  check "$step passes WS_URL from PRODUCTION_WS_URL" grep -qF 'WS_URL: ${{ vars.PRODUCTION_WS_URL }}' <<<"$block"
  check "$step passes the public REVERB_APP_KEY variable" grep -qF 'REVERB_APP_KEY: ${{ vars.REVERB_APP_KEY }}' <<<"$block"
  check "$step runs smoke.sh" grep -qF 'bash scripts/smoke.sh' <<<"$block"
done

case_ "hygiene: smoke never handles the Reverb secret or traces commands"
check "smoke.sh does not reference REVERB_APP_SECRET" bash -c '! grep -q "REVERB_APP_SECRET" "$1"' _ "$SMOKE"
check "smoke.sh has no shell tracing" bash -c '! grep -qE "set -[a-z]*x" "$1"' _ "$SMOKE"
check "workflow never reads a Reverb secret" bash -c '! grep -q "secrets\.REVERB" "$1"' _ "$WORKFLOW"

# ---------------------------------------------------------------- result

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
