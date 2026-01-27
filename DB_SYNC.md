# DB sync (versionar datos)

Este repo guarda un dump de la BD en `db/backup.sql` para que los datos viajen con Git.

## 1) Configura el hook de Git (solo una vez)

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
git config core.hooksPath .githooks
```

## 2) Configura conexión a la BD (recomendado)

Si usas Docker:

```bash
export DB_DOCKER_CONTAINER=next-test-db
export DB_DATABASE=paramascotasec
export DB_USERNAME=postgres
```

Si usas cliente local:

```bash
export DB_HOST=localhost
export DB_PORT=5432
export DB_DATABASE=paramascotasec
export DB_USERNAME=postgres
export DB_PASSWORD=postgres
```

## 3) Dump manual (si quieres)

```bash
./scripts/db_dump.sh
```

## 4) Restaurar en otro entorno

```bash
./scripts/db_restore.sh
```

Al hacer `git commit`, el hook ejecuta el dump y agrega `db/backup.sql` automáticamente.
