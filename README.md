# Backend API (`paramascotasec-backend`)

Backend PHP-FPM + Nginx para `paramascotasec`.

## Comandos exactos

Desarrollo:

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy-development.sh
```

Produccion:

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy-production.sh
```

Desarrollo con bootstrap:

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-development.sh
```

Produccion con bootstrap:

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-production.sh
```

## Requisitos
- Docker
- Docker Compose
- Red Docker `edge` creada:

```bash
docker network create edge || true
```

## Variables de entorno
1. Copia `.env.example` a `.env`.
2. Ajusta DB, JWT, SMTP y `APP_URL` segun ambiente.
3. No subir `.env` al repositorio.

Variables relevantes:
- `APP_ENV=development|production`
- `RUN_COMPOSER_INSTALL=0|1`
- `RUN_DB_BOOTSTRAP=0|1`

## Regla simple
- Cambio persistente por archivo:
  - `.env` o `.env.production` para produccion.
  - `.env.development` para desarrollo si quieres dejarlo fijo.
- Cambio por comando:
  - `./scripts/deploy-development.sh`
  - `./scripts/deploy-production.sh`
- Los scripts fuerzan `APP_ENV` correcto y usan `--remove-orphans`.

## Despliegue en desarrollo
Desde `/home/admincenter/contenedores/paramascotasec-backend`:

```bash
./scripts/deploy-development.sh
```

Si necesitas bootstrap en desarrollo:

```bash
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-development.sh
```

## Despliegue en produccion
Desde `/home/admincenter/contenedores/paramascotasec-backend`:

```bash
./scripts/deploy-production.sh
```

Si necesitas bootstrap en produccion:

```bash
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-production.sh
```

## Verificacion
```bash
docker compose ps
docker compose logs -f app
docker compose logs -f web
curl -s http://127.0.0.1:8080/api/health
```

## Token de servicio (SSR)
Para llamadas server-side desde el frontend:

```bash
docker exec -it paramascotasec-backend-app php /var/www/html/scripts/generate_service_token.php
```

## Carga de inventario demo
Para normalizar inventario y fechas de vencimiento de productos perecederos:

```bash
docker exec -it paramascotasec-backend-app php /var/www/html/scripts/seed_inventory_details.php
```




Workspace completo en /home/admincenter/contenedores:

cd /home/admincenter/contenedores
./scripts/deploy-workspace.sh development
./scripts/deploy-workspace.sh production
paramascotasec:

cd /home/admincenter/contenedores/paramascotasec
./scripts/deploy-development.sh
./scripts/deploy-production.sh
paramascotasec-backend:

cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy-development.sh
./scripts/deploy-production.sh
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-development.sh
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-production.sh
tecnolts:

cd /home/admincenter/contenedores/tecnolts
./scripts/deploy-development.sh
./scripts/deploy-production.sh
gateway:

cd /home/admincenter/contenedores/gateway
./scripts/setup-ssl-local.sh
./scripts/deploy-gateway-production.sh
./scripts/renew-letsencrypt.sh


