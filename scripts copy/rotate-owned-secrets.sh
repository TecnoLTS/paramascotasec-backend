#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/admincenter/contenedores"
LOG_DIR="$ROOT/logs"
mkdir -p "$LOG_DIR"
MODE="${1:-auto}"

if [[ "${MODE}" != "auto" && "${MODE}" != "development" && "${MODE}" != "production" ]]; then
  echo "Uso: $0 [auto|development|production]"
  exit 1
fi

resolve_env_file() {
  local dir="$1"
  local mode="$2"

  if [[ "${mode}" == "development" && -f "${dir}/.env.development" ]]; then
    printf '%s\n' "${dir}/.env.development"
    return 0
  fi

  if [[ "${mode}" == "production" && -f "${dir}/.env.production" ]]; then
    printf '%s\n' "${dir}/.env.production"
    return 0
  fi

  printf '%s\n' "${dir}/.env"
}

env_variants_for_dir() {
  local dir="$1"
  local candidates=(
    "${dir}/.env"
    "${dir}/.env.development"
    "${dir}/.env.production"
  )

  for candidate in "${candidates[@]}"; do
    if [[ -f "${candidate}" ]]; then
      printf '%s\n' "${candidate}"
    fi
  done
}

detect_active_mode() {
  if docker ps --format '{{.Names}}' | grep -qx 'paramascotasec-app-dev'; then
    printf '%s\n' "development"
    return 0
  fi

  if docker ps --format '{{.Names}}' | grep -qx 'paramascotasec-app'; then
    printf '%s\n' "production"
    return 0
  fi

  if docker ps --format '{{.Names}}' | grep -qx 'paramascotasec-backend-app'; then
    local backend_env
    backend_env="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' paramascotasec-backend-app 2>/dev/null | awk -F= '/^APP_ENV=/{print $2; exit}')"
    if [[ "${backend_env}" == "development" || "${backend_env}" == "production" ]]; then
      printf '%s\n' "${backend_env}"
      return 0
    fi
  fi

  printf '%s\n' "development"
}

write_env_value_many() {
  local key="$1"
  local value="$2"
  shift 2

  local file
  for file in "$@"; do
    write_env_value "${file}" "${key}" "${value}"
  done
}

EFFECTIVE_MODE="${MODE}"
if [[ "${MODE}" == "auto" ]]; then
  EFFECTIVE_MODE="$(detect_active_mode)"
fi

APP_ENV_FILE="$(resolve_env_file "$ROOT/paramascotasec" "$EFFECTIVE_MODE")"
BACKEND_ENV_FILE="$(resolve_env_file "$ROOT/paramascotasec-backend" "$EFFECTIVE_MODE")"
DB_ENV_FILE="$(resolve_env_file "$ROOT/paramascostas-DB" "$EFFECTIVE_MODE")"
FACT_ENV_FILE="$(resolve_env_file "$ROOT/Facturador" "$EFFECTIVE_MODE")"

mapfile -t APP_ENV_FILES < <(env_variants_for_dir "$ROOT/paramascotasec")
mapfile -t BACKEND_ENV_FILES < <(env_variants_for_dir "$ROOT/paramascotasec-backend")
mapfile -t DB_ENV_FILES < <(env_variants_for_dir "$ROOT/paramascostas-DB")
mapfile -t FACT_ENV_FILES < <(env_variants_for_dir "$ROOT/Facturador")

gen_hex() {
  openssl rand -hex "$1"
}

gen_alnum() {
  python3 - "$1" <<'PY'
import secrets
import string
import sys

length = int(sys.argv[1])
alphabet = string.ascii_letters + string.digits
print(''.join(secrets.choice(alphabet) for _ in range(length)))
PY
}

gen_recovery_code() {
  python3 - <<'PY'
import secrets
print('-'.join(secrets.token_hex(2).upper() for _ in range(3)))
PY
}

read_env_value() {
  local file="$1"
  local key="$2"
  python3 - "$file" "$key" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
key = sys.argv[2]
for line in path.read_text().splitlines():
    if not line.startswith(f"{key}="):
        continue
    print(line.split("=", 1)[1])
    break
PY
}

read_setting_value() {
  local db_user="$1"
  local db_name="$2"
  local password="$3"
  local tenant="$4"
  local setting_key="$5"

  docker exec next-test-db env PGPASSWORD="$password" \
    psql -h 127.0.0.1 -U "$db_user" -d "$db_name" -At -v ON_ERROR_STOP=1 \
    -v setting_key="${tenant}:${setting_key}" \
    -c "SELECT COALESCE((SELECT value FROM \"Setting\" WHERE key = :'setting_key' LIMIT 1), '')" 2>/dev/null || true
}

write_setting_value() {
  local db_user="$1"
  local db_name="$2"
  local password="$3"
  local tenant="$4"
  local setting_key="$5"
  local setting_value="$6"

  docker exec next-test-db env PGPASSWORD="$password" \
    psql -h 127.0.0.1 -U "$db_user" -d "$db_name" -v ON_ERROR_STOP=1 \
    -v tenant_id="$tenant" \
    -v setting_key="${tenant}:${setting_key}" \
    -v setting_value="$setting_value" <<'SQL' >/dev/null
INSERT INTO "Setting" (key, value, tenant_id)
VALUES (:'setting_key', :'setting_value', :'tenant_id')
ON CONFLICT (key)
DO UPDATE SET value = EXCLUDED.value, tenant_id = EXCLUDED.tenant_id;
SQL
}

write_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  python3 - "$file" "$key" "$value" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
key = sys.argv[2]
value = sys.argv[3]
lines = path.read_text().splitlines()
for index, line in enumerate(lines):
    if line.startswith(f"{key}="):
        lines[index] = f"{key}={value}"
        break
else:
    lines.append(f"{key}={value}")
path.write_text("\n".join(lines) + "\n")
PY
}

CURRENT_PM_DB_PASS="$(read_env_value "$DB_ENV_FILE" POSTGRES_PASSWORD)"
CURRENT_FACT_DB_USER="$(read_env_value "$FACT_ENV_FILE" DB_USER)"
CURRENT_FACT_DB_NAME="$(read_env_value "$FACT_ENV_FILE" DB_NAME)"
CURRENT_FACT_DB_PASS="$(read_env_value "$FACT_ENV_FILE" DB_PASSWORD)"
CURRENT_JWT_SECRET="$(read_env_value "$BACKEND_ENV_FILE" JWT_SECRET)"
CURRENT_INTERNAL_PROXY_TOKEN="$(read_env_value "$BACKEND_ENV_FILE" INTERNAL_PROXY_TOKEN)"
CURRENT_PM_DB_USER="$(read_env_value "$BACKEND_ENV_FILE" DB_USERNAME)"
CURRENT_PM_DB_NAME="$(read_env_value "$BACKEND_ENV_FILE" DB_DATABASE)"
CURRENT_PM_DB_USER="${CURRENT_PM_DB_USER:-postgres}"
CURRENT_PM_DB_NAME="${CURRENT_PM_DB_NAME:-paramascotasec}"
CURRENT_TENANT="$(read_env_value "$BACKEND_ENV_FILE" DEFAULT_TENANT)"
CURRENT_TENANT="${CURRENT_TENANT:-paramascotasec}"
CURRENT_ADMIN_RECOVERY_CODE="$(read_setting_value "$CURRENT_PM_DB_USER" "$CURRENT_PM_DB_NAME" "$CURRENT_PM_DB_PASS" "$CURRENT_TENANT" "security.admin_mfa_recovery_code.current")"
if [[ -z "${CURRENT_ADMIN_RECOVERY_CODE}" ]]; then
  CURRENT_ADMIN_RECOVERY_CODE="$(read_env_value "$BACKEND_ENV_FILE" ADMIN_MFA_RECOVERY_CODE)"
fi

NEW_JWT_SECRET="$(gen_hex 64)"
NEW_INTERNAL_PROXY_TOKEN="$(gen_hex 32)"
NEW_PM_DB_PASS="$(gen_alnum 40)"
NEW_FACT_DB_PASS="$(gen_alnum 32)"
NEW_ADMIN_RECOVERY_CODE="$(gen_recovery_code)"

echo "[1/6] Actualizando .env internos (${EFFECTIVE_MODE})"
write_env_value_many "INTERNAL_PROXY_TOKEN" "$NEW_INTERNAL_PROXY_TOKEN" "${APP_ENV_FILES[@]}"
write_env_value_many "JWT_SECRET" "$NEW_JWT_SECRET" "${BACKEND_ENV_FILES[@]}"
write_env_value_many "JWT_SECRET_PREVIOUS" "$CURRENT_JWT_SECRET" "${BACKEND_ENV_FILES[@]}"
write_env_value_many "INTERNAL_PROXY_TOKEN" "$NEW_INTERNAL_PROXY_TOKEN" "${BACKEND_ENV_FILES[@]}"
write_env_value_many "INTERNAL_PROXY_TOKEN_PREVIOUS" "$CURRENT_INTERNAL_PROXY_TOKEN" "${BACKEND_ENV_FILES[@]}"
write_env_value_many "DB_PASSWORD" "$NEW_PM_DB_PASS" "${BACKEND_ENV_FILES[@]}"
write_env_value_many "POSTGRES_PASSWORD" "$NEW_PM_DB_PASS" "${DB_ENV_FILES[@]}"
write_env_value_many "DB_PASSWORD" "$NEW_FACT_DB_PASS" "${FACT_ENV_FILES[@]}"

echo "[2/6] Rotando password de Postgres principal"
docker exec next-test-db env PGPASSWORD="$CURRENT_PM_DB_PASS" \
  psql -h 127.0.0.1 -U postgres -d postgres -v ON_ERROR_STOP=1 \
  -c "ALTER USER postgres WITH PASSWORD '$NEW_PM_DB_PASS';"

echo "[3/6] Rotando password de Postgres del facturador"
docker exec billing-postgres env PGPASSWORD="$CURRENT_FACT_DB_PASS" \
  psql -h 127.0.0.1 -U "$CURRENT_FACT_DB_USER" -d "$CURRENT_FACT_DB_NAME" -v ON_ERROR_STOP=1 \
  -c "ALTER USER $CURRENT_FACT_DB_USER WITH PASSWORD '$NEW_FACT_DB_PASS';"

echo "[4/6] Reaplicando contenedores de bases"
(cd "$ROOT/paramascostas-DB" && "./scripts/deploy-${EFFECTIVE_MODE}.sh")
(cd "$ROOT/Facturador" && docker compose up -d --force-recreate postgres)

echo "[5/6] Redeploy de frontend y backend (${EFFECTIVE_MODE})"
(cd "$ROOT/paramascotasec" && "./scripts/deploy-${EFFECTIVE_MODE}.sh")
(cd "$ROOT/paramascotasec-backend" && "./scripts/deploy-${EFFECTIVE_MODE}.sh")

echo "[6/6] Persistiendo codigo de recuperacion en DB y reaplicando servicios del facturador"
write_setting_value "$CURRENT_PM_DB_USER" "$CURRENT_PM_DB_NAME" "$NEW_PM_DB_PASS" "$CURRENT_TENANT" "security.admin_mfa_recovery_code.current" "$NEW_ADMIN_RECOVERY_CODE"
write_setting_value "$CURRENT_PM_DB_USER" "$CURRENT_PM_DB_NAME" "$NEW_PM_DB_PASS" "$CURRENT_TENANT" "security.admin_mfa_recovery_code.previous" "$CURRENT_ADMIN_RECOVERY_CODE"
(cd "$ROOT/Facturador" && docker compose up -d --force-recreate billing-service billing-recovery-worker nginx)

echo
echo "Rotación completada."
echo "- Modo aplicado: ${EFFECTIVE_MODE}"
echo "- JWT_SECRET: rotado"
echo "- INTERNAL_PROXY_TOKEN: rotado"
echo "- ADMIN_MFA_RECOVERY_CODE: rotado y guardado en DB"
echo "- DB principal: rotada"
echo "- DB facturador: rotada"
echo
echo "Nota: por solape de secreto anterior, las sesiones vigentes no deberian caerse inmediatamente."
