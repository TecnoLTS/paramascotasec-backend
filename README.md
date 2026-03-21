# Backend `paramascotasec-backend`

API PHP-FPM + Nginx para ParaMascotas.

## Flujo normal

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

Si necesitas bootstrap:

```bash
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-development.sh
RUN_COMPOSER_INSTALL=1 RUN_DB_BOOTSTRAP=1 ./scripts/deploy-production.sh
```

## Puntos claros para editar

- Configuracion de tenants compartidos:
  [tenants.php](/home/admincenter/contenedores/paramascotasec-backend/config/tenants.php)
- Reglas de productos:
  [ProductController.php](/home/admincenter/contenedores/paramascotasec-backend/src/Controllers/ProductController.php)
  y [ProductRepository.php](/home/admincenter/contenedores/paramascotasec-backend/src/Repositories/ProductRepository.php)
- Pedidos:
  [OrderController.php](/home/admincenter/contenedores/paramascotasec-backend/src/Controllers/OrderController.php)
  y [OrderRepository.php](/home/admincenter/contenedores/paramascotasec-backend/src/Repositories/OrderRepository.php)

## Scripts

- Los scripts `deploy-*.sh` usan ahora [common.sh](/home/admincenter/contenedores/paramascotasec-backend/scripts/common.sh)
  como unica logica compartida.
- `create_tenant_dbs.sh` y `seed_tenant_baseline.sh` son utilidades legacy del backend compartido.
  No forman parte del flujo diario de Paramascotasec.

## Verificacion

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
docker compose ps
docker compose logs -f app
docker compose logs -f web
php -l config/tenants.php
```
