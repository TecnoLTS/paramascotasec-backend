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

Si necesitas preparar base de datos e instalar dependencias:

```bash
RUN_COMPOSER_INSTALL=1 RUN_DB_SETUP=1 ./scripts/deploy-development.sh
RUN_COMPOSER_INSTALL=1 RUN_DB_SETUP=1 ./scripts/deploy-production.sh
```

Nota:

- `RUN_DB_SETUP` es el nombre recomendado porque describe mejor que se prepara la base de datos.
- `RUN_DB_BOOTSTRAP` sigue funcionando por compatibilidad, pero ya no es el nombre preferido.

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


---------------------------------------------------------------------------------------------------------------------------
Lista nueva de despliegues

Workspace completo

cd /home/admincenter/contenedores
./scripts/deploy-workspace.sh development
./scripts/deploy-workspace.sh production
paramascotasec

cd /home/admincenter/contenedores/paramascotasec
./scripts/deploy-development.sh
./scripts/deploy-production.sh
paramascotasec-backend

cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy-development.sh
./scripts/deploy-production.sh
Si necesitas instalar dependencias PHP y preparar base de datos:

cd /home/admincenter/contenedores/paramascotasec-backend
RUN_COMPOSER_INSTALL=1 RUN_DB_SETUP=1 ./scripts/deploy-development.sh
RUN_COMPOSER_INSTALL=1 RUN_DB_SETUP=1 ./scripts/deploy-production.sh
paramascostas-DB

cd /home/admincenter/contenedores/paramascostas-DB
./scripts/deploy.sh development
./scripts/deploy.sh production
tecnolts

cd /home/admincenter/contenedores/tecnolts
./scripts/deploy-development.sh
./scripts/deploy-production.sh
gateway

cd /home/admincenter/contenedores/gateway
./scripts/setup-ssl-local.sh
./scripts/deploy-gateway-production.sh
./scripts/renew-letsencrypt.sh
Facturador

cd /home/admincenter/contenedores/Facturador
./scripts/deploy.sh
