#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/admincenter/contenedores"
LOG_DIR="$ROOT/logs"
CRON_EXPR="${OWNED_SECRETS_ROTATION_CRON:-17 4 * * 1}"
ROTATION_MODE="${OWNED_SECRETS_ROTATION_MODE:-auto}"

if [[ "${ROTATION_MODE}" != "auto" && "${ROTATION_MODE}" != "development" && "${ROTATION_MODE}" != "production" ]]; then
  echo "OWNED_SECRETS_ROTATION_MODE debe ser auto, development o production"
  exit 1
fi

CRON_CMD="cd ${ROOT} && ./scripts/rotate-owned-secrets.sh ${ROTATION_MODE} >> ${LOG_DIR}/owned-secrets-rotation-${ROTATION_MODE}.log 2>&1"
CRON_LINE="${CRON_EXPR} ${CRON_CMD}"

if ! command -v crontab >/dev/null 2>&1; then
  echo "crontab no esta instalado en el sistema"
  exit 1
fi

mkdir -p "$LOG_DIR"

CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
FILTERED_CRON="$(printf '%s\n' "${CURRENT_CRON}" | grep -Fv "./scripts/rotate-owned-secrets.sh" || true)"

{
  printf '%s\n' "${FILTERED_CRON}"
  printf '%s\n' "${CRON_LINE}"
} | sed '/^[[:space:]]*$/d' | crontab -

echo "Cron de rotacion instalado: ${CRON_EXPR} (${ROTATION_MODE})"
echo "Log: ${LOG_DIR}/owned-secrets-rotation-${ROTATION_MODE}.log"
