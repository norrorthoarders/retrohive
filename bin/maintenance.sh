#!/usr/bin/env bash
#
# RetroHive housekeeping: the jobs that keep tables and directories from growing
# without bound.
#
#   ./bin/maintenance.sh              prune the sign-in log, report orphaned files
#   ./bin/maintenance.sh --delete     also delete the orphaned files
#   ./bin/maintenance.sh --days 90    keep 90 days of sign-in log instead of 30
#
# Suggested cron entry:
#   15 4 * * * /opt/retrohive/bin/maintenance.sh >> /var/log/retrohive-maintenance.log 2>&1
#
# This exists because the pruning was reachable and never reached.
# throttle_prune() has been there since rate limiting landed, exposed as
# `php bin/migrate.php prune`, and nothing scheduled it - so auth_log grew one row
# per sign-in attempt for ever on every install. A function nobody calls is not a
# feature, and the fix is a line in a crontab, not more code.
#
# Reporting orphans rather than deleting them by default is deliberate: a file
# nothing points at is usually a deleted entry, and occasionally a restore that
# has not finished. The second case wants looking at, not tidying away.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

KEEP_DAYS=30
DELETE_ORPHANS=0

while [ $# -gt 0 ]; do
  case "$1" in
    --days)     KEEP_DAYS="${2:?--days needs a number}"; shift 2 ;;
    --delete)   DELETE_ORPHANS=1; shift ;;
    -h|--help)  sed -n '2,22p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)          echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

case "$KEEP_DAYS" in
  ''|*[!0-9]*) echo "--days needs a whole number of days" >&2; exit 1 ;;
esac

command -v php >/dev/null 2>&1 || { echo "php is not installed." >&2; exit 1; }
[ -f src/config.local.php ] || { echo "No src/config.local.php; nothing to connect to." >&2; exit 1; }

echo "== $(date '+%Y-%m-%d %H:%M:%S') RetroHive maintenance =="

# Sign-in log. Keeps the throttle's evidence for as long as it is useful for
# answering "who has been trying", and no longer.
php bin/migrate.php prune "$KEEP_DAYS"

# Queued mail that has already been sent or has permanently failed.
if php bin/notify.php status >/dev/null 2>&1; then
  php bin/notify.php status
fi

# Photos on disk that nothing in the database points at.
if [ "$DELETE_ORPHANS" -eq 1 ]; then
  php bin/cleanup-uploads.php --delete
else
  php bin/cleanup-uploads.php
fi

echo "== done =="
