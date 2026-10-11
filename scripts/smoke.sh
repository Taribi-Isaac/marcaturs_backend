#!/usr/bin/env bash
# Post-deploy smoke checks (ENG-040 / MH-OPS-001, generalised in MH-OPS-006).
# Requires a reachable API. Does not invent hosts.
#
# Usage:
#   API_URL=https://api.marcaturshub.com.ng ./scripts/smoke.sh
# Optional:
#   APP_URL=https://app.marcaturshub.com.ng       participant SPA shell + CORS allow check
#   ADMIN_URL=https://admin.marcaturshub.com.ng   admin SPA shell (noindex)
#   WS_URL=wss://ws.marcaturshub.com.ng REVERB_APP_KEY=<public key>   WebSocket upgrade
#                                                 (WS_URL without REVERB_APP_KEY is a configuration error)
#   EXPECT_HSTS=1                                 require Strict-Transport-Security
#   EXPECT_WS=1                                   require WS_URL (the WebSocket check cannot be skipped)
# STAGING_API_URL / STAGING_APP_URL / STAGING_ADMIN_URL are accepted as aliases.
#
# Exit codes: 0 all checks passed, 1 a check failed, 2 configuration error (no check was run).
set -euo pipefail

API="${API_URL:-${STAGING_API_URL:-}}"
APP="${APP_URL:-${STAGING_APP_URL:-}}"
ADMIN="${ADMIN_URL:-${STAGING_ADMIN_URL:-}}"

if [[ -z "$API" ]]; then
  echo "API_URL is required (do not invent a host — use the real API origin)."
  exit 2
fi

if [[ "${EXPECT_WS:-0}" == "1" && -z "${WS_URL:-}" ]]; then
  echo "smoke configuration error: EXPECT_WS=1 but WS_URL is empty or unset."
  echo "Set WS_URL to the public Reverb origin (production: GitHub variable PRODUCTION_WS_URL)."
  exit 2
fi

REVERB_APP_KEY="${REVERB_APP_KEY:-}"
if [[ -n "${WS_URL:-}" && -z "${REVERB_APP_KEY//[[:space:]]/}" ]]; then
  echo "smoke configuration error: WS_URL is set but REVERB_APP_KEY is empty or unset, so the WebSocket check cannot run."
  echo "Set REVERB_APP_KEY to the public Reverb app key (the REVERB_APP_KEY value in shared/.env; production: GitHub variable REVERB_APP_KEY), or unset WS_URL to skip the WebSocket check."
  exit 2
fi

API="${API%/}"
HDR_FILE="$(mktemp)"
BODY_FILE="$(mktemp)"
trap 'rm -f "$HDR_FILE" "$BODY_FILE"' EXIT

echo "==> GET $API/api/v1/health"
HTTP_CODE="$(curl -sS -D "$HDR_FILE" -o "$BODY_FILE" -w "%{http_code}" "$API/api/v1/health")"
echo "HTTP $HTTP_CODE"
RESP="$(cat "$BODY_FILE")"
echo "$RESP" | head -c 800
echo

[[ "$HTTP_CODE" == "200" ]] || { echo "health HTTP not 200 (503 = database unavailable)"; exit 1; }
echo "$RESP" | grep -q '"success":true\|"success": true' || { echo "health success!=true"; exit 1; }
echo "$RESP" | grep -Ei 'password|APP_KEY|secret_key|private_key|BEGIN RSA' && {
  echo "health response appears to leak secrets"
  exit 1
}

echo "==> Security headers (MH-BE-050)"
require_header() {
  local name="$1"
  if ! grep -i "^${name}:" "$HDR_FILE" >/dev/null; then
    echo "missing header: $name"
    exit 1
  fi
  grep -i "^${name}:" "$HDR_FILE" | head -1
}

require_header "X-Content-Type-Options"
require_header "X-Frame-Options"
require_header "Referrer-Policy"
require_header "Permissions-Policy"

if [[ "${EXPECT_HSTS:-0}" == "1" ]]; then
  require_header "Strict-Transport-Security"
fi

if grep -i "^X-Powered-By:" "$HDR_FILE" >/dev/null; then
  echo "X-Powered-By header present — strip it at Nginx / php.ini"
  exit 1
fi

echo "==> GET $API/up"
curl -fsS -o /dev/null -w "HTTP %{http_code}\n" "$API/up"

if [[ -n "$APP" ]]; then
  APP="${APP%/}"
  echo "==> Participant SPA shell $APP/"
  APP_HTML="$(curl -fsS "$APP/")"
  echo "$APP_HTML" | grep -qi '<div id="root"' || { echo "participant SPA root missing"; exit 1; }

  echo "==> CORS: participant origin allowed with credentials"
  CORS_HDRS="$(curl -sS -o /dev/null -D - -X OPTIONS \
    -H "Origin: $APP" -H "Access-Control-Request-Method: GET" "$API/api/v1/health")"
  echo "$CORS_HDRS" | grep -qi "^Access-Control-Allow-Origin: $APP" || { echo "CORS does not allow $APP"; exit 1; }
  echo "$CORS_HDRS" | grep -qi "^Access-Control-Allow-Credentials: true" || { echo "CORS credentials not allowed"; exit 1; }

  echo "==> CORS: unknown origin rejected"
  EVIL_HDRS="$(curl -sS -o /dev/null -D - -X OPTIONS \
    -H "Origin: https://not-allowed.invalid" -H "Access-Control-Request-Method: GET" "$API/api/v1/health")"
  if echo "$EVIL_HDRS" | grep -qi "^Access-Control-Allow-Origin:"; then
    echo "CORS allowed an unknown origin"
    exit 1
  fi
fi

if [[ -n "$ADMIN" ]]; then
  ADMIN="${ADMIN%/}"
  echo "==> Admin SPA shell $ADMIN/"
  ADMIN_HTML="$(curl -fsS "$ADMIN/")"
  echo "$ADMIN_HTML" | grep -qi 'noindex' || { echo "admin missing noindex"; exit 1; }
fi

if [[ -n "${WS_URL:-}" ]]; then
  WS_HTTPS="$(echo "${WS_URL%/}" | sed -e 's#^wss://#https://#' -e 's#^ws://#http://#')"
  echo "==> Reverb WebSocket upgrade $WS_URL"
  WS_HDRS="$(curl -sS -o /dev/null -D - --http1.1 --max-time 5 \
    -H "Connection: Upgrade" -H "Upgrade: websocket" \
    -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: bWFyY2F0dXJzaHViLXNtb2tl" \
    -H "Origin: ${APP:-https://app.marcaturshub.com.ng}" \
    "$WS_HTTPS/app/${REVERB_APP_KEY}?protocol=7&client=smoke&version=1" || true)"
  echo "$WS_HDRS" | head -1
  echo "$WS_HDRS" | head -1 | grep -q " 101" || { echo "WebSocket upgrade failed"; exit 1; }
fi

echo
echo "Smoke OK."
echo "Authenticated journeys (auth, commerce, Reverb chat, queue, scheduler, mail, Paystack) still require the manual checklist in docs/deployment/production-checklist.md"
