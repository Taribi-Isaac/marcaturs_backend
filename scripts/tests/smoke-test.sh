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

# RFC 6455 §1.3 / §4.2.2. The stub's GUID is checked against the published known answer below,
# so the harness cannot silently share a wrong constant with smoke.sh.
RFC_GUID="258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
RFC_KEY="dGhlIHNhbXBsZSBub25jZQ=="
RFC_ACCEPT="s3pPLMBiTxaQ9kYGzzhZRbK+xOo="
# The value shipped in afcb34d, split so this file never contains it verbatim.
RETIRED_GUID="258EAFA5-E914-47DA-95CA-C5AB0DC""11B65"
REAL_OPENSSL="$(command -v openssl)"
SMOKE_RUN="$SMOKE"

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
dump="" out="" fmt="" method=GET origin="" upgrade="" wskey="" url=""
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
        "Sec-WebSocket-Key: "*) wskey="${2#Sec-WebSocket-Key: }" ;;
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

byte() { printf "\\$(printf '%03o' "$1")"; }
# frame <byte0> <payload> [mask-bit]: one server frame with a 7-bit or 16-bit payload length.
frame() {
  local n=${#2} m="${3:-0}"
  byte "$1"
  if [ "$n" -lt 126 ]; then byte $((n + m)); else byte $((126 + m)); byte $((n / 256)); byte $((n % 256)); fi
  if [ "$m" != 0 ]; then printf '\0\0\0\0'; fi
  printf '%s' "$2"
}

# Behaves like Reverb behind Nginx. After a 101 the socket stays open, so real curl reads the
# first frame as the body and then exits 28 at --max-time.
if [ -n "$upgrade" ]; then
  [ "$host" = ws.example.test ] || { echo "curl stub: WebSocket request to unexpected host $host" >&2; exit 99; }
  if fails ws-refused; then echo "curl: (7) Failed to connect to $host port 443" >&2; exit 7; fi
  if fails ws-timeout; then echo "curl: (28) Operation timed out after 5001 milliseconds with 0 bytes received" >&2; exit 28; fi
  if fails ws-502; then headers "HTTP/1.1 502 Bad Gateway" "Server: nginx"; exit 0; fi
  # Reverb rejects any key that is not a base64-encoded 16-byte nonce; Nginx then holds the connection.
  if fails ws-reject-key || [ "$(printf '%s' "$wskey" | openssl base64 -d -A 2>/dev/null | wc -c | tr -d ' ')" != 16 ]; then
    headers "HTTP/1.1 400 Invalid Sec-WebSocket-Key" "X-Powered-By: Laravel Reverb"
    exit 28
  fi
  # RFC 6455 §1.3 example is answered with the published value, not a computed one.
  if [ "$wskey" = "dGhlIHNhbXBsZSBub25jZQ==" ]; then
    accept="s3pPLMBiTxaQ9kYGzzhZRbK+xOo="
  else
    guid="$STUB_WS_GUID"
    if fails ws-wrong-guid; then guid="00000000-0000-0000-0000-000000000000"; fi
    accept="$(printf '%s' "$wskey$guid" | openssl dgst -sha1 -binary | openssl base64 -A)"
  fi
  if fails ws-bad-accept; then accept="s3pPLMBiTxaQ9kYGzzhZRbK+xOo="; fi
  # What the retired GUID yields for the §1.3 key (the value seen in the failed afcb34d smoke).
  if fails ws-retired-accept; then accept="7wm9f4CXJykTkSLTRhb/0x1kNkA="; fi
  upgrade_h="Upgrade: websocket"
  connection_h="Connection: Upgrade"
  if fails ws-nginx-case; then upgrade_h="upgrade: websocket"; connection_h="Connection: upgrade"; fi
  if fails ws-bad-upgrade-header; then upgrade_h="Upgrade: h2c"; fi
  if fails ws-bad-connection-header; then connection_h="Connection: keep-alive"; fi
  lines=("HTTP/1.1 101 Switching Protocols")
  fails ws-no-upgrade-header || lines+=("$upgrade_h")
  lines+=("$connection_h")
  fails ws-no-accept || lines+=("Sec-WebSocket-Accept: $accept")
  headers "${lines[@]}"
  ok='{"event":"pusher:connection_established","data":"{\"socket_id\":\"1.2\",\"activity_timeout\":30}"}'
  payload="$ok"
  if fails ws-pusher-error; then payload='{"event":"pusher:error","data":"{\"code\":4009,\"message\":\"Origin not allowed\"}"}'; fi
  if fails ws-other-event; then payload='{"event":"pusher:pong","data":"{}"}'; fi
  if fails ws-nested-event; then payload='{"event":"pusher:pong","data":"{\"event\":\"pusher:connection_established\",\"socket_id\":\"1.2\"}"}'; fi
  if fails ws-no-socket-id; then payload='{"event":"pusher:connection_established","data":"{\"activity_timeout\":30}"}'; fi
  if fails ws-not-json; then payload='pusher:connection_established socket_id'; fi
  if fails ws-object-data; then payload='{"data":{"event":"pusher:connection_established","socket_id":"1.2"}}'; fi
  if fails ws-bad-socket-id; then payload='{"event":"pusher:connection_established","data":"{\"no_socket_id\":true}"}'; fi
  if fails ws-extended; then
    payload="{\"event\":\"pusher:connection_established\",\"data\":\"{\\\"socket_id\\\":\\\"1.2\\\",\\\"activity_timeout\\\":30}\",\"pad\":\"$(printf '%0200d' 0)\"}"
  fi
  if fails ws-no-frame; then
    :
  elif fails ws-not-a-frame; then
    printf '%s' "$payload" >"$out"
  elif fails ws-masked; then
    frame 129 "$payload" 128 >"$out"
  elif fails ws-binary; then
    frame 130 "$payload" >"$out"
  elif fails ws-fragment; then
    frame 1 "$payload" >"$out"
  elif fails ws-64bit; then
    { byte 129; byte 127; printf '\0\0\0\0\0\0\0'; byte ${#payload}; printf '%s' "$payload"; } >"$out"
  elif fails ws-truncated; then
    frame 129 "$payload" | head -c 40 >"$out"
  elif fails ws-second-frame; then
    { frame 129 '{"event":"pusher:pong","data":"{}"}'; frame 129 "$ok"; } >"$out"
  else
    frame 129 "$payload" >"$out"
  fi
  if fails ws-reset; then echo "curl: (56) Recv failure: Connection reset by peer" >&2; exit 56; fi
  echo "curl: (28) Operation timed out after 5001 milliseconds with ${#payload} bytes received" >&2
  exit 28
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
    STUB_WS_GUID="$RFC_GUID" REAL_OPENSSL="$REAL_OPENSSL" \
    REVERB_APP_SECRET="$SECRET" ${vars[@]+"${vars[@]}"} "$@" bash "$SMOKE_RUN" 2>&1)"
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
assert_log "-H Sec-WebSocket-Key: "
assert_out "Reverb: pusher:connection_established"
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

# ---------------------------------------------------------------- WebSocket handshake (RFC 6455)

# Wraps the real openssl. FAKE_RAND replaces `openssl rand` output; FAKE_FAIL makes `all`
# subcommands, only `rand`, only `dgst`, or `dgst` for anything but the §1.3 self-test fail.
FAKE_SSL="$WORK/fake-openssl"
NO_SSL="$WORK/no-openssl"
mkdir -p "$FAKE_SSL" "$NO_SSL"
cat >"$FAKE_SSL/openssl" <<'EOF'
#!/usr/bin/env bash
fake_fail() { echo "openssl stub: failure" >&2; exit 1; }
case "${FAKE_FAIL:-}:$1" in
  all:* | rand:rand | dgst:dgst) fake_fail ;;
  dgst-after-selftest:dgst)
    input="$(cat)"
    case "$input" in dGhlIHNhbXBsZSBub25jZQ==*) ;; *) fake_fail ;; esac
    printf '%s' "$input" | "$REAL_OPENSSL" "$@"
    exit $?
    ;;
esac
if [ "$1" = rand ] && [ -n "${FAKE_RAND+x}" ]; then printf '%s\n' "$FAKE_RAND"; exit 0; fi
exec "$REAL_OPENSSL" "$@"
EOF
chmod +x "$FAKE_SSL/openssl"
ln -s "$(command -v bash)" "$NO_SSL/bash"

sent_key() { grep -oE 'Sec-WebSocket-Key: [^ ]+' "$STUB_LOG" | head -1 | cut -d' ' -f2; }
decoded_len() { printf '%s' "$1" | openssl base64 -d -A | wc -c | tr -d ' '; }

case_ "handshake: key is a fresh base64 nonce of exactly 16 bytes"
run_smoke
assert_rc 0
key1="$(sent_key)"
check "key is canonical base64 of 16 bytes" bash -c '[[ "$1" =~ ^[A-Za-z0-9+/]{21}[AQgw]==$ ]]' _ "$key1"
check "key decodes to 16 bytes" test "$(decoded_len "$key1")" = 16
check "key is not the old 18-byte constant" test "$key1" != "bWFyY2F0dXJzaHViLXNtb2tl"
run_smoke
check "each run uses a new key" test "$(sent_key)" != "$key1"

ws_accept_with() { printf '%s' "$1$2" | openssl dgst -sha1 -binary | openssl base64 -A; }

case_ "kat: harness GUID reproduces the RFC 6455 §1.3 known answer"
check "base64(SHA-1(\"$RFC_KEY\" + RFC GUID)) = $RFC_ACCEPT" test "$(ws_accept_with "$RFC_KEY" "$RFC_GUID")" = "$RFC_ACCEPT"
check "the retired GUID does not reproduce it" test "$(ws_accept_with "$RFC_KEY" "$RETIRED_GUID")" != "$RFC_ACCEPT"
check "the retired GUID yields the value seen in the failed afcb34d smoke" test "$(ws_accept_with "$RFC_KEY" "$RETIRED_GUID")" = "7wm9f4CXJykTkSLTRhb/0x1kNkA="
check "smoke.sh pins the RFC 6455 §1.3 pair" bash -c 'grep -qF "ws_accept $1" "$3" && grep -qF "\"$2\"" "$3"' _ "$RFC_KEY" "$RFC_ACCEPT" "$SMOKE"

case_ "kat: the RFC 6455 §1.3 key is accepted end to end against the published answer"
run_smoke PATH="$FAKE_SSL:$STUB_BIN:$PATH" FAKE_RAND="$RFC_KEY"
assert_rc 0
assert_out "Reverb: pusher:connection_established"
assert_out "Smoke OK."
check "the §1.3 key was sent" test "$(sent_key)" = "$RFC_KEY"

case_ "kat: the RFC 6455 §1.3 key with the retired-GUID answer fails"
run_smoke PATH="$FAKE_SSL:$STUB_BIN:$PATH" FAKE_RAND="$RFC_KEY" STUB_FAIL=ws-retired-accept
assert_rc 1
assert_smoke_failed
assert_out "Sec-WebSocket-Accept is missing or does not match the handshake key"

# Mutation tests: copies of smoke.sh with the retired GUID must fail, with and without the self-test.
MUT_GUID="$WORK/smoke-retired-guid.sh"
MUT_NOKAT="$WORK/smoke-retired-guid-no-selftest.sh"
MUT_OK_NOKAT="$WORK/smoke-no-selftest.sh"
sed "s/$RFC_GUID/$RETIRED_GUID/" "$SMOKE" >"$MUT_GUID"
sed -E 's/^  if \[\[ "\$\(ws_accept dGhlIHNhbXBsZSBub25jZQ==.*; then$/  if false; then/' "$MUT_GUID" >"$MUT_NOKAT"
sed -E 's/^  if \[\[ "\$\(ws_accept dGhlIHNhbXBsZSBub25jZQ==.*; then$/  if false; then/' "$SMOKE" >"$MUT_OK_NOKAT"

case_ "mutation: retired GUID is caught by the runtime self-test before any request"
check "mutant carries the retired GUID" grep -qF "$RETIRED_GUID" "$MUT_GUID"
SMOKE_RUN="$MUT_GUID" run_smoke
assert_config_error
assert_out "Sec-WebSocket-Accept self-test failed (RFC 6455 §1.3 example)"

case_ "mutation: retired GUID without the self-test still fails the accept check"
check "mutant has the self-test disabled" grep -qxF "  if false; then" "$MUT_NOKAT"
SMOKE_RUN="$MUT_NOKAT" run_smoke
assert_rc 1
assert_smoke_failed
assert_out "Sec-WebSocket-Accept is missing or does not match the handshake key"
SMOKE_RUN="$MUT_NOKAT" run_smoke PATH="$FAKE_SSL:$STUB_BIN:$PATH" FAKE_RAND="$RFC_KEY"
assert_rc 1
assert_out "Sec-WebSocket-Accept is missing or does not match the handshake key"

case_ "mutation: control (correct GUID, self-test disabled) still passes"
SMOKE_RUN="$MUT_OK_NOKAT" run_smoke
assert_rc 0
assert_out "Smoke OK."

case_ "handshake: server rejecting the key (400, connection held open) fails"
run_smoke STUB_FAIL=ws-reject-key
assert_rc 1
assert_smoke_failed
assert_out "HTTP/1.1 400 Invalid Sec-WebSocket-Key"
assert_out "WebSocket upgrade failed: expected HTTP/1.1 101 Switching Protocols (curl exit 28)"

case_ "handshake: timeout with no response fails"
run_smoke STUB_FAIL=ws-timeout
assert_rc 1
assert_smoke_failed
assert_out "no HTTP response"
assert_out "(curl exit 28)"

case_ "handshake: wrong Sec-WebSocket-Accept fails"
run_smoke STUB_FAIL=ws-bad-accept
assert_rc 1
assert_smoke_failed
assert_out "Sec-WebSocket-Accept is missing or does not match the handshake key"

case_ "handshake: missing Sec-WebSocket-Accept fails"
run_smoke STUB_FAIL=ws-no-accept
assert_rc 1
assert_smoke_failed
assert_out "Sec-WebSocket-Accept is missing or does not match the handshake key"

case_ "handshake: accept computed with a different GUID fails"
run_smoke STUB_FAIL=ws-wrong-guid
assert_rc 1
assert_smoke_failed
assert_out "Sec-WebSocket-Accept is missing or does not match the handshake key"

case_ "handshake: 101 without 'Upgrade: websocket' fails"
run_smoke STUB_FAIL=ws-no-upgrade-header
assert_rc 1
assert_smoke_failed
assert_out "response has no 'Upgrade: websocket' header"

case_ "handshake: 101 with a non-websocket Upgrade fails"
run_smoke STUB_FAIL=ws-bad-upgrade-header
assert_rc 1
assert_smoke_failed
assert_out "response has no 'Upgrade: websocket' header"

case_ "handshake: 101 without 'Connection: Upgrade' fails"
run_smoke STUB_FAIL=ws-bad-connection-header
assert_rc 1
assert_smoke_failed
assert_out "response has no 'Connection: Upgrade' header"

case_ "handshake: a bare HTTP 101 is not enough"
run_smoke STUB_FAIL="ws-no-upgrade-header ws-no-accept ws-no-frame"
assert_rc 1
assert_smoke_failed
assert_out "HTTP/1.1 101 Switching Protocols"
assert_out "WebSocket upgrade failed"
assert_not_out "Reverb: pusher:connection_established"
assert_not_out "Smoke OK."

case_ "handshake: lowercase header names and values (Nginx style) pass"
run_smoke STUB_FAIL=ws-nginx-case
assert_rc 0
assert_out "Smoke OK."

case_ "handshake: connection error after the 101 fails"
run_smoke STUB_FAIL=ws-reset
assert_rc 1
assert_smoke_failed
assert_out "connection error after the handshake (curl exit 56)"

case_ "frame: no frame within the timeout fails"
run_smoke STUB_FAIL=ws-no-frame
assert_rc 1
assert_smoke_failed
assert_out "no WebSocket frame received within 5s"

case_ "frame: unframed body fails"
run_smoke STUB_FAIL=ws-not-a-frame
assert_rc 1
assert_smoke_failed
assert_out "first frame is not a single unfragmented text frame (byte 0 = 123)"

case_ "frame: masked server frame fails"
run_smoke STUB_FAIL=ws-masked
assert_rc 1
assert_smoke_failed
assert_out "first frame is masked (servers must not mask, RFC 6455 §5.1)"

case_ "frame: binary first frame fails"
run_smoke STUB_FAIL=ws-binary
assert_rc 1
assert_smoke_failed
assert_out "first frame is not a single unfragmented text frame (byte 0 = 130)"

case_ "frame: fragmented first frame fails"
run_smoke STUB_FAIL=ws-fragment
assert_rc 1
assert_smoke_failed
assert_out "first frame is not a single unfragmented text frame (byte 0 = 1)"

case_ "frame: 64-bit length first frame fails"
run_smoke STUB_FAIL=ws-64bit
assert_rc 1
assert_smoke_failed
assert_out "first frame uses a 64-bit length"

case_ "frame: truncated first frame fails"
run_smoke STUB_FAIL=ws-truncated
assert_rc 1
assert_smoke_failed
assert_out "first frame is truncated"

case_ "frame: 16-bit length first frame passes"
run_smoke STUB_FAIL=ws-extended
assert_rc 0
assert_out "Reverb: pusher:connection_established"

case_ "frame: non-JSON payload fails"
run_smoke STUB_FAIL=ws-not-json
assert_rc 1
assert_smoke_failed
assert_out "first frame payload is not a JSON object"

case_ "frame: connection_established without socket_id fails"
run_smoke STUB_FAIL=ws-no-socket-id
assert_rc 1
assert_smoke_failed
assert_out "pusher:connection_established carries no socket_id"

case_ "frame: socket_id that is not a Pusher socket id fails"
run_smoke STUB_FAIL=ws-bad-socket-id
assert_rc 1
assert_smoke_failed
assert_out "pusher:connection_established carries no socket_id"

case_ "frame: event only inside an object-valued data field fails"
run_smoke STUB_FAIL=ws-object-data
assert_rc 1
assert_smoke_failed
assert_out "pusher:connection_established data is not a JSON-encoded string"

case_ "frame: connection_established only in a second frame fails"
run_smoke STUB_FAIL=ws-second-frame
assert_rc 1
assert_smoke_failed
assert_out "first frame event is 'pusher:pong', expected pusher:connection_established"

case_ "frame: connection_established nested inside data fails"
run_smoke STUB_FAIL=ws-nested-event
assert_rc 1
assert_smoke_failed
assert_out "first frame event is 'pusher:pong', expected pusher:connection_established"

case_ "frame: pusher:error (e.g. origin not allowed) fails with its code"
run_smoke STUB_FAIL=ws-pusher-error
assert_rc 1
assert_smoke_failed
assert_out "Reverb sent pusher:error 4009"

case_ "frame: an event other than pusher:connection_established fails"
run_smoke STUB_FAIL=ws-other-event
assert_rc 1
assert_smoke_failed
assert_out "first frame event is 'pusher:pong', expected pusher:connection_established"

# ---------------------------------------------------------------- handshake key generation

FAKE_PATH="$FAKE_SSL:$STUB_BIN:$PATH"
KEYGEN_ERR="could not generate a valid WebSocket handshake key (base64 of 16 random bytes)"

case_ "keygen: openssl missing fails before any request"
run_smoke PATH="$STUB_BIN:$NO_SSL"
assert_config_error
assert_out "openssl is required to generate the WebSocket handshake key"

case_ "keygen: openssl failing everything stops at the self-test"
run_smoke PATH="$FAKE_PATH" FAKE_FAIL=all
assert_config_error
assert_out "Sec-WebSocket-Accept self-test failed (RFC 6455 §1.3 example); check openssl."
assert_not_out "openssl stub: failure"

case_ "keygen: openssl dgst failure stops at the self-test"
run_smoke PATH="$FAKE_PATH" FAKE_FAIL=dgst
assert_config_error
assert_out "Sec-WebSocket-Accept self-test failed"

case_ "keygen: openssl rand failure fails before any request"
run_smoke PATH="$FAKE_PATH" FAKE_FAIL=rand
assert_config_error
assert_out "$KEYGEN_ERR"
assert_not_out "openssl stub: failure"

case_ "keygen: digest failure after the self-test fails before any request"
run_smoke PATH="$FAKE_PATH" FAKE_FAIL=dgst-after-selftest
assert_config_error
assert_out "could not compute the expected Sec-WebSocket-Accept; check openssl."

case_ "keygen: the old 18-byte key is refused before any request"
run_smoke PATH="$FAKE_PATH" FAKE_RAND=bWFyY2F0dXJzaHViLXNtb2tl
assert_config_error
assert_out "$KEYGEN_ERR"
assert_not_out "bWFyY2F0dXJzaHViLXNtb2tl"

case_ "keygen: non-canonical 24-character key is refused before any request"
run_smoke PATH="$FAKE_PATH" FAKE_RAND=AAAAAAAAAAAAAAAAAAAAAB==
assert_config_error
assert_out "$KEYGEN_ERR"

case_ "keygen: 15-byte key is refused before any request"
run_smoke PATH="$FAKE_PATH" FAKE_RAND=AAAAAAAAAAAAAAAAAAAA
assert_config_error
assert_out "$KEYGEN_ERR"

case_ "keygen: multi-line output is refused before any request"
run_smoke PATH="$FAKE_PATH" FAKE_RAND="$RFC_KEY
$RFC_KEY"
assert_config_error
assert_out "$KEYGEN_ERR"

case_ "keygen: empty output is refused before any request"
run_smoke PATH="$FAKE_PATH" FAKE_RAND=
assert_config_error
assert_out "$KEYGEN_ERR"

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
check "smoke.sh does not hard-code a Sec-WebSocket-Key" bash -c '! grep -qE "Sec-WebSocket-Key: [A-Za-z0-9+/=]" "$1"' _ "$SMOKE"
check "workflow never reads a Reverb secret" bash -c '! grep -q "secrets\.REVERB" "$1"' _ "$WORKFLOW"

case_ "hygiene: only the RFC 6455 GUID is used"
check "smoke.sh does not contain the retired GUID" bash -c '! grep -qF "$1" "$2"' _ "$RETIRED_GUID" "$SMOKE"
check "harness does not contain the retired GUID verbatim" bash -c '! grep -qF "$1" "$2"' _ "$RETIRED_GUID" "$HERE/smoke-test.sh"
check "smoke.sh contains the RFC GUID exactly once" test "$(grep -cF "$RFC_GUID" "$SMOKE")" = 1
check "harness defines the RFC GUID once, in the KAT-verified RFC_GUID" test "$(grep -cF "$RFC_GUID" "$HERE/smoke-test.sh")" = 1

# ---------------------------------------------------------------- result

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
