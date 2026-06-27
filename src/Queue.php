<?php
namespace KutSocial;

use PDO;
use Exception;
use RuntimeException;

class Queue {
    /**
     * Encola una actividad para entregar a un inbox remoto.
     */
    public static function enqueue(string $activityType, array $payload, string $inboxUrl): int {
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO jobs (activity_type, payload, inbox_url, status, next_attempt) VALUES (?, ?, ?, 'pending', datetime('now'))");
        $stmt->execute([
            $activityType,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $inboxUrl
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Dispara de manera no bloqueante (async) una llamada web al script de cron para procesar la cola.
     */
    public static function triggerAsync(string $domain = null): void {
        if (!$domain) {
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $url = "$proto://$domain/cron.php?async=1";

        $executed = false;
        // Ejecutar en segundo plano local por shell si es Unix (evita problemas de NAT Loopback local)
        $cronPath = realpath(__DIR__ . '/../cron.php');
        if ($cronPath && DIRECTORY_SEPARATOR === '/') {
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions') ?: '')))) {
                @exec("php " . escapeshellarg($cronPath) . " > /dev/null 2>&1 &");
                $executed = true;
            }
        }

        if (!$executed && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 150, // Timeout ligeramente mayor para dar margen
                CURLOPT_NOSIGNAL => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            @curl_exec($ch);
            @curl_close($ch);
        }

        // Registrar procesamiento al terminar el script de manera no bloqueante (si es posible con PHP-FPM)
        register_shutdown_function(function() {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                // Procesar hasta 3 tareas de la cola de forma sincrónica/secuencial al final de la petición
                self::process(3);
            } catch (\Exception $e) {
                // Silenciar errores
            }
        });
    }

    /**
     * Procesa una cantidad de tareas pendientes.
     */
    public static function process(int $limit = 10): int {
        $db = Database::connect();
        
        // Obtener tareas pendientes o fallidas con intentos menores a 5
        $stmt = $db->prepare("
            SELECT * FROM jobs 
            WHERE (status = 'pending' OR status = 'failed') 
              AND attempts < 5 
              AND next_attempt <= datetime('now') 
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll();

        $processed = 0;
        foreach ($jobs as $job) {
            $processed++;
            // Marcar como en proceso
            $upd = $db->prepare("UPDATE jobs SET status = 'processing', attempts = attempts + 1 WHERE id = ?");
            $upd->execute([$job['id']]);

            try {
                if ($job['activity_type'] === 'ImportFollow') {
                    $payloadArr = json_decode($job['payload'], true);
                    self::processImportFollow((int)$payloadArr['account_id'], $payloadArr['address']);
                    
                    $okStmt = $db->prepare("UPDATE jobs SET status = 'completed', last_error = NULL WHERE id = ?");
                    $okStmt->execute([$job['id']]);
                    continue;
                }

                $payloadArr = json_decode($job['payload'], true);
                $actorUrl = $payloadArr['actor'] ?? null;
                
                // Obtener llave privada del actor para firmar la petición
                $privateKey = null;
                $keyId = null;
                if ($actorUrl) {
                    // Extraer username del actor local: e.g. https://dominio.com/users/username
                    $path = parse_url($actorUrl, PHP_URL_PATH);
                    $path = rtrim($path, '/');
                    $pathParts = explode('/', $path);
                    $username = strtolower(end($pathParts));

                    $accStmt = $db->prepare("SELECT private_key, id FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
                    $accStmt->execute([$username]);
                    $account = $accStmt->fetch();
                    if ($account && !empty($account['private_key'])) {
                        $privateKey = $account['private_key'];
                        $keyId = $actorUrl . '#main-key';
                    }
                }

                // Si no se encuentra una llave privada local, intentar usar la de la cuenta de sistema
                if (!$privateKey) {
                    $accStmt = $db->prepare("SELECT private_key, username, domain FROM accounts WHERE private_key IS NOT NULL AND domain IS NULL LIMIT 1");
                    $accStmt->execute();
                    $account = $accStmt->fetch();
                    if ($account) {
                        $privateKey = $account['private_key'];
                        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $keyId = "$proto://$host/users/{$account['username']}#main-key";
                    }
                }

                if (!$privateKey) {
                    throw new Exception("No se encontró una clave privada para firmar la petición.");
                }

                self::sendSignedRequest($job['inbox_url'], $job['payload'], $privateKey, $keyId);

                // Éxito
                $okStmt = $db->prepare("UPDATE jobs SET status = 'completed', last_error = NULL WHERE id = ?");
                $okStmt->execute([$job['id']]);
            } catch (Exception $e) {
                // Fallido, calcular siguiente intento (backoff exponencial sencillo)
                $backoffSeconds = pow(3, $job['attempts'] + 1); // 9s, 27s, 81s, 243s...
                $next = date('Y-m-d H:i:s', time() + $backoffSeconds);

                $failStmt = $db->prepare("UPDATE jobs SET status = 'failed', last_error = ?, next_attempt = ? WHERE id = ?");
                $failStmt->execute([$e->getMessage(), $next, $job['id']]);
            }
        }

        return $processed;
    }

    /**
     * Envía una petición ActivityPub firmada mediante HTTP Signatures.
     */
    private static function sendSignedRequest(string $url, string $body, string $privateKey, string $keyId): void {
        $u = parse_url($url);
        if (empty($u['host'])) {
            throw new Exception("URL de inbox inválida: $url");
        }

        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $path = empty($u['path']) ? '/' : $u['path'];
        if (!empty($u['query'])) {
            $path .= '?' . $u['query'];
        }

        // String a firmar (draft-cavage-12)
        $signingString = "(request-target): post $path\nhost: {$u['host']}\ndate: $date\ndigest: $digest";
        
        $sig = '';
        if (!openssl_sign($signingString, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new Exception("Fallo en la firma RSA de la petición.");
        }

        $sigHeader = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
            $keyId,
            base64_encode($sig)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                "Host: {$u['host']}",
                "Date: $date",
                "Digest: $digest",
                "Signature: $sigHeader",
                "Content-Type: application/activity+json",
                "Accept: application/activity+json",
            ],
            CURLOPT_USERAGENT => 'KutSocial/1.0 (+' . (($keyId) ? explode('#', $keyId)[0] : 'https://' . ($u['host'])) . ')',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
            CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 400 || $resp === false) {
            throw new RuntimeException("Error HTTP $httpCode: $err. Respuesta: " . substr((string)$resp, 0, 200));
        }
    }

    /**
     * Resuelve e importa un seguimiento remoto en segundo plano.
     */
    private static function processImportFollow(int $accountId, string $address): void {
        $db = Database::connect();
        
        // 1. Obtener la cuenta local
        $stmtAcc = $db->prepare("SELECT id, username FROM accounts WHERE id = ? LIMIT 1");
        $stmtAcc->execute([$accountId]);
        $account = $stmtAcc->fetch();
        if (!$account) return;

        // 2. Resolver la cuenta a seguir
        $resolvedAcc = null;
        if (filter_var($address, FILTER_VALIDATE_URL)) {
            $resolvedAcc = \KutSocial\Controllers\ActivityPubController::getOrRegisterRemoteActor($address);
        } elseif (str_contains($address, '@')) {
            $resolvedAcc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($address);
        } else {
            $stmtLoc = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
            $stmtLoc->execute([$address]);
            $resolvedAcc = $stmtLoc->fetch();
        }

        if (!$resolvedAcc) {
            throw new Exception("No se pudo resolver el actor remoto: $address");
        }

        $targetId = $resolvedAcc['id'];
        $isRemote = !empty($resolvedAcc['domain']);
        $status = $isRemote ? 'pending' : (($resolvedAcc['locked'] ?? 0) ? 'pending' : 'accepted');
        
        $stmtCheck = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtCheck->execute([$accountId, $targetId]);
        if (!$stmtCheck->fetchColumn()) {
            $stmtIns = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, ?)");
            $stmtIns->execute([$accountId, $targetId, $status]);
            
            if ($isRemote && !empty($resolvedAcc['inbox_url'])) {
                $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $localActorUrl = "$proto://$domainName/users/{$account['username']}";
                $remoteActorUrl = $resolvedAcc['url'] ?? "$proto://{$resolvedAcc['domain']}/users/{$resolvedAcc['username']}";
                $followId = $localActorUrl . '/activities/follow-' . bin2hex(random_bytes(8));
                
                $followActivity = [
                    '@context' => 'https://www.w3.org/ns/activitystreams',
                    'id' => $followId,
                    'type' => 'Follow',
                    'actor' => $localActorUrl,
                    'object' => $remoteActorUrl
                ];
                
                \KutSocial\Queue::enqueue('Follow', $followActivity, $resolvedAcc['inbox_url']);
            }
        }
    }
}
