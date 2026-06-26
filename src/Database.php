<?php
namespace KutSocial;

use PDO;
use Exception;

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath = __DIR__ . '/../data/kutsocial.db';

    public static function setDbPath(string $path): void {
        self::$dbPath = $path;
    }

    public static function getDbPath(): string {
        return self::$dbPath;
    }

    public static function connect(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dir = dirname(self::$dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dsn = 'sqlite:' . self::$dbPath;
        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Optimización SQLite para concurrencia y seguridad
            $pdo->exec("PRAGMA journal_mode=WAL;");
            $pdo->exec("PRAGMA synchronous=NORMAL;");
            $pdo->exec("PRAGMA foreign_keys=ON;");
            $pdo->exec("PRAGMA busy_timeout=5000;");

            self::$instance = $pdo;
            return $pdo;
        } catch (Exception $e) {
            throw new Exception("Error al conectar a la base de datos SQLite: " . $e->getMessage());
        }
    }

    public static function runMigrations(): void {
        $db = self::connect();

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
                $db->beginTransaction();
                try {
                    foreach ($queries as $query) {
                        $db->exec($query);
                    }
                    $ins = $db->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
                    $ins->execute([$version]);
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    throw new Exception("Error aplicando migración versión $version: " . $e->getMessage());
                }
            }
        }
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
                "CREATE TRIGGER trg_statuses_au AFTER UPDATE ON statuses BEGIN
                    DELETE FROM statuses_fts WHERE rowid = old.id;
                    INSERT INTO statuses_fts(rowid, content) VALUES (new.id, new.content);
                END",
                "CREATE TRIGGER trg_accounts_ad AFTER DELETE ON accounts BEGIN
                    DELETE FROM accounts_fts WHERE rowid = old.id;
                END",
                "CREATE TRIGGER trg_accounts_au AFTER UPDATE ON accounts BEGIN
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
            ]
        ];
    }
}
