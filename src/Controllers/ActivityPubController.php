<?php
namespace KutSocial\Controllers;

use KutSocial\Database;
use KutSocial\Router;
use PDO;
use Exception;

class ActivityPubController {
    /**
     * Endpoint Webfinger: /.well-known/webfinger
     */
    public static function webfinger(): void {
        $resource = $_GET['resource'] ?? '';
        if (empty($resource)) {
            Router::json(['error' => 'Parámetro resource faltante'], 400);
        }

        // Formato esperado: acct:username@domain
        if (!preg_match('/^acct:([^@]+)@(.+)$/', $resource, $matches)) {
            Router::json(['error' => 'Formato de recurso no soportado'], 400);
        }

        $username = strtolower($matches[1]);
        $domain = strtolower($matches[2]);

        $expectedDomain = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
        if ($domain !== strtolower($expectedDomain)) {
            Router::json(['error' => 'Dominio no gestionado por este servidor'], 404);
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
        }

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $actorUrl = "$proto://$expectedDomain/users/$username";

        Router::json([
            'subject' => "acct:$username@$expectedDomain",
            'aliases' => [$actorUrl],
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => "$proto://$expectedDomain/@$username"
                ]
            ]
        ], 200);
    }

    public static function makeActorArray(array $account): array {
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $actorUrl = "$proto://$domain/users/{$account['username']}";

        $avatarUrl = null;
        if (!empty($account['avatar'])) {
            $avatarUrl = str_starts_with($account['avatar'], 'http') 
                ? $account['avatar'] 
                : "$proto://$domain" . $account['avatar'];
        } else {
            $avatarUrl = "$proto://$domain/assets/default-avatar.png";
        }

        $headerUrl = null;
        if (!empty($account['header'])) {
            $headerUrl = str_starts_with($account['header'], 'http') 
                ? $account['header'] 
                : "$proto://$domain" . $account['header'];
        } else {
            $headerUrl = "$proto://$domain/assets/default-header.png";
        }

        $response = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'id' => $actorUrl,
            'type' => 'Person',
            'preferredUsername' => $account['username'],
            'name' => $account['display_name'] ?: $account['username'],
            'summary' => $account['note'] ?: '',
            'inbox' => "$actorUrl/inbox",
            'outbox' => "$actorUrl/outbox",
            'followers' => "$actorUrl/followers",
            'following' => "$actorUrl/following",
            'manuallyApprovesFollowers' => (bool)($account['locked'] ?? 0),
            'publicKey' => [
                'id' => "$actorUrl#main-key",
                'owner' => $actorUrl,
                'publicKeyPem' => $account['public_key']
            ]
        ];

        if ($avatarUrl) {
            $ext = strtolower(pathinfo(parse_url($avatarUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $mediaType = 'image/jpeg';
            } elseif ($ext === 'svg') {
                $mediaType = 'image/svg+xml';
            } else {
                $mediaType = 'image/' . $ext;
            }
            $response['icon'] = [
                'type' => 'Image',
                'mediaType' => $mediaType,
                'url' => $avatarUrl
            ];
        }

        if ($headerUrl) {
            $ext = strtolower(pathinfo(parse_url($headerUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $mediaType = 'image/jpeg';
            } elseif ($ext === 'svg') {
                $mediaType = 'image/svg+xml';
            } else {
                $mediaType = 'image/' . $ext;
            }
            $response['image'] = [
                'type' => 'Image',
                'mediaType' => $mediaType,
                'url' => $headerUrl
            ];
        }

        if (!empty($account['fields'])) {
            $fieldsList = json_decode($account['fields'], true);
            if (is_array($fieldsList)) {
                $attachments = [];
                foreach ($fieldsList as $f) {
                    $attachments[] = [
                        'type' => 'PropertyValue',
                        'name' => $f['name'],
                        'value' => $f['value']
                    ];
                }
                if (!empty($attachments)) {
                    $response['attachment'] = $attachments;
                }
            }
        }

        return $response;
    }

    /**
     * Endpoint de Actor: /users/{username}
     */
    public static function getActor(array $params): void {
        $username = $params['username'] ?? '';
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account) {
            Router::json(['error' => 'Actor no encontrado'], 404);
        }

        $actorArray = self::makeActorArray($account);

        header('Content-Type: application/activity+json; charset=utf-8');
        echo json_encode($actorArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Endpoint de Inbox: /users/{username}/inbox (Recibir actividades remotas)
     */
    public static function postInbox(array $params): void {
        $username = $params['username'] ?? '';
        self::log("postInbox: Solicitud recibida para el actor local '$username'");
        
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account) {
            self::log("postInbox: Actor local '$username' no encontrado");
            Router::json(['error' => 'Actor local no encontrado'], 404);
        }

        $body = file_get_contents('php://input');
        $activity = json_decode($body, true);

        if (!$activity) {
            self::log("postInbox: JSON inválido recibido en el body");
            Router::json(['error' => 'JSON inválido'], 400);
        }

        self::log("postInbox: Actividad recibida de tipo '" . ($activity['type'] ?? 'unknown') . "'");

        // Verificar firma HTTP
        if (!self::verifyHttpSignature($body)) {
            self::log("postInbox: Error de verificación de firma HTTP para el actor local '$username'");
            Router::json(['error' => 'Firma HTTP inválida'], 401);
        }

        $type = $activity['type'] ?? '';

        switch ($type) {
            case 'Follow':
                self::log("postInbox: Procesando actividad de Follow");
                self::handleFollow($account, $activity);
                break;
            case 'Undo':
                self::log("postInbox: Procesando actividad de Undo");
                self::handleUndo($account, $activity);
                break;
            case 'Accept':
                self::log("postInbox: Procesando actividad de Accept");
                self::handleAccept($account, $activity);
                break;
            case 'Create':
                self::log("postInbox: Procesando actividad de Create");
                self::handleCreate($account, $activity);
                break;
            default:
                self::log("postInbox: Actividad de tipo '$type' no soportada, se responde 202");
                break;
        }

        http_response_code(202);
        exit;
    }

    private static function handleAccept(array $localAccount, array $activity): void {
        $object = $activity['object'] ?? [];
        $type = $object['type'] ?? '';
        
        if ($type === 'Follow') {
            $actorUrl = $activity['actor'] ?? ''; // El actor remoto que nos aceptó
            if (empty($actorUrl)) return;
            
            $db = Database::connect();
            $host = parse_url($actorUrl, PHP_URL_HOST);
            $pathParts = explode('/', parse_url($actorUrl, PHP_URL_PATH));
            $username = strtolower(end($pathParts));
            
            $stmt = $db->prepare("SELECT id FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
            $stmt->execute([$username, $host]);
            $remoteAccount = $stmt->fetch();
            
            if ($remoteAccount) {
                $upd = $db->prepare("UPDATE follows SET status = 'accepted' WHERE account_id = ? AND target_account_id = ?");
                $upd->execute([$localAccount['id'], $remoteAccount['id']]);
                self::log("handleAccept: Seguimiento de local ID {$localAccount['id']} -> remoto ID {$remoteAccount['id']} marcado como aceptado");
            }
        }
    }

    private static function determineVisibility(array $object): string {
        $to = isset($object['to']) ? (is_array($object['to']) ? $object['to'] : [$object['to']]) : [];
        $cc = isset($object['cc']) ? (is_array($object['cc']) ? $object['cc'] : [$object['cc']]) : [];

        $allRecipients = array_merge($to, $cc);

        $hasPublic = false;
        $hasFollowers = false;

        foreach ($allRecipients as $recipient) {
            if ($recipient === 'https://www.w3.org/ns/activitystreams#Public' || $recipient === 'as:Public') {
                $hasPublic = true;
            }
            if (is_string($recipient) && str_ends_with($recipient, '/followers')) {
                $hasFollowers = true;
            }
        }

        $toPublic = false;
        foreach ($to as $r) {
            if ($r === 'https://www.w3.org/ns/activitystreams#Public' || $r === 'as:Public') {
                $toPublic = true;
                break;
            }
        }

        if ($toPublic) {
            return 'public';
        }

        $ccPublic = false;
        foreach ($cc as $r) {
            if ($r === 'https://www.w3.org/ns/activitystreams#Public' || $r === 'as:Public') {
                $ccPublic = true;
                break;
            }
        }

        if ($ccPublic) {
            return 'unlisted';
        }

        if ($hasFollowers) {
            return 'private';
        }

        return 'direct';
    }

    private static function handleCreate(array $localAccount, array $activity): void {
        $object = $activity['object'] ?? [];
        if (empty($object)) {
            self::log("handleCreate: Objeto de actividad vacío");
            return;
        }

        $type = $object['type'] ?? '';
        if ($type !== 'Note' && $type !== 'Question') {
            self::log("handleCreate: Tipo de objeto '$type' no soportado en Create");
            return;
        }

        $actorUrl = $activity['actor'] ?? '';
        if (empty($actorUrl)) {
            self::log("handleCreate: Falta el actor de la actividad");
            return;
        }

        // Obtener o registrar el actor remoto
        $remoteAccount = self::getOrRegisterRemoteActor($actorUrl);
        if (!$remoteAccount) {
            self::log("handleCreate: No se pudo resolver o registrar el actor remoto: $actorUrl");
            return;
        }

        $db = Database::connect();

        $uri = $object['id'] ?? '';
        if (empty($uri)) {
            self::log("handleCreate: Falta el ID/URI del objeto");
            return;
        }

        // Verificar si la publicación ya existe en nuestra base de datos local
        $stmtCheck = $db->prepare("SELECT id FROM statuses WHERE uri = ? LIMIT 1");
        $stmtCheck->execute([$uri]);
        if ($stmtCheck->fetchColumn()) {
            self::log("handleCreate: Publicación '$uri' ya existe en DB local. Ignorando.");
            return;
        }

        $content = $object['content'] ?? '';
        $createdAt = $object['published'] ?? date('c');

        // Determinar visibilidad usando el helper determineVisibility
        $visibility = self::determineVisibility($object);

        // Procesar adjuntos (media)
        $attachments = [];
        if (!empty($object['attachment'])) {
            foreach ($object['attachment'] as $att) {
                $attType = $att['type'] ?? '';
                if ($attType === 'Document' || $attType === 'Image') {
                    $attachments[] = [
                        'id' => bin2hex(random_bytes(6)),
                        'type' => 'image',
                        'url' => $att['url'] ?? '',
                        'preview_url' => $att['url'] ?? '',
                        'remote_url' => $att['url'] ?? ''
                    ];
                }
            }
        }
        $mediaJson = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        // Procesar in_reply_to_id
        $inReplyToUri = $object['inReplyTo'] ?? null;
        $inReplyToId = null;
        if ($inReplyToUri) {
            $stmtReply = $db->prepare("SELECT id FROM statuses WHERE uri = ? LIMIT 1");
            $stmtReply->execute([$inReplyToUri]);
            $inReplyToId = $stmtReply->fetchColumn() ?: null;
        }

        $stmtIns = $db->prepare("
            INSERT INTO statuses (account_id, uri, content, visibility, created_at, media_attachments, in_reply_to_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([
            $remoteAccount['id'],
            $uri,
            $content,
            $visibility,
            date('Y-m-d H:i:s', strtotime($createdAt)),
            $mediaJson,
            $inReplyToId
        ]);

        $newStatusId = (int)$db->lastInsertId();
        \KutSocial\NotificationHelper::fetchAndSaveLinkCard($newStatusId, $content);
        \KutSocial\NotificationHelper::notifyMentionOrReply($newStatusId);

        self::log("handleCreate: Publicación remota '$uri' guardada exitosamente con visibilidad '$visibility'");
    }

    /**
     * Endpoint de Outbox: /users/{username}/outbox
     */
    public static function getOutbox(array $params): void {
        $username = $params['username'] ?? '';
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account) {
            Router::json(['error' => 'Actor no encontrado'], 404);
        }

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $outboxUrl = "$proto://$domain/users/$username/outbox";

        // Obtener statuses de la base de datos (solo públicos y no listados)
        $statusStmt = $db->prepare("
            SELECT * FROM statuses 
            WHERE account_id = ? AND visibility IN ('public', 'unlisted') 
            ORDER BY id DESC LIMIT 20
        ");
        $statusStmt->execute([$account['id']]);
        $statuses = $statusStmt->fetchAll();

        $items = [];
        foreach ($statuses as $status) {
            $visibility = $status['visibility'] ?? 'public';
            $to = [];
            $cc = [];
            if ($visibility === 'public') {
                $to[] = 'https://www.w3.org/ns/activitystreams#Public';
                $cc[] = "$proto://$domain/users/$username/followers";
            } else { // unlisted
                $to[] = "$proto://$domain/users/$username/followers";
                $cc[] = 'https://www.w3.org/ns/activitystreams#Public';
            }

            $items[] = [
                'id' => $status['uri'] . '/activity',
                'type' => 'Create',
                'actor' => "$proto://$domain/users/$username",
                'to' => $to,
                'cc' => $cc,
                'object' => [
                    'id' => $status['uri'],
                    'type' => 'Note',
                    'content' => $status['content'],
                    'published' => date('c', strtotime($status['created_at'])),
                    'to' => $to,
                    'cc' => $cc
                ]
            ];
        }

        Router::json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $outboxUrl,
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items
        ]);
    }

    // --- Lógica interna ---

    private static function handleFollow(array $localAccount, array $activity): void {
        $actorUrl = $activity['actor'] ?? '';
        if (empty($actorUrl)) {
            self::log("handleFollow: URL del actor remoto vacía en la actividad");
            return;
        }

        $db = Database::connect();

        // 1. Obtener o registrar actor remoto en nuestra DB
        self::log("handleFollow: Resolviendo o registrando actor remoto '$actorUrl'");
        $remoteAccount = self::getOrRegisterRemoteActor($actorUrl);
        if (!$remoteAccount) {
            self::log("handleFollow: No se pudo resolver o registrar el actor remoto '$actorUrl'");
            return;
        }

        // 2. Guardar relación en la tabla follows (respetando si la cuenta está cerrada/locked)
        $status = ($localAccount['locked'] ?? 0) ? 'pending' : 'accepted';
        self::log("handleFollow: Guardando relación follows en la DB con estado '$status' (remoto ID {$remoteAccount['id']} -> local ID {$localAccount['id']})");
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO follows (account_id, target_account_id, uri, status) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$remoteAccount['id'], $localAccount['id'], $activity['id'] ?? null, $status]);

        // Disparar notificación por correo
        if ($status === 'accepted') {
            \KutSocial\NotificationHelper::notifyFollow((int)$localAccount['id'], (int)$remoteAccount['id']);
        }

        // 3. Responder con un Accept encolado si el estado es accepted
        if ($status === 'accepted') {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$localAccount['username']}";

            $acceptActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActorUrl . '/activities/accept-' . bin2hex(random_bytes(8)),
                'type' => 'Accept',
                'actor' => $localActorUrl,
                'object' => $activity
            ];

            if (!empty($remoteAccount['inbox_url'])) {
                self::log("handleFollow: Encolando actividad Accept para el inbox '{$remoteAccount['inbox_url']}'");
                \KutSocial\Queue::enqueue('Accept', $acceptActivity, $remoteAccount['inbox_url']);
                // Disparar procesamiento asíncrono
                \KutSocial\Queue::triggerAsync($domain);
            } else {
                self::log("handleFollow: No se pudo encolar Accept porque inbox_url está vacía para el actor remoto");
            }
        } else {
            self::log("handleFollow: Seguimiento pendiente de aprobación manual, no se envía Accept");
        }
    }

    private static function handleUndo(array $localAccount, array $activity): void {
        $object = $activity['object'] ?? [];
        $type = $object['type'] ?? '';

        if ($type === 'Follow') {
            $actorUrl = $activity['actor'] ?? '';
            self::log("handleUndo: Deshaciendo seguimiento del actor remoto '$actorUrl'");
            $db = Database::connect();
            
            $remoteAccount = self::getOrRegisterRemoteActor($actorUrl);
            if ($remoteAccount) {
                $del = $db->prepare("DELETE FROM follows WHERE account_id = ? AND target_account_id = ?");
                $del->execute([$remoteAccount['id'], $localAccount['id']]);
                self::log("handleUndo: Relación de seguimiento eliminada exitosamente");
            }
        }
    }

    private static function getOrRegisterRemoteActor(string $actorUrl): ?array {
        $db = Database::connect();

        // Verificar si ya existe
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
        $host = parse_url($actorUrl, PHP_URL_HOST);
        $pathParts = explode('/', parse_url($actorUrl, PHP_URL_PATH));
        $username = end($pathParts);

        $stmt->execute([$username, $host]);
        $account = $stmt->fetch();

        if ($account) {
            $lastUpdated = strtotime($account['updated_at']);
            if (time() - $lastUpdated < 300) {
                self::log("getOrRegisterRemoteActor: Actor remoto '$username@$host' ya existe en DB local y está fresco");
                return $account;
            }
            self::log("getOrRegisterRemoteActor: Actor remoto '$username@$host' ya existe en DB local pero necesita actualización");
        }

        // Si no existe o está desactualizado, debemos resolver el actor para obtener su inbox, public_key y estadísticas
        self::log("getOrRegisterRemoteActor: Resolviendo actor remoto vía petición HTTP: $actorUrl");
        try {
            $ch = curl_init($actorUrl);
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$domain)"
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            self::log("getOrRegisterRemoteActor: HTTP status: $httpCode");
            if (!$resp) {
                self::log("getOrRegisterRemoteActor: Respuesta vacía al consultar actor remoto");
                return $account ?: null;
            }

            $json = json_decode($resp, true);
            if (!$json) {
                self::log("getOrRegisterRemoteActor: JSON inválido devuelto por el actor remoto: " . substr($resp, 0, 200));
                return $account ?: null;
            }

            $displayName = $json['name'] ?? $username;
            $note = $json['summary'] ?? '';
            $inbox = $json['inbox'] ?? '';
            $publicKeyPem = $json['publicKey']['publicKeyPem'] ?? '';

            if (empty($publicKeyPem)) {
                self::log("getOrRegisterRemoteActor: Clave pública ausente en el JSON del actor remoto");
                return $account ?: null;
            }

            // Extraer avatar e header (icon y image) del JSON del actor remoto
            $avatar = '';
            if (!empty($json['icon'])) {
                if (is_string($json['icon'])) {
                    $avatar = $json['icon'];
                } elseif (is_array($json['icon'])) {
                    $avatar = $json['icon']['url'] ?? '';
                }
            }
            $header = '';
            if (!empty($json['image'])) {
                if (is_string($json['image'])) {
                    $header = $json['image'];
                } elseif (is_array($json['image'])) {
                    $header = $json['image']['url'] ?? '';
                }
            }

            // Obtener estadísticas del Fediverso (Seguidores, Siguiendo, Outbox/Toots)
            $followersCount = self::getCollectionCount($json['followers'] ?? null);
            $followingCount = self::getCollectionCount($json['following'] ?? null);
            $statusesCount = self::getCollectionCount($json['outbox'] ?? null);

            if ($account) {
                // Actualizar cuenta existente
                $upd = $db->prepare("
                    UPDATE accounts 
                    SET display_name = ?, note = ?, avatar = ?, header = ?, inbox_url = ?, public_key = ?, followers_count = ?, following_count = ?, statuses_count = ?, updated_at = datetime('now')
                    WHERE id = ?
                ");
                $upd->execute([$displayName, $note, $avatar, $header, $inbox, $publicKeyPem, $followersCount, $followingCount, $statusesCount, $account['id']]);
                self::log("getOrRegisterRemoteActor: Actor remoto id={$account['id']} '$username@$host' actualizado");
            } else {
                // Insertar nueva cuenta
                $ins = $db->prepare("
                    INSERT INTO accounts (username, domain, display_name, note, avatar, header, inbox_url, public_key, followers_count, following_count, statuses_count, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $ins->execute([$username, $host, $displayName, $note, $avatar, $header, $inbox, $publicKeyPem, $followersCount, $followingCount, $statusesCount]);
                $newId = $db->lastInsertId();
                self::log("getOrRegisterRemoteActor: Actor remoto registrado con ID local $newId");
            }

            // Recargar registro
            $stmt->execute([$username, $host]);
            return $stmt->fetch();
        } catch (\Exception $e) {
            self::log("getOrRegisterRemoteActor: Excepción al resolver actor remoto: " . $e->getMessage());
            return $account ?: null;
        }
    }

    /**
     * Verifica la firma HTTP Signature de la petición entrante.
     */
    private static function verifyHttpSignature(string $body): bool {
        $headers = \KutSocial\Router::getAllHeaders();
        $sigHeader = $headers['Signature'] ?? $headers['signature'] ?? '';

        if (empty($sigHeader)) {
            self::log("verifyHttpSignature: Falta el encabezado Signature");
            return false;
        }

        self::log("verifyHttpSignature: Analizando encabezado: $sigHeader");

        // Parsear cabecera Signature: keyId="...",algorithm="...",headers="...",signature="..."
        $parts = [];
        preg_match_all('/(keyId|algorithm|headers|signature)="([^"]+)"/', $sigHeader, $matches);
        if (empty($matches[1])) {
            self::log("verifyHttpSignature: No se pudieron extraer campos de la cabecera Signature");
            return false;
        }

        foreach ($matches[1] as $idx => $key) {
            $parts[$key] = $matches[2][$idx];
        }

        if (empty($parts['keyId']) || empty($parts['signature']) || empty($parts['headers'])) {
            self::log("verifyHttpSignature: Campos keyId, signature, o headers ausentes");
            return false;
        }

        self::log("verifyHttpSignature: keyId '{$parts['keyId']}'");

        // Obtener la llave pública del actor remoto
        $publicKey = self::fetchRemotePublicKey($parts['keyId']);
        if (!$publicKey) {
            self::log("verifyHttpSignature: No se pudo obtener la clave pública remota para keyId '{$parts['keyId']}'");
            return false;
        }

        // Construir string firmado
        $headerNames = explode(' ', $parts['headers']);
        $signedLines = [];
        $headersLower = array_change_key_case($headers, CASE_LOWER);
        
        foreach ($headerNames as $headerName) {
            $headerNameLower = strtolower($headerName);
            if ($headerNameLower === '(request-target)') {
                $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'POST');
                $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
                if ($query) {
                    $uri .= '?' . $query;
                }
                $signedLines[] = "(request-target): $method $uri";
            } else {
                // PHP normaliza cabeceras (e.g. Content-Type -> HTTP_CONTENT_TYPE)
                $key = 'HTTP_' . strtoupper(str_replace('-', '_', $headerNameLower));
                if ($headerNameLower === 'content-type') $key = 'CONTENT_TYPE';
                if ($headerNameLower === 'content-length') $key = 'CONTENT_LENGTH';
                
                $val = $headersLower[$headerNameLower] ?? $_SERVER[$key] ?? '';
                $signedLines[] = "$headerNameLower: $val";
            }
        }

        $signingString = implode("\n", $signedLines);
        $signatureVal = base64_decode($parts['signature']);

        self::log("verifyHttpSignature: Cadena firmada construida:\n" . $signingString);

        $ok = openssl_verify($signingString, $signatureVal, $publicKey, OPENSSL_ALGO_SHA256);
        self::log("verifyHttpSignature: openssl_verify() retorno: " . $ok);
        return $ok === 1;
    }

    private static function fetchRemotePublicKey(string $keyId): ?string {
        self::log("fetchRemotePublicKey: Consultando keyId: $keyId");
        try {
            // Si el keyId es local o ya está registrado, evitar petición de red/loopback
            $parsedUrl = parse_url($keyId);
            $host = $parsedUrl['host'] ?? '';
            $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = $parsedUrl['path'] ?? '';
            
            $db = Database::connect();
            
            // 1. Caso actor local
            if (strtolower($host) === strtolower($currentHost)) {
                if (preg_match('@^/users/([^/]+)@', $path, $matches)) {
                    $username = strtolower($matches[1]);
                    $stmt = $db->prepare("SELECT public_key FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
                    $stmt->execute([$username]);
                    $publicKey = $stmt->fetchColumn();
                    if ($publicKey) {
                        self::log("fetchRemotePublicKey: Clave pública local recuperada de la BD para '$username'");
                        return $publicKey;
                    }
                }
            }
            
            // 2. Caso actor remoto ya registrado en base de datos
            $pathParts = array_filter(explode('/', $path));
            $usernameCandidate = end($pathParts);
            if ($usernameCandidate) {
                if (str_contains($usernameCandidate, '#')) {
                    $usernameCandidate = explode('#', $usernameCandidate)[0];
                }
                $stmt = $db->prepare("SELECT public_key FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
                $stmt->execute([strtolower($usernameCandidate), strtolower($host)]);
                $publicKey = $stmt->fetchColumn();
                if ($publicKey) {
                    self::log("fetchRemotePublicKey: Clave pública remota recuperada de la BD para '{$usernameCandidate}@{$host}'");
                    return $publicKey;
                }
            }

            $ch = curl_init($keyId);
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$domain)"
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            self::log("fetchRemotePublicKey: HTTP status: $httpCode");

            if (!$resp) {
                self::log("fetchRemotePublicKey: Respuesta vacía al consultar keyId");
                return null;
            }

            $json = json_decode($resp, true);
            if (isset($json['publicKey']['publicKeyPem'])) {
                self::log("fetchRemotePublicKey: Clave pública encontrada en publicKey.publicKeyPem");
                return $json['publicKey']['publicKeyPem'];
            }
            if (isset($json['publicKeyPem'])) {
                self::log("fetchRemotePublicKey: Clave pública encontrada en publicKeyPem de la raíz");
                return $json['publicKeyPem'];
            }
            self::log("fetchRemotePublicKey: No se encontró publickKeyPem en la respuesta: " . substr($resp, 0, 200));
        } catch (\Exception $e) {
            self::log("fetchRemotePublicKey: Excepción: " . $e->getMessage());
        }
        return null;
    }

    public static function resolveWebfinger(string $acct): ?array {
        $acct = ltrim($acct, '@');
        $parts = explode('@', $acct);
        if (count($parts) !== 2) {
            return null;
        }
        $username = strtolower($parts[0]);
        $domain = strtolower($parts[1]);

        $url = "https://$domain/.well-known/webfinger?resource=acct:$username@$domain";
        self::log("resolveWebfinger: Resolviendo $acct vía $url");

        try {
            $ch = curl_init($url);
            $localDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/jrd+json, application/json",
                    "User-Agent: KutSocial/1.0; (+https://$localDomain)"
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            if (!$resp) {
                self::log("resolveWebfinger: Respuesta vacía de webfinger");
                return null;
            }

            $json = json_decode($resp, true);
            if (!isset($json['links'])) {
                self::log("resolveWebfinger: Respuesta webfinger inválida (sin links)");
                return null;
            }

            foreach ($json['links'] as $link) {
                if (isset($link['rel']) && $link['rel'] === 'self' && isset($link['href'])) {
                    return self::getOrRegisterRemoteActor($link['href']);
                }
            }
        } catch (\Exception $e) {
            self::log("resolveWebfinger: Excepción: " . $e->getMessage());
        }
        return null;
    }

    public static function broadcastProfileUpdate(array $account): void {
        $db = Database::connect();
        
        // 1. Obtener lista de seguidores remotos (follows con status = 'accepted')
        $stmt = $db->prepare("
            SELECT a.inbox_url 
            FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ? AND f.status = 'accepted' AND a.domain IS NOT NULL
        ");
        $stmt->execute([$account['id']]);
        $followers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($followers)) {
            self::log("broadcastProfileUpdate: El usuario no tiene seguidores remotos a los cuales notificar");
            return;
        }

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $localActorUrl = "$proto://$domain/users/{$account['username']}";
        $actorArray = self::makeActorArray($account);

        $updateActivity = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'id' => $localActorUrl . '#updates/profile-' . bin2hex(random_bytes(8)),
            'type' => 'Update',
            'actor' => $localActorUrl,
            'object' => $actorArray
        ];

        self::log("broadcastProfileUpdate: Enviando Update a " . count($followers) . " seguidores remotos");

        foreach ($followers as $inboxUrl) {
            if (!empty($inboxUrl)) {
                \KutSocial\Queue::enqueue('Update', $updateActivity, $inboxUrl);
            }
        }

        \KutSocial\Queue::triggerAsync($domain);
    }

    private static function getCollectionCount($property): int {
        if (empty($property)) return 0;
        if (is_array($property)) {
            if (isset($property['totalItems'])) {
                return (int)$property['totalItems'];
            }
            if (isset($property['id']) && is_string($property['id'])) {
                return self::fetchCollectionTotalItems($property['id']);
            }
        } elseif (is_string($property)) {
            return self::fetchCollectionTotalItems($property);
        }
        return 0;
    }

    private static function fetchCollectionTotalItems(string $url): int {
        if (empty($url)) return 0;
        try {
            $ch = curl_init($url);
            $localDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$localDomain)"
                ],
                CURLOPT_TIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            if ($resp) {
                $json = json_decode($resp, true);
                if (isset($json['totalItems'])) {
                    return (int)$json['totalItems'];
                }
            }
        } catch (\Exception $e) {
            // Ignorar
        }
        return 0;
    }

    /**
     * Escribe un mensaje en el archivo de registro de ActivityPub
     */
    private static function log(string $message): void {
        $logFile = dirname(Database::getDbPath()) . '/activitypub.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
}
