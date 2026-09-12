#!/usr/bin/env bash
# Staging smoke checks (ENG-040A). Requires a reachable staging API.
# Usage:
#   STAGING_API_URL=https://api.staging.example.com ./scripts/staging-smoke.sh
set -euo pipefail

API="${STAGING_API_URL:-}"
if [[ -z "$API" ]]; then
  echo "STAGING_API_URL is required (e.g. https://api.staging.example.com)"
  exit 2
fi

API="${API%/}"
echo "==> GET $API/api/v1/health"
RESP="$(curl -fsS "$API/api/v1/health")"
echo "$RESP" | head -c 500
echo

echo "$RESP" | grep -q '"success":true' || { echo "health success=false"; exit 1; }
echo "$RESP" | grep -qi 'password\|APP_KEY\|secret' && { echo "health leaked secrets"; exit 1; }

echo "==> GET $API/up"
curl -fsS -o /dev/null -w "HTTP %{http_code}\n" "$API/up"

echo "Smoke OK (health + /up). Full auth/commerce UAT still required against staging."
