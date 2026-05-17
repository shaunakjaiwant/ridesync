#!/usr/bin/env sh
set -eu

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 path/to/backup.sql.gz" >&2
  exit 1
fi

: "${RIDESYNC_DB_HOST:=127.0.0.1}"
: "${RIDESYNC_DB_PORT:=3306}"
: "${RIDESYNC_DB_NAME:=ridesync_db}"
: "${RIDESYNC_DB_USER:=ridesync_app}"

if [ -z "${RIDESYNC_DB_PASSWORD:-}" ]; then
  echo "RIDESYNC_DB_PASSWORD is required" >&2
  exit 1
fi

backup="$1"
if [ ! -f "$backup" ]; then
  echo "Backup file does not exist: $backup" >&2
  exit 1
fi

if [ -f "$backup.sha256" ]; then
  sha256sum -c "$backup.sha256"
fi

gzip -dc "$backup" | mysql \
  --host="$RIDESYNC_DB_HOST" \
  --port="$RIDESYNC_DB_PORT" \
  --user="$RIDESYNC_DB_USER" \
  --password="$RIDESYNC_DB_PASSWORD" \
  "$RIDESYNC_DB_NAME"

echo "Restore completed into $RIDESYNC_DB_NAME"
