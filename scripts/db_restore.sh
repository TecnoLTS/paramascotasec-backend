#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IN_FILE="${IN_FILE:-$ROOT_DIR/db/backup.sql}"

DB_NAME="${DB_DATABASE:-paramascotasec}"
DB_USER="${DB_USERNAME:-postgres}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_PASS="${DB_PASSWORD:-}"
DB_CONTAINER="${DB_DOCKER_CONTAINER:-}"

if [[ ! -f "$IN_FILE" ]]; then
  echo "Error: backup file not found at $IN_FILE" >&2
  exit 1
fi

if [[ -n "$DB_CONTAINER" ]]; then
  docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$IN_FILE"
  exit 0
fi

if command -v psql >/dev/null 2>&1; then
  PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$IN_FILE"
  exit 0
fi

echo "Error: psql not found. Set DB_DOCKER_CONTAINER or install PostgreSQL client tools." >&2
exit 1
