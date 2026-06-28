<?php
/**
 * KutSocial - Procesador de Cola de Tareas (Worker)
 * Ejecutable mediante Cron (CLI) o peticiones HTTP asíncronas
 */

// Evitar abortar el script si el cliente (curl/navegador) desconecta
ignore_user_abort(true);
set_time_limit(300); // 5 minutos máximo de ejecución

// Normalizar HTTPS detrás de proxy inverso
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Cargar configuración si existe
if (!file_exists(__DIR__ . '/config.php')) {
    exit("KutSocial no está instalado aún.\n");
}

require_once __DIR__ . '/config.php';

// Normalizar host y protocolo para ejecuciones CLI/Cron en producción
if (defined('KUTSOCIAL_DOMAIN') && KUTSOCIAL_DOMAIN !== 'localhost') {
    if (empty($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === 'localhost') {
        $_SERVER['HTTP_HOST'] = KUTSOCIAL_DOMAIN;
    }
    $_SERVER['HTTPS'] = 'on';
}

use KutSocial\Database;
use KutSocial\Queue;

try {
    Database::setDbPath(KUTSOCIAL_DB_PATH);
} catch (Exception $e) {
    exit("Fallo de base de datos en worker: " . $e->getMessage() . "\n");
}

// Mecanismo de bloqueo para evitar ejecuciones concurrentes de cron.php
$lockFile = dirname(KUTSOCIAL_DB_PATH) . '/cron.lock';
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
    if (php_sapi_name() === 'cli') {
        echo "El worker ya está ejecutándose en otro proceso.\n";
    } else {
        header("Content-Type: text/plain");
        echo "OK: Worker ya en ejecución";
    }
    exit(0);
}

// Si es petición web, responder rápido y seguir procesando en segundo plano

if (php_sapi_name() !== 'cli') {
    header("Content-Type: text/plain");
    echo "OK: Procesando cola en segundo plano";
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// Procesar tareas en bucle hasta que no queden más o se alcance un límite seguro
$totalProcessed = 0;
$maxTasks = 200; // Límite para evitar bloqueos prolongados
$batchSize = 10;

do {
    $processed = Queue::process($batchSize);
    $totalProcessed += $processed;
} while ($processed > 0 && $totalProcessed < $maxTasks);

if (php_sapi_name() === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] Cola de tareas procesada. Tareas ejecutadas: $totalProcessed\n";
}

