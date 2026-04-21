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

upsert_env_value() {
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

resolve_env_file() {
  local mode="${1:-development}"

  if [[ "${mode}" == "development" ]]; then
    local env_file="${APP_DIR}/.env.development"
    if [[ ! -f "${env_file}" ]]; then
      if [[ -f "${APP_DIR}/.env.development.example" ]]; then
        cp "${APP_DIR}/.env.development.example" "${env_file}"
        echo "Se creo ${env_file} desde .env.development.example."
      elif [[ -f "${APP_DIR}/.env" ]]; then
        cp "${APP_DIR}/.env" "${env_file}"
        echo "Se creo ${env_file} desde .env para separar desarrollo de produccion."
      elif [[ -f "${APP_DIR}/.env.example" ]]; then
        cp "${APP_DIR}/.env.example" "${env_file}"
        echo "Se creo ${env_file} desde .env.example."
      else
        echo "No se encontro .env, .env.development.example ni .env.example" >&2
        exit 1
      fi
    fi

    upsert_env_value "${env_file}" "APP_ENV" "development"
    upsert_env_value "${env_file}" "APP_URL" "http://localhost:8080"
    upsert_env_value "${env_file}" "ADMIN_IP_MODE" "off"
    upsert_env_value "${env_file}" "ADMIN_IP_ALLOWLIST" ""

    printf '%s\n' "${env_file}"
    return 0
  fi

  if [[ "${mode}" == "production" && -f "${APP_DIR}/.env.production" ]]; then
    upsert_env_value "${APP_DIR}/.env.production" "APP_ENV" "production"
    printf '%s\n' "${APP_DIR}/.env.production"
    return 0
  fi

  if [[ -f "${APP_DIR}/.env" ]]; then
    upsert_env_value "${APP_DIR}/.env" "APP_ENV" "production"
    printf '%s\n' "${APP_DIR}/.env"
    return 0
  fi

  if [[ -f "${APP_DIR}/.env.example" ]]; then
    cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    echo "Se creo ${APP_DIR}/.env desde .env.example. Ajusta secretos y DB si hace falta."
    upsert_env_value "${APP_DIR}/.env" "APP_ENV" "production"
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

assert_backend_mode() {
  local mode="${1:-development}"
  local container_env

  container_env="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' paramascotasec-backend-app 2>/dev/null | awk -F= '/^APP_ENV=/{print $2; exit}')"
  if [[ "${container_env}" != "${mode}" ]]; then
    echo "El backend quedo levantado con APP_ENV=${container_env:-desconocido}, esperado ${mode}" >&2
    exit 1
  fi
}

wait_for_container_state() {
  local container_name="$1"
  local max_attempts="${2:-90}"
  local attempt=1
  local status

  while (( attempt <= max_attempts )); do
    status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${container_name}" 2>/dev/null || true)"
    if [[ "${status}" == "healthy" || "${status}" == "running" ]]; then
      return 0
    fi

    if [[ "${status}" == "unhealthy" || "${status}" == "exited" || "${status}" == "dead" ]]; then
      echo "El contenedor ${container_name} quedo en estado ${status}" >&2
      docker logs --tail 80 "${container_name}" >&2 || true
      exit 1
    fi

    sleep 2
    ((attempt++))
  done

  echo "El contenedor ${container_name} no quedo listo a tiempo" >&2
  docker logs --tail 80 "${container_name}" >&2 || true
  exit 1
}

deploy_backend() {
  local mode="${1:-development}"
  local env_file
  local run_db_setup="${RUN_DB_SETUP:-${RUN_DB_BOOTSTRAP:-1}}"

  ensure_docker_ready
  env_file="$(resolve_env_file "${mode}")"

  echo "Levantando backend Paramascotasec en ${mode} usando ${env_file}..."
  (
    cd "${APP_DIR}"
    APP_ENV="${mode}" RUN_DB_SETUP="${run_db_setup}" RUN_DB_BOOTSTRAP="${run_db_setup}" docker compose --env-file "${env_file}" up -d --build --force-recreate --remove-orphans app web
  )
  wait_for_container_state paramascotasec-backend-app
  wait_for_container_state paramascotasec-backend-web
  assert_backend_mode "${mode}"
  compose_cmd "${env_file}" ps
  echo "Backend Paramascotasec ${mode} listo"
}
