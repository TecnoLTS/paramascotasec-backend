#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT_DIR}/paramascotasec/app"
BACKEND_DIR="${ROOT_DIR}/paramascotasec-backend"

echo "==> Frontend lint"
(
  cd "${FRONTEND_DIR}"
  npm run lint
)

echo "==> Frontend typecheck"
(
  cd "${FRONTEND_DIR}"
  npm run typecheck
)

echo "==> Backend syntax"
find "${BACKEND_DIR}/src" "${BACKEND_DIR}/public" -type f -name '*.php' -print0 \
  | xargs -0 -n1 -P4 php -l >/dev/null

echo "==> Backend health"
curl -fsS http://127.0.0.1:8080/api/health >/dev/null

echo
echo "Paramascotas OK"
