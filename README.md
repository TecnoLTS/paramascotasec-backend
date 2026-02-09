# Backend API for paramascotasec

Este es el backend de la tienda en linea, construido con PHP (FPM) y Nginx, conectándose a la base de datos PostgreSQL existente.

## Requisitos
- Docker
- Docker Compose

## Instalación y Ejecución

1.  **Levantar los contenedores**:
    ```bash
    docker compose up -d --build
    ```

2.  **Instalar dependencias de PHP**:
    ```bash
    docker exec -it paramascotasec-backend-app composer install
    ```

## Estructura del Proyecto

- `public/index.php`: Punto de entrada de la API.
- `src/Core`: Clases esenciales como el Router y la conexión a la Base de Datos.
- `src/Controllers`: Controladores que manejan las solicitudes HTTP.
- `src/Repositories`: Capa de abstracción para el acceso a datos.
- `src/Models`: Entidades de datos (opcional si se usan arreglos).

## Endpoints Disponibles

- `GET /api/products`: Lista todos los productos.
- `GET /api/users`: Lista todos los usuarios.
- `GET /api/health`: Estado de salud de la API.

## Formato de Respuesta Estándar

Todas las respuestas JSON siguen el mismo envelope:

```json
{
  "ok": true,
  "data": {}
}
```

En errores:

```json
{
  "ok": false,
  "error": {
    "message": "Descripción del error",
    "code": "CODIGO_OPCIONAL",
    "details": {}
  }
}
```

## Seguridad (Token requerido)

Todas las rutas bajo `/api` requieren `Authorization: Bearer <token>` excepto:
- `/api/auth/login`
- `/api/auth/register`
- `/api/auth/verify`

Para solicitudes server-side (SSR), define `BACKEND_SERVICE_TOKEN` en el frontend con un JWT válido.
Puedes generarlo con:

```bash
php scripts/generate_service_token.php
```

### Un solo token activo por usuario
Al iniciar sesión se genera un nuevo token y se guarda como `active_token_id` en la tabla `User`.
Si el mismo usuario inicia sesión en otro dispositivo, el token anterior queda inválido automáticamente.

## Integración con el Frontend

El frontend debe apuntar a `http://localhost:8080/api/` (o la URL del proxy si se usa uno) para obtener los datos.
