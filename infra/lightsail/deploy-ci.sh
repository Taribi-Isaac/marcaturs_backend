#!/usr/bin/env bash
# Runner-side recovery decisions for .github/workflows/production.yml (MH-OPS-007D-R3).
# Pure functions of their environment: no network access, no secrets.
# Tested by infra/lightsail/tests/deploy-test.sh.
#
#   deploy-ci.sh describe <deploy.sh exit code>
#   deploy-ci.sh check-config  env: WS_URL REVERB_APP_KEY
#                          validates the smoke step's WebSocket inputs before anything reaches the server;
#                          prints ::error:: lines naming the variable (never its value), exits 1 if invalid
#   deploy-ci.sh decide    env: RELEASE_ID RELEASE_OUTCOME SMOKE_OUTCOME SERVER_CURRENT
#                          prints rollback=true|false and reason=<text> (GITHUB_OUTPUT format)
#   deploy-ci.sh summary   env: CONFIG_OUTCOME RELEASE_ID MIGRATE RELEASE_OUTCOME RELEASE_RC SMOKE_OUTCOME
#                               DECIDE_REASON ROLLBACK_OUTCOME ROLLBACK_RC POST_SMOKE_OUTCOME FINAL_CURRENT
#                          prints Markdown for $GITHUB_STEP_SUMMARY
set -euo pipefail

describe() {
  case "${1:-}" in
    0) echo "success" ;;
    2) echo "usage error; nothing changed" ;;
    10) echo "failed before activation; current release unchanged" ;;
    20) echo "failed after activation; new release current but unverified" ;;
    30) echo "no previous successful release (first release); manual recovery required" ;;
    31) echo "rollback target missing or invalid; nothing switched" ;;
    32) echo "rollback failed during switch, service restart or health check; manual recovery required" ;;
    75) echo "another release or rollback holds the lock; nothing changed" ;;
    "") echo "not run" ;;
    *) echo "unexpected exit code $1 (SSH or runner failure)" ;;
  esac
}

# The smoke step only runs after activation, and a first release has no rollback target, so its
# required inputs are checked here first. Values are never printed.
check_config() {
  local fail=0 ws="${WS_URL:-}" key="${REVERB_APP_KEY:-}"
  local ws_re='^wss://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?/?$' key_re='^[A-Za-z0-9_-]+$'
  if [[ -z "${ws//[[:space:]]/}" ]]; then
    echo "::error::Missing PRODUCTION_WS_URL in environment 'production'. Set it to the public Reverb origin (wss://<host>); the post-release WebSocket smoke check requires it."
    fail=1
  elif [[ ! "$ws" =~ $ws_re ]]; then
    echo "::error::PRODUCTION_WS_URL must look like wss://<host> (TLS, no port, path, query or whitespace)."
    fail=1
  fi
  if [[ -z "${key//[[:space:]]/}" ]]; then
    echo "::error::Missing REVERB_APP_KEY in environment 'production'. Set it to the public Reverb app key (REVERB_APP_KEY in shared/.env), never REVERB_APP_SECRET; the post-release WebSocket smoke check requires it."
    fail=1
  elif [[ ! "$key" =~ $key_re ]]; then
    echo "::error::REVERB_APP_KEY must contain only letters, digits, '-' or '_' (check for surrounding whitespace or quotes)."
    fail=1
  fi
  return "$fail"
}

decide() {
  local id="${RELEASE_ID:-}" release="${RELEASE_OUTCOME:-}" smoke="${SMOKE_OUTCOME:-}" current="${SERVER_CURRENT:-}"
  if [[ "$release" != failure && "$release" != cancelled && "$smoke" != failure ]]; then
    printf 'rollback=false\nreason=%s\n' "no deployment failure detected"
    return 0
  fi
  case "$current" in
    "" | unknown | unrecognised)
      printf 'rollback=false\nreason=%s\n' "server state could not be determined; manual check required"
      return 0
      ;;
  esac
  if [[ -z "$id" || "$current" != "$id" ]]; then
    printf 'rollback=false\nreason=%s\n' "release ${id:-?} never became current; current release ($current) left untouched"
    return 0
  fi
  printf 'rollback=true\nreason=%s\n' "release $id is current but a required step failed"
}

# Backticks below are literal Markdown.
# shellcheck disable=SC2016
summary() {
  local id="${RELEASE_ID:-?}" release="${RELEASE_OUTCOME:-}" release_rc="${RELEASE_RC:-}"
  local smoke="${SMOKE_OUTCOME:-}" reason="${DECIDE_REASON:-}" rollback="${ROLLBACK_OUTCOME:-}"
  local rollback_rc="${ROLLBACK_RC:-}" post="${POST_SMOKE_OUTCOME:-}" final="${FINAL_CURRENT:-unknown}"
  local config="${CONFIG_OUTCOME:-}"
  local verdict
  if [[ "$config" == failure ]]; then
    verdict="FAILED BEFORE RELEASE: production configuration is missing or invalid (see the 'Require production configuration' step). Nothing was uploaded, migrated or activated."
  elif [[ "$release" == success && "$smoke" == success ]]; then
    verdict="DEPLOYED: release \`$id\` is active and smoke checks passed."
  elif [[ -z "$rollback" || "$rollback" == skipped ]]; then
    if [[ "$final" == "$id" ]]; then
      verdict="FAILED: release \`$id\` is active but unverified and no rollback ran ($reason). Manual recovery required."
    elif [[ "$release" == failure || "$release" == cancelled ]]; then
      verdict="FAILED BEFORE ACTIVATION: $(describe "$release_rc"). Active release: \`$final\`."
    else
      verdict="FAILED: see the step log. Active release: \`$final\`."
    fi
  elif [[ "$rollback_rc" == 0 ]]; then
    if [[ "$post" == success ]]; then
      verdict="FAILED, ROLLED BACK: \`$id\` was rolled back; \`$final\` is active and post-rollback smoke passed."
    else
      verdict="FAILED, ROLLED BACK, POST-ROLLBACK SMOKE ${post:-not run}: \`$final\` is active. Manual investigation required."
    fi
  elif [[ "$rollback_rc" == 30 ]]; then
    verdict="FAILED (FIRST RELEASE): no previous successful release exists, so nothing was rolled back. \`$final\` is current. Manual recovery required: docs/deployment/rollback.md."
  else
    verdict="FAILED, ROLLBACK FAILED: $(describe "$rollback_rc"). Current release: \`$final\`. Manual recovery required: docs/deployment/rollback.md."
  fi

  printf '## Production API deployment\n\n**%s**\n\n' "$verdict"
  printf '| Step | Outcome | Detail |\n| --- | --- | --- |\n'
  printf '| Configuration check | %s | |\n' "${config:-not run}"
  printf '| Release `%s` | %s | %s |\n' "$id" "${release:-not run}" "$(describe "$release_rc")"
  printf '| Smoke | %s | |\n' "${smoke:-not run}"
  printf '| Recovery decision | | %s |\n' "${reason:-not evaluated}"
  printf '| Rollback | %s | %s |\n' "${rollback:-not run}" "$([[ -n "$rollback_rc" ]] && describe "$rollback_rc" || echo "not run")"
  printf '| Post-rollback smoke | %s | |\n' "${post:-not run}"
  printf '| **Active release now** | | `%s` |\n\n' "$final"
  if [[ "${MIGRATE:-false}" == true && "$config" != failure && ! ("$release" == success && "$smoke" == success) ]]; then
    printf 'Migrations were requested. Database changes are never reversed automatically; follow docs/deployment/rollback.md (Database) if a restore is needed.\n'
  fi
}

case "${1:-}" in
  describe) describe "${2:-}" ;;
  check-config) check_config ;;
  decide) decide ;;
  summary) summary ;;
  *)
    echo "usage: deploy-ci.sh describe <rc> | check-config | decide | summary" >&2
    exit 2
    ;;
esac
