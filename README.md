# Backend API (`paramascotasec-backend`)

Backend PHP-FPM + Nginx para `paramascotasec`.

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

## Despliegue en desarrollo
Desde `/home/admincenter/contenedores/paramascotasec-backend`:

```bash
APP_ENV=development RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 docker compose up -d --build
```

Siguientes arranques (sin bootstrap):

```bash
APP_ENV=development docker compose up -d
```

## Despliegue en produccion
Desde `/home/admincenter/contenedores/paramascotasec-backend`:

```bash
APP_ENV=production RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 docker compose up -d --build
```

Siguientes arranques (sin bootstrap):

```bash
APP_ENV=production docker compose up -d
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
