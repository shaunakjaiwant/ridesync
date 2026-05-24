#!/usr/bin/env sh
set -eu

: "${RIDESYNC_DB_HOST:=127.0.0.1}"
: "${RIDESYNC_DB_PORT:=3306}"
: "${RIDESYNC_DB_NAME:=ridesync_db}"
: "${RIDESYNC_DB_USER:=ridesync_app}"
: "${RIDESYNC_BACKUP_DIR:=./storage/backups}"

if [ -z "${RIDESYNC_DB_PASSWORD:-}" ]; then
  echo "RIDESYNC_DB_PASSWORD is required" >&2
  exit 1
fi

mkdir -p "$RIDESYNC_BACKUP_DIR"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$RIDESYNC_BACKUP_DIR/${RIDESYNC_DB_NAME}_${timestamp}.sql.gz"

mysqldump \
  --host="$RIDESYNC_DB_HOST" \
  --port="$RIDESYNC_DB_PORT" \
  --user="$RIDESYNC_DB_USER" \
  --password="$RIDESYNC_DB_PASSWORD" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  "$RIDESYNC_DB_NAME" | gzip -9 > "$target"

sha256sum "$target" > "$target.sha256"
echo "$target"
