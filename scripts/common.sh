#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ensure_docker_ready() {
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
}

resolve_env_file() {
  local mode="${1:-development}"

  if [[ "${mode}" == "development" && -f "${APP_DIR}/.env.development" ]]; then
    printf '%s\n' "${APP_DIR}/.env.development"
    return 0
  fi

  if [[ "${mode}" == "production" && -f "${APP_DIR}/.env.production" ]]; then
    printf '%s\n' "${APP_DIR}/.env.production"
    return 0
  fi

  if [[ -f "${APP_DIR}/.env" ]]; then
    printf '%s\n' "${APP_DIR}/.env"
    return 0
  fi

  if [[ -f "${APP_DIR}/.env.example" ]]; then
    cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    echo "Se creo ${APP_DIR}/.env desde .env.example. Ajusta secretos y DB si hace falta."
    printf '%s\n' "${APP_DIR}/.env"
    return 0
  fi

  echo "No se encontro .env, .env.development, .env.production ni .env.example" >&2
  exit 1
}

compose_cmd() {
  local env_file="$1"
  shift

  (
    cd "${APP_DIR}"
    docker compose --env-file "${env_file}" "$@"
  )
}

deploy_backend() {
  local mode="${1:-development}"
  local env_file

  ensure_docker_ready
  env_file="$(resolve_env_file "${mode}")"

  echo "Levantando backend Paramascotasec en ${mode} usando ${env_file}..."
  (
    cd "${APP_DIR}"
    APP_ENV="${mode}" docker compose --env-file "${env_file}" up -d --build --remove-orphans
  )
  compose_cmd "${env_file}" ps
  echo "Backend Paramascotasec ${mode} listo"
}
