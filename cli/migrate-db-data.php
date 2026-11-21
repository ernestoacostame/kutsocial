<?php
/**
 * KutSocial - Migrador de datos entre motores de base de datos
 * Soporta: sqlite <-> pgsql <-> mysql (todas las 6 direcciones).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script debe ejecutarse desde la línea de comandos (CLI).\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
require_once $rootDir . '/version.php';

spl_autoload_register(function (string $class) use ($rootDir): void {
    $prefix = 'KutSocial\\';
    $baseDir = $rootDir . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use KutSocial\Database;

$longOpts = [
    'src-driver:',
    'src-path::',
    'src-host::',
    'src-port::',
    'src-name::',
    'src-user::',
    'src-pass::',
    'dst-driver:',
    'dst-path::',
    'dst-host::',
    'dst-port::',
    'dst-name::',
    'dst-user::',
    'dst-pass::',
    'help',
];

$opts = getopt('', $longOpts);

if ($opts === false || isset($opts['help']) || empty($opts['src-driver']) || empty($opts['dst-driver'])) {
    echo "Uso: php migrate-db-data.php --src-driver=<sqlite|pgsql|mysql> [opciones] --dst-driver=<sqlite|pgsql|mysql> [opciones]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

function normalizeDriver(string $driver): string {
    $d = strtolower(trim($driver));
    if ($d === 'postgres' || $d === 'postgresql') {
        return 'pgsql';
    }
    if ($d === 'mariadb') {
        return 'mysql';
    }
    if (!in_array($d, ['sqlite', 'pgsql', 'mysql'], true)) {
        throw new InvalidArgumentException("Motor no soportado: {$driver}");
    }
    return $d;
}

function buildConfig(array $opts, string $prefix): array {
    $driver = normalizeDriver((string)($opts["{$prefix}-driver"] ?? 'sqlite'));
    $defaultPort = $driver === 'pgsql' ? '5432' : ($driver === 'mysql' ? '3306' : '');
    return [
        'driver' => $driver,
        'path'   => (string)($opts["{$prefix}-path"] ?? ''),
        'host'   => (string)($opts["{$prefix}-host"] ?? '127.0.0.1'),
        'port'   => (string)($opts["{$prefix}-port"] ?? $defaultPort),
        'name'   => (string)($opts["{$prefix}-name"] ?? 'kutsocial'),
        'user'   => (string)($opts["{$prefix}-user"] ?? 'kutsocial'),
        'pass'   => (string)($opts["{$prefix}-pass"] ?? ''),
    ];
}

function openPdo(array $cfg): PDO {
    $driver = $cfg['driver'];
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30,
    ];

    if ($driver === 'pgsql') {
        $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        $pdo->exec("SET TIME ZONE 'UTC'");
        return $pdo;
    }

    if ($driver === 'mysql') {
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = CONCAT(@@sql_mode, ',PIPES_AS_CONCAT')");
        return $pdo;
    }

    if ($cfg['path'] === '') {
        throw new InvalidArgumentException("Falta --path para SQLite.");
    }
    $dir = dirname($cfg['path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . $cfg['path'], null, null, $options);
    $pdo->exec("PRAGMA busy_timeout=30000;");
    return $pdo;
}

function quoteIdent(string $ident, string $driver): string {
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '``', $ident) . '`';
    }
    return '"' . str_replace('"', '""', $ident) . '"';
}

function listTables(PDO $pdo, string $driver): array {
    if ($driver === 'sqlite') {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        return array_values($rows);
    }
    if ($driver === 'pgsql') {
        $rows = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        return array_values($rows);
    }
    $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    return array_values($rows);
}

/**
 * Devuelve mapa [colName => lowerType] para la tabla en el motor dado.
 */
function getTableColumns(PDO $pdo, string $table, string $driver): array {
    $cols = [];
    if ($driver === 'sqlite') {
        $qTable = quoteIdent($table, 'sqlite');
        $rows = $pdo->query("PRAGMA table_info({$qTable})")->fetchAll();
        foreach ($rows as $row) {
            $cols[(string)$row['name']] = strtolower((string)$row['type']);
        }
        return $cols;
    }
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position");
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll() as $row) {
            $cols[(string)$row['column_name']] = strtolower((string)$row['data_type']);
        }
        return $cols;
    }
    $qTable = quoteIdent($table, 'mysql');
    $rows = $pdo->query("SHOW COLUMNS FROM {$qTable}")->fetchAll();
    foreach ($rows as $row) {
        $cols[(string)$row['Field']] = strtolower((string)$row['Type']);
    }
    return $cols;
}

function normalizeValueForTarget(mixed $val, string $targetType, string $targetDriver): mixed {
    if ($val === null) {
        return null;
    }

    $isBool = str_contains($targetType, 'bool');
    $isInt  = str_contains($targetType, 'int') || str_contains($targetType, 'serial');
    $isDate = str_contains($targetType, 'timestamp') || str_contains($targetType, 'datetime') || $targetType === 'date';

    if ($isBool) {
        if ($val === '' || $val === '0' || $val === 0 || $val === false) {
            return $targetDriver === 'pgsql' ? 'false' : 0;
        }
        return $targetDriver === 'pgsql' ? 'true' : 1;
    }

    if ($val === '' && ($isInt || $isDate)) {
        return null;
    }

    if ($isInt && is_bool($val)) {
        return $val ? 1 : 0;
    }

    return $val;
}

function disableForeignKeys(PDO $pdo, string $driver): void {
    if ($driver === 'sqlite') {
        $pdo->exec("PRAGMA foreign_keys = OFF");
    } elseif ($driver === 'mysql') {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    } elseif ($driver === 'pgsql') {
        try {
            $pdo->exec("SET session_replication_role = 'replica'");
        } catch (Throwable $e) {
            // Si el usuario no es superusuario, continuamos confiando en el orden padre->hijo
        }
    }
}

function enableForeignKeys(PDO $pdo, string $driver): void {
    if ($driver === 'sqlite') {
        $pdo->exec("PRAGMA foreign_keys = ON");
    } elseif ($driver === 'mysql') {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } elseif ($driver === 'pgsql') {
        try {
            $pdo->exec("SET session_replication_role = 'origin'");
        } catch (Throwable $e) {
            // Ignorar
        }
    }
}

function truncateTargetTable(PDO $pdo, string $table, string $driver): void {
    $qTable = quoteIdent($table, $driver);
    if ($driver === 'pgsql') {
        $pdo->exec("TRUNCATE TABLE {$qTable} RESTART IDENTITY CASCADE");
    } elseif ($driver === 'mysql') {
        $pdo->exec("TRUNCATE TABLE {$qTable}");
    } else {
        $pdo->exec("DELETE FROM {$qTable}");
    }
}

function resetSequence(PDO $pdo, string $table, array $targetCols, string $driver): void {
    if (!array_key_exists('id', $targetCols)) {
        return;
    }
    $qTable = quoteIdent($table, $driver);

    if ($driver === 'pgsql') {
        $seqStmt = $pdo->prepare("SELECT pg_get_serial_sequence(?, 'id')");
        $seqStmt->execute([$table]);
        $seqName = $seqStmt->fetchColumn();
        if ($seqName) {
            $pdo->exec(
                "SELECT setval(" . $pdo->quote((string)$seqName) . ", "
                . "COALESCE((SELECT MAX(\"id\") FROM {$qTable}), 1), "
                . "(SELECT COUNT(*) > 0 FROM {$qTable}))"
            );
        }
        return;
    }

    if ($driver === 'mysql') {
        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(`id`), 0) FROM {$qTable}")->fetchColumn();
        $nextId = $maxId + 1;
        $pdo->exec("ALTER TABLE {$qTable} AUTO_INCREMENT = {$nextId}");
        return;
    }

    if ($driver === 'sqlite') {
        $hasSeqTable = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='sqlite_sequence'")->fetchColumn();
        if ($hasSeqTable > 0) {
            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(\"id\"), 0) FROM {$qTable}")->fetchColumn();
            $del = $pdo->prepare("DELETE FROM sqlite_sequence WHERE name = ?");
            $del->execute([$table]);
            if ($maxId > 0) {
                $ins = $pdo->prepare("INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)");
                $ins->execute([$table, $maxId]);
            }
        }
    }
}

try {
    $srcCfg = buildConfig($opts, 'src');
    $dstCfg = buildConfig($opts, 'dst');

    if ($srcCfg['driver'] === $dstCfg['driver'] && $srcCfg['driver'] !== 'sqlite') {
        // Permitido incluso mismo motor si difieren credenciales/host, pero avisamos
        echo "[INFO] Origen y destino usan el mismo motor ({$dstCfg['driver']}).\n";
    }

    echo "-> Conectando a origen ({$srcCfg['driver']})...\n";
    $srcPdo = openPdo($srcCfg);

    echo "-> Conectando a destino ({$dstCfg['driver']}) y preparando esquema...\n";
    Database::setConfig($dstCfg);
    Database::resetConnection();
    Database::runMigrations();
    $dstPdo = Database::connect();
    Database::ensureMarkersTable($dstPdo);

    $srcTables = listTables($srcPdo, $srcCfg['driver']);
    $dstTables = listTables($dstPdo, $dstCfg['driver']);

    $commonSet = array_intersect($srcTables, $dstTables);

    // Orden padre -> hijo para respetar claves foráneas
    $priorityOrder = [
        'schema_migrations',
        'options',
        'accounts',
        'moderation_blocks',
        'relays',
        'updates',
        'admin_sessions',
        'oauth_apps',
        'statuses',
        'follows',
        'jobs',
        'favourites',
        'bookmarks',
        'polls',
        'poll_votes',
        'dismissed_notifications',
        'lists',
        'list_accounts',
        'collections',
        'collection_accounts',
        'followed_hashtags',
        'web_push_subscriptions',
        'reports',
        'oauth_codes',
        'oauth_tokens',
        'user_mutes',
        'user_blocks',
        'mentions',
        'status_hashtags',
        'notifications',
        'markers',
    ];

    $orderedTables = [];
    foreach ($priorityOrder as $t) {
        if (in_array($t, $commonSet, true)) {
            $orderedTables[] = $t;
        }
    }
    foreach ($commonSet as $t) {
        if (!in_array($t, $orderedTables, true)) {
            $orderedTables[] = $t;
        }
    }

    if (empty($orderedTables)) {
        fwrite(STDERR, "Error: No se encontraron tablas comunes entre origen y destino.\n");
        exit(1);
    }

    disableForeignKeys($dstPdo, $dstCfg['driver']);

    // Limpiar tablas de destino en orden inverso por si hubiera datos previos
    foreach (array_reverse($orderedTables) as $table) {
        truncateTargetTable($dstPdo, $table, $dstCfg['driver']);
    }

    $batchSize = 500;
    $summary = [];
    $hasMismatch = false;

    foreach ($orderedTables as $table) {
        $srcCols = getTableColumns($srcPdo, $table, $srcCfg['driver']);
        $dstCols = getTableColumns($dstPdo, $table, $dstCfg['driver']);
        $cols = array_values(array_intersect(array_keys($srcCols), array_keys($dstCols)));

        if (empty($cols)) {
            continue;
        }

        $qSrcTable = quoteIdent($table, $srcCfg['driver']);
        $qDstTable = quoteIdent($table, $dstCfg['driver']);

        $selectColsSql = implode(', ', array_map(fn($c) => quoteIdent($c, $srcCfg['driver']), $cols));
        $insertColsSql = implode(', ', array_map(fn($c) => quoteIdent($c, $dstCfg['driver']), $cols));
        $placeholdersSql = implode(', ', array_fill(0, count($cols), '?'));

        $orderClause = in_array('id', $cols, true)
            ? ' ORDER BY ' . quoteIdent('id', $srcCfg['driver']) . ' ASC'
            : '';

        $srcCount = (int)$srcPdo->query("SELECT COUNT(*) FROM {$qSrcTable}")->fetchColumn();
        if ($srcCount === 0) {
            $summary[] = [$table, 0, 0, 'OK'];
            continue;
        }

        $insertSql = "INSERT INTO {$qDstTable} ({$insertColsSql}) VALUES ({$placeholdersSql})";
        $insertStmt = $dstPdo->prepare($insertSql);
        $selectStmt = $srcPdo->query("SELECT {$selectColsSql} FROM {$qSrcTable}{$orderClause}");

        $countInTx = 0;
        $dstPdo->beginTransaction();
        try {
            while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
                $params = [];
                foreach ($cols as $col) {
                    $params[] = normalizeValueForTarget($row[$col] ?? null, $dstCols[$col], $dstCfg['driver']);
                }
                $insertStmt->execute($params);
                $countInTx++;

                if ($countInTx >= $batchSize) {
                    $dstPdo->commit();
                    $dstPdo->beginTransaction();
                    $countInTx = 0;
                }
            }
            if ($dstPdo->inTransaction()) {
                $dstPdo->commit();
            }
        } catch (Throwable $e) {
            if ($dstPdo->inTransaction()) {
                $dstPdo->rollBack();
            }
            throw new RuntimeException("Error copiando tabla '{$table}': " . $e->getMessage(), 0, $e);
        }

        resetSequence($dstPdo, $table, $dstCols, $dstCfg['driver']);

        $dstCount = (int)$dstPdo->query("SELECT COUNT(*) FROM {$qDstTable}")->fetchColumn();
        $status = ($srcCount === $dstCount) ? 'OK' : 'ERROR';
        if ($status !== 'OK') {
            $hasMismatch = true;
        }
        $summary[] = [$table, $srcCount, $dstCount, $status];
    }

    enableForeignKeys($dstPdo, $dstCfg['driver']);

    echo "\n=== Resumen de migración de datos ===\n";
    echo str_pad('Tabla', 28) . str_pad('Origen', 10) . str_pad('Destino', 10) . "Estado\n";
    echo str_repeat('-', 56) . "\n";
    foreach ($summary as [$tbl, $sc, $dc, $st]) {
        echo str_pad($tbl, 28) . str_pad((string)$sc, 10) . str_pad((string)$dc, 10) . "{$st}\n";
    }
    echo str_repeat('-', 56) . "\n";

    if ($hasMismatch) {
        fwrite(STDERR, "ERROR: Hay discrepancias en el conteo de filas.\n");
        exit(1);
    }

    echo "Migración de datos completada y verificada al 100%.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR FATAL: " . $e->getMessage() . "\n");
    exit(1);
}
