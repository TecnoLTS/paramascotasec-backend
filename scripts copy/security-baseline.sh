#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/admincenter/contenedores"
APP_DIR="$ROOT/paramascotasec"
BACKEND_DIR="$ROOT/paramascotasec-backend"
DOMAIN="paramascotasec.com"

failures=0

pass() { echo "[OK] $*"; }
warn() { echo "[WARN] $*"; }
fail() { echo "[FAIL] $*"; failures=$((failures + 1)); }

check_not_public_port() {
  local port="$1"
  local name="$2"
  if ss -ltn "( sport = :$port )" | tail -n +2 | awk '{print $4}' | grep -Eq '(^|:)0\.0\.0\.0:'"$port"'$|^\[::\]:'"$port"'$'; then
    fail "$name expuesto publicamente en el puerto $port"
  else
    pass "$name no esta expuesto publicamente en $port"
  fi
}

echo "== Puertos =="
check_not_public_port 3000 "Frontend Next"
check_not_public_port 8080 "Backend web"

echo
echo "== Modos de allowlist =="
panel_mode=$(grep -E '^PANEL_IP_MODE=' "$APP_DIR/.env" | cut -d= -f2- || true)
admin_mode=$(grep -E '^ADMIN_IP_MODE=' "$BACKEND_DIR/.env" | cut -d= -f2- || true)
echo "PANEL_IP_MODE=${panel_mode:-<no definido>}"
echo "ADMIN_IP_MODE=${admin_mode:-<no definido>}"
if [[ "${panel_mode:-}" == "off" || -z "${panel_mode:-}" ]]; then
  warn "PANEL_IP_MODE no esta endurecido"
else
  pass "PANEL_IP_MODE activo"
fi
if [[ "${admin_mode:-}" == "off" || -z "${admin_mode:-}" ]]; then
  warn "ADMIN_IP_MODE no esta endurecido"
else
  pass "ADMIN_IP_MODE activo"
fi

echo
echo "== Gateway y CSP =="
home_headers=$(mktemp)
trap 'rm -f "$home_headers"' EXIT
curl -isk -H "Host: $DOMAIN" https://127.0.0.1/ > "$home_headers"
if grep -qi '^content-security-policy:' "$home_headers"; then
  pass "CSP activa presente"
else
  fail "Falta CSP activa"
fi
if grep -qi '^content-security-policy:.*nonce-' "$home_headers"; then
  pass "CSP con nonce activa"
else
  fail "La CSP activa no incluye nonce"
fi
if grep -qi '^content-security-policy-report-only:' "$home_headers"; then
  pass "CSP Report-Only presente"
else
  warn "No hay CSP Report-Only"
fi

echo
echo "== Archivos sensibles y directorios =="
status_env=$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" https://127.0.0.1/.env || true)
status_readme=$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" https://127.0.0.1/README.md || true)
status_uploads=$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" https://127.0.0.1/uploads/ || true)
[[ "$status_env" != "200" ]] && pass "/.env no es accesible" || fail "/.env es accesible"
[[ "$status_readme" != "200" ]] && pass "/README.md no es accesible" || fail "/README.md es accesible"
[[ "$status_uploads" != "200" ]] && pass "/uploads/ no lista directorios" || fail "/uploads/ permite listado"

echo
echo "== Dependencias =="
if [[ -d "$APP_DIR/app/node_modules" ]]; then
  if (cd "$APP_DIR/app" && npm audit --omit=dev --audit-level=high >/tmp/pm_app_audit.log 2>&1); then
    pass "npm audit sin vulnerabilidades high+"
  else
    warn "npm audit detecto hallazgos. Revisa /tmp/pm_app_audit.log"
  fi
else
  warn "node_modules no presente; omito npm audit"
fi

if [[ -f "$BACKEND_DIR/composer.json" ]]; then
  if command -v composer >/dev/null 2>&1; then
    if (cd "$BACKEND_DIR" && composer audit >/tmp/pm_backend_audit.log 2>&1); then
      pass "composer audit sin advisories"
    else
      warn "composer audit detecto hallazgos. Revisa /tmp/pm_backend_audit.log"
    fi
  elif docker ps --format '{{.Names}}' | grep -qx 'paramascotasec-backend-app'; then
    if docker exec paramascotasec-backend-app composer audit >/tmp/pm_backend_audit.log 2>&1; then
      pass "composer audit sin advisories (contenedor)"
    else
      warn "composer audit detecto hallazgos. Revisa /tmp/pm_backend_audit.log"
    fi
  else
    warn "composer no disponible y contenedor backend no encontrado; omito composer audit"
  fi
fi

echo
if [[ "$failures" -gt 0 ]]; then
  echo "Resultado: $failures fallo(s)"
  exit 1
fi

echo "Resultado: baseline de seguridad OK"
