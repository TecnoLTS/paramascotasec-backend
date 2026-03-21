#!/usr/bin/env bash
set -euo pipefail

# Utilidad legacy del backend compartido.
# No forma parte del flujo normal de Paramascotasec y puede ignorarse
# si solo trabajas con el stack principal actual.

DB_CONTAINER="${DB_DOCKER_CONTAINER:-next-test-db}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USERNAME:-postgres}"
DB_PASS="${DB_PASSWORD:-postgres}"
BASE_DB="${BASE_DB:-paramascotasec}"
TENANT_DBS="${TENANT_DBS:-paramascotasec tecnolts}"

tmp_schema="$(mktemp)"
cleanup() { rm -f "$tmp_schema"; }
trap cleanup EXIT

echo "Generando schema desde DB base: $BASE_DB"
if [[ -n "$DB_CONTAINER" ]]; then
  docker exec -i "$DB_CONTAINER" pg_dump -U "$DB_USER" -d "$BASE_DB" --schema-only > "$tmp_schema"
else
  PGPASSWORD="$DB_PASS" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$BASE_DB" --schema-only > "$tmp_schema"
fi

for db in $TENANT_DBS; do
  if [[ "$db" == "$BASE_DB" ]]; then
    echo "Saltando DB base: $db"
    continue
  fi

  echo "Creando DB: $db (si no existe)"
  if [[ -n "$DB_CONTAINER" ]]; then
    docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" -tc "SELECT 1 FROM pg_database WHERE datname = '$db';" | grep -q 1 \
      || docker exec -i "$DB_CONTAINER" createdb -U "$DB_USER" "$db"
    docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" -d "$db" < "$tmp_schema"
  else
    PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -tc "SELECT 1 FROM pg_database WHERE datname = '$db';" | grep -q 1 \
      || PGPASSWORD="$DB_PASS" createdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$db"
    PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$db" < "$tmp_schema"
  fi

  echo "DB $db lista."
done

echo "OK: bases de datos por tenant creadas."
