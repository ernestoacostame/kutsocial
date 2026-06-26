<?php
/**
 * KutSocial - Script de Pruebas Automatizadas
 * Verifica base de datos SQLite WAL, FTS5, Colas, Autenticación y Criptografía RSA.
 */

// Simular entorno CLI
if (php_sapi_name() !== 'cli') {
    echo "Este script solo puede ejecutarse desde la terminal.\n";
    exit(1);
}

echo "========================================================================\n";
echo "           Iniciando Suite de Pruebas de KutSocial\n";
echo "========================================================================\n\n";

// Definir constante temporal para testing si no está instalado
if (!defined('KUTSOCIAL_DB_PATH')) {
    define('KUTSOCIAL_DB_PATH', __DIR__ . '/data/kutsocial_test.db');
}

// Autocargar
spl_autoload_register(function ($class) {
    $prefix = 'KutSocial\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use KutSocial\Database;
use KutSocial\Queue;
use KutSocial\UpdaterService;
use KutSocial\Controllers\MastodonApiController;

Database::setDbPath(KUTSOCIAL_DB_PATH);

// Limpiar base de datos de prueba anterior
if (file_exists(KUTSOCIAL_DB_PATH)) {
    @unlink(KUTSOCIAL_DB_PATH);
}
if (file_exists(KUTSOCIAL_DB_PATH . '-wal')) {
    @unlink(KUTSOCIAL_DB_PATH . '-wal');
}
if (file_exists(KUTSOCIAL_DB_PATH . '-shm')) {
    @unlink(KUTSOCIAL_DB_PATH . '-shm');
}

$success_count = 0;
$fail_count = 0;

function assertTest(string $name, bool $expression, string $errorMsg = ''): void {
    global $success_count, $fail_count;
    if ($expression) {
        echo "  [✓] PASÓ: $name\n";
        $success_count++;
    } else {
        echo "  [✗] FALLÓ: $name - $errorMsg\n";
        $fail_count++;
    }
}

// --- Prueba 1: Conexión SQLite y modo WAL ---
try {
    $db = Database::connect();
    $journal_mode = $db->query("PRAGMA journal_mode")->fetchColumn();
    assertTest("SQLite Conexión y Modo WAL", strtolower($journal_mode) === 'wal', "El modo de diario es $journal_mode en lugar de WAL.");
} catch (Exception $e) {
    assertTest("SQLite Conexión y Modo WAL", false, $e->getMessage());
}

// --- Prueba 2: Migraciones y Esquema de tablas ---
try {
    Database::runMigrations();
    
    // Verificar si las tablas requeridas fueron creadas
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['options', 'accounts', 'statuses', 'follows', 'jobs', 'schema_migrations'];
    $all_found = true;
    $missing = [];
    foreach ($required as $req) {
        if (!in_array($req, $tables)) {
            $all_found = false;
            $missing[] = $req;
        }
    }
    assertTest("Inicialización de tablas de base de datos", $all_found, "Faltan las tablas: " . implode(', ', $missing));
} catch (Exception $e) {
    assertTest("Inicialización de tablas de base de datos", false, $e->getMessage());
}

// --- Prueba 3: SQLite FTS5 Búsqueda de Posts y Perfiles ---
try {
    // Insertar un usuario y un post
    $db->exec("
        INSERT INTO accounts (username, display_name, note, public_key, created_at)
        VALUES ('johndoe', 'John Doe', 'Me gusta PHP y SQLite en modo WAL', 'dummy_key', datetime('now'))
    ");
    $user_id = $db->lastInsertId();

    $db->exec("
        INSERT INTO statuses (account_id, uri, content, created_at)
        VALUES ($user_id, 'https://test.local/statuses/1', 'Hola Fediverso! Este es un post ligero de prueba.', datetime('now'))
    ");
    $post_id = $db->lastInsertId();

    // Consultar tabla virtual FTS de cuentas
    $search_acc = $db->query("SELECT rowid FROM accounts_fts WHERE accounts_fts MATCH 'SQLite'")->fetchColumn();
    // Consultar tabla virtual FTS de posts
    $search_post = $db->query("SELECT rowid FROM statuses_fts WHERE statuses_fts MATCH 'Fediverso'")->fetchColumn();

    assertTest(
        "SQLite FTS5 Búsqueda de Perfiles e Indexación Automática",
        (int)$search_acc === (int)$user_id,
        "No se indexó el perfil o falló la búsqueda FTS."
    );
    assertTest(
        "SQLite FTS5 Búsqueda de Publicaciones e Indexación Automática",
        (int)$search_post === (int)$post_id,
        "No se indexó la publicación o falló la búsqueda FTS."
    );
} catch (Exception $e) {
    assertTest("Búsqueda FTS5", false, $e->getMessage());
}

// --- Prueba 4: Sistema de Colas (Jobs) ---
try {
    $payload = ['actor' => 'https://test.local/users/johndoe', 'type' => 'Create'];
    $jobId = Queue::enqueue('Create', $payload, 'https://remote.inbox/inbox');
    
    // Verificar que esté encolada
    $job = $db->query("SELECT * FROM jobs WHERE id = $jobId")->fetch();
    assertTest("Encolado de tareas (Jobs)", $job && $job['status'] === 'pending' && $job['attempts'] === 0, "No se encoló la tarea correctamente.");

    // Procesar la cola (debería intentar enviar e incurrir en error debido a URL remota ficticia)
    $processed = Queue::process(1);
    
    $job_after = $db->query("SELECT * FROM jobs WHERE id = $jobId")->fetch();
    assertTest(
        "Procesamiento de cola y registro de fallos con reintentos",
        $processed === 1 && $job_after['status'] === 'failed' && $job_after['attempts'] === 1 && !empty($job_after['last_error']),
        "El procesamiento no falló con reintentos o no registró el log de error."
    );
} catch (Exception $e) {
    assertTest("Sistema de Colas", false, $e->getMessage());
}

// --- Prueba 5: Criptografía RSA ---
try {
    $config_args = array(
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );
    $res = openssl_pkey_new($config_args);
    $key_ok = false;
    if ($res) {
        openssl_pkey_export($res, $privKey);
        $pubKey = openssl_pkey_get_details($res)["key"];
        if (str_contains($privKey, 'BEGIN PRIVATE KEY') && str_contains($pubKey, 'BEGIN PUBLIC KEY')) {
            $key_ok = true;
        }
    }
    assertTest("Criptografía RSA (Generación de par de llaves)", $key_ok, "Fallo al generar o exportar llaves RSA.");
} catch (Exception $e) {
    assertTest("Criptografía RSA", false, $e->getMessage());
}

// --- Prueba 6: UpdaterService (Verificación y Respaldo de DB) ---
try {
    $updater = new UpdaterService();
    // Probar backup de base de datos
    $backupPath = $updater->backupDatabase();
    $backup_exists = file_exists($backupPath);
    
    assertTest(
        "UpdaterService - Respaldo instantáneo de SQLite",
        $backup_exists,
        "No se creó el archivo de copia de seguridad de la base de datos."
    );
    
    // Limpieza de backup
    if ($backup_exists) {
        @unlink($backupPath);
    }
} catch (Exception $e) {
    assertTest("UpdaterService", false, $e->getMessage());
}

// --- Prueba 7: Consulta de Timelines (JOINs SQLite) ---
try {
    $db = Database::connect();
    // Ejecutar la consulta exacta usada en getPublicTimeline
    $stmt = $db->prepare("
        SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
               s.visibility as status_visibility, s.created_at as status_created_at, 
               a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
               a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
        FROM statuses s
        JOIN accounts a ON s.account_id = a.id
        ORDER BY s.id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    
    assertTest(
        "Consulta SQL de Timelines (JOINs SQLite)",
        is_array($row) && isset($row['status_content']) && $row['username'] === 'johndoe',
        "La consulta de timeline falló al recuperar el registro unido."
    );
} catch (Exception $e) {
    assertTest("Consulta SQL de Timelines", false, $e->getMessage());
}

// --- Prueba 8: Expresión Regular de Verificación de Enlace rel="me" ---
try {
    $profileUrl = "http://localhost/users/johndoe";
    $escapedUrl = preg_quote($profileUrl, '/');
    
    // Caso 1: rel="me" después de href
    $html1 = '<html><body><a href="http://localhost/users/johndoe" rel="me">Mi Perfil</a></body></html>';
    // Caso 2: rel="me" antes de href
    $html2 = '<html><body><a rel="me nofollow" href="http://localhost/users/johndoe">Mi Perfil</a></body></html>';
    // Caso 3: Sin rel="me"
    $html3 = '<html><body><a href="http://localhost/users/johndoe">Mi Perfil</a></body></html>';
    
    $pattern1 = '/<a\s+[^>]*href=["\']' . $escapedUrl . '["\'][^>]*rel=["\'][^"\']*me[^"\']*["\']/i';
    $pattern2 = '/<a\s+[^>]*rel=["\'][^"\']*me[^"\']*["\']\s+[^>]*href=["\']' . $escapedUrl . '["\']/i';
    
    $match1 = preg_match($pattern1, $html1) || preg_match($pattern2, $html1);
    $match2 = preg_match($pattern1, $html2) || preg_match($pattern2, $html2);
    $match3 = preg_match($pattern1, $html3) || preg_match($pattern2, $html3);
    
    assertTest(
        "Expresión Regular rel=\"me\" (Verificación de enlace de regreso)",
        $match1 && $match2 && !$match3,
        "La lógica de expresiones regulares rel=\"me\" falló."
    );
} catch (Exception $e) {
    assertTest("Verificación rel=\"me\"", false, $e->getMessage());
}

// --- Prueba 9: Creación de Hilos (in_reply_to_id) ---
try {
    // Ya tenemos $user_id y $post_id (que es el primer status) creados en la Prueba 3.
    // Insertamos una respuesta al primer post
    $db->exec("
        INSERT INTO statuses (account_id, uri, content, in_reply_to_id, created_at)
        VALUES ($user_id, 'https://test.local/statuses/2', 'Esta es una respuesta al post 1.', $post_id, datetime('now'))
    ");
    $reply1_id = $db->lastInsertId();

    // Insertamos otra respuesta, esta vez a la respuesta anterior (hilo multinivel)
    $db->exec("
        INSERT INTO statuses (account_id, uri, content, in_reply_to_id, created_at)
        VALUES ($user_id, 'https://test.local/statuses/3', 'Esta es una respuesta a la respuesta.', $reply1_id, datetime('now'))
    ");
    $reply2_id = $db->lastInsertId();

    // Verificamos que se hayan guardado correctamente las relaciones
    $stmt = $db->prepare("SELECT in_reply_to_id FROM statuses WHERE id = ?");
    $stmt->execute([$reply1_id]);
    $parent1 = $stmt->fetchColumn();

    $stmt->execute([$reply2_id]);
    $parent2 = $stmt->fetchColumn();

    assertTest(
        "Relaciones de hilos (in_reply_to_id)",
        (int)$parent1 === (int)$post_id && (int)$parent2 === (int)$reply1_id,
        "Las referencias in_reply_to_id no coinciden."
    );
} catch (Exception $e) {
    assertTest("Creación de Hilos", false, $e->getMessage());
}

// --- Prueba 10: Favoritos (Inserción y Prevención de Duplicados) ---
try {
    // Insertar favorito
    $db->exec("INSERT INTO favourites (account_id, status_id) VALUES ($user_id, $post_id)");
    
    // Verificar que existe
    $fav_exists = $db->query("SELECT COUNT(*) FROM favourites WHERE account_id = $user_id AND status_id = $post_id")->fetchColumn();
    $has_fav = ($fav_exists == 1);

    // Intentar insertar duplicado (debería fallar por UNIQUE constraint)
    $duplicate_failed = false;
    try {
        $db->exec("INSERT INTO favourites (account_id, status_id) VALUES ($user_id, $post_id)");
    } catch (PDOException $e) {
        $duplicate_failed = true;
    }

    assertTest(
        "Favoritos - Inserción y Prevención de Duplicados",
        $has_fav && $duplicate_failed,
        "Falló la inserción o no se previno la duplicación de favoritos."
    );
} catch (Exception $e) {
    assertTest("Favoritos", false, $e->getMessage());
}

// --- Prueba 11: Marcadores (Bookmarks - Inserción y Prevención de Duplicados) ---
try {
    // Insertar marcador
    $db->exec("INSERT INTO bookmarks (account_id, status_id) VALUES ($user_id, $post_id)");
    
    // Verificar que existe
    $bookmark_exists = $db->query("SELECT COUNT(*) FROM bookmarks WHERE account_id = $user_id AND status_id = $post_id")->fetchColumn();
    $has_bookmark = ($bookmark_exists == 1);

    // Intentar insertar duplicado (debería fallar por UNIQUE constraint)
    $duplicate_failed = false;
    try {
        $db->exec("INSERT INTO bookmarks (account_id, status_id) VALUES ($user_id, $post_id)");
    } catch (PDOException $e) {
        $duplicate_failed = true;
    }

    assertTest(
        "Marcadores - Inserción y Prevención de Duplicados",
        $has_bookmark && $duplicate_failed,
        "Falló la inserción o no se previno la duplicación de marcadores."
    );
} catch (Exception $e) {
    assertTest("Marcadores", false, $e->getMessage());
}

// --- Prueba 12: Consulta Jerárquica del Contexto de Hilo ---
try {
    // Vamos a reproducir el algoritmo de getStatusContext para el status $reply2_id
    // Antecesores
    $ancestors = [];
    $parentId = $reply1_id; // in_reply_to_id de $reply2_id
    while ($parentId !== null) {
        $stmt = $db->prepare("SELECT id, in_reply_to_id FROM statuses WHERE id = ?");
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();
        if ($parent) {
            array_unshift($ancestors, (int)$parent['id']);
            $parentId = $parent['in_reply_to_id'];
        } else {
            break;
        }
    }

    // Descendientes de $post_id
    $descendants = [];
    $replyQueue = [$post_id];
    while (!empty($replyQueue)) {
        $currentId = array_shift($replyQueue);
        $stmtReplies = $db->prepare("SELECT id FROM statuses WHERE in_reply_to_id = ? ORDER BY id ASC");
        $stmtReplies->execute([$currentId]);
        $replies = $stmtReplies->fetchAll(PDO::FETCH_COLUMN);

        foreach ($replies as $rowId) {
            $descendants[] = (int)$rowId;
            $replyQueue[] = (int)$rowId;
        }
    }

    $ancestors_ok = ($ancestors === [(int)$post_id, (int)$reply1_id]);
    $descendants_ok = ($descendants === [(int)$reply1_id, (int)$reply2_id]);

    assertTest(
        "Consulta Jerárquica de Hilo (Ancestros y Descendientes)",
        $ancestors_ok && $descendants_ok,
        "La jerarquía recuperada no coincide. Ancestros: " . json_encode($ancestors) . ", Descendientes: " . json_encode($descendants)
    );
} catch (Exception $e) {
    assertTest("Consulta Jerárquica de Hilo", false, $e->getMessage());
}

// --- Prueba 13: Verificación de Triggers FTS5 en Updates ---
try {
    // Intentar actualizar el perfil del usuario (dispara trg_accounts_au)
    $db->exec("UPDATE accounts SET display_name = 'John Doe Editado' WHERE id = $user_id");
    
    // Verificar que se haya indexado el nuevo nombre en FTS5
    $search_updated_acc = $db->query("SELECT rowid FROM accounts_fts WHERE accounts_fts MATCH 'Editado'")->fetchColumn();
    $acc_trigger_ok = ((int)$search_updated_acc === (int)$user_id);

    // Intentar actualizar un toot (dispara trg_statuses_au)
    $db->exec("UPDATE statuses SET content = 'Post 1 Editado' WHERE id = $post_id");
    
    // Verificar que se haya indexado el nuevo contenido en FTS5
    $search_updated_post = $db->query("SELECT rowid FROM statuses_fts WHERE statuses_fts MATCH 'Editado'")->fetchColumn();
    $post_trigger_ok = ((int)$search_updated_post === (int)$post_id);

    assertTest(
        "Triggers FTS5 en Modificaciones (Actualización de índices FTS)",
        $acc_trigger_ok && $post_trigger_ok,
        "La indexación FTS tras una actualización no funcionó correctamente."
    );
} catch (Exception $e) {
    assertTest("Triggers FTS5 en Modificaciones", false, $e->getMessage());
}

// --- Prueba 14: TotpHelper (Generación y Validación de TOTP 2FA) ---
try {
    $secret = \KutSocial\TotpHelper::generateSecret();
    $code = \KutSocial\TotpHelper::verifyCode($secret, '000000'); // incorrect code
    assertTest("TotpHelper - Rechazo de código inválido", !$code, "Se aceptó un código 000000 incorrecto.");

    $reflection = new ReflectionClass(\KutSocial\TotpHelper::class);
    $method = $reflection->getMethod('calculateCode');
    $method->setAccessible(true);
    
    $decodeMethod = $reflection->getMethod('base32Decode');
    $decodeMethod->setAccessible(true);
    $secretKey = $decodeMethod->invoke(null, $secret);

    $timeSlice = floor(time() / 30);
    $validCode = $method->invoke(null, $secretKey, $timeSlice);

    $verified = \KutSocial\TotpHelper::verifyCode($secret, $validCode);
    assertTest("TotpHelper - Aceptación de código válido actual", $verified, "No se aceptó el código válido actual.");

    $prevCode = $method->invoke(null, $secretKey, $timeSlice - 1);
    $verifiedPrev = \KutSocial\TotpHelper::verifyCode($secret, $prevCode);
    assertTest("TotpHelper - Aceptación de código con discrepancia (-30s)", $verifiedPrev, "No se aceptó el código del bloque anterior.");
} catch (Exception $e) {
    assertTest("TotpHelper - Generación y Validación de TOTP", false, $e->getMessage());
}

// --- Prueba 15: Sistema de Encuestas (Creación y Voto Único) ---
try {
    $db = Database::connect();
    $db->exec("
        INSERT INTO statuses (account_id, uri, content, created_at)
        VALUES ($user_id, 'https://test.local/statuses/poll-test', '¿Cuál es tu lenguaje favorito?', datetime('now'))
    ");
    $poll_status_id = $db->lastInsertId();

    $options = json_encode([
        ['title' => 'PHP', 'votes_count' => 0],
        ['title' => 'Python', 'votes_count' => 0]
    ]);
    
    $db->prepare("
        INSERT INTO polls (status_id, question, options_json, expires_at)
        VALUES (?, ?, ?, datetime('now', '+1 day'))
    ")->execute([$poll_status_id, '¿Cuál es tu lenguaje favorito?', $options]);
    $poll_id = $db->lastInsertId();

    assertTest("Creación de encuesta", $poll_id > 0, "No se creó la encuesta en la base de datos.");

    $db->prepare("
        INSERT INTO poll_votes (poll_id, account_id, choice_index)
        VALUES (?, ?, ?)
    ")->execute([$poll_id, $user_id, 0]);
    $vote_id = $db->lastInsertId();
    assertTest("Votación inicial en encuesta", $vote_id > 0, "No se registró el voto.");

    $failed_dup = false;
    try {
        $db->prepare("
            INSERT INTO poll_votes (poll_id, account_id, choice_index)
            VALUES (?, ?, ?)
        ")->execute([$poll_id, $user_id, 1]);
    } catch (PDOException $ex) {
        $failed_dup = true;
    }
    assertTest("Restricción de voto único por cuenta", $failed_dup, "Se permitió duplicar voto para la misma cuenta.");
} catch (Exception $e) {
    assertTest("Sistema de Encuestas", false, $e->getMessage());
}

// --- Prueba 16: Moderación y Bloqueos (Filtrado de Timelines) ---
try {
    $db = Database::connect();
    
    $db->exec("INSERT OR IGNORE INTO moderation_blocks (type, target) VALUES ('domain', 'blocked-domain.com')");
    $db->exec("INSERT OR IGNORE INTO moderation_blocks (type, target) VALUES ('word', 'palabramala')");
    $db->exec("INSERT OR IGNORE INTO moderation_blocks (type, target) VALUES ('hashtag', '#spam')");

    $rows = [
        [
            'status_id' => 1,
            'status_content' => 'Este es un toot normal',
            'domain' => 'nice-domain.com',
            'username' => 'alice'
        ],
        [
            'status_id' => 2,
            'status_content' => 'Este es un toot de un dominio malo',
            'domain' => 'blocked-domain.com',
            'username' => 'bob'
        ],
        [
            'status_id' => 3,
            'status_content' => 'Contiene palabramala en el texto',
            'domain' => 'nice-domain.com',
            'username' => 'charlie'
        ],
        [
            'status_id' => 4,
            'status_content' => 'Contiene el hashtag #spam',
            'domain' => 'nice-domain.com',
            'username' => 'dan'
        ]
    ];

    $reflection = new ReflectionClass(\KutSocial\Controllers\MastodonApiController::class);
    $method = $reflection->getMethod('filterStatuses');
    $method->setAccessible(true);
    $filtered = $method->invoke(null, $rows);

    assertTest(
        "Filtros de Moderación - Cantidad de elementos filtrados",
        count($filtered) === 1,
        "Debería quedar exactamente 1 status de 4. Quedaron: " . count($filtered)
    );
    assertTest(
        "Filtros de Moderación - Contenido no bloqueado preservado",
        $filtered[0]['status_id'] === 1,
        "El status preservado no es el esperado."
    );
} catch (Exception $e) {
    assertTest("Filtros de Moderación", false, $e->getMessage());
}

// Limpiar base de datos de prueba al terminar
if (file_exists(KUTSOCIAL_DB_PATH)) {
    @unlink(KUTSOCIAL_DB_PATH);
}

echo "\n========================================================================\n";
echo "Resultados de las pruebas: $success_count exitosas, $fail_count fallidas.\n";
echo "========================================================================\n";

if ($fail_count > 0) {
    exit(1);
} else {
    exit(0);
}
