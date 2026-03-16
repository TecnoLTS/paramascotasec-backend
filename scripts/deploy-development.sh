#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${APP_DIR}"

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

ENV_FILE=".env"
if [[ -f ".env.development" ]]; then
  ENV_FILE=".env.development"
elif [[ ! -f "${ENV_FILE}" ]]; then
  if [[ -f ".env.example" ]]; then
    cp .env.example "${ENV_FILE}"
    echo "Se creo ${ENV_FILE} desde .env.example. Ajusta secretos y DB si hace falta."
  else
    echo "No se encontro ${ENV_FILE} ni .env.example"
    exit 1
  fi
fi

echo "Levantando backend Paramascotasec en desarrollo usando ${ENV_FILE}..."
APP_ENV=development docker compose --env-file "${ENV_FILE}" up -d --build --remove-orphans

docker compose --env-file "${ENV_FILE}" ps

echo "Backend Paramascotasec desarrollo listo"
