#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-development}"
if [[ "${MODE}" != "development" && "${MODE}" != "production" ]]; then
  echo "Uso: $0 [development|production]"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker no esta instalado"
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "docker compose no esta disponible"
  exit 1
fi

if ! docker network inspect edge >/dev/null 2>&1; then
  docker network create edge >/dev/null
fi

echo "Levantando Facturador..."
(
  cd "${WORKSPACE_DIR}/Facturador"
  "./scripts/deploy-${MODE}.sh"
)

echo "Levantando base de datos..."
(
  cd "${WORKSPACE_DIR}/paramascostas-DB"
  "./scripts/deploy-${MODE}.sh"
)

echo "Levantando backend..."
(
  cd "${WORKSPACE_DIR}/paramascotasec-backend"
  "./scripts/deploy-${MODE}.sh"
)

echo "Levantando frontend Paramascotasec..."
(
  cd "${WORKSPACE_DIR}/paramascotasec"
  "./scripts/deploy-${MODE}.sh"
)

echo "Levantando frontend TecnoLTS..."
(
  cd "${WORKSPACE_DIR}/tecnolts"
  "./scripts/deploy-${MODE}.sh"
)

echo "Levantando gateway..."
(
  cd "${WORKSPACE_DIR}/gateway"
  "./scripts/deploy-${MODE}.sh"
)

echo
echo "Workspace ${MODE} listo"
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
echo
echo "Nota: certbot solo se ejecuta en produccion con el gateway."
