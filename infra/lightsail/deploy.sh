#!/usr/bin/env bash
# MarcatursHub API release / rollback on the Lightsail host (MH-OPS-006).
#
# Layout:
#   /var/www/marcaturshub/releases/<YYYYmmddHHMMSS-sha>/   one directory per release
#   /var/www/marcaturshub/shared/.env                      production secrets (never in Git)
#   /var/www/marcaturshub/shared/storage/                  logs, framework cache
#   /var/www/marcaturshub/current -> releases/<id>         served by Nginx / Supervisor
#
# Usage (as the deploy user, normally via the production GitHub workflow):
#   deploy.sh release <artifact.tar.gz> <release-id> [--migrate]
#   deploy.sh rollback
#
# Migrations run only with --migrate, before the symlink switch, so they must be
# backward compatible with the running release. Never migrate:fresh / db:wipe.
# Rollback switches code only; it never reverses migrations.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/marcaturshub}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
RELEASES="$APP_ROOT/releases"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

activate() {
  local target="$1"
  ln -sfn "$target" "$APP_ROOT/current.next"
  mv -Tf "$APP_ROOT/current.next" "$CURRENT"
  sudo systemctl reload "$PHP_FPM_SERVICE"
  sudo supervisorctl restart 'marcaturshub:*'
  log "Active release: $(basename "$target")"
}

release() {
  local artifact="${1:-}" id="${2:-}" migrate="${3:-}"
  [[ -f "$artifact" ]] || die "artifact not found: $artifact"
  [[ "$id" =~ ^[0-9]{14}-[0-9a-f]{7,40}$ ]] || die "release id must look like YYYYmmddHHMMSS-<sha>"
  [[ -f "$SHARED/.env" ]] || die "missing $SHARED/.env"
  grep -q '^APP_ENV=production$' "$SHARED/.env" || die "shared/.env is not APP_ENV=production"
  grep -q '^APP_DEBUG=false$' "$SHARED/.env" || die "shared/.env must set APP_DEBUG=false"

  local dir="$RELEASES/$id"
  [[ ! -e "$dir" ]] || die "release already exists: $dir"

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
    log "Pending migrations"
    php artisan migrate:status --pending || true
    log "Running migrations (php artisan migrate --force)"
    php artisan migrate --force --no-interaction
  else
    log "Skipping migrations (pass --migrate to run them)"
  fi

  activate "$dir"
  prune
}

current_id() {
  local target
  target="$(readlink "$CURRENT")" || die "no current release"
  basename "$target"
}

rollback() {
  local current previous
  current="$(current_id)"
  # Release directory names are validated against RELEASE_ID_PATTERN, so ls output is safe.
  # shellcheck disable=SC2012
  previous="$(ls -1 "$RELEASES" | sort | awk -v cur="$current" '$0 == cur { print prev; exit } { prev = $0 }')"
  [[ -n "$previous" ]] || die "no release older than $current"
  log "Rolling back $current -> $previous (database is NOT rolled back)"
  activate "$RELEASES/$previous"
}

prune() {
  local current
  current="$(current_id)"
  # shellcheck disable=SC2012
  ls -1 "$RELEASES" | sort -r | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
    [[ "$old" == "$current" ]] && continue
    log "Pruning $old"
    rm -rf "${RELEASES:?}/$old"
  done
}

case "${1:-}" in
  release) shift; release "$@" ;;
  rollback) rollback ;;
  *) die "usage: deploy.sh release <artifact.tar.gz> <release-id> [--migrate] | deploy.sh rollback" ;;
esac
