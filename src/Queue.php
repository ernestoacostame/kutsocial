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
        return Database::executeWithRetry(function() use ($activityType, $payload, $inboxUrl) {
            $db = Database::connect();
            $stmt = $db->prepare("INSERT INTO jobs (activity_type, payload, inbox_url, status, next_attempt) VALUES (?, ?, ?, 'pending', datetime('now'))");
            $stmt->execute([
                $activityType,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $inboxUrl
            ]);
            return (int)$db->lastInsertId();
        });
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
        // Detectar binario de PHP CLI si es posible
        $phpBin = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY && is_executable(PHP_BINARY)) {
            if (!str_contains(PHP_BINARY, 'fpm')) {
                $phpBin = PHP_BINARY;
            }
        }

        // 1. Prioridad: Ejecutar en segundo plano local por shell si es Unix
        $cronPath = realpath(__DIR__ . '/../cron.php');
        if ($cronPath && DIRECTORY_SEPARATOR === '/') {
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions') ?: '')))) {
                @exec(escapeshellcmd($phpBin) . " " . escapeshellarg($cronPath) . " > /dev/null 2>&1 &", $cmdOut, $retCode);
                if ($retCode === 0) {
                    $executed = true;
                }
            }
        }

        // 2. Si CLI no pudo ejecutarse, intentar trigger web async por cURL
        if (!$executed && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 1,
                CURLOPT_NOSIGNAL => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            @curl_exec($ch);
            @curl_close($ch);
            $executed = true;
        }

        // 3. Fallback: Solo registrar en shutdown si no se pudo invocar un proceso externo
        if (!$executed) {
            register_shutdown_function(function() {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                try {
                    self::process(10);
                } catch (\Exception $e) {
                    // Silenciar errores
                }
            });
        }
    }

    /**
     * Procesa una cantidad de tareas pendientes.
     */
    public static function process(int $limit = 10): int {
        // Mecanismo de exclusión mutua para evitar ejecuciones concurrentes de workers compitiendo por SQLite
        $lockFile = dirname(Database::getDbPath()) . '/queue_worker.lock';
        $lockFp = @fopen($lockFile, 'c');
        if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
            if ($lockFp) {
                @fclose($lockFp);
            }
            return 0;
        }

        try {
            $db = Database::connect();
            
            // Liberar tareas atascadas en 'processing' por más de 10 minutos
            try {
                Database::executeWithRetry(function() use ($db) {
                    $db->exec("UPDATE jobs SET status = 'pending' WHERE status = 'processing' AND next_attempt <= datetime('now', '-10 minutes')");
                });
            } catch (\Exception $e) {}
            
            // Purga periódica (1 de cada 50 ejecuciones) de tareas completadas con más de 1 día
            if (mt_rand(1, 50) === 1) {
                try {
                    Database::executeWithRetry(function() use ($db) {
                        $db->exec("DELETE FROM jobs WHERE status = 'completed' AND created_at < datetime('now', '-1 day')");
                    });
                } catch (\Exception $e) {}
            }

            // Obtener tareas pendientes o fallidas con intentos menores a 5
            $stmt = $db->prepare("
                SELECT * FROM jobs 
                WHERE (status = 'pending' OR status = 'failed') 
                  AND attempts < 5 
                  AND next_attempt <= datetime('now') 
                ORDER BY next_attempt, id
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $jobs = $stmt->fetchAll();

            $processed = 0;
            $httpJobs = [];
            $actorKeyCache = [];
            $defaultAccount = null;
            $defaultAccountLoaded = false;

            foreach ($jobs as $job) {
                $processed++;
                // Marcar como en proceso
                try {
                    Database::executeWithRetry(function() use ($db, $job) {
                        $upd = $db->prepare("UPDATE jobs SET status = 'processing', attempts = attempts + 1, next_attempt = datetime('now') WHERE id = ?");
                        $upd->execute([$job['id']]);
                    });
                } catch (\Exception $e) {
                    continue;
                }

                if ($job['activity_type'] === 'ImportFollow' || $job['activity_type'] === 'ImportFollower' || $job['activity_type'] === 'ImportListItem') {
                    try {
                        $payloadArr = json_decode($job['payload'], true);
                        if ($job['activity_type'] === 'ImportFollow') {
                            self::processImportFollow((int)$payloadArr['account_id'], $payloadArr['address'], !empty($payloadArr['mutual']));
                        } elseif ($job['activity_type'] === 'ImportFollower') {
                            self::processImportFollower((int)$payloadArr['account_id'], $payloadArr['address'], !empty($payloadArr['mutual']));
                        } else {
                            self::processImportListItem((int)$payloadArr['account_id'], (int)$payloadArr['list_id'], $payloadArr['address']);
                        }
                        Database::executeWithRetry(function() use ($db, $job) {
                            $okStmt = $db->prepare("UPDATE jobs SET status = 'completed', last_error = NULL WHERE id = ?");
                            $okStmt->execute([$job['id']]);
                        });
                    } catch (Exception $e) {
                        $backoffSeconds = pow(3, $job['attempts'] + 1);
                        $next = date('Y-m-d H:i:s', time() + $backoffSeconds);
                        try {
                            Database::executeWithRetry(function() use ($db, $e, $next, $job) {
                                $failStmt = $db->prepare("UPDATE jobs SET status = 'failed', last_error = ?, next_attempt = ? WHERE id = ?");
                                $failStmt->execute([$e->getMessage(), $next, $job['id']]);
                            });
                        } catch (Exception $e2) {}
                    }
                    continue;
                }

                $httpJobs[] = $job;
            }

            // Procesar entregas HTTP en lotes concurrentes de 20 usando curl_multi_*
            foreach (array_chunk($httpJobs, 20) as $batch) {
                $mh = curl_multi_init();
                $handles = [];

                foreach ($batch as $job) {
                    try {
                        if (!\KutSocial\SecurityHelper::isSafeExternalUrl((string)$job['inbox_url'])) {
                            throw new Exception("URL de inbox bloqueada por seguridad: " . $job['inbox_url']);
                        }

                        $payloadArr = json_decode($job['payload'], true);
                        $actorUrl = $payloadArr['actor'] ?? null;

                        $privateKey = null;
                        $keyId = null;
                        if ($actorUrl) {
                            $path = rtrim((string)parse_url($actorUrl, PHP_URL_PATH), '/');
                            $pathParts = explode('/', $path);
                            $username = strtolower(end($pathParts));

                            if (!array_key_exists($username, $actorKeyCache)) {
                                $accStmt = $db->prepare("SELECT private_key, id FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
                                $accStmt->execute([$username]);
                                $actorKeyCache[$username] = $accStmt->fetch() ?: null;
                            }
                            $account = $actorKeyCache[$username];
                            if ($account && !empty($account['private_key'])) {
                                $privateKey = $account['private_key'];
                                $keyId = $actorUrl . '#main-key';
                            }
                        }

                        if (!$privateKey) {
                            if (!$defaultAccountLoaded) {
                                $accStmt = $db->prepare("SELECT private_key, username, domain FROM accounts WHERE private_key IS NOT NULL AND domain IS NULL LIMIT 1");
                                $accStmt->execute();
                                $defaultAccount = $accStmt->fetch() ?: null;
                                $defaultAccountLoaded = true;
                            }
                            if ($defaultAccount) {
                                $privateKey = $defaultAccount['private_key'];
                                $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $keyId = "$proto://$host/users/{$defaultAccount['username']}#main-key";
                            }
                        }

                        if (!$privateKey) {
                            throw new Exception("No se encontró una clave privada para firmar la petición.");
                        }

                        $ch = self::createSignedCurlHandle($job['inbox_url'], $job['payload'], $privateKey, $keyId);
                        curl_multi_add_handle($mh, $ch);
                        $handles[] = ['job' => $job, 'ch' => $ch];
                    } catch (Exception $e) {
                        $backoffSeconds = pow(3, $job['attempts'] + 1);
                        $next = date('Y-m-d H:i:s', time() + $backoffSeconds);
                        try {
                            $failStmt = $db->prepare("UPDATE jobs SET status = 'failed', last_error = ?, next_attempt = ? WHERE id = ?");
                            $failStmt->execute([$e->getMessage(), $next, $job['id']]);
                        } catch (Exception $e2) {}
                    }
                }

                if (!empty($handles)) {
                    $active = null;
                    do {
                        $mrc = curl_multi_exec($mh, $active);
                        if ($active) {
                            curl_multi_select($mh, 0.5);
                        }
                    } while ($active && $mrc === CURLM_OK);

                    foreach ($handles as $item) {
                        $job = $item['job'];
                        $ch = $item['ch'];
                        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $err = curl_error($ch);
                        $resp = curl_multi_getcontent($ch);

                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);

                        if ($httpCode >= 200 && $httpCode < 400 && $err === '') {
                            try {
                                Database::executeWithRetry(function() use ($db, $job) {
                                    $okStmt = $db->prepare("UPDATE jobs SET status = 'completed', last_error = NULL WHERE id = ?");
                                    $okStmt->execute([$job['id']]);
                                });
                            } catch (Exception $e2) {}
                        } else {
                            $errMsg = "Error HTTP $httpCode: $err. Respuesta: " . substr((string)$resp, 0, 200);
                            $backoffSeconds = pow(3, $job['attempts'] + 1);
                            $next = date('Y-m-d H:i:s', time() + $backoffSeconds);
                            try {
                                Database::executeWithRetry(function() use ($db, $errMsg, $next, $job) {
                                    $failStmt = $db->prepare("UPDATE jobs SET status = 'failed', last_error = ?, next_attempt = ? WHERE id = ?");
                                    $failStmt->execute([$errMsg, $next, $job['id']]);
                                });
                            } catch (Exception $e2) {}
                        }
                    }
                }

                curl_multi_close($mh);
            }
        } finally {
            if ($lockFp) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
        }

        return $processed;
    }

    /**
     * Crea un handle cURL firmado con HTTP Signatures para envío directo o en lote (curl_multi).
     */
    private static function createSignedCurlHandle(string $url, string $body, string $privateKey, string $keyId) {
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
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
            CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
        ]);

        return $ch;
    }

    /**
     * Envía una petición ActivityPub firmada mediante HTTP Signatures.
     */
    private static function sendSignedRequest(string $url, string $body, string $privateKey, string $keyId): void {
        $ch = self::createSignedCurlHandle($url, $body, $privateKey, $keyId);
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
    private static function processImportFollow(int $accountId, string $address, bool $mutual = false): void {
        $db = Database::connect();
        
        // 1. Obtener la cuenta local
        $stmtAcc = $db->prepare("SELECT id, username FROM accounts WHERE id = ? LIMIT 1");
        $stmtAcc->execute([$accountId]);
        $account = $stmtAcc->fetch();
        if (!$account) return;

        // 2. Resolver la cuenta a seguir
        $address = trim($address, " \t\n\r\0\x0B\"'");
        $address = ltrim($address, '@');
        if (empty($address)) return;

        $resolvedAcc = null;
        if (filter_var($address, FILTER_VALIDATE_URL)) {
            $resolvedAcc = \KutSocial\Controllers\ActivityPubController::getOrRegisterRemoteActor($address);
        } elseif (str_contains($address, '@')) {
            $parts = explode('@', $address);
            $uName = strtolower($parts[0]);
            $uDomain = strtolower($parts[1]);
            $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
            if ($uDomain === $serverHost || $uDomain === 'localhost') {
                $stmtLoc = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = ? AND (domain IS NULL OR domain = '') LIMIT 1");
                $stmtLoc->execute([$uName]);
                $resolvedAcc = $stmtLoc->fetch();
            } else {
                $stmtExist = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = ? AND LOWER(domain) = ? LIMIT 1");
                $stmtExist->execute([$uName, $uDomain]);
                $resolvedAcc = $stmtExist->fetch();
                if (!$resolvedAcc) {
                    $resolvedAcc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($address);
                }
            }
        } else {
            $stmtLoc = $db->prepare("SELECT * FROM accounts WHERE username = ? AND (domain IS NULL OR domain = '') LIMIT 1");
            $stmtLoc->execute([$address]);
            $resolvedAcc = $stmtLoc->fetch();
        }

        if (!$resolvedAcc) {
            throw new Exception("No se pudo resolver el actor remoto: $address");
        }

        $targetId = (int)$resolvedAcc['id'];
        if ($targetId === (int)$accountId) {
            return;
        }
        $isRemote = !empty($resolvedAcc['domain']);
        $status = ($resolvedAcc['locked'] ?? 0) ? 'pending' : 'accepted';
        
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

    /**
     * Resuelve e importa un seguidor en segundo plano.
     */
    private static function processImportFollower(int $accountId, string $address, bool $mutual = false): void {
        $db = Database::connect();
        
        $stmtAcc = $db->prepare("SELECT id, username FROM accounts WHERE id = ? LIMIT 1");
        $stmtAcc->execute([$accountId]);
        $account = $stmtAcc->fetch();
        if (!$account) return;

        $address = trim($address, " \t\n\r\0\x0B\"'");
        $address = ltrim($address, '@');
        if (empty($address)) return;

        $resolvedAcc = null;
        if (filter_var($address, FILTER_VALIDATE_URL)) {
            $resolvedAcc = \KutSocial\Controllers\ActivityPubController::getOrRegisterRemoteActor($address);
        } elseif (str_contains($address, '@')) {
            $parts = explode('@', $address);
            $uName = strtolower($parts[0]);
            $uDomain = strtolower($parts[1]);
            $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
            if ($uDomain === $serverHost || $uDomain === 'localhost') {
                $stmtLoc = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = ? AND (domain IS NULL OR domain = '') LIMIT 1");
                $stmtLoc->execute([$uName]);
                $resolvedAcc = $stmtLoc->fetch();
            } else {
                $stmtExist = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = ? AND LOWER(domain) = ? LIMIT 1");
                $stmtExist->execute([$uName, $uDomain]);
                $resolvedAcc = $stmtExist->fetch();
                if (!$resolvedAcc) {
                    $resolvedAcc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($address);
                }
            }
        } else {
            $stmtLoc = $db->prepare("SELECT * FROM accounts WHERE username = ? AND (domain IS NULL OR domain = '') LIMIT 1");
            $stmtLoc->execute([$address]);
            $resolvedAcc = $stmtLoc->fetch();
        }

        if (!$resolvedAcc) {
            throw new Exception("No se pudo resolver el actor remoto seguidor: $address");
        }

        $remoteId = $resolvedAcc['id'];
        
        // Registrar relación de seguidor
        $stmtCheck = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtCheck->execute([$remoteId, $accountId]);
        if (!$stmtCheck->fetchColumn()) {
            $stmtIns = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, 'accepted')");
            $stmtIns->execute([$remoteId, $accountId]);
        }

        // Si es mutuo, nosotros también lo seguimos
        if ($mutual) {
            $isRemote = !empty($resolvedAcc['domain']);
            $status = ($resolvedAcc['locked'] ?? 0) ? 'pending' : 'accepted';
            
            $stmtCheckFwd = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
            $stmtCheckFwd->execute([$accountId, $remoteId]);
            if (!$stmtCheckFwd->fetchColumn()) {
                $stmtInsFwd = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, ?)");
                $stmtInsFwd->execute([$accountId, $remoteId, $status]);

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

    /**
     * Resuelve e inserta un miembro en una lista en segundo plano.
     */
    private static function processImportListItem(int $accountId, int $listId, string $address): void {
        $db = Database::connect();
        $address = trim($address, " \t\n\r\0\x0B\"'");
        $address = ltrim($address, '@');
        if (empty($address)) return;

        $resolvedAcc = null;
        if (filter_var($address, FILTER_VALIDATE_URL)) {
            $resolvedAcc = \KutSocial\Controllers\ActivityPubController::getOrRegisterRemoteActor($address);
        } elseif (str_contains($address, '@')) {
            $parts = explode('@', $address);
            $uName = strtolower($parts[0]);
            $uDomain = strtolower($parts[1]);
            $stmtExist = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = ? AND LOWER(domain) = ? LIMIT 1");
            $stmtExist->execute([$uName, $uDomain]);
            $resolvedAcc = $stmtExist->fetch();
            if (!$resolvedAcc) {
                $resolvedAcc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($address);
            }
        } else {
            $stmtLoc = $db->prepare("SELECT * FROM accounts WHERE username = ? AND (domain IS NULL OR domain = '') LIMIT 1");
            $stmtLoc->execute([$address]);
            $resolvedAcc = $stmtLoc->fetch();
        }

        if (!$resolvedAcc) {
            throw new Exception("No se pudo resolver el actor remoto para lista: $address");
        }

        $targetId = $resolvedAcc['id'];
        
        $stmtAdd = $db->prepare("INSERT OR IGNORE INTO list_accounts (list_id, account_id) VALUES (?, ?)");
        $stmtAdd->execute([$listId, $targetId]);

        // Asegurar que también lo seguimos para que sus publicaciones lleguen a la lista
        $stmtCheck = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtCheck->execute([$accountId, $targetId]);
        if (!$stmtCheck->fetchColumn()) {
            self::processImportFollow($accountId, $address, false);
        }
    }
}
