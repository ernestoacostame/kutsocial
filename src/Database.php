<?php
namespace KutSocial;

use PDO;
use Exception;

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath = __DIR__ . '/../data/kutsocial.db';
    private static array $config = [];
    private static bool $migrationsRan = false;

    public static function setDbPath(string $path): void {
        self::$dbPath = $path;
        self::$config['path'] = $path;
    }

    public static function getDbPath(): string {
        if (!empty(self::$config['path'])) {
            return (string)self::$config['path'];
        }
        if (defined('KUTSOCIAL_DB_PATH') && KUTSOCIAL_DB_PATH !== '') {
            return (string)KUTSOCIAL_DB_PATH;
        }
        return self::$dbPath;
    }

    public static function setConfig(array $cfg): void {
        self::$config = array_merge(self::$config, $cfg);
        if (isset($cfg['path']) && $cfg['path'] !== '') {
            self::$dbPath = (string)$cfg['path'];
        }
        self::resetConnection();
    }

    public static function resetConnection(): void {
        self::$instance = null;
        self::$migrationsRan = false;
    }

    public static function getDriver(): string {
        if (!empty(self::$config['driver'])) {
            $d = strtolower(trim((string)self::$config['driver']));
        } elseif (defined('KUTSOCIAL_DB_DRIVER') && KUTSOCIAL_DB_DRIVER !== '') {
            $d = strtolower(trim((string)KUTSOCIAL_DB_DRIVER));
        } else {
            $d = 'sqlite';
        }
        if ($d === 'postgres' || $d === 'postgresql') {
            return 'pgsql';
        }
        if ($d === 'mariadb') {
            return 'mysql';
        }
        return in_array($d, ['sqlite', 'pgsql', 'mysql'], true) ? $d : 'sqlite';
    }

    private static function getConf(string $key, string $constName, string $default = ''): string {
        if (isset(self::$config[$key]) && self::$config[$key] !== '') {
            return (string)self::$config[$key];
        }
        if (defined($constName) && constant($constName) !== '') {
            return (string)constant($constName);
        }
        return $default;
    }

    /**
     * Return resolved database connection parameters for backup/CLI tools.
     */
    public static function getConnectionConfig(): array {
        $driver = self::getDriver();
        $defaultPort = $driver === 'pgsql' ? '5432' : ($driver === 'mysql' ? '3306' : '');
        return [
            'driver' => $driver,
            'host'   => self::getConf('host', 'KUTSOCIAL_DB_HOST', '127.0.0.1'),
            'port'   => self::getConf('port', 'KUTSOCIAL_DB_PORT', $defaultPort),
            'name'   => self::getConf('name', 'KUTSOCIAL_DB_NAME', 'kutsocial'),
            'user'   => self::getConf('user', 'KUTSOCIAL_DB_USER', 'kutsocial'),
            'pass'   => self::getConf('pass', 'KUTSOCIAL_DB_PASS', ''),
            'path'   => self::getDbPath(),
        ];
    }

    public static function nowUtc(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function agoUtc(int $seconds): string {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    public static function lastInsertId(PDO $db, string $table = '', string $col = 'id'): int {
        if (self::getDriver() === 'pgsql' && $table !== '') {
            return (int)$db->lastInsertId("{$table}_{$col}_seq");
        }
        return (int)$db->lastInsertId();
    }

    public static function insertIgnoreSql(string $table, string $colsSql, string $placeholdersSql): string {
        $driver = self::getDriver();
        if ($driver === 'mysql') {
            return "INSERT IGNORE INTO {$table} ({$colsSql}) VALUES ({$placeholdersSql})";
        }
        if ($driver === 'pgsql') {
            return "INSERT INTO {$table} ({$colsSql}) VALUES ({$placeholdersSql}) ON CONFLICT DO NOTHING";
        }
        return "INSERT OR IGNORE INTO {$table} ({$colsSql}) VALUES ({$placeholdersSql})";
    }

    public static function upsertOption(PDO $db, string $key, string $value): void {
        $now = self::nowUtc();
        $driver = self::getDriver();
        if ($driver === 'mysql') {
            $stmt = $db->prepare("INSERT INTO options (`key`, `value`, created_at, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)");
            $stmt->execute([$key, $value, $now, $now]);
        } else {
            $stmt = $db->prepare("INSERT INTO options (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at");
            $stmt->execute([$key, $value, $now, $now]);
        }
    }

    public static function optimizeDb(PDO $db): string {
        $driver = self::getDriver();
        if ($driver === 'sqlite') {
            $dbPath = self::getDbPath();
            $initialSize = file_exists($dbPath) ? filesize($dbPath) : 0;
            $db->exec("VACUUM;");
            $finalSize = file_exists($dbPath) ? filesize($dbPath) : 0;
            $savedDBBytes = max(0, $initialSize - $finalSize);
            $savedDBMB = round($savedDBBytes / (1024 * 1024), 2);
            return "Base de datos optimizada (VACUUM): se recuperaron $savedDBMB MB de espacio.";
        }
        if ($driver === 'pgsql') {
            $db->exec("VACUUM ANALYZE;");
            return "Base de datos PostgreSQL optimizada (VACUUM ANALYZE).";
        }
        $db->exec("OPTIMIZE TABLE accounts, statuses, follows, jobs, favourites, bookmarks, notifications, mentions, status_hashtags, options;");
        return "Tablas principales optimizadas en MySQL/MariaDB (OPTIMIZE TABLE).";
    }

    public static function ensureMarkersTable(PDO $db): void {
        $driver = self::getDriver();
        if ($driver === 'pgsql') {
            $db->exec("CREATE TABLE IF NOT EXISTS markers (
                id SERIAL PRIMARY KEY,
                account_id INTEGER NOT NULL,
                timeline VARCHAR(64) NOT NULL,
                last_read_id VARCHAR(191) NOT NULL,
                version INTEGER NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(account_id, timeline)
            )");
        } elseif ($driver === 'mysql') {
            $db->exec("CREATE TABLE IF NOT EXISTS markers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                timeline VARCHAR(64) NOT NULL,
                last_read_id VARCHAR(191) NOT NULL,
                version INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_markers_account_timeline (account_id, timeline)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS markers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                timeline TEXT NOT NULL,
                last_read_id TEXT NOT NULL,
                version INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(account_id, timeline)
            )");
        }
    }

    public static function getVersion(): string {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $versionFile = __DIR__ . '/../version.php';
        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            if (preg_match("/define\('KUTSOCIAL_VERSION',\s*'([^']+)'\)/", $content, $matches)) {
                return $cached = $matches[1];
            }
        }
        return $cached = (defined('KUTSOCIAL_VERSION') ? KUTSOCIAL_VERSION : '1.0.0');
    }

    public static function connect(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = self::getDriver();
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30,
        ];

        try {
            if ($driver === 'pgsql') {
                $host = self::getConf('host', 'KUTSOCIAL_DB_HOST', '127.0.0.1');
                $port = self::getConf('port', 'KUTSOCIAL_DB_PORT', '5432');
                $name = self::getConf('name', 'KUTSOCIAL_DB_NAME', 'kutsocial');
                $user = self::getConf('user', 'KUTSOCIAL_DB_USER', 'kutsocial');
                $pass = self::getConf('pass', 'KUTSOCIAL_DB_PASS', '');
                $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
                $pdo = new PDO($dsn, $user, $pass, $options);
                $pdo->exec("SET TIME ZONE 'UTC'");
            } elseif ($driver === 'mysql') {
                $host = self::getConf('host', 'KUTSOCIAL_DB_HOST', '127.0.0.1');
                $port = self::getConf('port', 'KUTSOCIAL_DB_PORT', '3306');
                $name = self::getConf('name', 'KUTSOCIAL_DB_NAME', 'kutsocial');
                $user = self::getConf('user', 'KUTSOCIAL_DB_USER', 'kutsocial');
                $pass = self::getConf('pass', 'KUTSOCIAL_DB_PASS', '');
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $options[PDO::ATTR_EMULATE_PREPARES] = false;
                $pdo = new PDO($dsn, $user, $pass, $options);
                $pdo->exec("SET time_zone = '+00:00', sql_mode = CONCAT(@@sql_mode, ',PIPES_AS_CONCAT')");
            } else {
                $dbPath = self::getDbPath();
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $dsn = 'sqlite:' . $dbPath;
                $pdo = new PDO($dsn, null, null, $options);

                // Optimización SQLite para concurrencia y seguridad en modo WAL (busy_timeout primero para evitar SQLITE_BUSY inmediato)
                $pdo->exec("PRAGMA busy_timeout=30000;");
                $mode = strtolower((string)$pdo->query("PRAGMA journal_mode;")->fetchColumn());
                if ($mode !== 'wal') {
                    $pdo->exec("PRAGMA journal_mode=WAL;");
                }
                $pdo->exec("PRAGMA synchronous=NORMAL;");
                $pdo->exec("PRAGMA foreign_keys=ON;");
                $pdo->exec("PRAGMA cache_size=-65536;");
                $pdo->exec("PRAGMA mmap_size=268435456;");
                $pdo->exec("PRAGMA temp_store=MEMORY;");
            }

            self::$instance = $pdo;
            return $pdo;
        } catch (Exception $e) {
            throw new Exception("Error al conectar a la base de datos ($driver): " . $e->getMessage());
        }
    }

    /**
     * Ejecuta una operación con reintentos automáticos si ocurre SQLITE_BUSY / database is locked.
     */
    public static function executeWithRetry(callable $callback, int $maxRetries = 5, int $initialDelayMs = 50): mixed {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $callback();
            } catch (\PDOException $e) {
                $isLocked = str_contains(strtolower($e->getMessage()), 'database is locked') ||
                            str_contains(strtolower($e->getMessage()), 'busy') ||
                            (isset($e->errorInfo[1]) && $e->errorInfo[1] === 5);

                if ($isLocked && $attempt < $maxRetries) {
                    usleep(($initialDelayMs * 1000 * (1 << ($attempt - 1))) + rand(10000, 40000));
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function runMigrations(): void {
        if (self::$migrationsRan) {
            return;
        }
        self::$migrationsRan = true;

        $driver = self::getDriver();
        if ($driver !== 'sqlite') {
            $dataDir = __DIR__ . '/../data';
            if (!is_dir($dataDir)) {
                @mkdir($dataDir, 0755, true);
            }
            $markerFile = $dataDir . '/.schema_version_' . $driver . '_' . self::getVersion();
        } else {
            $markerFile = dirname(self::getDbPath()) . '/.schema_version_' . self::getVersion();
        }

        if (file_exists($markerFile)) {
            return;
        }

        $db = self::connect();

        if ($driver !== 'sqlite') {
            self::createSchemaForDriver($db, $driver);
            @touch($markerFile);
            return;
        }

        self::executeWithRetry(function() use ($db, $markerFile) {
            // Tabla interna de control de versiones de base de datos
            $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");

            $migrations = self::getMigrations();
            $stmt = $db->query("SELECT version FROM schema_migrations");
            $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($migrations as $version => $queries) {
                if (!in_array($version, $applied)) {
                    try {
                        foreach ($queries as $query) {
                            try {
                                $db->exec($query);
                            } catch (\PDOException $ex) {
                                // Ignorar si la columna o índice ya existe
                                $msg = strtolower($ex->getMessage());
                                if (!str_contains($msg, 'duplicate column') && !str_contains($msg, 'already exists')) {
                                    throw $ex;
                                }
                            }
                        }
                        $ins = $db->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
                        $ins->execute([$version]);
                    } catch (Exception $e) {
                        throw new Exception("Error aplicando migración versión $version: " . $e->getMessage());
                    }
                }
            }

            // Tareas de normalización y compatibilidad al actualizar
            try {
                $cols = $db->query("PRAGMA table_info(statuses)")->fetchAll(\PDO::FETCH_ASSOC);
                $hasReblogOfId = false;
                $hasIdempotencyKey = false;
                foreach ($cols as $col) {
                    if (strcasecmp($col['name'], 'reblog_of_id') === 0) {
                        $hasReblogOfId = true;
                    }
                    if (strcasecmp($col['name'], 'idempotency_key') === 0) {
                        $hasIdempotencyKey = true;
                    }
                }
                if (!$hasReblogOfId) {
                    $db->exec("ALTER TABLE statuses ADD COLUMN reblog_of_id INTEGER");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_statuses_reblog_of_id ON statuses(reblog_of_id)");
                }
                if (!$hasIdempotencyKey) {
                    $db->exec("ALTER TABLE statuses ADD COLUMN idempotency_key TEXT NULL");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_statuses_idempotency_key ON statuses(account_id, idempotency_key)");
                }

                $hasInReplyToUrl = false;
                $hasInReplyToAccountId = false;
                foreach ($cols as $col) {
                    if (strcasecmp($col['name'], 'in_reply_to_url') === 0) {
                        $hasInReplyToUrl = true;
                    }
                    if (strcasecmp($col['name'], 'in_reply_to_account_id') === 0) {
                        $hasInReplyToAccountId = true;
                    }
                }
                if (!$hasInReplyToUrl) {
                    $db->exec("ALTER TABLE statuses ADD COLUMN in_reply_to_url TEXT NULL");
                }
                if (!$hasInReplyToAccountId) {
                    $db->exec("ALTER TABLE statuses ADD COLUMN in_reply_to_account_id INTEGER NULL");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_statuses_in_reply_to_account_id ON statuses(in_reply_to_account_id)");
                    $db->exec("UPDATE statuses SET in_reply_to_account_id = (SELECT account_id FROM statuses p WHERE p.id = statuses.in_reply_to_id) WHERE in_reply_to_id IS NOT NULL AND in_reply_to_account_id IS NULL");
                }
                $hasIsProfileFetch = false;
                foreach ($cols as $col) {
                    if (strcasecmp($col['name'], 'is_profile_fetch') === 0) {
                        $hasIsProfileFetch = true;
                        break;
                    }
                }
                if (!$hasIsProfileFetch) {
                    $db->exec("ALTER TABLE statuses ADD COLUMN is_profile_fetch INTEGER NOT NULL DEFAULT 0");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_statuses_is_profile_fetch ON statuses(is_profile_fetch)");
                    // Marcar estados remotos de cuentas no seguidas como consultas de perfil
                    $db->exec("UPDATE statuses SET is_profile_fetch = 1 WHERE account_id IN (
                        SELECT a.id FROM accounts a 
                        WHERE a.domain IS NOT NULL AND a.domain != '' 
                          AND a.id NOT IN (SELECT target_account_id FROM follows)
                    )");
                }

                // Asegurar columnas en follows
                $followCols = $db->query("PRAGMA table_info(follows)")->fetchAll(\PDO::FETCH_ASSOC);
                $hasOnlyRepliesToMe = false;
                $hasShowReblogs = false;
                foreach ($followCols as $col) {
                    if (strcasecmp($col['name'], 'only_replies_to_me') === 0) {
                        $hasOnlyRepliesToMe = true;
                    }
                    if (strcasecmp($col['name'], 'show_reblogs') === 0) {
                        $hasShowReblogs = true;
                    }
                }
                if (!$hasOnlyRepliesToMe) {
                    $db->exec("ALTER TABLE follows ADD COLUMN only_replies_to_me INTEGER NOT NULL DEFAULT 0");
                }
                if (!$hasShowReblogs) {
                    $db->exec("ALTER TABLE follows ADD COLUMN show_reblogs INTEGER NOT NULL DEFAULT 1");
                }

                // Asegurar columna also_known_as y statuses_last_fetched_at
                $accCols = $db->query("PRAGMA table_info(accounts)")->fetchAll(\PDO::FETCH_ASSOC);
                $hasAlsoKnownAs = false;
                $hasStatusesLastFetchedAt = false;
                foreach ($accCols as $col) {
                    if (strcasecmp($col['name'], 'also_known_as') === 0) {
                        $hasAlsoKnownAs = true;
                    }
                    if (strcasecmp($col['name'], 'statuses_last_fetched_at') === 0) {
                        $hasStatusesLastFetchedAt = true;
                    }
                }
                if (!$hasAlsoKnownAs) {
                    $db->exec("ALTER TABLE accounts ADD COLUMN also_known_as TEXT NULL");
                }
                if (!$hasStatusesLastFetchedAt) {
                    $db->exec("ALTER TABLE accounts ADD COLUMN statuses_last_fetched_at TEXT NULL");
                }

                // Limpieza de auto-seguimientos erróneos
                $db->exec("DELETE FROM follows WHERE account_id = target_account_id");

                // Normalizar dominio de cuentas locales
                $db->exec("UPDATE accounts SET domain = NULL WHERE domain = '' OR LOWER(domain) = 'localhost'");
                if (!empty($_SERVER['HTTP_HOST'])) {
                    $hostClean = strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]);
                    $stmtNorm = $db->prepare("UPDATE accounts SET domain = NULL WHERE LOWER(domain) = ?");
                    $stmtNorm->execute([$hostClean]);
                }

                // Normalizar estado de seguimientos existentes a accepted
                $db->exec("UPDATE follows SET status = 'accepted' WHERE (status IS NULL OR status = '' OR status = 'pending')");

                // Sincronizar contadores reales en la tabla accounts para cuentas locales
                $db->exec("UPDATE accounts SET
                    followers_count = (SELECT COUNT(*) FROM follows WHERE target_account_id = accounts.id AND (status = 'accepted' OR status = 'pending')),
                    following_count = (SELECT COUNT(*) FROM follows WHERE account_id = accounts.id AND (status = 'accepted' OR status = 'pending')),
                    statuses_count = (SELECT COUNT(*) FROM statuses WHERE account_id = accounts.id)
                    WHERE domain IS NULL OR domain = ''");

                // Asegurar tabla admin_sessions para sesiones persistentes configurables
                $db->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    selector TEXT UNIQUE NOT NULL,
                    hashed_validator TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    ip TEXT,
                    user_agent TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_admin_sessions_selector ON admin_sessions(selector)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_admin_sessions_account_id ON admin_sessions(account_id)");

                @touch($markerFile);
            } catch (\Throwable $e) {
                // Silenciar fallos menores en tareas de limpieza
            }
        });
    }

    private static function getMigrations(): array {
        return [
            1 => [
                // Configuración general del sitio
                "CREATE TABLE IF NOT EXISTS options (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                // Cuentas / Usuarios (locales y remotos federados)
                "CREATE TABLE IF NOT EXISTS accounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    domain TEXT, -- NULL para local
                    email TEXT,  -- NULL para remoto
                    password_hash TEXT, -- NULL para remoto
                    display_name TEXT,
                    note TEXT, -- biografía
                    avatar TEXT,
                    header TEXT,
                    avatar_description TEXT,
                    header_description TEXT,
                    inbox_url TEXT, -- URL de inbox para federación remota
                    public_key TEXT NOT NULL,
                    private_key TEXT, -- NULL para remoto
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(username, domain)
                )",
                // Publicaciones / Statuses
                "CREATE TABLE IF NOT EXISTS statuses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    uri TEXT NOT NULL UNIQUE,
                    content TEXT NOT NULL,
                    visibility TEXT NOT NULL DEFAULT 'public',
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                // Seguimientos (Follows)
                "CREATE TABLE IF NOT EXISTS follows (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE, -- seguidor
                    target_account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE, -- seguido
                    uri TEXT UNIQUE,
                    status TEXT NOT NULL DEFAULT 'pending', -- pending, accepted
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                // Cola de Tareas (Jobs) para ActivityPub
                "CREATE TABLE IF NOT EXISTS jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    activity_type TEXT NOT NULL,
                    payload TEXT NOT NULL, -- JSON string de la actividad
                    inbox_url TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending', -- pending, processing, completed, failed
                    attempts INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT,
                    next_attempt TEXT NOT NULL DEFAULT (datetime('now')),
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            ],
            2 => [
                // Soporte FTS5 para búsquedas
                // Tablas virtuales para búsqueda de posts y perfiles
                "CREATE VIRTUAL TABLE IF NOT EXISTS statuses_fts USING fts5(
                    content,
                    content_rowid=id
                )",
                "CREATE VIRTUAL TABLE IF NOT EXISTS accounts_fts USING fts5(
                    username,
                    display_name,
                    note,
                    content_rowid=id
                )",
                // Triggers para mantener la búsqueda FTS sincronizada
                "CREATE TRIGGER IF NOT EXISTS trg_statuses_ai AFTER INSERT ON statuses BEGIN
                    INSERT INTO statuses_fts(rowid, content) VALUES (new.id, new.content);
                END",
                "CREATE TRIGGER IF NOT EXISTS trg_statuses_ad AFTER DELETE ON statuses BEGIN
                    INSERT INTO statuses_fts(statuses_fts, rowid, content) VALUES('delete', old.id, old.content);
                END",
                "CREATE TRIGGER IF NOT EXISTS trg_statuses_au AFTER UPDATE ON statuses BEGIN
                    INSERT INTO statuses_fts(statuses_fts, rowid, content) VALUES('delete', old.id, old.content);
                    INSERT INTO statuses_fts(rowid, content) VALUES (new.id, new.content);
                END",
                "CREATE TRIGGER IF NOT EXISTS trg_accounts_ai AFTER INSERT ON accounts BEGIN
                    INSERT INTO accounts_fts(rowid, username, display_name, note) VALUES (new.id, new.username, new.display_name, new.note);
                END",
                "CREATE TRIGGER IF NOT EXISTS trg_accounts_ad AFTER DELETE ON accounts BEGIN
                    INSERT INTO accounts_fts(accounts_fts, rowid, username, display_name, note) VALUES('delete', old.id, old.username, old.display_name, old.note);
                END",
                "CREATE TRIGGER IF NOT EXISTS trg_accounts_au AFTER UPDATE ON accounts BEGIN
                    INSERT INTO accounts_fts(accounts_fts, rowid, username, display_name, note) VALUES('delete', old.id, old.username, old.display_name, old.note);
                    INSERT INTO accounts_fts(rowid, username, display_name, note) VALUES (new.id, new.username, new.display_name, new.note);
                END"
            ],
            3 => [
                // Metadatos personalizados (campos extra de perfil al estilo Mastodon)
                "ALTER TABLE accounts ADD COLUMN fields TEXT"
            ],
            4 => [
                // Soporte para hilos y Content Warnings (advertencias de contenido)
                "ALTER TABLE statuses ADD COLUMN in_reply_to_id INTEGER REFERENCES statuses(id) ON DELETE SET NULL",
                "ALTER TABLE statuses ADD COLUMN sensitive INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE statuses ADD COLUMN spoiler_text TEXT DEFAULT ''",
                // Tabla de Favoritos
                "CREATE TABLE IF NOT EXISTS favourites (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    status_id INTEGER NOT NULL REFERENCES statuses(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(account_id, status_id)
                )",
                // Tabla de Marcadores (Bookmarks)
                "CREATE TABLE IF NOT EXISTS bookmarks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    status_id INTEGER NOT NULL REFERENCES statuses(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(account_id, status_id)
                )"
            ],
            5 => [
                // Corregir triggers de FTS5 para usar la sintaxis de eliminación correcta en tablas con contenido propio
                "DROP TRIGGER IF EXISTS trg_statuses_ad",
                "DROP TRIGGER IF EXISTS trg_statuses_au",
                "DROP TRIGGER IF EXISTS trg_accounts_ad",
                "DROP TRIGGER IF EXISTS trg_accounts_au",
                "CREATE TRIGGER trg_statuses_ad AFTER DELETE ON statuses BEGIN
                    DELETE FROM statuses_fts WHERE rowid = old.id;
                END",
                "CREATE TRIGGER trg_statuses_au AFTER UPDATE OF content ON statuses BEGIN
                    DELETE FROM statuses_fts WHERE rowid = old.id;
                    INSERT INTO statuses_fts(rowid, content) VALUES (new.id, new.content);
                END",
                "CREATE TRIGGER trg_accounts_ad AFTER DELETE ON accounts BEGIN
                    DELETE FROM accounts_fts WHERE rowid = old.id;
                END",
                "CREATE TRIGGER trg_accounts_au AFTER UPDATE OF username, display_name, note ON accounts BEGIN
                    DELETE FROM accounts_fts WHERE rowid = old.id;
                    INSERT INTO accounts_fts(rowid, username, display_name, note) VALUES (new.id, new.username, new.display_name, new.note);
                END"
            ],
            6 => [
                "ALTER TABLE accounts ADD COLUMN otp_secret TEXT NULL",
                "ALTER TABLE statuses ADD COLUMN media_attachments TEXT NULL",
                "CREATE TABLE IF NOT EXISTS moderation_blocks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL, -- domain, account, word, hashtag
                    target TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(type, target)
                )",
                "CREATE TABLE IF NOT EXISTS relays (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    inbox_url TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending', -- pending, accepted, disabled
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(inbox_url)
                )",
                "CREATE TABLE IF NOT EXISTS polls (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    status_id INTEGER NOT NULL REFERENCES statuses(id) ON DELETE CASCADE,
                    question TEXT NOT NULL,
                    options_json TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    UNIQUE(status_id)
                )",
                "CREATE TABLE IF NOT EXISTS poll_votes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    choice_index INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(poll_id, account_id)
                )"
            ],
            7 => [
                "ALTER TABLE accounts ADD COLUMN role TEXT NOT NULL DEFAULT 'user'",
                "UPDATE accounts SET role = 'owner' WHERE id = 1",
                "ALTER TABLE accounts ADD COLUMN locked INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE accounts ADD COLUMN discoverable INTEGER NOT NULL DEFAULT 1",
                "ALTER TABLE accounts ADD COLUMN searchable INTEGER NOT NULL DEFAULT 1",
                "ALTER TABLE accounts ADD COLUMN indexable INTEGER NOT NULL DEFAULT 1",
                "ALTER TABLE accounts ADD COLUMN show_source INTEGER NOT NULL DEFAULT 1",
                "ALTER TABLE statuses ADD COLUMN language TEXT NULL"
            ],
            8 => [
                "CREATE TABLE IF NOT EXISTS updates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    from_version TEXT NOT NULL,
                    to_version TEXT NOT NULL,
                    status TEXT NOT NULL,
                    backup_path TEXT NOT NULL,
                    applied_at TEXT NOT NULL DEFAULT (datetime('now')),
                    rolled_back_at TEXT NULL
                )"
            ],
            9 => [
                "CREATE TABLE IF NOT EXISTS dismissed_notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    notification_id TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(account_id, notification_id)
                )"
            ],
            10 => [
                "ALTER TABLE accounts ADD COLUMN followers_count INTEGER DEFAULT 0",
                "ALTER TABLE accounts ADD COLUMN following_count INTEGER DEFAULT 0",
                "ALTER TABLE accounts ADD COLUMN statuses_count INTEGER DEFAULT 0"
            ],
            11 => [
                "CREATE TABLE IF NOT EXISTS lists (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    title TEXT NOT NULL,
                    replies_policy TEXT NOT NULL DEFAULT 'followed',
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                "CREATE TABLE IF NOT EXISTS list_accounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    list_id INTEGER NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(list_id, account_id)
                )",
                "CREATE TABLE IF NOT EXISTS collections (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    title TEXT NOT NULL,
                    description TEXT DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                "CREATE TABLE IF NOT EXISTS collection_accounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(collection_id, account_id)
                )",
                "CREATE TABLE IF NOT EXISTS followed_hashtags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    hashtag TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(account_id, hashtag)
                )"
            ],
            12 => [
                "ALTER TABLE statuses ADD COLUMN card TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN smtp_host TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN smtp_port INTEGER NULL",
                "ALTER TABLE accounts ADD COLUMN smtp_user TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN smtp_pass TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN smtp_from TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN email_notifications INTEGER DEFAULT 0",
                "ALTER TABLE accounts ADD COLUMN attribution_domains TEXT NULL"
            ],
            13 => [
                "CREATE INDEX IF NOT EXISTS idx_statuses_account_id ON statuses(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_in_reply_to_id ON statuses(in_reply_to_id)",
                "CREATE INDEX IF NOT EXISTS idx_follows_account_id ON follows(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_follows_target_account_id ON follows(target_account_id)",
                "CREATE INDEX IF NOT EXISTS idx_favourites_status_id ON favourites(status_id)",
                "CREATE INDEX IF NOT EXISTS idx_favourites_account_id ON favourites(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_bookmarks_account_id ON bookmarks(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_bookmarks_status_id ON bookmarks(status_id)"
            ],
            14 => [
                "ALTER TABLE statuses ADD COLUMN reblog_of_id INTEGER",
                "CREATE INDEX IF NOT EXISTS idx_statuses_reblog_of_id ON statuses(reblog_of_id)"
            ],
            15 => [
                "ALTER TABLE statuses ADD COLUMN emojis TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN emojis TEXT NULL",
                "ALTER TABLE accounts ADD COLUMN url TEXT NULL"
            ],
            16 => [
                "ALTER TABLE accounts ADD COLUMN notifications_read_at TEXT NULL"
            ],
            17 => [
                "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    endpoint TEXT NOT NULL,
                    key_p256dh TEXT NOT NULL,
                    key_auth TEXT NOT NULL,
                    alerts TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(account_id, endpoint)
                )",
                "CREATE INDEX IF NOT EXISTS idx_web_push_subscriptions_account_id ON web_push_subscriptions(account_id)"
            ],
            18 => [
                "ALTER TABLE accounts ADD COLUMN also_known_as TEXT NULL"
            ],
            19 => [
                "ALTER TABLE statuses ADD COLUMN idempotency_key TEXT NULL",
                "CREATE INDEX IF NOT EXISTS idx_statuses_idempotency_key ON statuses(account_id, idempotency_key)"
            ],
            20 => [
                "CREATE TABLE IF NOT EXISTS reports (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    target_account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    status_ids TEXT NULL,
                    comment TEXT NOT NULL,
                    forward INTEGER NOT NULL DEFAULT 0,
                    category TEXT NOT NULL DEFAULT 'other',
                    action_taken INTEGER NOT NULL DEFAULT 0,
                    action_taken_at TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                "CREATE INDEX IF NOT EXISTS idx_reports_account_id ON reports(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_reports_target_account_id ON reports(target_account_id)"
            ],
            21 => [
                "ALTER TABLE follows ADD COLUMN only_replies_to_me INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE follows ADD COLUMN show_reblogs INTEGER NOT NULL DEFAULT 1",
                "ALTER TABLE statuses ADD COLUMN in_reply_to_url TEXT NULL",
                "ALTER TABLE statuses ADD COLUMN in_reply_to_account_id INTEGER NULL",
                "CREATE INDEX IF NOT EXISTS idx_statuses_in_reply_to_account_id ON statuses(in_reply_to_account_id)"
            ],
            22 => [
                "CREATE TABLE IF NOT EXISTS admin_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    selector TEXT UNIQUE NOT NULL,
                    hashed_validator TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    ip TEXT,
                    user_agent TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE INDEX IF NOT EXISTS idx_admin_sessions_selector ON admin_sessions(selector)",
                "CREATE INDEX IF NOT EXISTS idx_admin_sessions_account_id ON admin_sessions(account_id)"
            ],
            23 => [
                "ALTER TABLE accounts ADD COLUMN statuses_last_fetched_at TEXT NULL"
            ],
            24 => [
                "ALTER TABLE statuses ADD COLUMN is_profile_fetch INTEGER NOT NULL DEFAULT 0",
                "CREATE INDEX IF NOT EXISTS idx_statuses_is_profile_fetch ON statuses(is_profile_fetch)",
                "UPDATE statuses SET is_profile_fetch = 1 WHERE account_id IN (
                    SELECT a.id FROM accounts a 
                    WHERE a.domain IS NOT NULL AND a.domain != '' 
                      AND a.id NOT IN (SELECT target_account_id FROM follows)
                )"
            ],
            25 => [
                // Índices de rendimiento (todas las tablas/columnas verificadas en el esquema)
                "CREATE INDEX IF NOT EXISTS idx_jobs_status_next_attempt ON jobs(status, next_attempt)",
                "CREATE INDEX IF NOT EXISTS idx_follows_account_status_target ON follows(account_id, status, target_account_id)",
                "CREATE INDEX IF NOT EXISTS idx_follows_target_status_account ON follows(target_account_id, status, account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_account_id_id_desc ON statuses(account_id, id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_created_at ON statuses(created_at)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_visibility_id_desc ON statuses(visibility, id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_accounts_url ON accounts(url)",
                "CREATE INDEX IF NOT EXISTS idx_accounts_inbox_url ON accounts(inbox_url)",
                "CREATE INDEX IF NOT EXISTS idx_poll_votes_poll_id ON poll_votes(poll_id)"
            ],
            26 => [
                // Los triggers FTS de UPDATE solo se disparan al cambiar las columnas indexadas
                "DROP TRIGGER IF EXISTS trg_statuses_au",
                "DROP TRIGGER IF EXISTS trg_accounts_au",
                "CREATE TRIGGER trg_statuses_au AFTER UPDATE OF content ON statuses BEGIN
                    DELETE FROM statuses_fts WHERE rowid = old.id;
                    INSERT INTO statuses_fts(rowid, content) VALUES (new.id, new.content);
                END",
                "CREATE TRIGGER trg_accounts_au AFTER UPDATE OF username, display_name, note ON accounts BEGIN
                    DELETE FROM accounts_fts WHERE rowid = old.id;
                    INSERT INTO accounts_fts(rowid, username, display_name, note) VALUES (new.id, new.username, new.display_name, new.note);
                END"
            ],
            27 => [
                "CREATE TABLE IF NOT EXISTS oauth_apps (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_name TEXT NOT NULL,
                    client_id TEXT NOT NULL UNIQUE,
                    client_secret_hash TEXT NOT NULL,
                    redirect_uris TEXT NOT NULL,
                    scopes TEXT DEFAULT 'read write follow push',
                    website TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS oauth_codes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code_hash TEXT NOT NULL UNIQUE,
                    client_id TEXT NOT NULL,
                    account_id INTEGER NOT NULL,
                    redirect_uri TEXT NOT NULL,
                    scopes TEXT DEFAULT 'read write follow push',
                    expires_at INTEGER NOT NULL,
                    used_at INTEGER DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
                )",
                "CREATE TABLE IF NOT EXISTS oauth_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    token_hash TEXT NOT NULL UNIQUE,
                    client_id TEXT,
                    account_id INTEGER NOT NULL,
                    scopes TEXT DEFAULT 'read write follow push',
                    expires_at INTEGER NOT NULL,
                    revoked_at INTEGER DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
                )",
                "CREATE TABLE IF NOT EXISTS user_mutes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    target_account_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, target_account_id),
                    FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    FOREIGN KEY(target_account_id) REFERENCES accounts(id) ON DELETE CASCADE
                )",
                "CREATE TABLE IF NOT EXISTS user_blocks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    target_account_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, target_account_id),
                    FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    FOREIGN KEY(target_account_id) REFERENCES accounts(id) ON DELETE CASCADE
                )",
                "CREATE INDEX IF NOT EXISTS idx_oauth_tokens_account_id ON oauth_tokens(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_oauth_codes_expires ON oauth_codes(expires_at)",
                "CREATE INDEX IF NOT EXISTS idx_user_mutes_account_id ON user_mutes(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_blocks_account_id ON user_blocks(account_id)"
            ],
            28 => [
                "CREATE TABLE IF NOT EXISTS mentions (
                    status_id INTEGER NOT NULL,
                    account_id INTEGER NOT NULL,
                    PRIMARY KEY(status_id, account_id)
                )",
                "CREATE INDEX IF NOT EXISTS idx_mentions_acc ON mentions(account_id, status_id DESC)",
                "CREATE TABLE IF NOT EXISTS status_hashtags (
                    status_id INTEGER NOT NULL,
                    tag TEXT NOT NULL,
                    PRIMARY KEY(status_id, tag)
                )",
                "CREATE INDEX IF NOT EXISTS idx_status_tags_tag ON status_hashtags(tag, status_id DESC)",
                "CREATE TABLE IF NOT EXISTS notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    type TEXT NOT NULL,
                    from_account_id INTEGER NOT NULL,
                    status_id INTEGER DEFAULT NULL,
                    created_at TEXT NOT NULL,
                    read_at TEXT DEFAULT NULL
                )",
                "CREATE INDEX IF NOT EXISTS idx_notif_acc_id ON notifications(account_id, id DESC)"
            ]
        ];
    }

    private static function createSchemaForDriver(PDO $db, string $driver): void {
        if ($driver === 'pgsql') {
            $tables = [
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    version INTEGER PRIMARY KEY,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS options (
                    key VARCHAR(191) PRIMARY KEY,
                    value TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS accounts (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(191) NOT NULL,
                    domain VARCHAR(191),
                    email VARCHAR(191),
                    password_hash TEXT,
                    display_name TEXT,
                    note TEXT,
                    avatar TEXT,
                    header TEXT,
                    avatar_description TEXT,
                    header_description TEXT,
                    inbox_url VARCHAR(191),
                    public_key TEXT NOT NULL,
                    private_key TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    fields TEXT,
                    otp_secret TEXT,
                    role VARCHAR(32) NOT NULL DEFAULT 'user',
                    locked INTEGER NOT NULL DEFAULT 0,
                    discoverable INTEGER NOT NULL DEFAULT 1,
                    searchable INTEGER NOT NULL DEFAULT 1,
                    indexable INTEGER NOT NULL DEFAULT 1,
                    show_source INTEGER NOT NULL DEFAULT 1,
                    followers_count INTEGER DEFAULT 0,
                    following_count INTEGER DEFAULT 0,
                    statuses_count INTEGER DEFAULT 0,
                    smtp_host TEXT,
                    smtp_port INTEGER,
                    smtp_user TEXT,
                    smtp_pass TEXT,
                    smtp_from TEXT,
                    email_notifications INTEGER DEFAULT 0,
                    attribution_domains TEXT,
                    emojis TEXT,
                    url VARCHAR(191),
                    notifications_read_at TIMESTAMP NULL,
                    also_known_as TEXT,
                    statuses_last_fetched_at TIMESTAMP NULL,
                    UNIQUE(username, domain)
                )",
                "CREATE TABLE IF NOT EXISTS statuses (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    uri VARCHAR(191) NOT NULL UNIQUE,
                    content TEXT NOT NULL,
                    visibility VARCHAR(32) NOT NULL DEFAULT 'public',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    in_reply_to_id INTEGER REFERENCES statuses(id) ON DELETE SET NULL,
                    sensitive INTEGER NOT NULL DEFAULT 0,
                    spoiler_text TEXT DEFAULT '',
                    media_attachments TEXT,
                    language VARCHAR(16),
                    card TEXT,
                    reblog_of_id INTEGER,
                    emojis TEXT,
                    idempotency_key VARCHAR(191),
                    in_reply_to_url TEXT,
                    in_reply_to_account_id INTEGER,
                    is_profile_fetch INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS follows (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    target_account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    uri VARCHAR(191) UNIQUE,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    only_replies_to_me INTEGER NOT NULL DEFAULT 0,
                    show_reblogs INTEGER NOT NULL DEFAULT 1
                )",
                "CREATE TABLE IF NOT EXISTS jobs (
                    id SERIAL PRIMARY KEY,
                    activity_type VARCHAR(128) NOT NULL,
                    payload TEXT NOT NULL,
                    inbox_url TEXT NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT,
                    next_attempt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS favourites (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    status_id INTEGER NOT NULL REFERENCES statuses(id) ON DELETE CASCADE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, status_id)
                )",
                "CREATE TABLE IF NOT EXISTS bookmarks (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    status_id INTEGER NOT NULL REFERENCES statuses(id) ON DELETE CASCADE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, status_id)
                )",
                "CREATE TABLE IF NOT EXISTS moderation_blocks (
                    id SERIAL PRIMARY KEY,
                    type VARCHAR(64) NOT NULL,
                    target VARCHAR(191) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(type, target)
                )",
                "CREATE TABLE IF NOT EXISTS relays (
                    id SERIAL PRIMARY KEY,
                    inbox_url VARCHAR(191) NOT NULL UNIQUE,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS polls (
                    id SERIAL PRIMARY KEY,
                    status_id INTEGER NOT NULL REFERENCES statuses(id) ON DELETE CASCADE,
                    question TEXT NOT NULL,
                    options_json TEXT NOT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    UNIQUE(status_id)
                )",
                "CREATE TABLE IF NOT EXISTS poll_votes (
                    id SERIAL PRIMARY KEY,
                    poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    choice_index INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(poll_id, account_id)
                )",
                "CREATE TABLE IF NOT EXISTS updates (
                    id SERIAL PRIMARY KEY,
                    from_version VARCHAR(64) NOT NULL,
                    to_version VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    backup_path TEXT NOT NULL,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    rolled_back_at TIMESTAMP NULL
                )",
                "CREATE TABLE IF NOT EXISTS dismissed_notifications (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    notification_id VARCHAR(191) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, notification_id)
                )",
                "CREATE TABLE IF NOT EXISTS lists (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    title TEXT NOT NULL,
                    replies_policy VARCHAR(32) NOT NULL DEFAULT 'followed',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS list_accounts (
                    id SERIAL PRIMARY KEY,
                    list_id INTEGER NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(list_id, account_id)
                )",
                "CREATE TABLE IF NOT EXISTS collections (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    title TEXT NOT NULL,
                    description TEXT DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS collection_accounts (
                    id SERIAL PRIMARY KEY,
                    collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(collection_id, account_id)
                )",
                "CREATE TABLE IF NOT EXISTS followed_hashtags (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    hashtag VARCHAR(191) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, hashtag)
                )",
                "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    endpoint VARCHAR(191) NOT NULL,
                    key_p256dh TEXT NOT NULL,
                    key_auth TEXT NOT NULL,
                    alerts TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, endpoint)
                )",
                "CREATE TABLE IF NOT EXISTS reports (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    target_account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    status_ids TEXT,
                    comment TEXT NOT NULL,
                    forward INTEGER NOT NULL DEFAULT 0,
                    category VARCHAR(64) NOT NULL DEFAULT 'other',
                    action_taken INTEGER NOT NULL DEFAULT 0,
                    action_taken_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS admin_sessions (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL,
                    selector VARCHAR(191) UNIQUE NOT NULL,
                    hashed_validator TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    ip VARCHAR(64),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS oauth_apps (
                    id SERIAL PRIMARY KEY,
                    client_name TEXT NOT NULL,
                    client_id VARCHAR(191) NOT NULL UNIQUE,
                    client_secret_hash TEXT NOT NULL,
                    redirect_uris TEXT NOT NULL,
                    scopes TEXT DEFAULT 'read write follow push',
                    website TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS oauth_codes (
                    id SERIAL PRIMARY KEY,
                    code_hash VARCHAR(191) NOT NULL UNIQUE,
                    client_id VARCHAR(191) NOT NULL,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    redirect_uri TEXT NOT NULL,
                    scopes TEXT DEFAULT 'read write follow push',
                    expires_at INTEGER NOT NULL,
                    used_at INTEGER DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS oauth_tokens (
                    id SERIAL PRIMARY KEY,
                    token_hash VARCHAR(191) NOT NULL UNIQUE,
                    client_id VARCHAR(191),
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    scopes TEXT DEFAULT 'read write follow push',
                    expires_at INTEGER NOT NULL,
                    revoked_at INTEGER DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS user_mutes (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    target_account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, target_account_id)
                )",
                "CREATE TABLE IF NOT EXISTS user_blocks (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    target_account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, target_account_id)
                )",
                "CREATE TABLE IF NOT EXISTS mentions (
                    status_id INTEGER NOT NULL,
                    account_id INTEGER NOT NULL,
                    PRIMARY KEY(status_id, account_id)
                )",
                "CREATE TABLE IF NOT EXISTS status_hashtags (
                    status_id INTEGER NOT NULL,
                    tag VARCHAR(191) NOT NULL,
                    PRIMARY KEY(status_id, tag)
                )",
                "CREATE TABLE IF NOT EXISTS notifications (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL,
                    type VARCHAR(64) NOT NULL,
                    from_account_id INTEGER NOT NULL,
                    status_id INTEGER DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    read_at TIMESTAMP DEFAULT NULL
                )",
                "CREATE TABLE IF NOT EXISTS markers (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL,
                    timeline VARCHAR(64) NOT NULL,
                    last_read_id VARCHAR(191) NOT NULL,
                    version INTEGER NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(account_id, timeline)
                )",
            ];
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_statuses_account_id ON statuses(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_in_reply_to_id ON statuses(in_reply_to_id)",
                "CREATE INDEX IF NOT EXISTS idx_follows_account_id ON follows(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_follows_target_account_id ON follows(target_account_id)",
                "CREATE INDEX IF NOT EXISTS idx_favourites_status_id ON favourites(status_id)",
                "CREATE INDEX IF NOT EXISTS idx_favourites_account_id ON favourites(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_bookmarks_account_id ON bookmarks(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_bookmarks_status_id ON bookmarks(status_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_reblog_of_id ON statuses(reblog_of_id)",
                "CREATE INDEX IF NOT EXISTS idx_web_push_subscriptions_account_id ON web_push_subscriptions(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_idempotency_key ON statuses(account_id, idempotency_key)",
                "CREATE INDEX IF NOT EXISTS idx_reports_account_id ON reports(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_reports_target_account_id ON reports(target_account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_in_reply_to_account_id ON statuses(in_reply_to_account_id)",
                "CREATE INDEX IF NOT EXISTS idx_admin_sessions_selector ON admin_sessions(selector)",
                "CREATE INDEX IF NOT EXISTS idx_admin_sessions_account_id ON admin_sessions(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_is_profile_fetch ON statuses(is_profile_fetch)",
                "CREATE INDEX IF NOT EXISTS idx_jobs_status_next_attempt ON jobs(status, next_attempt)",
                "CREATE INDEX IF NOT EXISTS idx_follows_account_status_target ON follows(account_id, status, target_account_id)",
                "CREATE INDEX IF NOT EXISTS idx_follows_target_status_account ON follows(target_account_id, status, account_id)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_account_id_id_desc ON statuses(account_id, id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_created_at ON statuses(created_at)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_visibility_id_desc ON statuses(visibility, id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_accounts_url ON accounts(url)",
                "CREATE INDEX IF NOT EXISTS idx_accounts_inbox_url ON accounts(inbox_url)",
                "CREATE INDEX IF NOT EXISTS idx_poll_votes_poll_id ON poll_votes(poll_id)",
                "CREATE INDEX IF NOT EXISTS idx_oauth_tokens_account_id ON oauth_tokens(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_oauth_codes_expires ON oauth_codes(expires_at)",
                "CREATE INDEX IF NOT EXISTS idx_user_mutes_account_id ON user_mutes(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_blocks_account_id ON user_blocks(account_id)",
                "CREATE INDEX IF NOT EXISTS idx_mentions_acc ON mentions(account_id, status_id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_status_tags_tag ON status_hashtags(tag, status_id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_notif_acc_id ON notifications(account_id, id DESC)",
                "CREATE INDEX IF NOT EXISTS idx_statuses_fts ON statuses USING GIN (to_tsvector('simple', coalesce(content, '')))",
                "CREATE INDEX IF NOT EXISTS idx_accounts_fts ON accounts USING GIN (to_tsvector('simple', coalesce(username, '') || ' ' || coalesce(display_name, '') || ' ' || coalesce(note, '')))",
            ];
        } else {
            // mysql / MariaDB
            $tables = [
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    version INT PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS options (
                    `key` VARCHAR(191) PRIMARY KEY,
                    `value` LONGTEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(191) NOT NULL,
                    domain VARCHAR(191) NULL,
                    email VARCHAR(191) NULL,
                    password_hash VARCHAR(255) NULL,
                    display_name VARCHAR(255) NULL,
                    note LONGTEXT NULL,
                    avatar TEXT NULL,
                    header TEXT NULL,
                    avatar_description TEXT NULL,
                    header_description TEXT NULL,
                    inbox_url VARCHAR(191) NULL,
                    public_key TEXT NOT NULL,
                    private_key LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    fields LONGTEXT NULL,
                    otp_secret VARCHAR(191) NULL,
                    role VARCHAR(32) NOT NULL DEFAULT 'user',
                    locked TINYINT(1) NOT NULL DEFAULT 0,
                    discoverable TINYINT(1) NOT NULL DEFAULT 1,
                    searchable TINYINT(1) NOT NULL DEFAULT 1,
                    indexable TINYINT(1) NOT NULL DEFAULT 1,
                    show_source TINYINT(1) NOT NULL DEFAULT 1,
                    followers_count INT DEFAULT 0,
                    following_count INT DEFAULT 0,
                    statuses_count INT DEFAULT 0,
                    smtp_host VARCHAR(191) NULL,
                    smtp_port INT NULL,
                    smtp_user VARCHAR(191) NULL,
                    smtp_pass VARCHAR(255) NULL,
                    smtp_from VARCHAR(191) NULL,
                    email_notifications TINYINT(1) DEFAULT 0,
                    attribution_domains LONGTEXT NULL,
                    emojis LONGTEXT NULL,
                    url VARCHAR(191) NULL,
                    notifications_read_at DATETIME NULL,
                    also_known_as LONGTEXT NULL,
                    statuses_last_fetched_at DATETIME NULL,
                    UNIQUE KEY uq_accounts_username_domain (username, domain)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS statuses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    uri VARCHAR(191) NOT NULL,
                    content LONGTEXT NOT NULL,
                    visibility VARCHAR(32) NOT NULL DEFAULT 'public',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    in_reply_to_id INT NULL,
                    sensitive TINYINT(1) NOT NULL DEFAULT 0,
                    spoiler_text VARCHAR(500) NOT NULL DEFAULT '',
                    media_attachments LONGTEXT NULL,
                    language VARCHAR(16) NULL,
                    card LONGTEXT NULL,
                    reblog_of_id INT NULL,
                    emojis LONGTEXT NULL,
                    idempotency_key VARCHAR(191) NULL,
                    in_reply_to_url TEXT NULL,
                    in_reply_to_account_id INT NULL,
                    is_profile_fetch TINYINT(1) NOT NULL DEFAULT 0,
                    UNIQUE KEY uq_statuses_uri (uri),
                    CONSTRAINT fk_statuses_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_statuses_reply FOREIGN KEY (in_reply_to_id) REFERENCES statuses(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS follows (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    target_account_id INT NOT NULL,
                    uri VARCHAR(191) NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    only_replies_to_me TINYINT(1) NOT NULL DEFAULT 0,
                    show_reblogs TINYINT(1) NOT NULL DEFAULT 1,
                    UNIQUE KEY uq_follows_uri (uri),
                    CONSTRAINT fk_follows_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_follows_target FOREIGN KEY (target_account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS jobs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    activity_type VARCHAR(128) NOT NULL,
                    payload LONGTEXT NOT NULL,
                    inbox_url TEXT NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    next_attempt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS favourites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    status_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_favourites (account_id, status_id),
                    CONSTRAINT fk_favourites_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_favourites_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS bookmarks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    status_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_bookmarks (account_id, status_id),
                    CONSTRAINT fk_bookmarks_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_bookmarks_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS moderation_blocks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type VARCHAR(64) NOT NULL,
                    target VARCHAR(191) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_moderation_blocks (type, target)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS relays (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    inbox_url VARCHAR(191) NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_relays_inbox (inbox_url)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS polls (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    status_id INT NOT NULL,
                    question TEXT NOT NULL,
                    options_json LONGTEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    UNIQUE KEY uq_polls_status (status_id),
                    CONSTRAINT fk_polls_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS poll_votes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    poll_id INT NOT NULL,
                    account_id INT NOT NULL,
                    choice_index INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_poll_votes (poll_id, account_id),
                    CONSTRAINT fk_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
                    CONSTRAINT fk_poll_votes_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS updates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    from_version VARCHAR(64) NOT NULL,
                    to_version VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    backup_path TEXT NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    rolled_back_at DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS dismissed_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    notification_id VARCHAR(191) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_dismissed_notif (account_id, notification_id),
                    CONSTRAINT fk_dismissed_notif_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS lists (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    replies_policy VARCHAR(32) NOT NULL DEFAULT 'followed',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_lists_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS list_accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    list_id INT NOT NULL,
                    account_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_list_accounts (list_id, account_id),
                    CONSTRAINT fk_list_accounts_list FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
                    CONSTRAINT fk_list_accounts_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS collections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description VARCHAR(500) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_collections_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS collection_accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    collection_id INT NOT NULL,
                    account_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_collection_accounts (collection_id, account_id),
                    CONSTRAINT fk_collection_accounts_coll FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
                    CONSTRAINT fk_collection_accounts_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS followed_hashtags (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    hashtag VARCHAR(191) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_followed_hashtags (account_id, hashtag),
                    CONSTRAINT fk_followed_hashtags_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    endpoint VARCHAR(191) NOT NULL,
                    key_p256dh TEXT NOT NULL,
                    key_auth TEXT NOT NULL,
                    alerts TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_web_push_sub (account_id, endpoint),
                    CONSTRAINT fk_web_push_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS reports (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    target_account_id INT NOT NULL,
                    status_ids LONGTEXT NULL,
                    comment LONGTEXT NOT NULL,
                    forward TINYINT(1) NOT NULL DEFAULT 0,
                    category VARCHAR(64) NOT NULL DEFAULT 'other',
                    action_taken TINYINT(1) NOT NULL DEFAULT 0,
                    action_taken_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_reports_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reports_target FOREIGN KEY (target_account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS admin_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    selector VARCHAR(191) NOT NULL,
                    hashed_validator VARCHAR(255) NOT NULL,
                    expires_at INT NOT NULL,
                    ip VARCHAR(64) NULL,
                    user_agent TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_admin_sessions_selector (selector)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS oauth_apps (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_name VARCHAR(255) NOT NULL,
                    client_id VARCHAR(191) NOT NULL,
                    client_secret_hash VARCHAR(255) NOT NULL,
                    redirect_uris TEXT NOT NULL,
                    scopes VARCHAR(255) DEFAULT 'read write follow push',
                    website VARCHAR(500) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_oauth_apps_client_id (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS oauth_codes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code_hash VARCHAR(191) NOT NULL,
                    client_id VARCHAR(191) NOT NULL,
                    account_id INT NOT NULL,
                    redirect_uri TEXT NOT NULL,
                    scopes VARCHAR(255) DEFAULT 'read write follow push',
                    expires_at INT NOT NULL,
                    used_at INT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_oauth_codes_hash (code_hash),
                    CONSTRAINT fk_oauth_codes_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS oauth_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    token_hash VARCHAR(191) NOT NULL,
                    client_id VARCHAR(191) NULL,
                    account_id INT NOT NULL,
                    scopes VARCHAR(255) DEFAULT 'read write follow push',
                    expires_at INT NOT NULL,
                    revoked_at INT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_oauth_tokens_hash (token_hash),
                    CONSTRAINT fk_oauth_tokens_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS user_mutes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    target_account_id INT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_user_mutes (account_id, target_account_id),
                    CONSTRAINT fk_user_mutes_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_mutes_target FOREIGN KEY (target_account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS user_blocks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    target_account_id INT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_user_blocks (account_id, target_account_id),
                    CONSTRAINT fk_user_blocks_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_blocks_target FOREIGN KEY (target_account_id) REFERENCES accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS mentions (
                    status_id INT NOT NULL,
                    account_id INT NOT NULL,
                    PRIMARY KEY(status_id, account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS status_hashtags (
                    status_id INT NOT NULL,
                    tag VARCHAR(191) NOT NULL,
                    PRIMARY KEY(status_id, tag)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    type VARCHAR(64) NOT NULL,
                    from_account_id INT NOT NULL,
                    status_id INT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    read_at DATETIME DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS markers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    timeline VARCHAR(64) NOT NULL,
                    last_read_id VARCHAR(191) NOT NULL,
                    version INT NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_markers_account_timeline (account_id, timeline)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
            $indexes = [
                "CREATE INDEX idx_statuses_account_id ON statuses(account_id)",
                "CREATE INDEX idx_statuses_in_reply_to_id ON statuses(in_reply_to_id)",
                "CREATE INDEX idx_follows_account_id ON follows(account_id)",
                "CREATE INDEX idx_follows_target_account_id ON follows(target_account_id)",
                "CREATE INDEX idx_favourites_status_id ON favourites(status_id)",
                "CREATE INDEX idx_favourites_account_id ON favourites(account_id)",
                "CREATE INDEX idx_bookmarks_account_id ON bookmarks(account_id)",
                "CREATE INDEX idx_bookmarks_status_id ON bookmarks(status_id)",
                "CREATE INDEX idx_statuses_reblog_of_id ON statuses(reblog_of_id)",
                "CREATE INDEX idx_web_push_subscriptions_account_id ON web_push_subscriptions(account_id)",
                "CREATE INDEX idx_statuses_idempotency_key ON statuses(account_id, idempotency_key)",
                "CREATE INDEX idx_reports_account_id ON reports(account_id)",
                "CREATE INDEX idx_reports_target_account_id ON reports(target_account_id)",
                "CREATE INDEX idx_statuses_in_reply_to_account_id ON statuses(in_reply_to_account_id)",
                "CREATE INDEX idx_admin_sessions_selector ON admin_sessions(selector)",
                "CREATE INDEX idx_admin_sessions_account_id ON admin_sessions(account_id)",
                "CREATE INDEX idx_statuses_is_profile_fetch ON statuses(is_profile_fetch)",
                "CREATE INDEX idx_jobs_status_next_attempt ON jobs(status, next_attempt)",
                "CREATE INDEX idx_follows_account_status_target ON follows(account_id, status, target_account_id)",
                "CREATE INDEX idx_follows_target_status_account ON follows(target_account_id, status, account_id)",
                "CREATE INDEX idx_statuses_account_id_id_desc ON statuses(account_id, id DESC)",
                "CREATE INDEX idx_statuses_created_at ON statuses(created_at)",
                "CREATE INDEX idx_statuses_visibility_id_desc ON statuses(visibility, id DESC)",
                "CREATE INDEX idx_accounts_url ON accounts(url)",
                "CREATE INDEX idx_accounts_inbox_url ON accounts(inbox_url)",
                "CREATE INDEX idx_poll_votes_poll_id ON poll_votes(poll_id)",
                "CREATE INDEX idx_oauth_tokens_account_id ON oauth_tokens(account_id)",
                "CREATE INDEX idx_oauth_codes_expires ON oauth_codes(expires_at)",
                "CREATE INDEX idx_user_mutes_account_id ON user_mutes(account_id)",
                "CREATE INDEX idx_user_blocks_account_id ON user_blocks(account_id)",
                "CREATE INDEX idx_mentions_acc ON mentions(account_id, status_id DESC)",
                "CREATE INDEX idx_status_tags_tag ON status_hashtags(tag, status_id DESC)",
                "CREATE INDEX idx_notif_acc_id ON notifications(account_id, id DESC)",
                "CREATE FULLTEXT INDEX ft_statuses_content ON statuses(content)",
                "CREATE FULLTEXT INDEX ft_accounts_search ON accounts(username, display_name, note)",
            ];
        }

        foreach ($tables as $sql) {
            $db->exec($sql);
        }
        foreach ($indexes as $idxSql) {
            try {
                $db->exec($idxSql);
            } catch (\PDOException $ex) {
                $msg = strtolower($ex->getMessage());
                if (!str_contains($msg, 'already exists') && !str_contains($msg, 'duplicate key name')) {
                    throw $ex;
                }
            }
        }

        // Registrar versiones 1..28 en schema_migrations
        $stmtApplied = $db->query("SELECT version FROM schema_migrations");
        $applied = $stmtApplied->fetchAll(PDO::FETCH_COLUMN);
        $now = self::nowUtc();
        $insMig = $db->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)");
        for ($v = 1; $v <= 28; $v++) {
            if (!in_array($v, $applied)) {
                $insMig->execute([$v, $now]);
            }
        }
    }

    public static function verifySsl(): bool {
        return defined('KUTSOCIAL_VERIFY_SSL') ? (bool)KUTSOCIAL_VERIFY_SSL : true;
    }
}
