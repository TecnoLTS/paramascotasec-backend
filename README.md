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

## Integración con el Frontend

El frontend debe apuntar a `http://localhost:8080/api/` (o la URL del proxy si se usa uno) para obtener los datos.
