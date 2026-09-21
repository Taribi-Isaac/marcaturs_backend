#!/usr/bin/env bash
# Staging smoke checks (ENG-040 / MH-OPS-001).
# Requires a reachable staging API. Does not invent hosts.
#
# Usage:
#   STAGING_API_URL=https://api.staging.example.com ./scripts/staging-smoke.sh
# Optional:
#   STAGING_APP_URL=https://app.staging.example.com
#   STAGING_ADMIN_URL=https://admin.staging.example.com
#   EXPECT_HSTS=1   # require Strict-Transport-Security (HTTPS edge/app)
set -euo pipefail

API="${STAGING_API_URL:-}"
if [[ -z "$API" ]]; then
  echo "STAGING_API_URL is required (do not invent a host — use the real staging API origin)."
  exit 2
fi

API="${API%/}"
HDR_FILE="$(mktemp)"
trap 'rm -f "$HDR_FILE"' EXIT

echo "==> GET $API/api/v1/health"
HTTP_CODE="$(curl -fsS -D "$HDR_FILE" -o /tmp/mh-staging-health.json -w "%{http_code}" "$API/api/v1/health")"
echo "HTTP $HTTP_CODE"
RESP="$(cat /tmp/mh-staging-health.json)"
echo "$RESP" | head -c 800
echo

[[ "$HTTP_CODE" == "200" ]] || { echo "health HTTP not 200"; exit 1; }
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

echo "==> GET $API/up"
curl -fsS -o /dev/null -w "HTTP %{http_code}\n" "$API/up"

if [[ -n "${STAGING_APP_URL:-}" ]]; then
  APP="${STAGING_APP_URL%/}"
  echo "==> Participant SPA shell $APP/"
  APP_HTML="$(curl -fsS "$APP/")"
  echo "$APP_HTML" | grep -qi '<div id="root"' || { echo "participant SPA root missing"; exit 1; }
fi

if [[ -n "${STAGING_ADMIN_URL:-}" ]]; then
  ADMIN="${STAGING_ADMIN_URL%/}"
  echo "==> Admin SPA shell $ADMIN/"
  ADMIN_HTML="$(curl -fsS "$ADMIN/")"
  echo "$ADMIN_HTML" | grep -qi 'noindex' || { echo "admin missing noindex"; exit 1; }
fi

echo
echo "Smoke OK (health + /up + security headers${STAGING_APP_URL:+ + app shell}${STAGING_ADMIN_URL:+ + admin shell})."
echo "Full auth/commerce/Reverb/queue/scheduler/Paystack UAT still required — see docs/deployment/staging.md"
