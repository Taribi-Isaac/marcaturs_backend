#!/usr/bin/env bash
# Stubbed test harness for deploy.sh, deploy-ci.sh and the production workflow wiring (MH-OPS-007D-R3).
#
# Everything runs inside a fresh temporary directory: APP_ROOT is a temp path and php, sudo,
# systemctl, supervisorctl, curl and perl are stubs on PATH. Nothing touches /var/www, a real
# service, the network or a database. Compatible with bash 3.2 (macOS) and 5.x (CI, server).
#
#   bash infra/lightsail/tests/deploy-test.sh
#
# Single-quoted `bash -c` bodies and expected strings are intentionally literal.
# shellcheck disable=SC2016
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
LIGHTSAIL="$(cd "$HERE/.." && pwd)"
REPO="$(cd "$LIGHTSAIL/../.." && pwd)"
DEPLOY="$LIGHTSAIL/deploy.sh"
CI="$LIGHTSAIL/deploy-ci.sh"
WORKFLOW="$REPO/.github/workflows/production.yml"
REAL_PERL="$(command -v perl)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/mh-deploy-test.XXXXXX")"
WORK="$(cd "$WORK" && pwd -P)"
trap 'rm -rf "$WORK"' EXIT

STUB_BIN="$WORK/bin"
STUB_LOG="$WORK/stub.log"
export STUB_BIN STUB_LOG

A=20261011000001-aaaaaaa
B=20261011000002-bbbbbbb
C=20261011000003-ccccccc
D=20261011000004-ddddddd
Z=20991231235959-fffffff

PASS=0
FAIL=0
N=0
NAME=""
OUT=""
RC=0

# ---------------------------------------------------------------- stubs + fixtures

mkdir -p "$STUB_BIN"

cat >"$STUB_BIN/sudo" <<'EOF'
#!/usr/bin/env bash
echo "sudo $*" >>"$STUB_LOG"
if [ "${1:-}" = -n ]; then shift; fi
case "${1:-}" in
  systemctl | supervisorctl) ;;
  *) echo "sudo stub: refusing $*" >&2; exit 97 ;;
esac
cmd="$1"
shift
exec "$STUB_BIN/$cmd" "$@"
EOF

cat >"$STUB_BIN/systemctl" <<'EOF'
#!/usr/bin/env bash
echo "systemctl $*" >>"$STUB_LOG"
case "${1:-}" in
  reload) exit "${STUB_FPM_RELOAD_RC:-0}" ;;
  is-active) exit "${STUB_FPM_ACTIVE_RC:-0}" ;;
  *) exit 98 ;;
esac
EOF

cat >"$STUB_BIN/supervisorctl" <<'EOF'
#!/usr/bin/env bash
echo "supervisorctl $*" >>"$STUB_LOG"
if [ "${STUB_SUP_RC:-0}" = 0 ]; then echo "marcaturshub:queue: started"; else echo "marcaturshub:reverb: ERROR (spawn error)"; fi
exit "${STUB_SUP_RC:-0}"
EOF

cat >"$STUB_BIN/php" <<'EOF'
#!/usr/bin/env bash
echo "php $*" >>"$STUB_LOG"
case "$*" in
  "artisan config:cache")
    if [ -n "${STUB_SLOW:-}" ]; then sleep "$STUB_SLOW"; fi
    mkdir -p bootstrap/cache && echo '<?php return [];' >bootstrap/cache/config.php ;;
  "artisan route:cache" | "artisan event:cache") ;;
  "artisan migrate:status --pending")
    case "${STUB_MIGRATE_STATUS:-ok}" in
      ok) echo "  2026_01_01_000000_example ........ Pending" ;;
      missing) echo "   ERROR  Migration table not found."; exit 1 ;;
      connfail) echo "SQLSTATE[HY000] [2002] Connection refused (Connection: mysql)"; exit 1 ;;
    esac ;;
  "artisan migrate --force --no-interaction") echo "migrating"; exit "${STUB_MIGRATE_RC:-0}" ;;
  *"/artisan --version") echo "Laravel Framework 13.0.0"; exit "${STUB_ARTISAN_RC:-0}" ;;
  *) echo "php stub: unexpected arguments: $*" >&2; exit 99 ;;
esac
EOF

cat >"$STUB_BIN/curl" <<'EOF'
#!/usr/bin/env bash
echo "curl $*" >>"$STUB_LOG"
out=/dev/null
while [ $# -gt 0 ]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    *) shift ;;
  esac
done
if [ "${STUB_HEALTH_CODE:-200}" = 200 ]; then printf '{"success":true,"data":{"status":"ok"}}' >"$out"; else printf '{"success":false}' >"$out"; fi
printf '%s' "${STUB_HEALTH_CODE:-200}"
EOF

cat >"$STUB_BIN/perl" <<EOF
#!/usr/bin/env bash
echo "perl rename" >>"\$STUB_LOG"
if [ -n "\${STUB_RENAME_FAIL:-}" ]; then echo "rename failed: stub" >&2; exit 1; fi
exec "$REAL_PERL" "\$@"
EOF

chmod +x "$STUB_BIN"/*

SRC="$WORK/src"
mkdir -p "$SRC/vendor" "$SRC/public" "$SRC/bootstrap/cache" "$SRC/storage/logs"
echo '<?php // artisan' >"$SRC/artisan"
echo '<?php // autoload' >"$SRC/vendor/autoload.php"
echo '<?php // index' >"$SRC/public/index.php"
: >"$SRC/bootstrap/cache/.gitignore"
tar -czf "$WORK/valid.tgz" -C "$SRC" .
printf 'this is not a gzip archive' >"$WORK/corrupt.tgz"
VALID="$WORK/valid.tgz"

# ---------------------------------------------------------------- helpers

clear_stubs() {
  unset STUB_FPM_RELOAD_RC STUB_FPM_ACTIVE_RC STUB_SUP_RC STUB_MIGRATE_STATUS STUB_MIGRATE_RC \
    STUB_ARTISAN_RC STUB_HEALTH_CODE STUB_RENAME_FAIL STUB_SLOW KEEP_RELEASES
}

fresh() {
  NAME="$1"
  N=$((N + 1))
  clear_stubs
  ROOT="$WORK/t$N/app"
  case "$ROOT" in "$WORK"/*) ;; *)
    echo "refusing to run outside $WORK" >&2
    exit 1
    ;;
  esac
  mkdir -p "$ROOT/releases" "$ROOT/shared/storage" "$ROOT/bin"
  printf 'APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:harness-dummy-key\n' >"$ROOT/shared/.env"
  : >"$STUB_LOG"
  echo "# $NAME"
}

run() {
  OUT="$(env APP_ROOT="$ROOT" PATH="$STUB_BIN:$PATH" HEALTH_URL="https://api.example.test/api/v1/health" \
    HEALTH_RESOLVE="api.example.test:443:127.0.0.1" HEALTH_ATTEMPTS=2 HEALTH_DELAY=0 \
    bash "$DEPLOY" "$@" 2>&1)"
  RC=$?
}

rel() { run release "$VALID" "$@"; }

# Releases that must succeed as test setup.
setup_release() {
  rel "$@"
  if [[ "$RC" -ne 0 ]]; then bad "setup release $* failed with $RC"; fi
}

cur() { if [[ -L "$ROOT/current" ]]; then basename "$(readlink "$ROOT/current")"; else echo none; fi; }

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

assert_rc() { check "exit code $1" test "$RC" = "$1"; }
assert_out() { check "output mentions '$1'" grep -qF -- "$1" <<<"$OUT"; }
assert_not_out() { check "output omits '$1'" bash -c '! grep -qF -- "$1" <<<"$2"' _ "$1" "$OUT"; }
assert_log() { check "stub log has '$1'" grep -qF -- "$1" "$STUB_LOG"; }
assert_no_log() { check "stub log lacks '$1'" bash -c '! grep -qF -- "$1" "$2"' _ "$1" "$STUB_LOG"; }
assert_state() { check "state has $1" grep -qx -- "$1" "$ROOT/state/active"; }
assert_release() { check "releases/$1 kept" test -d "$ROOT/releases/$1"; }
assert_no_release() { check "releases/$1 absent" test ! -e "$ROOT/releases/$1"; }
in_failed() {
  local f
  for f in "$ROOT/failed/$1".*".$2"; do
    if [[ -d "$f" ]]; then return 0; fi
  done
  return 1
}
assert_failed() { check "failed/ holds $1 ($2)" in_failed "$1" "$2"; }
assert_not_failed() { check "failed/ does not hold $1" bash -c '! ls "$1" 2>/dev/null | grep -q "^$2\."' _ "$ROOT/failed" "$1"; }

# Also proves the serving release is a real directory under releases/ (never moved to failed/).
assert_current() {
  check "current = $1" test "$(cur)" = "$1"
  if [[ "$1" != none ]]; then
    check "current $1 resolves under releases/" test "$(readlink "$ROOT/current")" = "$ROOT/releases/$1"
    check "current $1 is a live release directory" test -d "$ROOT/releases/$1" -a ! -L "$ROOT/releases/$1"
  fi
  check "no stray current.next" test ! -e "$ROOT/current.next" -a ! -L "$ROOT/current.next"
}

status_field() {
  env APP_ROOT="$ROOT" PATH="$STUB_BIN:$PATH" bash "$DEPLOY" status 2>/dev/null | sed -n "s/^$1=//p"
}

# ---------------------------------------------------------------- release

fresh "release: invalid release id"
rel "not-a-release"
assert_rc 2
assert_out "release id must look like"
check "nothing created" test -z "$(ls "$ROOT/releases")"
check "no state dir created" test ! -e "$ROOT/state"
run release "$VALID" "20261011000001-AAAAAAA"
assert_rc 2
run release "$VALID" "../$A"
assert_rc 2

fresh "release: usage errors"
run
assert_rc 2
run release "$VALID"
assert_rc 2
run release "$VALID" "$A" --unknown
assert_rc 2
run deploy-everything
assert_rc 2

fresh "release: first activation"
rel "$A"
assert_rc 0
assert_current "$A"
assert_state "active=$A"
assert_state "previous=none"
check "activation marker written" test -f "$ROOT/releases/$A/.deploy-activated"
check "shared .env linked" test -L "$ROOT/releases/$A/.env"
check "shared storage linked" test -L "$ROOT/releases/$A/storage"
check "bootstrap/cache group-writable" bash -c 'ls -ld "$1" | grep -q "^d....w"' _ "$ROOT/releases/$A/bootstrap/cache"
check "state dir is 0750" bash -c 'ls -ld "$1" | grep -q "^drwxr-x---"' _ "$ROOT/state"
check "failed dir is 0750" bash -c 'ls -ld "$1" | grep -q "^drwxr-x---"' _ "$ROOT/failed"
check "lock released" test ! -e "$ROOT/state/lock"
check "history records activation" grep -q "activated $A previous=none" "$ROOT/state/history.log"
assert_log "systemctl reload php8.4-fpm"
assert_log "systemctl is-active --quiet php8.4-fpm"
assert_log "supervisorctl restart marcaturshub:*"
assert_log "--resolve api.example.test:443:127.0.0.1"
assert_no_log "migrate"
assert_out "DEPLOY_RESULT action=release rc=0 release=$A current=$A"
assert_not_out "harness-dummy-key"

fresh "release: second activation records the previous release"
setup_release "$A"
rel "$B"
assert_rc 0
assert_current "$B"
assert_state "active=$B"
assert_state "previous=$A"
assert_release "$A"
check "status reports rollback target" test "$(status_field rollback_target)" = "$A"
check "status reports activated" test "$(status_field current_activated)" = yes
check "status reports lock free" test "$(status_field lock)" = free

fresh "release: existing id that was activated is refused"
setup_release "$A"
setup_release "$B"
rel "$A"
assert_rc 10
assert_out "already exists and has been active"
assert_current "$B"
assert_release "$A"
assert_state "active=$B"
rel "$B"
assert_rc 10
assert_current "$B"
assert_release "$B"

fresh "release: leftover from a failed attempt does not block a retry"
setup_release "$A"
mkdir -p "$ROOT/releases/$B/partial"
rel "$B"
assert_rc 0
assert_current "$B"
assert_failed "$B" stale-attempt
assert_state "previous=$A"

fresh "release: failed extraction is isolated and retry works"
setup_release "$A"
: >"$STUB_LOG"
run release "$WORK/corrupt.tgz" "$B"
assert_rc 10
assert_out "failed before activation"
assert_current "$A"
assert_state "active=$A"
assert_no_release "$B"
assert_failed "$B" failed
assert_no_log "systemctl reload"
rel "$B"
assert_rc 0
assert_current "$B"
assert_state "previous=$A"

fresh "release: missing artifact"
setup_release "$A"
run release "$WORK/missing.tgz" "$B"
assert_rc 10
assert_out "artifact not found"
assert_current "$A"

fresh "release: unsafe shared/.env is refused"
printf 'APP_ENV=production\nAPP_DEBUG=true\n' >"$ROOT/shared/.env"
rel "$A"
assert_rc 10
assert_out "APP_DEBUG=false"
assert_current none
assert_no_release "$A"

fresh "release: failed migration keeps the old release serving"
setup_release "$A"
: >"$STUB_LOG"
export STUB_MIGRATE_RC=1
rel "$B" --migrate
assert_rc 10
assert_out "NOT restored automatically"
assert_current "$A"
assert_failed "$B" failed
assert_no_log "systemctl reload"

fresh "release: migrate:status connection error is not hidden"
export STUB_MIGRATE_STATUS=connfail
rel "$A" --migrate
assert_rc 10
assert_out "SQLSTATE[HY000] [2002] Connection refused"
assert_out "migrations were not started"
assert_no_log "migrate --force"
assert_current none

fresh "release: first release on an empty database"
export STUB_MIGRATE_STATUS=missing
rel "$A" --migrate
assert_rc 0
assert_out "expected only for the first release"
assert_not_out "Migration table not found"
assert_log "artisan migrate --force --no-interaction"
assert_current "$A"

fresh "release: missing migrations table with an active release is refused"
setup_release "$A"
export STUB_MIGRATE_STATUS=missing
rel "$B" --migrate
assert_rc 10
assert_out "already active"
assert_out "Migration table not found"
assert_current "$A"

fresh "release: symlink switch failure"
setup_release "$A"
: >"$STUB_LOG"
export STUB_RENAME_FAIL=1
rel "$B"
assert_rc 10
assert_out "could not switch current"
assert_current "$A"
assert_failed "$B" failed
assert_no_log "systemctl reload"
assert_state "active=$A"

fresh "release: symlink switch failure on first release"
export STUB_RENAME_FAIL=1
rel "$A"
assert_rc 10
assert_current none

for case_spec in "STUB_FPM_RELOAD_RC=1:PHP-FPM reload failed" "STUB_FPM_ACTIVE_RC=3:PHP-FPM is not active" \
  "STUB_SUP_RC=1:Supervisor could not restart" "STUB_HEALTH_CODE=503:health check failed" \
  "STUB_ARTISAN_RC=1:cannot boot"; do
  fresh "release: post-activation failure (${case_spec%%:*})"
  setup_release "$A"
  export "${case_spec%%:*}"
  rel "$B"
  assert_rc 20
  assert_out "${case_spec#*:}"
  assert_out "CURRENT but unverified"
  assert_current "$B"
  check "unverified release has no activation marker" test ! -f "$ROOT/releases/$B/.deploy-activated"
  assert_state "active=$A"
  assert_out "DEPLOY_RESULT action=release rc=20 release=$B current=$B"
done

fresh "release: health check retries before failing"
setup_release "$A"
: >"$STUB_LOG"
export STUB_HEALTH_CODE=502
rel "$B"
assert_rc 20
check "two health attempts" test "$(grep -c '^curl' "$STUB_LOG")" -eq 2

fresh "release: an unverified current release is never recorded as previous"
setup_release "$A"
export STUB_SUP_RC=1
rel "$B"
assert_rc 20
clear_stubs
rel "$C"
assert_rc 0
assert_out "rollback target stays $A"
assert_state "active=$C"
assert_state "previous=$A"

fresh "release: concurrent run is refused while a release holds the lock"
setup_release "$A"
STUB_SLOW=3 env APP_ROOT="$ROOT" PATH="$STUB_BIN:$PATH" HEALTH_ATTEMPTS=1 HEALTH_DELAY=0 \
  bash "$DEPLOY" release "$VALID" "$B" >"$WORK/bg.out" 2>&1 &
bg=$!
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  [[ -f "$ROOT/state/lock/owner" ]] && break
  sleep 0.25
done
rel "$C"
assert_rc 75
assert_out "another release or rollback is running"
run rollback
assert_rc 75
wait "$bg"
check "first release finished successfully" test "$?" -eq 0
assert_current "$B"
assert_no_release "$C"
check "lock released" test ! -e "$ROOT/state/lock"

fresh "release: stale lock is reported, not removed"
setup_release "$A"
dead=99999
while kill -0 "$dead" 2>/dev/null || ps -p "$dead" >/dev/null 2>&1; do dead=$((dead - 1)); done
mkdir -p "$ROOT/state/lock"
printf 'pid=%s\naction=release\nstarted_at=x\n' "$dead" >"$ROOT/state/lock/owner"
rel "$B"
assert_rc 75
assert_out "stale lock"
check "lock left for the operator" test -d "$ROOT/state/lock"
assert_current "$A"
check "status reports held lock" test "$(status_field lock)" = held

fresh "release: unreadable state file is refused"
setup_release "$A"
printf 'active=%s\nprevious=../../etc\n' "$A" >"$ROOT/state/active"
rel "$B"
assert_rc 10
assert_out "unreadable or invalid"
assert_current "$A"
check "status reports invalid state" test "$(status_field state)" = invalid

fresh "release: current pointing outside releases/ is refused"
mkdir -p "$WORK/elsewhere/$A"
ln -s "$WORK/elsewhere/$A" "$ROOT/current"
rel "$B"
assert_rc 10
assert_out "does not point to a release directory"

fresh "release: pruning keeps current and recorded previous"
export KEEP_RELEASES=1
setup_release "$A"
setup_release "$B"
assert_release "$A"
assert_release "$B"
setup_release "$C"
assert_no_release "$A"
assert_release "$B"
assert_release "$C"
mkdir -p "$ROOT/releases/$Z"
setup_release "$D"
assert_release "$C"
assert_release "$D"
assert_release "$Z"

# ---------------------------------------------------------------- rollback

fresh "rollback: nothing deployed"
run rollback
assert_rc 30
assert_out "nothing to roll back"

fresh "rollback: first release fails safely"
setup_release "$A"
run rollback
assert_rc 30
assert_out "first release"
assert_current "$A"
assert_release "$A"

fresh "rollback: first release that failed after activation"
export STUB_SUP_RC=1
rel "$A"
assert_rc 20
clear_stubs
run rollback
assert_rc 30
assert_current "$A"
assert_release "$A"

fresh "rollback: returns to the recorded previous release"
setup_release "$A"
setup_release "$B"
: >"$STUB_LOG"
run rollback
assert_rc 0
assert_current "$A"
assert_state "active=$A"
assert_state "previous=none"
assert_failed "$B" rolled-back
assert_log "systemctl reload php8.4-fpm"
assert_log "supervisorctl restart marcaturshub:*"
assert_log "curl"
assert_out "database is NOT rolled back"
check "history records rollback" grep -q "rollback $B -> $A" "$ROOT/state/history.log"
run rollback
assert_rc 30
assert_current "$A"

fresh "rollback: a never-activated release is ignored"
setup_release "$A"
cp -R "$ROOT/releases/$A" "$ROOT/releases/$B"
rm -f "$ROOT/releases/$B/.deploy-activated"
setup_release "$C"
assert_state "previous=$A"
run rollback
assert_rc 0
assert_current "$A"
assert_release "$B"

fresh "rollback: after a post-activation failure returns to the recorded active release"
setup_release "$A"
export STUB_HEALTH_CODE=503
rel "$B"
assert_rc 20
clear_stubs
run rollback
assert_rc 0
assert_current "$A"
assert_failed "$B" rolled-back

for broken in "missing:release directory missing" "unmarked:never activated successfully" \
  "nofile:missing vendor/autoload.php"; do
  fresh "rollback: invalid previous release (${broken%%:*})"
  setup_release "$A"
  setup_release "$B"
  case "${broken%%:*}" in
    missing) rm -rf "$ROOT/releases/$A" ;;
    unmarked) rm -f "$ROOT/releases/$A/.deploy-activated" ;;
    nofile) rm -f "$ROOT/releases/$A/vendor/autoload.php" ;;
  esac
  : >"$STUB_LOG"
  run rollback
  assert_rc 31
  assert_out "${broken#*:}"
  assert_current "$B"
  assert_no_log "systemctl reload"
done

fresh "rollback: invalid id in state file"
setup_release "$A"
setup_release "$B"
printf 'active=%s\nprevious=../../etc/passwd\n' "$B" >"$ROOT/state/active"
run rollback
assert_rc 31
assert_current "$B"

for case_spec in "STUB_FPM_RELOAD_RC=1:PHP-FPM reload failed" "STUB_FPM_ACTIVE_RC=3:PHP-FPM is not active" \
  "STUB_SUP_RC=1:Supervisor could not restart" "STUB_HEALTH_CODE=500:health check failed"; do
  fresh "rollback: failure after switching (${case_spec%%:*})"
  setup_release "$A"
  setup_release "$B"
  export "${case_spec%%:*}"
  run rollback
  assert_rc 32
  assert_out "${case_spec#*:}"
  assert_out "manual recovery required"
  assert_current "$A"
  assert_release "$B"
  assert_not_failed "$B"
  assert_state "active=$B"
done

fresh "rollback: symlink switch failure"
setup_release "$A"
setup_release "$B"
export STUB_RENAME_FAIL=1
run rollback
assert_rc 32
assert_current "$B"
assert_release "$A"

fresh "rollback: lock held"
setup_release "$A"
setup_release "$B"
mkdir -p "$ROOT/state/lock"
printf 'pid=%s\n' "$$" >"$ROOT/state/lock/owner"
run rollback
assert_rc 75
assert_current "$B"

# ---------------------------------------------------------------- workflow decisions

decide() { env RELEASE_ID="$1" RELEASE_OUTCOME="$2" SMOKE_OUTCOME="$3" SERVER_CURRENT="$4" bash "$CI" decide; }

fresh "workflow: rollback decision"
OUT="$(decide "$B" failure skipped "$A")"
assert_out "rollback=false"
assert_out "never became current"
OUT="$(decide "$B" failure skipped "$B")"
assert_out "rollback=true"
OUT="$(decide "$B" success failure "$B")"
assert_out "rollback=true"
OUT="$(decide "$B" success success "$B")"
assert_out "rollback=false"
OUT="$(decide "$B" failure skipped unknown)"
assert_out "rollback=false"
assert_out "manual check required"
OUT="$(decide "$B" cancelled skipped "$B")"
assert_out "rollback=true"
OUT="$(bash "$CI" describe 30)"
assert_out "first release"

# Mirrors the deploy job: release -> smoke -> decide (status) -> rollback -> post-rollback smoke -> summary.
# Usage: simulate <id> <artifact> <smoke outcome if released> <post-rollback smoke outcome> [migrate]
# Env: SIM_ROLLBACK_STUB (NAME=value applied only during rollback).
simulate() {
  local id="$1" artifact="$2" smoke="$3" post="$4" migrate="${5:-false}" flag=""
  local rel_out rel_rc smoke_out decision rollback reason rb_out="" rb_rc="" post_out="" final
  [[ "$migrate" == true ]] && flag=--migrate
  run release "$artifact" "$id" ${flag:+"$flag"}
  rel_rc=$RC
  if [[ "$rel_rc" -eq 0 ]]; then
    rel_out=success
    smoke_out="$smoke"
  else
    rel_out=failure
    smoke_out=skipped
  fi
  clear_stubs
  decision="$(decide "$id" "$rel_out" "$smoke_out" "$(status_field current)")"
  rollback="$(sed -n 's/^rollback=//p' <<<"$decision")"
  reason="$(sed -n 's/^reason=//p' <<<"$decision")"
  if [[ "$rollback" == true ]]; then
    if [[ -n "${SIM_ROLLBACK_STUB:-}" ]]; then export "${SIM_ROLLBACK_STUB?}"; fi
    run rollback
    rb_rc=$RC
    clear_stubs
    if [[ "$rb_rc" -eq 0 ]]; then
      rb_out=success
      post_out="$post"
    else
      rb_out=failure
      post_out=skipped
    fi
  else
    rb_out=skipped
    post_out=skipped
  fi
  final="$(status_field current)"
  SIM_JOB=success
  if [[ "$rel_out" != success || "$smoke_out" != success || "$rb_out" == failure || "$post_out" == failure ]]; then
    SIM_JOB=failure
  fi
  SIM_ROLLBACK="$rb_out"
  OUT="$(env RELEASE_ID="$id" MIGRATE="$migrate" RELEASE_OUTCOME="$rel_out" RELEASE_RC="$rel_rc" \
    SMOKE_OUTCOME="$smoke_out" DECIDE_REASON="$reason" ROLLBACK_OUTCOME="$rb_out" ROLLBACK_RC="$rb_rc" \
    POST_SMOKE_OUTCOME="$post_out" FINAL_CURRENT="$final" bash "$CI" summary)"
}

fresh "workflow: successful deployment"
setup_release "$A"
simulate "$B" "$VALID" success skipped
check "job succeeds" test "$SIM_JOB" = success
check "no rollback" test "$SIM_ROLLBACK" = skipped
assert_out "DEPLOYED: release \`$B\` is active"
assert_out "| **Active release now** | | \`$B\` |"

fresh "workflow: pre-activation failure does not roll back"
setup_release "$A"
simulate "$B" "$WORK/corrupt.tgz" success skipped
check "job fails" test "$SIM_JOB" = failure
check "no rollback" test "$SIM_ROLLBACK" = skipped
assert_current "$A"
assert_out "FAILED BEFORE ACTIVATION"
assert_out "\`$A\`"

fresh "workflow: smoke failure rolls back and re-verifies"
setup_release "$A"
simulate "$B" "$VALID" failure success
check "job still fails" test "$SIM_JOB" = failure
check "rollback ran" test "$SIM_ROLLBACK" = success
assert_current "$A"
assert_out "FAILED, ROLLED BACK"
assert_out "post-rollback smoke passed"
assert_out "| **Active release now** | | \`$A\` |"

fresh "workflow: post-activation health failure rolls back"
setup_release "$A"
export STUB_HEALTH_CODE=503
simulate "$B" "$VALID" success success
check "rollback ran" test "$SIM_ROLLBACK" = success
assert_current "$A"
assert_out "Release \`$B\` | failure | failed after activation"

fresh "workflow: first-release failure needs manual recovery"
export STUB_SUP_RC=1
simulate "$A" "$VALID" success success true
check "job fails" test "$SIM_JOB" = failure
assert_out "FAILED (FIRST RELEASE)"
assert_out "Manual recovery required"
assert_out "never reversed automatically"
assert_current "$A"

fresh "workflow: failed rollback keeps the job failed"
setup_release "$A"
SIM_ROLLBACK_STUB=STUB_HEALTH_CODE=500 simulate "$B" "$VALID" failure success
check "job fails" test "$SIM_JOB" = failure
check "rollback failed" test "$SIM_ROLLBACK" = failure
assert_out "FAILED, ROLLBACK FAILED"
assert_out "\`$A\`"

fresh "workflow: post-rollback smoke failure is reported"
setup_release "$A"
simulate "$B" "$VALID" failure failure
check "job fails" test "$SIM_JOB" = failure
assert_out "POST-ROLLBACK SMOKE failure"
assert_not_out "harness-dummy-key"

# ---------------------------------------------------------------- workflow wiring (static)

wf_has() { check "workflow: $1" grep -qF -- "$1" "$WORKFLOW"; }
wf_count() { check "workflow: '$1' appears $2 time(s)" test "$(grep -cF -- "$1" "$WORKFLOW")" -eq "$2"; }

fresh "workflow: production.yml wiring"
wf_has "if: github.event_name == 'workflow_dispatch' && inputs.deploy && github.ref == 'refs/heads/main'"
wf_has "environment: production"
wf_has "if: inputs.migrate && !inputs.backup_confirmed"
wf_has "MIGRATE_FLAG: \${{ inputs.migrate && '--migrate' || '' }}"
wf_has "id: release"
wf_has "id: smoke"
wf_has "id: decide"
wf_has "if: always() && steps.release.outcome != 'skipped'"
wf_has "id: rollback"
wf_has "if: always() && steps.decide.outputs.rollback == 'true'"
wf_has "id: post_rollback_smoke"
wf_has "if: always() && steps.rollback.outcome == 'success'"
wf_has "bash infra/lightsail/deploy-ci.sh summary >> \"\$GITHUB_STEP_SUMMARY\""
wf_count "deploy.sh rollback" 1
wf_count 'exit "$rc"' 2
wf_count "secrets." 4
check "workflow: no destructive artisan commands" bash -c '! grep -nE "migrate:fresh|db:wipe|db:seed|migrate:rollback|migrate:reset" "$1"' _ "$WORKFLOW"
check "deploy.sh: destructive artisan commands only in comments" \
  bash -c '! grep -vE "^[[:space:]]*#" "$1" | grep -qE "migrate:fresh|db:wipe|db:seed|migrate:rollback|migrate:reset"' _ "$DEPLOY"
check "deploy.sh: no shell tracing" bash -c '! grep -qE "set -[a-z]*x" "$1"' _ "$DEPLOY"

# ---------------------------------------------------------------- result

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
