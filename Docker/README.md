# KutSocial en Docker

## Inicio rápido

Desde la carpeta `Docker/`:

```bash
docker compose up -d --build
```

Abre en tu navegador: `http://localhost:8080` (se abrirá el instalador si es la primera vez).

## Puerto personalizado

```bash
KUTSOCIAL_PORT=8082 docker compose up -d --build
```

## Características incluidas

- PHP 8.3 FPM + Nginx con soporte SSE (`/api/v1/streaming`).
- Drivers **SQLite, PostgreSQL y MySQL/MariaDB** habilitados (`pdo_sqlite`, `pdo_pgsql`, `pdo_mysql`).
- Worker integrado en segundo plano ejecutando `cron.php` cada 60 segundos para la cola de ActivityPub.
- Persistencia completa en el volumen `kutsocial_data` montado en `/var/www/html/data`.
