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
    Database::setDbPath(KUTSOCIAL_DB_PATH);
    $db = Database::connect();
    
    if (isset($_GET['reset_failed'])) {
        $db->query("UPDATE jobs SET status = 'pending', attempts = 0 WHERE status = 'failed'");
        echo "SUCCESS: Se han reiniciado todos los trabajos fallidos a estado 'pending'.\n\n";
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

    echo "\n=== LOG TAIL ===\n";
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $tail = array_slice($lines, -40);
        foreach ($tail as $line) {
            echo $line;
        }
    } else {
        echo "El archivo de log no existe.\n";
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
    
    echo "\n=== LOCAL ACCOUNTS (domain IS NULL) ===\n";
    $localAccounts = $db->query("SELECT id, username, domain, role, created_at FROM accounts WHERE domain IS NULL")->fetchAll();
    print_r($localAccounts);
    
    echo "\n=== ALL TABLES ===\n";
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);

    echo "\n=== APPLIED MIGRATIONS ===\n";
    $migrations = $db->query("SELECT version FROM schema_migrations ORDER BY version ASC")->fetchAll(PDO::FETCH_COLUMN);
    print_r($migrations);
    
    echo "\n=== FOLLOWS COUNT BY STATUS ===\n";
    $followsCount = $db->query("SELECT status, COUNT(*) as qty FROM follows GROUP BY status")->fetchAll();
    print_r($followsCount);

    echo "\n=== JOBS COUNT BY TYPE AND STATUS ===\n";
    $jobsCount = $db->query("SELECT activity_type, status, COUNT(*) as qty FROM jobs GROUP BY activity_type, status")->fetchAll();
    print_r($jobsCount);

    echo "\n=== FAILED JOBS SAMPLES ===\n";
    $failedJobs = $db->query("SELECT id, activity_type, attempts, last_error, created_at FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 10")->fetchAll();
    print_r($failedJobs);

    echo "\n=== AUTH LOGS SEARCH ===\n";
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $matches = [];
        foreach ($lines as $line) {
            if (str_contains($line, 'handleAuthorize') || str_contains($line, 'postToken')) {
                $matches[] = $line;
            }
        }
        $matchesTail = array_slice($matches, -40);
        foreach ($matchesTail as $matchLine) {
            echo $matchLine;
        }
    }

    echo "\n=== PHP ERROR LOG ===\n";
    $phpErrorLog = ini_get('error_log');
    if ($phpErrorLog && file_exists($phpErrorLog)) {
        echo "PHP Error Log path: $phpErrorLog\n";
        $errLines = @file($phpErrorLog);
        if ($errLines) {
            $errTail = array_slice($errLines, -40);
            foreach ($errTail as $errLine) {
                echo $errLine;
            }
        } else {
            echo "Failed to read PHP Error Log.\n";
        }
    } else {
        echo "PHP Error Log not found or not readable (path: $phpErrorLog)\n";
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
            
            // Limpiar los datos de prueba de base de datos para no generar notificaciones basura
            $stmtCleanNotif = $db->prepare("DELETE FROM notifications WHERE from_account_id IN (SELECT id FROM accounts WHERE username = 'test_actor' AND domain = 'remote.test')");
            $stmtCleanNotif->execute();
            $stmtCleanFollow = $db->prepare("DELETE FROM follows WHERE account_id IN (SELECT id FROM accounts WHERE username = 'test_actor' AND domain = 'remote.test') OR target_account_id IN (SELECT id FROM accounts WHERE username = 'test_actor' AND domain = 'remote.test')");
            $stmtCleanFollow->execute();
            $stmtCleanAcc = $db->prepare("DELETE FROM accounts WHERE username = 'test_actor' AND domain = 'remote.test'");
            $stmtCleanAcc->execute();
        } else {
            echo "Error al generar la firma para la petición de prueba.\n";
        }
    }

    // 3. Probar petición local de Búsqueda
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
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
