#!/usr/bin/env bash
# MarcatursHub API release / rollback on the Lightsail host (MH-OPS-006; recovery model MH-OPS-007D-R3).
#
# Layout (APP_ROOT=/var/www/marcaturshub):
#   releases/<YYYYmmddHHMMSS-sha>/      one directory per release
#   current -> releases/<id>            served by Nginx (PHP-FPM) and Supervisor
#   failed/<id>.<UTC>.<reason>/         releases that failed or were rolled back (kept for investigation)
#   state/active                        last verified activation: active=<id>, previous=<id|none>
#   state/history.log                   append-only activation / rollback log
#   state/lock/                         held while a release or rollback runs
#   shared/.env                         production secrets (never in Git)
#   shared/storage/                     logs, framework cache
#
# A release counts as activated only after `current` points at it, PHP-FPM reloaded,
# the Supervisor group restarted and the health check passed. It then carries
# releases/<id>/.deploy-activated and is recorded in state/active. Rollback targets
# come from state/active only — never from directory ordering.
#
# Usage (deploy user, normally via .github/workflows/production.yml):
#   deploy.sh release <artifact.tar.gz> <release-id> [--migrate]
#   deploy.sh rollback
#   deploy.sh status
#
# Optional environment: HEALTH_URL (HTTP health check after the switch; requires
# HTTP 200 and "success":true) and HEALTH_RESOLVE (curl --resolve host:port:addr).
#
# Exit codes (docs/deployment/rollback.md):
#   0   success
#   2   usage error or invalid release id; nothing changed
#   10  release failed before activation; `current` unchanged, candidate moved to failed/
#   20  release failed after activation; the new release is current but unverified — roll back
#   30  rollback impossible: no previous successful release recorded (first release)
#   31  rollback refused: recorded target missing/invalid or state unreadable; nothing switched
#   32  rollback failed during switch, service restart or health check; manual recovery
#   75  another release/rollback holds the lock (or a stale lock needs operator review)
#
# Migrations run only with --migrate, before the switch, and are never reversed.
# Never migrate:fresh / db:wipe. Rollback switches code only.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/marcaturshub}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
SUPERVISOR_GROUP="${SUPERVISOR_GROUP:-marcaturshub}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
HEALTH_URL="${HEALTH_URL:-}"
HEALTH_RESOLVE="${HEALTH_RESOLVE:-}"
HEALTH_ATTEMPTS="${HEALTH_ATTEMPTS:-5}"
HEALTH_DELAY="${HEALTH_DELAY:-3}"

RELEASES="$APP_ROOT/releases"
FAILED="$APP_ROOT/failed"
STATE="$APP_ROOT/state"
STATE_FILE="$STATE/active"
HISTORY="$STATE/history.log"
LOCK="$STATE/lock"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"
MARKER=".deploy-activated"
RELEASE_ID_PATTERN='^[0-9]{14}-[0-9a-f]{7,40}$'

EXIT_USAGE=2
EXIT_BEFORE_ACTIVATION=10
EXIT_AFTER_ACTIVATION=20
EXIT_NO_ROLLBACK_TARGET=30
EXIT_INVALID_ROLLBACK_TARGET=31
EXIT_ROLLBACK_FAILED=32
EXIT_LOCKED=75

ACTION=""
PHASE=""
CANDIDATE=""
PREV_ID=none
ROLLBACK_FROM=""
LOCK_HELD=0
STATE_ACTIVE=none
STATE_PREVIOUS=none

log() { printf '==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
fail() {
  local code="$1"
  shift
  printf 'ERROR: %s\n' "$*" >&2
  exit "$code"
}
usage() { fail "$EXIT_USAGE" "usage: deploy.sh release <artifact.tar.gz> <release-id> [--migrate] | deploy.sh rollback | deploy.sh status"; }
utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }
valid_id() { [[ "$1" =~ $RELEASE_ID_PATTERN ]]; }
is_activated() { valid_id "$1" && [[ -d "$RELEASES/$1" && -f "$RELEASES/$1/$MARKER" ]]; }
record() { printf '%s %s\n' "$(utc)" "$*" >>"$HISTORY" 2>/dev/null || warn "could not append to $HISTORY"; }

# Prints the release id `current` points at, "none" if absent, "unrecognised" otherwise.
current_id() {
  local target id
  if [[ ! -L "$CURRENT" ]]; then
    if [[ -e "$CURRENT" ]]; then echo unrecognised; else echo none; fi
    return 0
  fi
  target="$(readlink "$CURRENT")"
  id="${target##*/}"
  if valid_id "$id" && [[ "$target" == "$RELEASES/$id" ]]; then echo "$id"; else echo unrecognised; fi
}

read_state() {
  local key value
  STATE_ACTIVE=none
  STATE_PREVIOUS=none
  [[ -f "$STATE_FILE" ]] || return 0
  while IFS='=' read -r key value; do
    case "$key" in
      active) STATE_ACTIVE="$value" ;;
      previous) STATE_PREVIOUS="$value" ;;
      updated_at | reason | '') ;;
      *) return 1 ;;
    esac
  done <"$STATE_FILE"
  for value in "$STATE_ACTIVE" "$STATE_PREVIOUS"; do
    [[ "$value" == none ]] || valid_id "$value" || return 1
  done
}

# Written to a temp file and renamed so readers never see a partial state.
write_state() {
  local active="$1" previous="$2" reason="$3" tmp
  tmp="$(mktemp "$STATE/.active.XXXXXX")" || return 1
  if ! printf 'active=%s\nprevious=%s\nupdated_at=%s\nreason=%s\n' "$active" "$previous" "$(utc)" "$reason" >"$tmp" ||
    ! mv -f "$tmp" "$STATE_FILE"; then
    rm -f "$tmp"
    return 1
  fi
  STATE_ACTIVE="$active"
  STATE_PREVIOUS="$previous"
}

ensure_layout() {
  [[ -d "$RELEASES" && -d "$SHARED" ]] || fail "$EXIT_BEFORE_ACTIVATION" "unexpected layout: $RELEASES or $SHARED missing"
  install -d -m 0750 "$STATE" "$FAILED"
}

acquire_lock() {
  local key value pid=""
  if mkdir "$LOCK" 2>/dev/null; then
    LOCK_HELD=1
    printf 'pid=%s\naction=%s\nstarted_at=%s\n' "$$" "$ACTION" "$(utc)" >"$LOCK/owner"
    return 0
  fi
  if [[ -f "$LOCK/owner" ]]; then
    while IFS='=' read -r key value; do
      if [[ "$key" == pid ]]; then pid="$value"; fi
    done <"$LOCK/owner"
  fi
  if [[ "$pid" =~ ^[0-9]+$ ]] && { kill -0 "$pid" 2>/dev/null || ps -p "$pid" >/dev/null 2>&1; }; then
    fail "$EXIT_LOCKED" "another release or rollback is running (pid $pid); nothing changed"
  fi
  fail "$EXIT_LOCKED" "stale lock $LOCK (pid ${pid:-unknown} is not running). Check that no release or rollback is in progress, inspect 'deploy.sh status', then remove it: rm -r $LOCK"
}

release_lock() {
  if [[ "$LOCK_HELD" -eq 1 ]]; then
    rm -rf "$LOCK"
    LOCK_HELD=0
  fi
}

# Moves a release directory to failed/. Never moves the current, active or recorded previous release.
isolate() {
  local id="$1" reason="$2" src="$RELEASES/$1" dest
  [[ -e "$src" || -L "$src" ]] || return 0
  if [[ "$id" == "$(current_id)" || "$id" == "$STATE_ACTIVE" || "$id" == "$STATE_PREVIOUS" ]]; then
    warn "not moving $id to failed/: it is the current, active or recorded previous release"
    return 1
  fi
  dest="$FAILED/$id.$(date -u +%Y%m%d%H%M%S).$reason"
  [[ ! -e "$dest" ]] || dest="$dest.$$"
  if ! mv "$src" "$dest"; then
    warn "could not move $src to $dest; it is NOT a rollback target, but inspect and move it manually"
    return 1
  fi
  log "Moved $id to failed/${dest##*/}"
  record "isolated $id reason=$reason"
}

# rename(2) replaces the symlink atomically; GNU `mv -T` is not portable.
switch_current() {
  local id="$1" next="$APP_ROOT/current.next"
  rm -f "$next"
  ln -s "$RELEASES/$id" "$next" || return 1
  if ! perl -e 'rename($ARGV[0], $ARGV[1]) or die "rename failed: $!\n"' "$next" "$CURRENT"; then
    rm -f "$next"
    return 1
  fi
}

restart_services() {
  if ! sudo -n systemctl reload "$PHP_FPM_SERVICE"; then
    warn "PHP-FPM reload failed ($PHP_FPM_SERVICE)"
    return 1
  fi
  if ! systemctl is-active --quiet "$PHP_FPM_SERVICE"; then
    warn "PHP-FPM is not active after reload ($PHP_FPM_SERVICE)"
    return 1
  fi
  if ! sudo -n supervisorctl restart "$SUPERVISOR_GROUP:*"; then
    warn "Supervisor could not restart $SUPERVISOR_GROUP:* (see /var/log/marcaturshub/)"
    return 1
  fi
}

health_check() {
  local attempt=1 code body
  if ! php "$CURRENT/artisan" --version >/dev/null; then
    warn "the active release cannot boot (php artisan --version failed)"
    return 1
  fi
  if [[ -z "$HEALTH_URL" ]]; then
    log "HTTP health check skipped (HEALTH_URL not set)"
    return 0
  fi
  body="$(mktemp "${TMPDIR:-/tmp}/mh-health.XXXXXX")"
  while :; do
    code="$(curl -sS -o "$body" -w '%{http_code}' --max-time 10 ${HEALTH_RESOLVE:+--resolve "$HEALTH_RESOLVE"} "$HEALTH_URL" 2>/dev/null || true)"
    if [[ "$code" == 200 ]] && grep -Eq '"success"[[:space:]]*:[[:space:]]*true' "$body"; then
      rm -f "$body"
      log "Health check passed (HTTP 200)"
      return 0
    fi
    log "Health check attempt $attempt/$HEALTH_ATTEMPTS: HTTP ${code:-none}"
    if ((attempt >= HEALTH_ATTEMPTS)); then break; fi
    attempt=$((attempt + 1))
    sleep "$HEALTH_DELAY"
  done
  rm -f "$body"
  warn "health check failed: $HEALTH_URL"
  return 1
}

# Laravel exits non-zero from migrate:status for an empty database; only that exact case is benign.
migration_preflight() {
  local out rc=0
  out="$(php artisan migrate:status --pending 2>&1)" || rc=$?
  if [[ "$rc" -eq 0 ]]; then
    log "Pending migrations:"
    printf '%s\n' "$out"
    return 0
  fi
  if [[ "$out" == *"Migration table not found"* ]]; then
    if [[ "$(current_id)" != none ]]; then
      printf '%s\n' "$out" >&2
      fail "$EXIT_BEFORE_ACTIVATION" "the database has no migrations table but a release is already active. Refusing to migrate: check DB_* in shared/.env points at the production database"
    fi
    log "No migrations table yet: this database has never been migrated (expected only for the first release). All migrations will run."
    return 0
  fi
  printf '%s\n' "$out" >&2
  fail "$EXIT_BEFORE_ACTIVATION" "php artisan migrate:status failed (output above); migrations were not started"
}

release() {
  [[ $# -eq 2 || $# -eq 3 ]] || usage
  local artifact="$1" id="$2" migrate="${3:-}" dir current
  [[ -z "$migrate" || "$migrate" == "--migrate" ]] || usage
  valid_id "$id" || fail "$EXIT_USAGE" "release id must look like YYYYmmddHHMMSS-<sha>: $id"
  ACTION=release
  ensure_layout
  acquire_lock
  PHASE=validate
  read_state || fail "$EXIT_BEFORE_ACTIVATION" "$STATE_FILE is unreadable or invalid; refusing to guess the active release"
  [[ -f "$artifact" ]] || fail "$EXIT_BEFORE_ACTIVATION" "artifact not found: $artifact"
  [[ -f "$SHARED/.env" ]] || fail "$EXIT_BEFORE_ACTIVATION" "missing $SHARED/.env"
  grep -q '^APP_ENV=production$' "$SHARED/.env" || fail "$EXIT_BEFORE_ACTIVATION" "shared/.env must set APP_ENV=production"
  grep -q '^APP_DEBUG=false$' "$SHARED/.env" || fail "$EXIT_BEFORE_ACTIVATION" "shared/.env must set APP_DEBUG=false"

  current="$(current_id)"
  case "$current" in
    unrecognised) fail "$EXIT_BEFORE_ACTIVATION" "$CURRENT does not point to a release directory; refusing to continue" ;;
    none) PREV_ID=none ;;
    *)
      if is_activated "$current"; then
        PREV_ID="$current"
      elif [[ "$STATE_ACTIVE" != none ]] && is_activated "$STATE_ACTIVE"; then
        warn "current release $current was never verified; rollback target stays $STATE_ACTIVE"
        PREV_ID="$STATE_ACTIVE"
      else
        warn "current release $current was never verified and no earlier verified release is recorded"
        PREV_ID=none
      fi
      ;;
  esac

  dir="$RELEASES/$id"
  if [[ -e "$dir" || -L "$dir" ]]; then
    if [[ "$id" == "$current" || "$id" == "$STATE_ACTIVE" || "$id" == "$STATE_PREVIOUS" ]] || is_activated "$id"; then
      fail "$EXIT_BEFORE_ACTIVATION" "release $id already exists and has been active; build a new artifact instead"
    fi
    log "releases/$id is left over from an attempt that never activated; moving it aside"
    isolate "$id" stale-attempt || fail "$EXIT_BEFORE_ACTIVATION" "could not move the earlier attempt of $id aside"
  fi

  CANDIDATE="$id"
  PHASE=prepare
  log "Unpacking $id"
  mkdir -p "$dir"
  tar -xzf "$artifact" -C "$dir"
  ln -sfn "$SHARED/.env" "$dir/.env"
  rm -rf "$dir/storage"
  ln -sfn "$SHARED/storage" "$dir/storage"
  chmod -R g+w "$dir/bootstrap/cache"

  cd "$dir"
  log "Caching configuration"
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache

  if [[ "$migrate" == "--migrate" ]]; then
    migration_preflight
    log "Running migrations"
    php artisan migrate --force --no-interaction ||
      fail "$EXIT_BEFORE_ACTIVATION" "migrations failed before activation. The database may be partially migrated (MySQL DDL is not transactional) and is NOT restored automatically; see docs/deployment/rollback.md before retrying"
  else
    log "Skipping migrations (pass --migrate to run them)"
  fi

  PHASE=switch
  switch_current "$id" || fail "$EXIT_BEFORE_ACTIVATION" "could not switch current to $id"
  PHASE=verify
  log "Switched current to $id; verifying"
  restart_services || fail "$EXIT_AFTER_ACTIVATION" "service restart failed after switching to $id"
  health_check || fail "$EXIT_AFTER_ACTIVATION" "health check failed after switching to $id"
  utc >"$dir/$MARKER" || fail "$EXIT_AFTER_ACTIVATION" "could not mark $id as activated"
  write_state "$id" "$PREV_ID" release || fail "$EXIT_AFTER_ACTIVATION" "could not record activation of $id in $STATE_FILE"
  record "activated $id previous=$PREV_ID replaced=$current"
  PHASE=complete
  log "Active release: $id (rollback target: $PREV_ID)"
  prune || warn "pruning old releases failed (not fatal)"
}

validate_target() {
  local id="$1" cur="$2" dir="$RELEASES/$1" f
  valid_id "$id" || {
    warn "invalid release id in $STATE_FILE: $id"
    return 1
  }
  [[ "$id" != "$cur" ]] || {
    warn "rollback target $id is already current"
    return 1
  }
  [[ -d "$dir" && ! -L "$dir" ]] || {
    warn "release directory missing: $dir"
    return 1
  }
  [[ -f "$dir/$MARKER" ]] || {
    warn "$id was never activated successfully"
    return 1
  }
  for f in artisan vendor/autoload.php public/index.php bootstrap/cache/config.php .env; do
    [[ -e "$dir/$f" ]] || {
      warn "$id is missing $f"
      return 1
    }
  done
}

rollback() {
  [[ $# -eq 0 ]] || usage
  local cur target
  ACTION=rollback
  ensure_layout
  acquire_lock
  PHASE=validate
  read_state || fail "$EXIT_INVALID_ROLLBACK_TARGET" "$STATE_FILE is unreadable or invalid; refusing to guess a rollback target"
  cur="$(current_id)"
  ROLLBACK_FROM="$cur"
  case "$cur" in
    none) fail "$EXIT_NO_ROLLBACK_TARGET" "no release is active; there is nothing to roll back" ;;
    unrecognised) fail "$EXIT_INVALID_ROLLBACK_TARGET" "$CURRENT does not point to a release directory; refusing to guess" ;;
  esac
  if [[ "$cur" == "$STATE_ACTIVE" ]]; then target="$STATE_PREVIOUS"; else target="$STATE_ACTIVE"; fi
  if [[ "$target" == none ]]; then
    fail "$EXIT_NO_ROLLBACK_TARGET" "no previous successful release is recorded (first release). $cur stays current; automatic code rollback is impossible. Follow the first-release recovery procedure in docs/deployment/rollback.md"
  fi
  CANDIDATE="$target"
  validate_target "$target" "$cur" || fail "$EXIT_INVALID_ROLLBACK_TARGET" "recorded rollback target $target is not usable; nothing switched ($cur stays current)"

  PHASE=switch
  log "Rolling back $cur -> $target (database is NOT rolled back)"
  switch_current "$target" || fail "$EXIT_ROLLBACK_FAILED" "could not switch current to $target; $cur stays current"
  PHASE=verify
  restart_services || fail "$EXIT_ROLLBACK_FAILED" "current now points to $target but services did not restart; manual recovery required"
  health_check || fail "$EXIT_ROLLBACK_FAILED" "current now points to $target but the health check failed; manual recovery required"
  write_state "$target" none "rollback from $cur" || fail "$EXIT_ROLLBACK_FAILED" "rolled back to $target but could not update $STATE_FILE"
  record "rollback $cur -> $target"
  PHASE=complete
  isolate "$cur" rolled-back || warn "rolled-back release $cur stays in releases/ (it is not a rollback target)"
  log "Rollback complete; active release: $target"
}

status() {
  [[ $# -eq 0 ]] || usage
  local cur activated=no target lock=free state=ok
  read_state || {
    state=invalid
    STATE_ACTIVE=unknown
    STATE_PREVIOUS=unknown
  }
  cur="$(current_id)"
  if valid_id "$cur" && is_activated "$cur"; then activated=yes; fi
  if [[ "$state" != ok ]]; then
    target=unknown
  elif [[ "$cur" == "$STATE_ACTIVE" ]]; then
    target="$STATE_PREVIOUS"
  else
    target="$STATE_ACTIVE"
  fi
  if [[ -d "$LOCK" ]]; then lock=held; fi
  printf 'current=%s\ncurrent_activated=%s\nrecorded_active=%s\nrecorded_previous=%s\nrollback_target=%s\nstate=%s\nlock=%s\n' \
    "$cur" "$activated" "$STATE_ACTIVE" "$STATE_PREVIOUS" "$target" "$state" "$lock"
}

# Keeps the KEEP_RELEASES newest releases; never deletes current, active or recorded previous.
prune() {
  local cur n=0 old
  cur="$(current_id)"
  # Directory names are filtered through valid_id, so ls output is safe here.
  # shellcheck disable=SC2012
  ls -1 "$RELEASES" | sort -r | while read -r old; do
    valid_id "$old" || continue
    n=$((n + 1))
    [[ "$n" -gt "$KEEP_RELEASES" ]] || continue
    case "$old" in "$cur" | "$STATE_ACTIVE" | "$STATE_PREVIOUS") continue ;; esac
    log "Pruning release $old"
    rm -rf "${RELEASES:?}/$old"
  done
}

on_exit() {
  local rc=$? now
  set +e
  if [[ "$ACTION" == release && "$rc" -ne 0 && -n "$PHASE" ]]; then
    now="$(current_id)"
    if [[ -n "$CANDIDATE" && "$now" == "$CANDIDATE" ]]; then
      rc=$EXIT_AFTER_ACTIVATION
      printf 'ERROR: release %s is CURRENT but unverified. It was left in place; run "deploy.sh rollback" (first release: follow docs/deployment/rollback.md).\n' "$CANDIDATE" >&2
      record "release-unverified $CANDIDATE previous=$PREV_ID"
    else
      rc=$EXIT_BEFORE_ACTIVATION
      rm -f "$APP_ROOT/current.next"
      if [[ -n "$CANDIDATE" ]]; then
        isolate "$CANDIDATE" failed
        record "release-failed $CANDIDATE phase=$PHASE"
      fi
      printf 'ERROR: release failed before activation; current release (%s) unchanged.\n' "$now" >&2
    fi
  elif [[ "$ACTION" == rollback && "$rc" -ne 0 && -n "$PHASE" ]]; then
    case "$rc" in
      "$EXIT_NO_ROLLBACK_TARGET" | "$EXIT_INVALID_ROLLBACK_TARGET" | "$EXIT_ROLLBACK_FAILED") ;;
      *) if [[ "$PHASE" == validate ]]; then rc=$EXIT_INVALID_ROLLBACK_TARGET; else rc=$EXIT_ROLLBACK_FAILED; fi ;;
    esac
    rm -f "$APP_ROOT/current.next"
    record "rollback-failed from=$ROLLBACK_FROM target=${CANDIDATE:-none} rc=$rc"
  fi
  release_lock
  if [[ "$ACTION" == release || "$ACTION" == rollback ]]; then
    printf 'DEPLOY_RESULT action=%s rc=%s release=%s current=%s\n' "$ACTION" "$rc" "${CANDIDATE:--}" "$(current_id)"
  fi
  exit "$rc"
}

trap on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

case "${1:-}" in
  release)
    shift
    release "$@"
    ;;
  rollback)
    shift
    rollback "$@"
    ;;
  status)
    shift
    status "$@"
    ;;
  *) usage ;;
esac
