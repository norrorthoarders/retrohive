#!/usr/bin/env bash

# --help, before anything is created.
#
# Without this the argument fell through to the first command that took one -
# mkdir - so `backup.sh --help` printed mkdir's manual, which is a confusing way
# to learn that a script has no help.
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  echo "Usage: backup.sh [directory]"
  echo
  echo "Dumps the database and copies public/uploads into the directory given,"
  echo "or into ./backups when none is. Reads src/config.local.php."
  exit 0
fi

#
# RetroHive backup: a compressed SQL dump plus a tar of the photos.
#
#   ./bin/backup.sh /srv/backups/retrohive
#
# Credentials are read from src/config.local.php, so normally no arguments or
# environment variables are needed beyond the destination directory.
#
# Suggested cron entry:
#   30 3 * * * /opt/retrohive/bin/backup.sh /srv/backups/retrohive >> /var/log/retrohive-backup.log 2>&1

set -euo pipefail

DEST="${1:-./backups}"
KEEP_DAYS="${KEEP_DAYS:-30}"
STAMP="$(date +%Y%m%d-%H%M%S)"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Ask the app itself for its database settings rather than duplicating them.
read_config() {
    php -r '
        define("APP_ROOT", $argv[1]);
        define("BASE_PATH", "");
        require APP_ROOT . "/src/helpers.php";
        $db = config("db");
        printf("%s\n%s\n%s\n%s\n", $db["host"], $db["name"], $db["user"], $db["pass"]);
    ' "$PROJECT_DIR"
}

if ! command -v php >/dev/null 2>&1; then
    echo "php not found on PATH" >&2
    exit 1
fi

mapfile -t CFG < <(read_config)
DB_HOST="${DB_HOST:-${CFG[0]}}"
DB_NAME="${DB_NAME:-${CFG[1]}}"
DB_USER="${DB_USER:-${CFG[2]}}"
DB_PASS="${DB_PASS:-${CFG[3]}}"

mkdir -p "$DEST"

DUMP=mariadb-dump
command -v mariadb-dump >/dev/null 2>&1 || DUMP=mysqldump

echo "==> Dumping database '$DB_NAME' from $DB_HOST"
MYSQL_PWD="$DB_PASS" "$DUMP" \
    --single-transaction --routines --events --default-character-set=utf8mb4 \
    -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
    | gzip -9 > "$DEST/db-$STAMP.sql.gz"

echo "==> Archiving photos"
tar -C "$PROJECT_DIR/public" -czf "$DEST/uploads-$STAMP.tar.gz" uploads

echo "==> Pruning backups older than $KEEP_DAYS days"
find "$DEST" -name 'db-*.sql.gz'      -mtime +"$KEEP_DAYS" -delete
find "$DEST" -name 'uploads-*.tar.gz' -mtime +"$KEEP_DAYS" -delete

echo "==> Done"
ls -lh "$DEST/db-$STAMP.sql.gz" "$DEST/uploads-$STAMP.tar.gz"
