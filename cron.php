<?php
/**
 * KutSocial - Procesador de Cola de Tareas (Worker)
 * Ejecutable mediante Cron (CLI) o peticiones HTTP asíncronas
 */

// Normalizar HTTPS detrás de proxy inverso
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Cargar configuración si existe
if (!file_exists(__DIR__ . '/config.php')) {
    exit("KutSocial no está instalado aún.\n");
}

require_once __DIR__ . '/config.php';

use KutSocial\Database;
use KutSocial\Queue;

try {
    Database::setDbPath(KUTSOCIAL_DB_PATH);
} catch (Exception $e) {
    exit("Fallo de base de datos en worker: " . $e->getMessage() . "\n");
}

// Límite de tareas a procesar por ejecución
$limit = 10;
$processed = Queue::process($limit);

if (php_sapi_name() === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] Cola de tareas procesada. Tareas ejecutadas: $processed\n";
} else {
    // Si es petición web asíncrona, responder rápido y liberar conexión
    header("Content-Type: text/plain");
    echo "OK: $processed";
    exit;
}
