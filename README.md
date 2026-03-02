# Backend API (`paramascotasec-backend`)

Backend PHP-FPM + Nginx para `paramascotasec`, con soporte multi-tenant por dominio.

## Requisitos
- Docker
- Docker Compose

## Variables de entorno
1. Copia `.env.example` a `.env`.
2. Ajusta credenciales reales (DB, JWT, SMTP).
3. No subas `.env` al repositorio.

## Despliegue
```bash
docker compose up -d --build
```

El contenedor `app` ejecuta automáticamente:
- `scripts/bootstrap_schema.php` (idempotente, prepara esquema/columnas/indexes).
- `php-fpm`.

Si quieres desactivar bootstrap de esquema:
```bash
RUN_DB_BOOTSTRAP=0 docker compose up -d --build
```

## Token de servicio (SSR)
Para llamadas server-side desde el frontend:
```bash
docker exec -it paramascotasec-backend-app php /var/www/html/scripts/generate_service_token.php
```

El token incluye `tenant_id` usando `DEFAULT_TENANT` (por defecto `paramascotasec`).

## Seguridad
- Errores `5xx` no exponen detalles internos en producción.
- JWT requiere `JWT_SECRET` explícito.
- CORS/tenant resuelven host real y soportan entorno local por IP.
- Nginx aplica headers de seguridad y bloquea archivos ocultos.

## Endpoints básicos
- `GET /api/health`
- `GET /api/products`
- `POST /api/auth/login`

## Descuentos registrados (admin)
- `GET /api/admin/discounts`
- `POST /api/admin/discounts`
- `PUT /api/admin/discounts/{id}`
- `PATCH /api/admin/discounts/{id}/status`
- `GET /api/admin/discounts/audit`

Checkout/cotización:
- `POST /api/orders/quote` acepta opcional `coupon_code` o `discount_code`.
- `POST /api/orders` acepta opcional `coupon_code` o `discount_code`.
