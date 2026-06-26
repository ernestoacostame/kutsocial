<?php
namespace KutSocial\Controllers;

use KutSocial\Database;
use KutSocial\Router;
use PDO;
use Exception;

class MastodonApiController {
    /**
     * Endpoint GET /api/v1/instance
     */
    public static function getInstance(): void {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT value FROM options WHERE key = 'instance_title' LIMIT 1");
        $stmt->execute();
        $title = $stmt->fetchColumn() ?: 'KutSocial';

        $stmtLimit = $db->prepare("SELECT value FROM options WHERE key = 'max_toot_chars' LIMIT 1");
        $stmtLimit->execute();
        $maxChars = (int)($stmtLimit->fetchColumn() ?: 500);

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = "$proto://$domain";

        $response = [
            'uri' => $domain,
            'title' => $title,
            'short_description' => 'Servidor ligero compatible con Mastodon',
            'description' => 'KutSocial: Alternativa ultraligera construida sobre PHP y SQLite.',
            'email' => 'admin@' . $domain,
            'version' => '4.6.0-kutsocial',
            'urls' => [
                'streaming_api' => 'wss://' . $domain . '/api/v1/streaming'
            ],
            'stats' => [
                'user_count' => self::getTableRowCount('accounts', "domain IS NULL"),
                'status_count' => self::getTableRowCount('statuses'),
                'domain_count' => self::getTableRowCount('accounts', "domain IS NOT NULL")
            ],
            'thumbnail' => $base . '/assets/thumbnail.png',
            'languages' => ['es', 'en'],
            'registrations' => false,
            'approval_required' => false,
            'invites_enabled' => false,
            'configuration' => [
                'statuses' => [
                    'max_characters' => $maxChars,
                    'max_media_attachments' => 4
                ]
            ]
        ];

        Router::json($response);
    }

    /**
     * Endpoint GET /api/v2/instance
     */
    public static function getInstanceV2(): void {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT value FROM options WHERE key = 'instance_title' LIMIT 1");
        $stmt->execute();
        $title = $stmt->fetchColumn() ?: 'KutSocial';

        $stmtLimit = $db->prepare("SELECT value FROM options WHERE key = 'max_toot_chars' LIMIT 1");
        $stmtLimit->execute();
        $maxChars = (int)($stmtLimit->fetchColumn() ?: 500);

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $response = [
            'domain' => $domain,
            'title' => $title,
            'version' => '4.6.0-kutsocial',
            'source_url' => 'https://github.com/ernestoacostame/kutsocial',
            'description' => 'KutSocial: Alternativa ultraligera construida sobre PHP y SQLite.',
            'usage' => [
                'users' => [
                    'active_month' => self::getTableRowCount('accounts', "domain IS NULL")
                ]
            ],
            'thumbnail' => [
                'url' => "$proto://$domain/assets/thumbnail.png"
            ],
            'languages' => ['es'],
            'configuration' => [
                'statuses' => [
                    'max_characters' => $maxChars,
                    'max_media_attachments' => 4
                ]
            ],
            'registrations' => [
                'enabled' => false,
                'approval_required' => false,
                'message' => null
            ],
            'contact' => [
                'email' => 'admin@' . $domain,
                'account' => null
            ],
            'rules' => []
        ];

        Router::json($response);
    }

    /**
     * Endpoint POST /api/v1/apps
     */
    public static function createApp(): void {
        $body = Router::getRequestBody();
        $clientName = $body['client_name'] ?? 'KutSocial Client';
        $redirectUris = $body['redirect_uris'] ?? 'urn:ietf:wg:oauth:2.0:oob';

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));

        Router::json([
            'id' => '1',
            'name' => $clientName,
            'website' => null,
            'redirect_uri' => $redirectUris,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'vapid_key' => 'BC1234567890...'
        ]);
    }

    private static function generateCodeForUser(int $userId): string {
        $secret = self::getSigningSecret();
        $hash = hash_hmac('sha256', 'authcode_' . $userId, $secret);
        return "code_" . $userId . "_" . $hash;
    }

    private static function verifyCodeAndGetUserId(string $code): ?int {
        $parts = explode('_', $code);
        if (count($parts) !== 3 || $parts[0] !== 'code') {
            return null;
        }
        $userId = (int)$parts[1];
        $hash = $parts[2];

        $secret = self::getSigningSecret();
        $expectedHash = hash_hmac('sha256', 'authcode_' . $userId, $secret);

        if (!hash_equals($expectedHash, $hash)) {
            return null;
        }
        return $userId;
    }

    /**
     * Endpoint GET /oauth/authorize
     */
    public static function showAuthorize(): void {
        $clientId = $_GET['client_id'] ?? '';
        $redirectUri = $_GET['redirect_uri'] ?? '';
        $responseType = $_GET['response_type'] ?? '';
        $scope = $_GET['scope'] ?? '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Autorizar Aplicación - KutSocial</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: #f8fafc;
            font-family: 'Outfit', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h2 {
            margin-top: 0;
            color: #38bdf8;
            font-size: 24px;
        }
        p {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 25px;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            color: #cbd5e1;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(15, 23, 42, 0.6);
            color: white;
            box-sizing: border-box;
            transition: all 0.3s;
        }
        input[type="text"]:focus, input[type="password"]:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.2);
            outline: none;
        }
        .btn {
            width: 100%;
            padding: 14px;
            border-radius: 8px;
            border: none;
            background: linear-gradient(90deg, #38bdf8, #0ea5e9);
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Autorizar Acceso</h2>
        <p>Una aplicación externa quiere conectarse a tu cuenta de <strong>KutSocial</strong>.</p>
        
        <form method="POST" action="/oauth/authorize">
            <input type="hidden" name="client_id" value="{$clientId}">
            <input type="hidden" name="redirect_uri" value="{$redirectUri}">
            <input type="hidden" name="response_type" value="{$responseType}">
            <input type="hidden" name="scope" value="{$scope}">
            
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required placeholder="Tu usuario local">
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>
            
            <button type="submit" class="btn">Iniciar Sesión y Autorizar</button>
        </form>
    </div>
</body>
</html>
HTML;
        Router::html($html);
    }

    /**
     * Endpoint POST /oauth/authorize
     */
    public static function handleAuthorize(): void {
        $clientId = $_POST['client_id'] ?? '';
        $redirectUri = $_POST['redirect_uri'] ?? '';
        $responseType = $_POST['response_type'] ?? '';
        $scope = $_POST['scope'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Autorizar Aplicación - KutSocial</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: #f8fafc;
            font-family: 'Outfit', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h2 {
            margin-top: 0;
            color: #38bdf8;
            font-size: 24px;
        }
        p {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 25px;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            color: #cbd5e1;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(15, 23, 42, 0.6);
            color: white;
            box-sizing: border-box;
            transition: all 0.3s;
        }
        input[type="text"]:focus, input[type="password"]:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.2);
            outline: none;
        }
        .btn {
            width: 100%;
            padding: 14px;
            border-radius: 8px;
            border: none;
            background: linear-gradient(90deg, #38bdf8, #0ea5e9);
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
            transform: translateY(-2px);
        }
        .error {
            color: #f87171;
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.2);
            border-radius: 8px;
            padding: 10px;
            font-size: 13px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Autorizar Acceso</h2>
        <p>Una aplicación externa quiere conectarse a tu cuenta de <strong>KutSocial</strong>.</p>
        
        <div class="error">Usuario o contraseña incorrectos.</div>
        
        <form method="POST" action="/oauth/authorize">
            <input type="hidden" name="client_id" value="{$clientId}">
            <input type="hidden" name="redirect_uri" value="{$redirectUri}">
            <input type="hidden" name="response_type" value="{$responseType}">
            <input type="hidden" name="scope" value="{$scope}">
            
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required value="{$username}">
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>
            
            <button type="submit" class="btn">Iniciar Sesión y Autorizar</button>
        </form>
    </div>
</body>
</html>
HTML;
            Router::html($html);
        }

        $code = self::generateCodeForUser((int)$account['id']);

        if ($redirectUri === 'urn:ietf:wg:oauth:2.0:oob') {
            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Código de Autorización - KutSocial</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: #f8fafc;
            font-family: 'Outfit', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h2 {
            margin-top: 0;
            color: #38bdf8;
            font-size: 24px;
        }
        p {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 25px;
        }
        .code-box {
            background: rgba(15, 23, 42, 0.8);
            border: 1px dashed rgba(56, 189, 248, 0.3);
            border-radius: 8px;
            padding: 15px;
            font-family: monospace;
            font-size: 16px;
            color: #38bdf8;
            word-break: break-all;
            margin-bottom: 25px;
            user-select: all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Código de Autorización</h2>
        <p>Copia y pega este código en la aplicación externa para completar el inicio de sesión:</p>
        <div class="code-box">{$code}</div>
    </div>
</body>
</html>
HTML;
            Router::html($html);
        } else {
            $redirectUrl = $redirectUri;
            if (str_contains($redirectUrl, '?')) {
                $redirectUrl .= '&code=' . urlencode($code);
            } else {
                $redirectUrl .= '?code=' . urlencode($code);
            }
            header("Location: " . $redirectUrl);
            exit;
        }
    }

    /**
     * Endpoint POST /oauth/token
     */
    public static function postToken(): void {
        $body = Router::getRequestBody();
        $grantType = $body['grant_type'] ?? '';

        if ($grantType === 'authorization_code') {
            $code = $body['code'] ?? '';
            $userId = self::verifyCodeAndGetUserId($code);
            if (!$userId) {
                Router::json(['error' => 'invalid_grant', 'error_description' => 'Código de autorización inválido o expirado'], 400);
            }
            
            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $account = $stmt->fetch();
            
            if (!$account) {
                Router::json(['error' => 'invalid_grant', 'error_description' => 'Usuario no encontrado'], 400);
            }
        } elseif ($grantType === 'password') {
            $username = $body['username'] ?? '';
            $password = $body['password'] ?? '';
            
            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
            $stmt->execute([$username]);
            $account = $stmt->fetch();

            if (!$account || !password_verify($password, $account['password_hash'])) {
                Router::json(['error' => 'invalid_grant', 'error_description' => 'Usuario o contraseña incorrectos'], 400);
            }
        } else {
            Router::json(['error' => 'unsupported_grant_type'], 400);
        }

        // Generar token firmado sin guardar estado en DB
        $token = self::generateTokenForUser((int)$account['id']);

        Router::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'scope' => 'read write follow',
            'created_at' => time()
        ]);
    }

    /**
     * Endpoint GET /api/v1/accounts/verify_credentials
     */
    public static function verifyCredentials(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        Router::json(self::formatAccount($account));
    }

    /**
     * Endpoint GET /api/v1/collections (Novedad Mastodon 4.6 - Listas Públicas Federadas)
     */
    public static function getCollections(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        // Devolvemos colecciones/listas públicas del servidor
        // Mapeado sencillo
        Router::json([
            'items' => [
                [
                    'id' => 'public-fed',
                    'title' => 'Listas Federadas Públicas',
                    'description' => 'Servicio de descubrimiento federado',
                    'type' => 'public'
                ]
            ]
        ]);
    }

    /**
     * Endpoint GET /api/v1/profile (Novedad Mastodon 4.6)
     * Retorna detalles del perfil del usuario actual
     */
    public static function getProfile(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        // Formato con la bio en texto plano
        Router::json(self::formatAccount($account, true));
    }

    /**
     * Endpoint PATCH /api/v1/profile (Novedad Mastodon 4.6)
     * Permite actualizar bio en texto plano y descripciones de accesibilidad de avatar/header
     */
    public static function patchProfile(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        $body = Router::getRequestBody();
        
        $db = Database::connect();
        
        $fieldsToUpdate = [];
        $params = [];

        if (isset($body['note'])) {
            $fieldsToUpdate[] = "note = ?";
            $params[] = strip_tags($body['note']); // biografía en texto plano
        }
        if (isset($body['display_name'])) {
            $fieldsToUpdate[] = "display_name = ?";
            $params[] = $body['display_name'];
        }
        if (isset($body['avatar_description'])) {
            $fieldsToUpdate[] = "avatar_description = ?";
            $params[] = $body['avatar_description'];
        }
        if (isset($body['header_description'])) {
            $fieldsToUpdate[] = "header_description = ?";
            $params[] = $body['header_description'];
        }

        if (!empty($fieldsToUpdate)) {
            $fieldsToUpdate[] = "updated_at = datetime('now')";
            $sql = "UPDATE accounts SET " . implode(", ", $fieldsToUpdate) . " WHERE id = ?";
            $params[] = $account['id'];
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            // Recargar cuenta
            $stmtReload = $db->prepare("SELECT * FROM accounts WHERE id = ?");
            $stmtReload->execute([$account['id']]);
            $account = $stmtReload->fetch();

            // Transmitir actualización al Fediverso (followers)
            \KutSocial\Controllers\ActivityPubController::broadcastProfileUpdate($account);
        }

        Router::json(self::formatAccount($account, true));
    }

    /**
     * Endpoint GET /api/v1/notifications (Con soporte para fallback de supported_types)
     */
    public static function getNotifications(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        // Obtener tipos soportados por el cliente
        $supportedTypes = $_GET['supported_types'] ?? null;
        if (is_string($supportedTypes)) {
            $supportedTypes = [$supportedTypes];
        }

        $db = Database::connect();
        
        // Obtener seguimientos reales excluyendo los descartados
        $stmt = $db->prepare("
            SELECT f.id, f.created_at, a.id as follower_id, a.username, a.domain, a.display_name, a.avatar, a.note
            FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ?
              AND ('follow_' || f.id) NOT IN (
                  SELECT notification_id FROM dismissed_notifications WHERE account_id = ?
              )
            ORDER BY f.id DESC
            LIMIT 50
        ");
        $stmt->execute([$account['id'], $account['id']]);
        $rows = $stmt->fetchAll();

        $notifications = [];
        foreach ($rows as $row) {
            $domain = $row['domain'] ?? '';
            $acct = $domain ? $row['username'] . '@' . $domain : $row['username'];
            
            $notifications[] = [
                'id' => 'follow_' . $row['id'],
                'type' => 'follow',
                'created_at' => date('c', strtotime($row['created_at'])),
                'account' => [
                    'id' => (string)$row['follower_id'],
                    'username' => $row['username'],
                    'domain' => $row['domain'],
                    'acct' => $acct,
                    'display_name' => $row['display_name'] ?: $row['username'],
                    'avatar' => $row['avatar'] ?: '/assets/default-avatar.png',
                    'note' => $row['note'] ?? ''
                ]
            ];
        }

        // Lógica de fallback para notificaciones de tipos desconocidos
        $filtered = [];
        foreach ($notifications as $notification) {
            if ($supportedTypes !== null && !in_array($notification['type'], $supportedTypes)) {
                $notification['type'] = 'mention'; // Fallback a mención genérica
            }
            $filtered[] = $notification;
        }

        Router::json($filtered);
    }

    /**
     * Endpoint POST /api/v1/notifications/:id/dismiss
     */
    public static function dismissNotification(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        $id = $params['id'] ?? '';
        if (empty($id)) {
            Router::json(['error' => 'Bad Request'], 400);
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO dismissed_notifications (account_id, notification_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$account['id'], $id]);

        Router::json([]);
    }

    /**
     * Endpoint POST /api/v1/notifications/clear
     */
    public static function clearNotifications(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        $db = Database::connect();

        // Obtener las notificaciones actuales para guardarlas como descartadas
        $stmt = $db->prepare("
            SELECT f.id FROM follows f
            WHERE f.target_account_id = ?
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!empty($rows)) {
            $ins = $db->prepare("
                INSERT OR IGNORE INTO dismissed_notifications (account_id, notification_id)
                VALUES (?, ?)
            ");
            foreach ($rows as $id) {
                $ins->execute([$account['id'], 'follow_' . $id]);
            }
        }

        Router::json([]);
    }

    /**
     * Endpoint GET /api/v1/custom_emojis
     */
    public static function getCustomEmojis(): void {
        Router::json([]);
    }

    // --- Helpers auxiliares ---

    private static function getTableRowCount(string $table, string $where = null): int {
        try {
            $db = Database::connect();
            $sql = "SELECT COUNT(*) FROM " . $table;
            if ($where) {
                $sql .= " WHERE " . $where;
            }
            $stmt = $db->query($sql);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private static function getSigningSecret(): string {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT value FROM options WHERE key = 'jwt_secret' LIMIT 1");
        $stmt->execute();
        $secret = $stmt->fetchColumn();
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            $ins = $db->prepare("INSERT INTO options (key, value) VALUES ('jwt_secret', ?)");
            $ins->execute([$secret]);
        }
        return $secret;
    }

    private static function generateTokenForUser(int $userId): string {
        $secret = self::getSigningSecret();
        $hash = hash_hmac('sha256', $userId, $secret);
        return "token_" . $userId . "_" . $hash;
    }

    public static function getAuthenticatedAccount(): ?array {
        $token = Router::getBearerToken();
        if (!$token) {
            return null;
        }

        $parts = explode('_', $token);
        if (count($parts) !== 3 || $parts[0] !== 'token') {
            return null;
        }

        $userId = (int)$parts[1];
        $hash = $parts[2];

        $secret = self::getSigningSecret();
        $expectedHash = hash_hmac('sha256', $userId, $secret);

        if (!hash_equals($expectedHash, $hash)) {
            return null;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $account = $stmt->fetch();

        return $account ?: null;
    }

    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $resolve = (($_GET['resolve'] ?? '') === 'true' || ($_GET['resolve'] ?? '') === '1');
        $type = $_GET['type'] ?? null;
        
        $db = Database::connect();
        
        $accounts = [];
        $statuses = [];
        $hashtags = [];
        
        if (!empty($q)) {
            // 1. Resolver actor remoto si es un recurso Webfinger o URL
            if ($resolve) {
                if (filter_var($q, FILTER_VALIDATE_URL)) {
                    $resolvedAcc = \KutSocial\Controllers\ActivityPubController::getOrRegisterRemoteActor($q);
                    if ($resolvedAcc) {
                        $accounts[] = self::formatAccount($resolvedAcc);
                    }
                } elseif (preg_match('/^@?[a-zA-Z0-9_\-\.]+@[a-zA-Z0-9_\-\.]+$/', $q)) {
                    $resolvedAcc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($q);
                    if ($resolvedAcc) {
                        $accounts[] = self::formatAccount($resolvedAcc);
                    }
                }
            }
            
            // 2. Si no es un resolve exitoso o no se buscó resolver, buscar localmente
            if (empty($accounts) && ($type === null || $type === 'accounts')) {
                // Búsqueda en FTS5 si es texto plano, sino LIKE fallback
                $cleanQ = preg_replace('/[^\p{L}\p{N}\s]/u', '', $q);
                if (!empty($cleanQ)) {
                    $stmt = $db->prepare("
                        SELECT * FROM accounts 
                        WHERE id IN (SELECT rowid FROM accounts_fts WHERE accounts_fts MATCH ?)
                        LIMIT 10
                    ");
                    $stmt->execute([$cleanQ . '*']);
                    $localAccs = $stmt->fetchAll();
                } else {
                    $localAccs = [];
                }
                
                // Fallback por si FTS5 no arrojó o query tiene caracteres especiales
                if (empty($localAccs)) {
                    $stmt = $db->prepare("
                        SELECT * FROM accounts 
                        WHERE username LIKE ? OR display_name LIKE ? 
                        LIMIT 10
                    ");
                    $stmt->execute(['%' . $q . '%', '%' . $q . '%']);
                    $localAccs = $stmt->fetchAll();
                }
                
                foreach ($localAccs as $acc) {
                    $exists = false;
                    foreach ($accounts as $a) {
                        if ($a['id'] == $acc['id']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $accounts[] = self::formatAccount($acc);
                    }
                }
            }
            
            // 3. Buscar Statuses locales
            if ($type === null || $type === 'statuses') {
                $cleanQ = preg_replace('/[^\p{L}\p{N}\s]/u', '', $q);
                if (!empty($cleanQ)) {
                    $stmt = $db->prepare("
                        SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                               s.visibility as status_visibility, s.created_at as status_created_at,
                               s.media_attachments as status_media, s.sensitive as status_sensitive,
                               s.spoiler_text as status_spoiler,
                               a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                        FROM statuses s
                        JOIN accounts a ON s.account_id = a.id
                        WHERE s.id IN (SELECT rowid FROM statuses_fts WHERE statuses_fts MATCH ?)
                        ORDER BY s.id DESC
                        LIMIT 10
                    ");
                    $stmt->execute([$cleanQ . '*']);
                    $localStatuses = $stmt->fetchAll();
                } else {
                    $localStatuses = [];
                }
                
                if (empty($localStatuses)) {
                    $stmt = $db->prepare("
                        SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                               s.visibility as status_visibility, s.created_at as status_created_at,
                               s.media_attachments as status_media, s.sensitive as status_sensitive,
                               s.spoiler_text as status_spoiler,
                               a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                        FROM statuses s
                        JOIN accounts a ON s.account_id = a.id
                        WHERE s.content LIKE ?
                        ORDER BY s.id DESC
                        LIMIT 10
                    ");
                    $stmt->execute(['%' . $q . '%']);
                    $localStatuses = $stmt->fetchAll();
                }
                
                // Filtrar según bloqueos de moderación
                $localStatuses = self::filterStatuses($localStatuses);
                
                $currUserId = null;
                try {
                    $currentAccount = self::getAuthenticatedAccount();
                    if ($currentAccount) {
                        $currUserId = (int)$currentAccount['id'];
                    }
                } catch (\Exception $e) {
                    // No autenticado
                }

                foreach ($localStatuses as $s) {
                    $statuses[] = self::formatStatus($s, $currUserId);
                }
            }
            
            // 4. Buscar Hashtags locales
            if ($type === null || $type === 'hashtags') {
                if (str_starts_with($q, '#')) {
                    $tag = ltrim($q, '#');
                    $hashtags[] = [
                        'name' => $tag,
                        'url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/tags/' . $tag,
                        'history' => []
                    ];
                }
            }
        }
        
        Router::json([
            'accounts' => $accounts,
            'statuses' => $statuses,
            'hashtags' => $hashtags
        ]);
    }

    private static function formatAccount(array $account, bool $plainTextBio = false): array {
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $avatarUrl = $account['avatar'] ?: "$proto://$domain/assets/default-avatar.png";
        $headerUrl = $account['header'] ?: "$proto://$domain/assets/default-header.png";

        // Asegurar que las rutas locales absolutas tengan el prefijo del dominio si no son ya URLs
        if (!filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
            $avatarUrl = $proto . '://' . $domain . $avatarUrl;
        }
        if (!filter_var($headerUrl, FILTER_VALIDATE_URL)) {
            $headerUrl = $proto . '://' . $domain . $headerUrl;
        }

        // Devolver la bio en el formato adecuado
        $bio = $account['note'] ?: '';
        if (!$plainTextBio) {
            $bio = "<p>" . nl2br(htmlspecialchars($bio)) . "</p>";
        }

        // Procesar metadatos (campos adicionales)
        $fields = [];
        if (!empty($account['fields'])) {
            $fieldsArr = json_decode($account['fields'], true);
            if (is_array($fieldsArr)) {
                foreach ($fieldsArr as $f) {
                    $name = $f['name'] ?? '';
                    $val = $f['value'] ?? '';
                    
                    // Si el valor es un enlace web, formatearlo como HTML interactivo
                    if (filter_var($val, FILTER_VALIDATE_URL)) {
                        $label = parse_url($val, PHP_URL_HOST);
                        $val = sprintf('<a href="%s" rel="me nofollow noopener noreferrer" class="url"><span class="invisible">https://</span><span class="">%s</span></a>', htmlspecialchars($val), htmlspecialchars($label));
                    }

                    $fields[] = [
                        'name' => $name,
                        'value' => $val,
                        'verified_at' => $f['verified_at'] ?? null
                    ];
                }
            }
        }

        // Consultar estadísticas en tiempo real en SQLite
        $db = Database::connect();
        
        if ($account['domain'] !== null) {
            $followersCount = (int)($account['followers_count'] ?? 0);
            $followingCount = (int)($account['following_count'] ?? 0);
            $statusesCount = (int)($account['statuses_count'] ?? 0);
        } else {
            // Contar seguidores recibidos
            $stmtFollowers = $db->prepare("SELECT COUNT(*) FROM follows WHERE target_account_id = ? AND status = 'accepted'");
            $stmtFollowers->execute([$account['id']]);
            $followersCount = (int)$stmtFollowers->fetchColumn();

            // Contar seguidos realizados
            $stmtFollowing = $db->prepare("SELECT COUNT(*) FROM follows WHERE account_id = ? AND status = 'accepted'");
            $stmtFollowing->execute([$account['id']]);
            $followingCount = (int)$stmtFollowing->fetchColumn();

            // Contar toots creados
            $stmtStatuses = $db->prepare("SELECT COUNT(*) FROM statuses WHERE account_id = ?");
            $stmtStatuses->execute([$account['id']]);
            $statusesCount = (int)$stmtStatuses->fetchColumn();
        }

        return [
            'id' => (string)$account['id'],
            'username' => $account['username'],
            'acct' => $account['domain'] ? $account['username'] . '@' . $account['domain'] : $account['username'],
            'display_name' => $account['display_name'] ?: $account['username'],
            'locked' => (bool)($account['locked'] ?? 0),
            'bot' => false,
            'discoverable' => (bool)($account['discoverable'] ?? 1),
            'searchable' => (bool)($account['searchable'] ?? 1),
            'indexable' => (bool)($account['indexable'] ?? 1),
            'show_source' => (bool)($account['show_source'] ?? 1),
            'group' => false,
            'created_at' => date('c', strtotime($account['created_at'])),
            'note' => $bio,
            'url' => "$proto://$domain/users/" . $account['username'],
            'avatar' => $avatarUrl,
            'avatar_static' => $avatarUrl,
            'header' => $headerUrl,
            'header_static' => $headerUrl,
            'followers_count' => $followersCount,
            'following_count' => $followingCount,
            'statuses_count' => $statusesCount,
            'last_status_at' => null,
            'emojis' => [],
            'fields' => $fields,
            'role' => $account['role'] ?? 'user',
            'avatar_description' => $account['avatar_description'] ?? 'Imagen de perfil de ' . $account['username'],
            'header_description' => $account['header_description'] ?? 'Banner de perfil de ' . $account['username'],
        ];
    }

    /**
     * Endpoint PATCH /api/v1/accounts/update_credentials
     * Actualiza el perfil del usuario actual (nombre, bio, avatar, banner y metadatos)
     */
    public static function updateCredentials(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
        }

        $db = Database::connect();
        $uploadsDir = dirname(Database::getDbPath()) . '/uploads';
        
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $fieldsToUpdate = [];
        $params = [];

        // 1. Manejo de imágenes (Avatar y Header)
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach (['avatar', 'header'] as $fileKey) {
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$fileKey];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExtensions)) {
                    Router::json(['error' => "Extensión de imagen no permitida para $fileKey."], 400);
                }

                $filename = $fileKey . '_' . $account['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $targetFile = $uploadsDir . '/' . $filename;

                $moved = is_uploaded_file($file['tmp_name']) 
                    ? move_uploaded_file($file['tmp_name'], $targetFile) 
                    : rename($file['tmp_name'], $targetFile);

                if ($moved) {
                    $fieldsToUpdate[] = "$fileKey = ?";
                    $params[] = '/data/uploads/' . $filename;
                }
            }
        }

        // 2. Manejo de campos de texto
        if (isset($_POST['display_name'])) {
            $fieldsToUpdate[] = "display_name = ?";
            $params[] = trim($_POST['display_name']);
        }

        if (isset($_POST['note'])) {
            $fieldsToUpdate[] = "note = ?";
            $params[] = strip_tags(trim($_POST['note']));
        }

        // 3. Manejo de Campos de Metadatos Personalizados (Estilo Mastodon)
        if (isset($_POST['fields_attributes']) && is_array($_POST['fields_attributes'])) {
            $metaFields = [];
            foreach ($_POST['fields_attributes'] as $f) {
                $name = trim($f['name'] ?? '');
                $value = trim($f['value'] ?? '');
                
                if (!empty($name)) {
                    $verifiedAt = null;

                    // Si es un enlace web, verificar rel="me"
                    if (filter_var($value, FILTER_VALIDATE_URL)) {
                        if (self::verifyLinkRelation($value, $account['username'])) {
                            $verifiedAt = date('c');
                        }
                    }

                    $metaFields[] = [
                        'name' => $name,
                        'value' => $value,
                        'verified_at' => $verifiedAt
                    ];
                }
            }
            $fieldsToUpdate[] = "fields = ?";
            $params[] = json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $body = Router::getRequestBody();
        
        // Discoverable
        if (isset($_POST['discoverable']) || isset($body['discoverable'])) {
            $val = isset($_POST['discoverable']) ? ($_POST['discoverable'] === 'on' || $_POST['discoverable'] === '1' || $_POST['discoverable'] === 'true') : (bool)$body['discoverable'];
            $fieldsToUpdate[] = "discoverable = ?";
            $params[] = $val ? 1 : 0;
        } else if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            $fieldsToUpdate[] = "discoverable = ?";
            $params[] = 0;
        }

        // Auto Accept / Locked
        if (isset($_POST['auto_accept']) || isset($body['locked'])) {
            if (isset($_POST['auto_accept'])) {
                $val = ($_POST['auto_accept'] === 'on' || $_POST['auto_accept'] === '1');
                $lockedVal = $val ? 0 : 1;
            } else {
                $lockedVal = (bool)$body['locked'] ? 1 : 0;
            }
            $fieldsToUpdate[] = "locked = ?";
            $params[] = $lockedVal;
        } else if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            $fieldsToUpdate[] = "locked = ?";
            $params[] = 1;
        }

        // Searchable
        if (isset($_POST['searchable']) || isset($body['searchable'])) {
            $val = isset($_POST['searchable']) ? ($_POST['searchable'] === 'on' || $_POST['searchable'] === '1') : (bool)$body['searchable'];
            $fieldsToUpdate[] = "searchable = ?";
            $params[] = $val ? 1 : 0;
        } else if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            $fieldsToUpdate[] = "searchable = ?";
            $params[] = 0;
        }

        // Indexable
        if (isset($_POST['indexable']) || isset($body['indexable'])) {
            $val = isset($_POST['indexable']) ? ($_POST['indexable'] === 'on' || $_POST['indexable'] === '1') : (bool)$body['indexable'];
            $fieldsToUpdate[] = "indexable = ?";
            $params[] = $val ? 1 : 0;
        } else if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            $fieldsToUpdate[] = "indexable = ?";
            $params[] = 0;
        }

        // Show Source
        if (isset($_POST['show_source']) || isset($body['show_source'])) {
            $val = isset($_POST['show_source']) ? ($_POST['show_source'] === 'on' || $_POST['show_source'] === '1') : (bool)$body['show_source'];
            $fieldsToUpdate[] = "show_source = ?";
            $params[] = $val ? 1 : 0;
        } else if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            $fieldsToUpdate[] = "show_source = ?";
            $params[] = 0;
        }

        if (!empty($fieldsToUpdate)) {
            $fieldsToUpdate[] = "updated_at = datetime('now')";
            $sql = "UPDATE accounts SET " . implode(", ", $fieldsToUpdate) . " WHERE id = ?";
            $params[] = $account['id'];

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            // Recargar cuenta
            $stmtReload = $db->prepare("SELECT * FROM accounts WHERE id = ?");
            $stmtReload->execute([$account['id']]);
            $account = $stmtReload->fetch();

            // Transmitir actualización al Fediverso (followers)
            \KutSocial\Controllers\ActivityPubController::broadcastProfileUpdate($account);
        }

        Router::json(self::formatAccount($account));
    }

    /**
     * Verifica de fondo si un enlace remoto enlaza de vuelta a este perfil con rel="me".
     */
    private static function verifyLinkRelation(string $url, string $username): bool {
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $profileUrl = "$proto://$domain/users/$username";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_MAXFILESIZE => 102400, // Cargar máximo 100 KB para evitar abusos
            CURLOPT_USERAGENT => 'KutSocial-LinkVerifier/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($html)) {
            return false;
        }

        // Buscar patrón <a ... href="mi-perfil-url" ... rel="...me..." ...> o rel="me" antes de href
        $escapedUrl = preg_quote($profileUrl, '/');
        $pattern1 = '/<a\s+[^>]*href=["\']' . $escapedUrl . '["\'][^>]*rel=["\'][^"\']*me[^"\']*["\']/i';
        $pattern2 = '/<a\s+[^>]*rel=["\'][^"\']*me[^"\']*["\']\s+[^>]*href=["\']' . $escapedUrl . '["\']/i';

        if (preg_match($pattern1, $html) || preg_match($pattern2, $html)) {
            return true;
        }

        return false;
    }

    /**
     * Endpoint GET /api/v1/accounts/:id
     * Obtiene el perfil de cualquier cuenta por su ID
     */
    public static function getAccountById(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $account = $stmt->fetch();

        if (!$account) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        if ($account['domain'] !== null) {
            $lastUpdated = strtotime($account['updated_at']);
            if (time() - $lastUpdated >= 300) {
                $updatedAccount = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($account['username'] . '@' . $account['domain']);
                if ($updatedAccount) {
                    $account = $updatedAccount;
                }
            }
        }

        Router::json(self::formatAccount($account));
    }

    /**
     * Endpoint GET /api/v1/accounts/:id/statuses
     * Obtiene las publicaciones creadas por una cuenta específica
     */
    public static function getAccountStatuses(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;

        $whereClauses = ["s.account_id = ?"];
        $queryParams = [$id];

        if ($maxId !== null) {
            $whereClauses[] = "s.id < ?";
            $queryParams[] = $maxId;
        }
        if ($sinceId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $sinceId;
        }
        if ($minId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $minId;
        }

        $whereSql = implode(" AND ", $whereClauses);

        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE $whereSql
            ORDER BY s.id DESC
            LIMIT $limit
        ");
        $stmt->execute($queryParams);
        $rows = self::filterStatuses($stmt->fetchAll());

        $currUser = self::getAuthenticatedAccount();
        $currUserId = $currUser ? (int)$currUser['id'] : null;

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = self::formatStatus($row, $currUserId);
        }

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    /**
     * Endpoint GET /api/v1/timelines/home
     */
    public static function getHomeTimeline(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;

        $whereClauses = ["(s.account_id = ? OR s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND status = 'accepted'))"];
        $queryParams = [$account['id'], $account['id']];

        if ($maxId !== null) {
            $whereClauses[] = "s.id < ?";
            $queryParams[] = $maxId;
        }
        if ($sinceId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $sinceId;
        }
        if ($minId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $minId;
        }

        $whereSql = implode(" AND ", $whereClauses);

        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE $whereSql
            ORDER BY s.id DESC
            LIMIT $limit
        ");
        $stmt->execute($queryParams);
        $rows = self::filterStatuses($stmt->fetchAll());

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = self::formatStatus($row, (int)$account['id']);
        }

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    /**
     * Endpoint GET /api/v1/timelines/public
     */
    public static function getPublicTimeline(): void {
        $db = Database::connect();

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 40;
        if ($limit < 1) $limit = 40;
        if ($limit > 80) $limit = 80;

        $whereClauses = [];
        $queryParams = [];

        if ($maxId !== null) {
            $whereClauses[] = "s.id < ?";
            $queryParams[] = $maxId;
        }
        if ($sinceId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $sinceId;
        }
        if ($minId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $minId;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            $whereSql
            ORDER BY s.id DESC
            LIMIT $limit
        ");
        $stmt->execute($queryParams);
        $rows = self::filterStatuses($stmt->fetchAll());

        $currUser = self::getAuthenticatedAccount();
        $currUserId = $currUser ? (int)$currUser['id'] : null;

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = self::formatStatus($row, $currUserId);
        }

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    /**
     * Endpoint POST /api/v1/statuses
     */
    public static function postStatus(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $body = Router::getRequestBody();
        $content = trim($body['status'] ?? '');
        $visibility = $body['visibility'] ?? 'public';
        $inReplyToId = !empty($body['in_reply_to_id']) ? (int)$body['in_reply_to_id'] : null;
        $sensitive = !empty($body['sensitive']) ? 1 : 0;
        $spoilerText = trim($body['spoiler_text'] ?? '');

        if (empty($content)) {
            Router::json(['error' => 'El estado no puede estar vacío.'], 400);
            return;
        }

        $db = Database::connect();
        
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Cargar límite de caracteres
        $stmtLimit = $db->prepare("SELECT value FROM options WHERE key = 'max_toot_chars' LIMIT 1");
        $stmtLimit->execute();
        $maxChars = (int)($stmtLimit->fetchColumn() ?: 500);

        if (mb_strlen($content) > $maxChars) {
            Router::json(['error' => "El estado no puede superar el límite de {$maxChars} caracteres."], 400);
            return;
        }

        $statusIdStr = bin2hex(random_bytes(8));
        $uri = "$proto://$domain/users/{$account['username']}/statuses/$statusIdStr";

        // Carga de media attachments
        $mediaIds = $body['media_ids'] ?? [];
        $mediaAttachments = [];
        if (!empty($mediaIds) && is_array($mediaIds)) {
            $uploadsDir = dirname(Database::getDbPath()) . '/uploads';
            foreach ($mediaIds as $mId) {
                $mId = preg_replace('/[^a-zA-Z0-9]/', '', $mId);
                $matches = glob($uploadsDir . "/media_" . $mId . ".*");
                if (!empty($matches)) {
                    $filePath = $matches[0];
                    $filename = basename($filePath);
                    $fileUrl = "$proto://$domain/data/uploads/$filename";
                    
                    $width = 600;
                    $height = 400;
                    $aspect = 1.5;
                    if ($size = @getimagesize($filePath)) {
                        $width = $size[0];
                        $height = $size[1];
                        $aspect = $height > 0 ? $width / $height : 1.0;
                    }

                    $mediaAttachments[] = [
                        'id' => $mId,
                        'type' => 'image',
                        'url' => $fileUrl,
                        'preview_url' => $fileUrl,
                        'remote_url' => null,
                        'text_url' => $fileUrl,
                        'meta' => [
                            'focus' => ['x' => 0.0, 'y' => 0.0],
                            'original' => [
                                'width' => $width,
                                'height' => $height,
                                'size' => "{$width}x{$height}",
                                'aspect' => $aspect
                            ]
                        ],
                        'description' => null,
                        'blurhash' => 'L6PZfHnd.Txu_N%M_Mx]URt7bIWA'
                    ];
                }
            }
        }

        $language = !empty($body['language']) ? strtolower(trim($body['language'])) : (isset($_POST['language']) ? strtolower(trim($_POST['language'])) : null);

        // Insertar status
        $stmt = $db->prepare("
            INSERT INTO statuses (account_id, uri, content, visibility, in_reply_to_id, sensitive, spoiler_text, media_attachments, language, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$account['id'], $uri, $content, $visibility, $inReplyToId, $sensitive, $spoilerText, !empty($mediaAttachments) ? json_encode($mediaAttachments) : null, $language]);
        $newId = $db->lastInsertId();

        // Procesamiento de Encuestas (Polls)
        $pollData = $body['poll'] ?? null;
        if (!$pollData && isset($_POST['poll']) && is_array($_POST['poll'])) {
            $pollData = $_POST['poll'];
        }
        if ($pollData && isset($pollData['options']) && is_array($pollData['options'])) {
            $options = array_map('trim', $pollData['options']);
            $options = array_filter($options);
            if (count($options) >= 2) {
                $expiresIn = (int)($pollData['expires_in'] ?? 3600);
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
                
                $stmtPoll = $db->prepare("
                    INSERT INTO polls (status_id, question, options_json, expires_at)
                    VALUES (?, ?, ?, ?)
                ");
                $stmtPoll->execute([
                    $newId,
                    $content,
                    json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
                    $expiresAt
                ]);
            }
        }

        // Obtener el status insertado con los datos de la cuenta
        $row = self::fetchStatusRow($db, $newId);

        // FEDERACIÓN: Encolar la actividad Create Note a todos los seguidores
        $followersStmt = $db->prepare("
            SELECT a.inbox_url FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ? AND f.status = 'accepted' AND a.domain IS NOT NULL
        ");
        $followersStmt->execute([$account['id']]);
        $followers = $followersStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($followers)) {
            $actorUrl = "$proto://$domain/users/{$account['username']}";
            
            $noteObject = [
                'id' => $uri,
                'type' => 'Note',
                'published' => date('c'),
                'attributedTo' => $actorUrl,
                'content' => $content,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc' => ["$actorUrl/followers"]
            ];

            if (!empty($mediaAttachments)) {
                $attachmentsList = [];
                foreach ($mediaAttachments as $att) {
                    $attachmentsList[] = [
                        'type' => 'Document',
                        'mediaType' => 'image/' . pathinfo($att['url'], PATHINFO_EXTENSION),
                        'url' => $att['url'],
                        'name' => null
                    ];
                }
                $noteObject['attachment'] = $attachmentsList;
            }

            if ($pollData && isset($options) && count($options) >= 2) {
                $oneOfList = [];
                foreach ($options as $opt) {
                    $oneOfList[] = [
                        'type' => 'Note',
                        'name' => $opt,
                        'replies' => [
                            'type' => 'Collection',
                            'totalItems' => 0
                        ]
                    ];
                }
                $noteObject['oneOf'] = $oneOfList;
                $noteObject['endTime'] = date('c', time() + $expiresIn);
            }

            $createActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $uri . '/activity',
                'type' => 'Create',
                'actor' => $actorUrl,
                'published' => date('c'),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc' => ["$actorUrl/followers"],
                'object' => $noteObject
            ];

            foreach ($followers as $inboxUrl) {
                if (!empty($inboxUrl)) {
                    \KutSocial\Queue::enqueue('Create', $createActivity, $inboxUrl);
                }
            }
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    /**
     * Endpoint Server-Sent Events GET /api/v1/streaming
     * Envía notificaciones de toots en tiempo real al cliente
     */
    public static function getStreaming(): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        $lastId = (int)($_GET['since_id'] ?? 0);
        if ($lastId <= 0) {
            $db = Database::connect();
            $stmt = $db->query("SELECT MAX(id) FROM statuses");
            $lastId = (int)$stmt->fetchColumn();
        }

        $db = Database::connect();
        $startTime = time();
        $timeout = 25;

        while (time() - $startTime < $timeout) {
            if (connection_aborted()) {
                break;
            }

            $stmt = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id > ?
                ORDER BY s.id ASC
            ");
            $stmt->execute([$lastId]);
            $newStatuses = $stmt->fetchAll();

            foreach ($newStatuses as $row) {
                $lastId = max($lastId, (int)$row['status_id']);
                $statusFormatted = self::formatStatus($row);
                echo "event: update\n";
                echo "data: " . json_encode($statusFormatted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            }

            echo ": ping\n\n";
            flush();

            sleep(2);
        }
        exit;
    }

    private static function formatStatus(array $row, ?int $currentAccountId = null): array {
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $avatarUrl = $row['avatar'] ?: "$proto://$domain/assets/default-avatar.png";
        $headerUrl = $row['header'] ?: "$proto://$domain/assets/default-header.png";

        if (!filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
            $avatarUrl = $proto . '://' . $domain . $avatarUrl;
        }
        if (!filter_var($headerUrl, FILTER_VALIDATE_URL)) {
            $headerUrl = $proto . '://' . $domain . $headerUrl;
        }

        $account = [
            'id' => (string)$row['account_id'],
            'username' => $row['username'],
            'acct' => $row['domain'] ? $row['username'] . '@' . $row['domain'] : $row['username'],
            'display_name' => $row['display_name'] ?: $row['username'],
            'locked' => false,
            'bot' => false,
            'discoverable' => true,
            'group' => false,
            'created_at' => date('c', strtotime($row['account_created_at'])),
            'note' => "<p>" . nl2br(htmlspecialchars($row['note'] ?? '')) . "</p>",
            'url' => $row['domain'] ? "$proto://{$row['domain']}/users/{$row['username']}" : "$proto://$domain/users/" . $row['username'],
            'avatar' => $avatarUrl,
            'avatar_static' => $avatarUrl,
            'header' => $headerUrl,
            'header_static' => $headerUrl,
            'followers_count' => 0,
            'following_count' => 0,
            'statuses_count' => 0,
            'last_status_at' => null,
            'emojis' => [],
            'fields' => [],
            'avatar_description' => $row['avatar_description'] ?? '',
            'header_description' => $row['header_description'] ?? ''
        ];

        $db = Database::connect();

        $showSource = true;
        try {
            $stmtShowSource = $db->prepare("SELECT show_source FROM accounts WHERE id = ? LIMIT 1");
            $stmtShowSource->execute([$row['account_id']]);
            $showSourceVal = $stmtShowSource->fetchColumn();
            if ($showSourceVal !== false) {
                $showSource = (bool)$showSourceVal;
            }
        } catch (\Exception $e) {
            // Ignorar
        }

        $application = null;
        if ($showSource) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $appName = 'Web';
            $appUrl = null;
            if (stripos($ua, 'Tusky') !== false) {
                $appName = 'Tusky';
                $appUrl = 'https://tusky.app';
            } elseif (stripos($ua, 'Fedilab') !== false) {
                $appName = 'Fedilab';
                $appUrl = 'https://fedilab.app';
            } elseif (stripos($ua, 'KutPod') !== false) {
                $appName = 'KutPod';
            } elseif (stripos($ua, 'SubwayTooter') !== false) {
                $appName = 'SubwayTooter';
            } elseif (stripos($ua, 'Mastodon') !== false) {
                $appName = 'Mastodon Client';
            } else {
                $appName = 'KutSocial';
            }
            
            $application = [
                'name' => $appName,
                'website' => $appUrl
            ];
        }
        $statusId = (int)$row['status_id'];

        // Favourites count
        $stmtFav = $db->prepare("SELECT COUNT(*) FROM favourites WHERE status_id = ?");
        $stmtFav->execute([$statusId]);
        $favCount = (int)$stmtFav->fetchColumn();

        // Replies count
        $stmtRep = $db->prepare("SELECT COUNT(*) FROM statuses WHERE in_reply_to_id = ?");
        $stmtRep->execute([$statusId]);
        $repCount = (int)$stmtRep->fetchColumn();

        // Checks de interacción para usuario logueado
        $favourited = false;
        $bookmarked = false;

        if ($currentAccountId !== null) {
            $stmtFavCheck = $db->prepare("SELECT 1 FROM favourites WHERE account_id = ? AND status_id = ? LIMIT 1");
            $stmtFavCheck->execute([$currentAccountId, $statusId]);
            $favourited = (bool)$stmtFavCheck->fetchColumn();

            $stmtBookCheck = $db->prepare("SELECT 1 FROM bookmarks WHERE account_id = ? AND status_id = ? LIMIT 1");
            $stmtBookCheck->execute([$currentAccountId, $statusId]);
            $bookmarked = (bool)$stmtBookCheck->fetchColumn();
        }

        // Cargar attachments guardados
        $attachments = [];
        if (!empty($row['media_attachments'])) {
            $attachments = json_decode($row['media_attachments'], true);
            if (!is_array($attachments)) {
                $attachments = [];
            }
        }

        // Cargar encuesta si existe
        $poll = null;
        $stmtPoll = $db->prepare("SELECT * FROM polls WHERE status_id = ? LIMIT 1");
        $stmtPoll->execute([$statusId]);
        if ($pollRow = $stmtPoll->fetch()) {
            $options = json_decode($pollRow['options_json'], true) ?: [];
            
            $votesCount = [];
            foreach ($options as $idx => $optName) {
                $votesCount[$idx] = 0;
            }
            
            $stmtVotes = $db->prepare("SELECT choice_index, COUNT(*) as qty FROM poll_votes WHERE poll_id = ? GROUP BY choice_index");
            $stmtVotes->execute([$pollRow['id']]);
            $votesRows = $stmtVotes->fetchAll();
            $totalVotes = 0;
            foreach ($votesRows as $vr) {
                $cIdx = (int)$vr['choice_index'];
                $qty = (int)$vr['qty'];
                if (isset($votesCount[$cIdx])) {
                    $votesCount[$cIdx] = $qty;
                }
                $totalVotes += $qty;
            }

            $voted = false;
            $ownVote = null;
            if ($currentAccountId !== null) {
                $stmtVoted = $db->prepare("SELECT choice_index FROM poll_votes WHERE poll_id = ? AND account_id = ? LIMIT 1");
                $stmtVoted->execute([$pollRow['id'], $currentAccountId]);
                $voteVal = $stmtVoted->fetch();
                if ($voteVal !== false) {
                    $voted = true;
                    $ownVote = (int)$voteVal['choice_index'];
                }
            }

            $formattedOptions = [];
            foreach ($options as $idx => $title) {
                $formattedOptions[] = [
                    'title' => $title,
                    'votes_count' => $votesCount[$idx]
                ];
            }

            $expired = (strtotime($pollRow['expires_at']) <= time());

            $poll = [
                'id' => (string)$pollRow['id'],
                'expires_at' => date('c', strtotime($pollRow['expires_at'])),
                'expired' => $expired,
                'multiple' => false,
                'votes_count' => $totalVotes,
                'options' => $formattedOptions,
                'voted' => $voted,
                'own_vote' => $ownVote,
                'voters_count' => $totalVotes
            ];
        }

        return [
            'id' => (string)$row['status_id'],
            'created_at' => date('c', strtotime($row['status_created_at'])),
            'in_reply_to_id' => !empty($row['in_reply_to_id']) ? (string)$row['in_reply_to_id'] : null,
            'in_reply_to_account_id' => null,
            'sensitive' => !empty($row['sensitive']),
            'spoiler_text' => $row['spoiler_text'] ?? '',
            'visibility' => $row['status_visibility'] ?? 'public',
            'language' => $row['language'] ?? 'es',
            'uri' => $row['status_uri'],
            'url' => $row['status_uri'],
            'replies_count' => $repCount,
            'reblogs_count' => 0,
            'favourites_count' => $favCount,
            'favourited' => $favourited,
            'reblogged' => false,
            'muted' => false,
            'bookmarked' => $bookmarked,
            'content' => "<p>" . nl2br(htmlspecialchars($row['status_content'])) . "</p>",
            'account' => $account,
            'media_attachments' => $attachments,
            'mentions' => [],
            'tags' => [],
            'emojis' => [],
            'card' => null,
            'poll' => $poll,
            'application' => $application
        ];
    }

    public static function getRelationships(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $ids = $_GET['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $db = Database::connect();
        $relationships = [];

        foreach ($ids as $id) {
            $id = (int)$id;
            
            $stmt = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
            $stmt->execute([$account['id'], $id]);
            $followStatus = $stmt->fetchColumn();

            $stmt2 = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
            $stmt2->execute([$id, $account['id']]);
            $followedByStatus = $stmt2->fetchColumn();

            $relationships[] = [
                'id' => (string)$id,
                'following' => ($followStatus === 'accepted' || $followStatus === 'pending'),
                'showing_reblogs' => true,
                'notifying' => false,
                'languages' => null,
                'followed_by' => ($followedByStatus === 'accepted'),
                'blocking' => false,
                'blocked_by' => false,
                'muting' => false,
                'muting_notifications' => false,
                'requested' => ($followStatus === 'pending'),
                'domain_blocking' => false,
                'endorsement' => false,
                'note' => ''
            ];
        }

        Router::json($relationships);
    }

    public static function followAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $targetId = (int)$params['id'];
        $db = Database::connect();

        if ($targetId === (int)$account['id']) {
            Router::json(['error' => 'No puedes seguirte a ti mismo'], 400);
            return;
        }

        $stmtTarget = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmtTarget->execute([$targetId]);
        $targetAccount = $stmtTarget->fetch();
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $stmtCheck = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtCheck->execute([$account['id'], $targetId]);
        $existing = $stmtCheck->fetchColumn();

        $isRemote = !empty($targetAccount['domain']);
        if ($isRemote) {
            $status = 'pending';
        } else {
            $status = ($targetAccount['locked'] ?? 0) ? 'pending' : 'accepted';
        }

        if (!$existing) {
            $stmt = $db->prepare("
                INSERT INTO follows (account_id, target_account_id, status, created_at)
                VALUES (?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$account['id'], $targetId, $status]);
        } else {
            $stmt = $db->prepare("UPDATE follows SET status = ? WHERE id = ?");
            $stmt->execute([$status, $existing]);
        }

        // Send federated ActivityPub Follow activity if remote
        if ($isRemote && !empty($targetAccount['inbox_url'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$account['username']}";
            $remoteActorUrl = $targetAccount['url'] ?? "$proto://{$targetAccount['domain']}/users/{$targetAccount['username']}";
            
            $followId = $localActorUrl . '/activities/follow-' . bin2hex(random_bytes(8));
            
            $followActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $followId,
                'type' => 'Follow',
                'actor' => $localActorUrl,
                'object' => $remoteActorUrl
            ];
            \KutSocial\Queue::enqueue('Follow', $followActivity, $targetAccount['inbox_url']);
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json([
            'id' => (string)$targetId,
            'following' => ($status === 'accepted' || $status === 'pending'),
            'showing_reblogs' => true,
            'notifying' => false,
            'languages' => null,
            'followed_by' => false,
            'blocking' => false,
            'blocked_by' => false,
            'muting' => false,
            'muting_notifications' => false,
            'requested' => ($status === 'pending'),
            'domain_blocking' => false,
            'endorsement' => false,
            'note' => ''
        ]);
    }

    public static function unfollowAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $targetId = (int)$params['id'];
        $db = Database::connect();

        $stmtTarget = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmtTarget->execute([$targetId]);
        $targetAccount = $stmtTarget->fetch();

        $stmt = $db->prepare("DELETE FROM follows WHERE account_id = ? AND target_account_id = ?");
        $stmt->execute([$account['id'], $targetId]);

        if ($targetAccount && !empty($targetAccount['domain']) && !empty($targetAccount['inbox_url'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$account['username']}";
            $remoteActorUrl = $targetAccount['url'] ?? "$proto://{$targetAccount['domain']}/users/{$targetAccount['username']}";
            
            $undoActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActorUrl . '/activities/undo-' . bin2hex(random_bytes(8)),
                'type' => 'Undo',
                'actor' => $localActorUrl,
                'object' => [
                    'id' => $localActorUrl . '/activities/follow-dummy',
                    'type' => 'Follow',
                    'actor' => $localActorUrl,
                    'object' => $remoteActorUrl
                ]
            ];
            \KutSocial\Queue::enqueue('Undo', $undoActivity, $targetAccount['inbox_url']);
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json([
            'id' => (string)$targetId,
            'following' => false,
            'showing_reblogs' => false,
            'notifying' => false,
            'languages' => null,
            'followed_by' => false,
            'blocking' => false,
            'blocked_by' => false,
            'muting' => false,
            'muting_notifications' => false,
            'requested' => false,
            'domain_blocking' => false,
            'endorsement' => false,
            'note' => ''
        ]);
    }

    public static function getBookmarks(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;

        $whereClauses = ["b.account_id = ?"];
        $queryParams = [$account['id']];

        if ($maxId !== null) {
            $whereClauses[] = "s.id < ?";
            $queryParams[] = $maxId;
        }
        if ($sinceId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $sinceId;
        }
        if ($minId !== null) {
            $whereClauses[] = "s.id > ?";
            $queryParams[] = $minId;
        }

        $whereSql = implode(" AND ", $whereClauses);

        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM bookmarks b
            JOIN statuses s ON b.status_id = s.id
            JOIN accounts a ON s.account_id = a.id
            WHERE $whereSql
            ORDER BY b.id DESC
            LIMIT $limit
        ");
        $stmt->execute($queryParams);
        $rows = self::filterStatuses($stmt->fetchAll());

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = self::formatStatus($row, (int)$account['id']);
        }

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    public static function getStatusContext(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();
        
        $account = self::getAuthenticatedAccount();
        $currUserId = $account ? (int)$account['id'] : null;

        $stmt = $db->prepare("SELECT in_reply_to_id FROM statuses WHERE id = ?");
        $stmt->execute([$id]);
        $status = $stmt->fetch();
        if (!$status) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

        $statusFetcher = function($statusId) use ($db, $currUserId) {
            $stmt = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$statusId]);
            $row = $stmt->fetch();
            
            if ($row) {
                $filtered = self::filterStatuses([$row]);
                if (empty($filtered)) {
                    return null;
                }
            }
            
            return $row ? self::formatStatus($row, $currUserId) : null;
        };

        $ancestors = [];
        $parentId = $status['in_reply_to_id'];
        while ($parentId !== null) {
            $parentStatus = $statusFetcher($parentId);
            if ($parentStatus) {
                array_unshift($ancestors, $parentStatus);
                $parentId = $parentStatus['in_reply_to_id'];
            } else {
                break;
            }
        }

        $descendants = [];
        $replyQueue = [$id];
        while (!empty($replyQueue)) {
            $currentId = array_shift($replyQueue);
            
            $stmtReplies = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.in_reply_to_id = ?
                ORDER BY s.id ASC
            ");
            $stmtReplies->execute([$currentId]);
            $replies = self::filterStatuses($stmtReplies->fetchAll());

            foreach ($replies as $row) {
                $descendants[] = self::formatStatus($row, $currUserId);
                $replyQueue[] = (int)$row['status_id'];
            }
        }

        Router::json([
            'ancestors' => $ancestors,
            'status' => $statusFetcher($id),
            'descendants' => $descendants
        ]);
    }

    public static function updateStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $body = Router::getRequestBody();
        $content = trim($body['status'] ?? '');
        $sensitive = !empty($body['sensitive']) ? 1 : 0;
        $spoilerText = trim($body['spoiler_text'] ?? '');

        if (empty($content)) {
            Router::json(['error' => 'El contenido no puede estar vacío'], 400);
            return;
        }

        $db = Database::connect();
        
        $stmtCheck = $db->prepare("SELECT account_id FROM statuses WHERE id = ? LIMIT 1");
        $stmtCheck->execute([$id]);
        $ownerId = $stmtCheck->fetchColumn();

        if (!$ownerId) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

        if ((int)$ownerId !== (int)$account['id']) {
            Router::json(['error' => 'No tienes permiso para editar este status'], 403);
            return;
        }

        $stmt = $db->prepare("
            UPDATE statuses 
            SET content = ?, sensitive = ?, spoiler_text = ? 
            WHERE id = ?
        ");
        $stmt->execute([$content, $sensitive, $spoilerText, $id]);

        $stmtFetch = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmtFetch->execute([$id]);
        $row = $stmtFetch->fetch();

        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    public static function deleteStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();
        
        $stmtCheck = $db->prepare("SELECT * FROM statuses WHERE id = ? LIMIT 1");
        $stmtCheck->execute([$id]);
        $status = $stmtCheck->fetch();

        if (!$status) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

        if ((int)$status['account_id'] !== (int)$account['id']) {
            Router::json(['error' => 'No tienes permiso para borrar este status'], 403);
            return;
        }

        $stmt = $db->prepare("DELETE FROM statuses WHERE id = ?");
        $stmt->execute([$id]);

        Router::json([
            'id' => (string)$id,
            'text' => $status['content']
        ]);
    }

    public static function favouriteStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();

        $stmtCheck = $db->prepare("SELECT 1 FROM statuses WHERE id = ?");
        $stmtCheck->execute([$id]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO favourites (account_id, status_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$account['id'], $id]);

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO favourites (account_id, status_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$account['id'], $id]);

        $row = self::fetchStatusRow($db, $id);
        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    public static function unfavouriteStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();

        $stmt = $db->prepare("DELETE FROM favourites WHERE account_id = ? AND status_id = ?");
        $stmt->execute([$account['id'], $id]);

        $stmt = $db->prepare("DELETE FROM favourites WHERE account_id = ? AND status_id = ?");
        $stmt->execute([$account['id'], $id]);

        $row = self::fetchStatusRow($db, $id);
        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    public static function bookmarkStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();

        $stmtCheck = $db->prepare("SELECT 1 FROM statuses WHERE id = ?");
        $stmtCheck->execute([$id]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO bookmarks (account_id, status_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$account['id'], $id]);

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO bookmarks (account_id, status_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$account['id'], $id]);

        $row = self::fetchStatusRow($db, $id);
        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    public static function unbookmarkStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();

        $stmt = $db->prepare("DELETE FROM bookmarks WHERE account_id = ? AND status_id = ?");
        $stmt->execute([$account['id'], $id]);

        $row = self::fetchStatusRow($db, $id);
        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    private static function fetchStatusRow(PDO $db, int $id): ?array {
        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function setLinkHeader(array $items): void {
        if (empty($items)) {
            return;
        }
        
        $oldestId = null;
        $newestId = null;
        
        if (isset($items[0]['id'])) {
            $newestId = $items[0]['id'];
        }
        if (isset($items[count($items) - 1]['id'])) {
            $oldestId = $items[count($items) - 1]['id'];
        }
        
        if ($oldestId === null || $newestId === null) {
            return;
        }
        
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        
        $queryParams = $_GET;
        unset($queryParams['max_id'], $queryParams['since_id'], $queryParams['min_id']);
        
        $nextQuery = http_build_query(array_merge($queryParams, ['max_id' => $oldestId]));
        $prevQuery = http_build_query(array_merge($queryParams, ['since_id' => $newestId]));
        
        $nextUrl = "$proto://$domain$uriPath?$nextQuery";
        $prevUrl = "$proto://$domain$uriPath?$prevQuery";
        
        header("Link: <$nextUrl>; rel=\"next\", <$prevUrl>; rel=\"prev\"");
    }

    private static function filterStatuses(array $rows): array {
        $db = Database::connect();
        $blocks = $db->query("SELECT type, target FROM moderation_blocks")->fetchAll();
        if (empty($blocks)) {
            return $rows;
        }

        $blockedDomains = [];
        $blockedAccounts = [];
        $blockedWords = [];
        $blockedHashtags = [];
        $blockedLanguages = [];

        foreach ($blocks as $b) {
            $t = strtolower($b['target']);
            if ($b['type'] === 'domain') {
                $blockedDomains[] = $t;
            } elseif ($b['type'] === 'account') {
                $blockedAccounts[] = $t;
            } elseif ($b['type'] === 'word') {
                $blockedWords[] = $t;
            } elseif ($b['type'] === 'hashtag') {
                if (!str_starts_with($t, '#')) {
                    $t = '#' . $t;
                }
                $blockedHashtags[] = $t;
            } elseif ($b['type'] === 'language') {
                $blockedLanguages[] = $t;
            }
        }

        $filtered = [];
        foreach ($rows as $row) {
            $domain = strtolower($row['domain'] ?? '');
            if (!empty($domain) && in_array($domain, $blockedDomains)) {
                continue;
            }

            $username = strtolower($row['username'] ?? '');
            $acct = $domain ? $username . '@' . $domain : $username;
            if (in_array($username, $blockedAccounts) || in_array($acct, $blockedAccounts)) {
                continue;
            }

            $content = strtolower($row['status_content'] ?? '');
            $hasBlockedWord = false;
            foreach ($blockedWords as $word) {
                if (str_contains($content, $word)) {
                    $hasBlockedWord = true;
                    break;
                }
            }
            if ($hasBlockedWord) {
                continue;
            }

            $hasBlockedHashtag = false;
            foreach ($blockedHashtags as $hash) {
                if (str_contains($content, $hash)) {
                    $hasBlockedHashtag = true;
                    break;
                }
            }
            if ($hasBlockedHashtag) {
                continue;
            }

            $lang = strtolower($row['language'] ?? '');
            if (!empty($lang) && in_array($lang, $blockedLanguages)) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    public static function uploadMedia(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!isset($_FILES['file'])) {
            $receivedKeys = implode(', ', array_keys($_FILES));
            self::log("uploadMedia: No se recibió la clave 'file' en $_FILES. Claves recibidas: [$receivedKeys]");
            Router::json(['error' => 'No se recibió ningún archivo.'], 400);
            return;
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errCode = $file['error'];
            $errMsg = "Error de subida de PHP (Código $errCode).";
            if ($errCode === UPLOAD_ERR_INI_SIZE) {
                $errMsg .= " El archivo excede el límite 'upload_max_filesize' configurado en php.ini (actualmente: " . ini_get('upload_max_filesize') . ").";
            } elseif ($errCode === UPLOAD_ERR_FORM_SIZE) {
                $errMsg .= " El archivo excede el límite MAX_FILE_SIZE especificado en el formulario HTML.";
            } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
                $errMsg .= " El archivo se subió solo parcialmente.";
            } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
                $errMsg .= " No se subió ningún archivo.";
            } elseif ($errCode === UPLOAD_ERR_NO_TMP_DIR) {
                $errMsg .= " Falta la carpeta temporal de PHP.";
            } elseif ($errCode === UPLOAD_ERR_CANT_WRITE) {
                $errMsg .= " Error al escribir el archivo en el disco del servidor.";
            }
            self::log("uploadMedia: $errMsg");
            Router::json(['error' => $errMsg], 400);
            return;
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExtensions)) {
            self::log("uploadMedia: Extensión de archivo no permitida: '$ext'");
            Router::json(['error' => 'Extensión de archivo multimedia no permitida.'], 400);
            return;
        }

        $uploadsDir = dirname(Database::getDbPath()) . '/uploads';
        if (!is_dir($uploadsDir)) {
            if (!@mkdir($uploadsDir, 0755, true)) {
                self::log("uploadMedia: Error al crear el directorio de subidas: '$uploadsDir'");
                Router::json(['error' => 'No se pudo crear el directorio de subidas.'], 500);
                return;
            }
        }

        $mediaId = time() . bin2hex(random_bytes(4));
        $filename = 'media_' . $mediaId . '.' . $ext;
        $targetFile = $uploadsDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            self::log("uploadMedia: No se pudo mover el archivo temporal '{$file['tmp_name']}' a '$targetFile'. Revisa permisos de escritura.");
            Router::json(['error' => 'No se pudo guardar el archivo multimedia en el disco.'], 500);
            return;
        }

        self::log("uploadMedia: Archivo '$filename' ({$file['size']} bytes) subido con éxito por el usuario '{$account['username']}'");

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $fileUrl = "$proto://$domain/data/uploads/$filename";

        $width = 600;
        $height = 400;
        $aspect = 1.5;
        if ($size = @getimagesize($targetFile)) {
            $width = $size[0];
            $height = $size[1];
            $aspect = $height > 0 ? $width / $height : 1.0;
        }

        $attachment = [
            'id' => $mediaId,
            'type' => 'image',
            'url' => $fileUrl,
            'preview_url' => $fileUrl,
            'remote_url' => null,
            'text_url' => $fileUrl,
            'meta' => [
                'focus' => ['x' => 0.0, 'y' => 0.0],
                'original' => [
                    'width' => $width,
                    'height' => $height,
                    'size' => "{$width}x{$height}",
                    'aspect' => $aspect
                ]
            ],
            'description' => $_POST['description'] ?? null,
            'blurhash' => 'L6PZfHnd.Txu_N%M_Mx]URt7bIWA'
        ];

        Router::json($attachment);
    }

    public static function votePoll(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $pollId = (int)$params['id'];
        $body = Router::getRequestBody();
        $choices = $body['choices'] ?? [];
        if (empty($choices)) {
            Router::json(['error' => 'Debes seleccionar al menos una opción.'], 400);
            return;
        }

        $choiceIndex = (int)$choices[0];

        $db = Database::connect();
        $stmtPoll = $db->prepare("SELECT * FROM polls WHERE id = ? LIMIT 1");
        $stmtPoll->execute([$pollId]);
        $poll = $stmtPoll->fetch();
        if (!$poll) {
            Router::json(['error' => 'Encuesta no encontrada'], 404);
            return;
        }

        if (strtotime($poll['expires_at']) <= time()) {
            Router::json(['error' => 'La encuesta ya ha expirado.'], 400);
            return;
        }

        $stmtCheck = $db->prepare("SELECT 1 FROM poll_votes WHERE poll_id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$pollId, $account['id']]);
        if ($stmtCheck->fetch()) {
            Router::json(['error' => 'Ya has votado en esta encuesta.'], 400);
            return;
        }

        try {
            $stmtVote = $db->prepare("
                INSERT INTO poll_votes (poll_id, account_id, choice_index)
                VALUES (?, ?, ?)
            ");
            $stmtVote->execute([$pollId, $account['id'], $choiceIndex]);
        } catch (Exception $e) {
            Router::json(['error' => 'Error al registrar el voto.'], 500);
            return;
        }

        self::getPoll(['id' => $pollId]);
    }

    public static function getPoll(array $params): void {
        $pollId = (int)$params['id'];
        $db = Database::connect();

        $stmtPoll = $db->prepare("SELECT * FROM polls WHERE id = ? LIMIT 1");
        $stmtPoll->execute([$pollId]);
        $pollRow = $stmtPoll->fetch();
        if (!$pollRow) {
            Router::json(['error' => 'Encuesta no encontrada'], 404);
            return;
        }

        $options = json_decode($pollRow['options_json'], true) ?: [];
        
        $votesCount = [];
        foreach ($options as $idx => $optName) {
            $votesCount[$idx] = 0;
        }

        $stmtVotes = $db->prepare("SELECT choice_index, COUNT(*) as qty FROM poll_votes WHERE poll_id = ? GROUP BY choice_index");
        $stmtVotes->execute([$pollId]);
        $votesRows = $stmtVotes->fetchAll();
        $totalVotes = 0;
        foreach ($votesRows as $vr) {
            $cIdx = (int)$vr['choice_index'];
            $qty = (int)$vr['qty'];
            if (isset($votesCount[$cIdx])) {
                $votesCount[$cIdx] = $qty;
            }
            $totalVotes += $qty;
        }

        $account = self::getAuthenticatedAccount();
        $currentAccountId = $account ? (int)$account['id'] : null;
        $voted = false;
        $ownVote = null;
        if ($currentAccountId !== null) {
            $stmtVoted = $db->prepare("SELECT choice_index FROM poll_votes WHERE poll_id = ? AND account_id = ? LIMIT 1");
            $stmtVoted->execute([$pollId, $currentAccountId]);
            $voteVal = $stmtVoted->fetch();
            if ($voteVal !== false) {
                $voted = true;
                $ownVote = (int)$voteVal['choice_index'];
            }
        }

        $formattedOptions = [];
        foreach ($options as $idx => $title) {
            $formattedOptions[] = [
                'title' => $title,
                'votes_count' => $votesCount[$idx]
            ];
        }

        $expired = (strtotime($pollRow['expires_at']) <= time());

        Router::json([
            'id' => (string)$pollRow['id'],
            'expires_at' => date('c', strtotime($pollRow['expires_at'])),
            'expired' => $expired,
            'multiple' => false,
            'votes_count' => $totalVotes,
            'options' => $formattedOptions,
            'voted' => $voted,
            'own_vote' => $ownVote,
            'voters_count' => $totalVotes
        ]);
    }

    private static function log(string $msg): void {
        $logFile = dirname(Database::getDbPath()) . '/activitypub.log';
        $time = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
    }

    private static function outputCsv(string $filename, array $header, array $rows): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, $header);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    public static function getFollowers(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("SELECT a.* FROM accounts a JOIN follows f ON a.id = f.account_id WHERE f.target_account_id = ? AND f.status = 'accepted'");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = self::formatAccount($row);
        }
        Router::json($accounts);
    }

    public static function getFollowing(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("SELECT a.* FROM accounts a JOIN follows f ON a.id = f.target_account_id WHERE f.account_id = ? AND f.status = 'accepted'");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = self::formatAccount($row);
        }
        Router::json($accounts);
    }

    public static function exportPosts(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments, s.language,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE s.account_id = ? ORDER BY s.id DESC
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();
        $posts = [];
        foreach ($rows as $row) {
            $posts[] = self::formatStatus($row, (int)$account['id']);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="posts.json"');
        echo json_encode($posts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function exportFollows(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT a.username, a.domain FROM follows f
            JOIN accounts a ON f.target_account_id = a.id
            WHERE f.account_id = ? AND f.status = 'accepted'
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();
        $csvRows = [];
        foreach ($rows as $row) {
            $addr = $row['domain'] ? $row['username'] . '@' . $row['domain'] : $row['username'];
            $csvRows[] = [$addr, 'true'];
        }
        self::outputCsv('following.csv', ['Account address', 'Show boosts'], $csvRows);
    }

    public static function exportFollowers(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT a.username, a.domain FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ? AND f.status = 'accepted'
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();
        $csvRows = [];
        foreach ($rows as $row) {
            $addr = $row['domain'] ? $row['username'] . '@' . $row['domain'] : $row['username'];
            $csvRows[] = [$addr];
        }
        self::outputCsv('followers.csv', ['Account address'], $csvRows);
    }

    public static function exportMutes(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        self::outputCsv('mutes.csv', ['Account address', 'Hide notifications'], []);
    }

    public static function exportBlocks(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("SELECT target FROM moderation_blocks WHERE type = 'account' ORDER BY id DESC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $csvRows = [];
        foreach ($rows as $target) {
            $csvRows[] = [$target, 'true'];
        }
        self::outputCsv('blocks.csv', ['Account address', 'Hide notifications'], $csvRows);
    }

    public static function exportDomainBlocks(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("SELECT target FROM moderation_blocks WHERE type = 'domain' ORDER BY id DESC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $csvRows = [];
        foreach ($rows as $target) {
            $csvRows[] = [$target];
        }
        self::outputCsv('domain_blocks.csv', ['Domain'], $csvRows);
    }

    public static function exportBookmarks(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT s.uri FROM bookmarks b
            JOIN statuses s ON b.status_id = s.id
            WHERE b.account_id = ?
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $csvRows = [];
        foreach ($rows as $uri) {
            $csvRows[] = [$uri];
        }
        self::outputCsv('bookmarks.csv', ['Status URI'], $csvRows);
    }

    public static function exportFilters(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("SELECT type, target FROM moderation_blocks WHERE type IN ('word', 'hashtag', 'language') ORDER BY id DESC");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $filters = [];
        foreach ($rows as $row) {
            $filters[] = [
                'type' => $row['type'],
                'target' => $row['target']
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="filters.json"');
        echo json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function handleImport(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $type = $_POST['type'] ?? '';
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Router::json(['error' => 'Archivo no recibido o corrupto.'], 400);
            return;
        }

        $filePath = $_FILES['file']['tmp_name'];
        $content = file_get_contents($filePath);
        $db = Database::connect();

        $importedCount = 0;
        $errorCount = 0;

        if ($type === 'filters') {
            $data = json_decode($content, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    $filterType = $item['type'] ?? 'word';
                    $target = trim($item['target'] ?? $item['phrase'] ?? '');
                    if (!empty($target)) {
                        $stmt = $db->prepare("INSERT OR IGNORE INTO moderation_blocks (type, target) VALUES (?, ?)");
                        $stmt->execute([$filterType, $target]);
                        $importedCount++;
                    }
                }
            } else {
                Router::json(['error' => 'JSON de filtros inválido.'], 400);
                return;
            }
        } elseif ($type === 'domain_blocks') {
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    if (strtolower($row[0] ?? '') === 'domain') continue;
                }
                $domain = trim($row[0]);
                if (!empty($domain)) {
                    $stmt = $db->prepare("INSERT OR IGNORE INTO moderation_blocks (type, target) VALUES ('domain', ?)");
                    $stmt->execute([$domain]);
                    $importedCount++;
                }
            }
        } elseif ($type === 'blocks') {
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    if (strtolower($row[0] ?? '') === 'account address') continue;
                }
                $address = trim($row[0]);
                if (!empty($address)) {
                    $stmt = $db->prepare("INSERT OR IGNORE INTO moderation_blocks (type, target) VALUES ('account', ?)");
                    $stmt->execute([$address]);
                    $importedCount++;
                }
            }
        } elseif ($type === 'bookmarks') {
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    if (strtolower($row[0] ?? '') === 'status uri') continue;
                }
                $statusUri = trim($row[0]);
                if (!empty($statusUri)) {
                    $stmtStatus = $db->prepare("SELECT id FROM statuses WHERE uri = ? LIMIT 1");
                    $stmtStatus->execute([$statusUri]);
                    $statusId = $stmtStatus->fetchColumn();
                    if ($statusId) {
                        $stmtIns = $db->prepare("INSERT OR IGNORE INTO bookmarks (account_id, status_id) VALUES (?, ?)");
                        $stmtIns->execute([$account['id'], $statusId]);
                        $importedCount++;
                    } else {
                        $errorCount++;
                    }
                }
            }
        } elseif ($type === 'follows') {
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    if (strtolower($row[0] ?? '') === 'account address') continue;
                }
                $address = trim($row[0]);
                if (!empty($address)) {
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

                    if ($resolvedAcc) {
                        $targetId = $resolvedAcc['id'];
                        $isRemote = !empty($resolvedAcc['domain']);
                        $status = $isRemote ? 'pending' : (($resolvedAcc['locked'] ?? 0) ? 'pending' : 'accepted');
                        
                        $stmtCheck = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                        $stmtCheck->execute([$account['id'], $targetId]);
                        if (!$stmtCheck->fetchColumn()) {
                            $stmtIns = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, ?)");
                            $stmtIns->execute([$account['id'], $targetId, $status]);
                            
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
                            $importedCount++;
                        }
                    } else {
                        $errorCount++;
                    }
                }
            }
            if ($importedCount > 0) {
                $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
                \KutSocial\Queue::triggerAsync($domainName);
            }
        }

        Router::json([
            'success' => true,
            'imported' => $importedCount,
            'errors' => $errorCount
        ]);
    }
}
