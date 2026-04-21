#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-development}"
ROOT="/home/admincenter/contenedores"
ENV_FILE="$ROOT/paramascotasec-backend/.env"

if [[ "${MODE}" == "development" && -f "$ROOT/paramascotasec-backend/.env.development" ]]; then
  ENV_FILE="$ROOT/paramascotasec-backend/.env.development"
elif [[ "${MODE}" == "production" && -f "$ROOT/paramascotasec-backend/.env.production" ]]; then
  ENV_FILE="$ROOT/paramascotasec-backend/.env.production"
fi

read_env_value() {
  local file="$1"
  local key="$2"
  python3 - "$file" "$key" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
key = sys.argv[2]
for line in path.read_text().splitlines():
    if "=" not in line or line.lstrip().startswith("#"):
        continue
    candidate, value = line.split("=", 1)
    if candidate.strip() == key:
        print(value.strip())
        break
PY
}

DB_PASSWORD="$(read_env_value "$ENV_FILE" DB_PASSWORD)"
DB_USER="$(read_env_value "$ENV_FILE" DB_USERNAME)"
DB_NAME="$(read_env_value "$ENV_FILE" DB_DATABASE)"
DEFAULT_TENANT="$(read_env_value "$ENV_FILE" DEFAULT_TENANT)"
DB_USER="${DB_USER:-postgres}"
DB_NAME="${DB_NAME:-paramascotasec}"
DEFAULT_TENANT="${DEFAULT_TENANT:-paramascotasec}"

docker exec next-test-db env PGPASSWORD="$DB_PASSWORD" \
  psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -At -v ON_ERROR_STOP=1 \
  -v current_key="${DEFAULT_TENANT}:security.admin_mfa_recovery_code.current" \
  -v previous_key="${DEFAULT_TENANT}:security.admin_mfa_recovery_code.previous" <<'SQL'
SELECT 'ADMIN_MFA_RECOVERY_CODE=' || COALESCE((SELECT value FROM "Setting" WHERE key = :'current_key' LIMIT 1), '');
SELECT 'ADMIN_MFA_RECOVERY_CODE_PREVIOUS=' || COALESCE((SELECT value FROM "Setting" WHERE key = :'previous_key' LIMIT 1), '');
SQL
