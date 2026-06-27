<?php
// Evitar ejecución si no está el token correcto
if (($_GET['token'] ?? '') !== 'kutsocial_dev') {
    http_response_code(403);
    exit("No autorizado");
}

require_once __DIR__ . '/config.php';
use KutSocial\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    echo "=== LOADING CONTROLLERS AND DB FOR SYNTAX CHECK ===\n";
    require_once __DIR__ . '/src/Database.php';
    require_once __DIR__ . '/src/Controllers/MastodonApiController.php';
    require_once __DIR__ . '/src/Controllers/ActivityPubController.php';
    echo "ALL CLASSES LOADED SUCCESSFULLY\n\n";

    Database::setDbPath(KUTSOCIAL_DB_PATH);
    $db = Database::connect();
    
    echo "=== DB MIGRATIONS ===\n";
    try {
        $applied = $db->query("SELECT version FROM schema_migrations ORDER BY version ASC")->fetchAll(PDO::FETCH_COLUMN);
        echo "Applied migrations: " . implode(", ", $applied) . "\n";
    } catch (\Throwable $e) {
        echo "Error querying schema_migrations: " . $e->getMessage() . "\n";
    }

    echo "=== STATUSES TABLE COLUMNS ===\n";
    try {
        $cols = $db->query("PRAGMA table_info(statuses)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo "Column: " . $col['name'] . " (" . $col['type'] . ") - NotNull: " . $col['notnull'] . " - Default: " . $col['dflt_value'] . "\n";
        }
    } catch (\Throwable $e) {
        echo "Error querying statuses schema: " . $e->getMessage() . "\n";
    }

    echo "=== RUNNING TIMELINE SIMULATION ===\n";
    try {
        $limit = 15;
        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE s.visibility = 'public'
            ORDER BY s.id DESC
            LIMIT $limit
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        echo "Fetched " . count($rows) . " rows.\n";
        foreach ($rows as $row) {
            // No llamar a formatStatus desde aquí por ser private, solo verificar de forma segura
            echo "Fetched Status ID: " . $row['status_id'] . "\n";
        }
        echo "TIMELINE SIMULATION SUCCESS\n";
    } catch (\Throwable $e) {
        echo "TIMELINE ERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
    
    echo "=== SYSTEM CHECK ===\n";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
    echo "HTTPS server variable: " . ($_SERVER['HTTPS'] ?? 'off') . "\n";
    echo "X-Forwarded-Proto: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'none') . "\n";
    echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
    echo "DB Path: " . KUTSOCIAL_DB_PATH . "\n";
    
    // 1. Probar escritura de logs
    echo "\n=== LOG PERMISSION TEST ===\n";
    $logFile = dirname(KUTSOCIAL_DB_PATH) . '/activitypub.log';
    echo "Intentando escribir a: $logFile\n";
    $testWrite = @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] TEST LINE FROM DB_CHECK\n", FILE_APPEND);
    if ($testWrite === false) {
        echo "FAIL: No se puede escribir en el archivo de log.\n";
        $dir = dirname($logFile);
        echo "Permisos del directorio '$dir': " . substr(sprintf('%o', fileperms($dir)), -4) . "\n";
        echo "Usuario del script: " . get_current_user() . "\n";
    } else {
        echo "SUCCESS: Escritura de log correcta ($testWrite bytes escritos).\n";
    }

    echo "\n=== FOLLOWS ===\n";
    $follows = $db->query("SELECT * FROM follows ORDER BY id DESC LIMIT 20")->fetchAll();
    if (empty($follows)) {
        echo "No hay registros en follows\n";
    } else {
        print_r($follows);
    }
    
    echo "\n=== STATUSES ===\n";
    $statusesList = $db->query("SELECT id, account_id, uri, content, created_at FROM statuses ORDER BY id DESC LIMIT 20")->fetchAll();
    if (empty($statusesList)) {
        echo "No hay registros en statuses\n";
    } else {
        print_r($statusesList);
    }
    
    echo "\n=== ACCOUNTS ===\n";
    $accounts = $db->query("SELECT id, username, domain, inbox_url, created_at FROM accounts ORDER BY id DESC LIMIT 20")->fetchAll();
    print_r($accounts);
    
    echo "\n=== JOBS ===\n";
    $jobs = $db->query("SELECT id, activity_type, status, attempts, last_error, created_at FROM jobs ORDER BY id DESC LIMIT 20")->fetchAll();
    if (empty($jobs)) {
        echo "No hay registros en jobs\n";
    } else {
        print_r($jobs);
    }
    
    // 2. Probar petición local de Inbox
    echo "\n=== LOCAL INBOX ROUTING TEST ===\n";
    $testAccount = $db->query("SELECT id, username, public_key, private_key FROM accounts WHERE domain IS NULL AND private_key IS NOT NULL LIMIT 1")->fetch();
    if (!$testAccount) {
        echo "No se encontró cuenta local para firmar la petición de prueba del Inbox.\n";
    } else {
        $localUsername = $testAccount['username'];
        $privateKey = $testAccount['private_key'];
        $publicKey = $testAccount['public_key'];
        
        $mockUsername = 'test_actor';
        $mockDomain = 'remote.test';
        $mockActorUrl = "https://$mockDomain/users/$mockUsername";
        $mockInboxUrl = "https://$mockDomain/users/$mockUsername/inbox";
        
        // Insertar o actualizar actor remoto ficticio para la prueba
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO accounts (username, domain, display_name, note, inbox_url, public_key, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$mockUsername, $mockDomain, 'Test Remote Actor', 'Mock actor for local testing', $mockInboxUrl, $publicKey]);
        
        $host = $_SERVER['HTTP_HOST'] ?? 'kutsocial.ernestoacosta.org';
        $localUrl = "http://127.0.0.1/users/$localUsername/inbox";
        
        $body = json_encode([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => "https://$mockDomain/activities/test-follow-" . bin2hex(random_bytes(4)),
            'type' => 'Follow',
            'actor' => $mockActorUrl,
            'object' => "https://$host/users/$localUsername"
        ]);
        
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $path = "/users/$localUsername/inbox";
        
        // Generar firma HTTP
        $signingString = "(request-target): post $path\nhost: $host\ndate: $date\ndigest: $digest";
        $sig = '';
        if (openssl_sign($signingString, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
            $keyId = "$mockActorUrl#main-key";
            $sigHeader = sprintf(
                'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
                $keyId,
                base64_encode($sig)
            );
            
            echo "Haciendo POST firmado a: $localUrl (con Host: $host)\n";
            $ch = curl_init($localUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    "Host: $host",
                    "Content-Type: application/activity+json",
                    "Date: $date",
                    "Digest: $digest",
                    "Signature: $sigHeader",
                    "X-Forwarded-Proto: https"
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            
            echo "HTTP Status Code: $httpCode\n";
            if ($curlErr) {
                echo "Curl Error: $curlErr\n";
            } else {
                echo "Response Body: " . substr($resp, 0, 300) . "\n";
            }
        } else {
            echo "Error al generar la firma para la petición de prueba.\n";
        }
    }

    // 3. Probar petición local de Timeline Público
    echo "\n=== LOCAL PUBLIC TIMELINE ROUTING TEST ===\n";
    $host = $_SERVER['HTTP_HOST'] ?? 'kutsocial.ernestoacosta.org';
    $timelineUrl = "http://127.0.0.1/api/v1/timelines/public?limit=15";
    echo "Haciendo GET a: $timelineUrl (con Host: $host)\n";
    $ch = curl_init($timelineUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Host: $host",
            "X-Forwarded-Proto: https"
        ],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Status Code: $httpCode\n";
    if ($curlErr) {
        echo "Curl Error: $curlErr\n";
    } else {
        echo "Response Body: " . $resp . "\n";
    }

    // 4. Probar petición local de Búsqueda
    echo "\n=== LOCAL SEARCH ROUTING TEST ===\n";
    $searchUrl = "http://127.0.0.1/api/v2/search?q=elav";
    echo "Haciendo GET a: $searchUrl (con Host: $host)\n";
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Host: $host",
            "X-Forwarded-Proto: https"
        ],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Status Code: $httpCode\n";
    if ($curlErr) {
        echo "Curl Error: $curlErr\n";
    } else {
        echo "Response Body: " . substr($resp, 0, 300) . "\n";
    }

    echo "\n=== LOG FILE (activitypub.log) ===\n";
    if (file_exists($logFile)) {
        echo file_get_contents($logFile);
    } else {
        echo "El archivo de log no existe en: $logFile\n";
    }

    $dbErrFile = dirname(KUTSOCIAL_DB_PATH) . '/db_error.txt';
    echo "\n=== DB ERROR LOG (db_error.txt) ===\n";
    if (file_exists($dbErrFile)) {
        echo file_get_contents($dbErrFile);
    } else {
        echo "No hay errores de base de datos registrados.\n";
    }

    $routeErrFile = dirname(KUTSOCIAL_DB_PATH) . '/route_error.txt';
    echo "\n=== ROUTE ERROR LOG (route_error.txt) ===\n";
    if (file_exists($routeErrFile)) {
        echo file_get_contents($routeErrFile);
    } else {
        echo "No hay errores de enrutamiento registrados.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
