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

        // Obtener cuenta del administrador/propietario para contact_account
        $stmtOwner = $db->prepare("SELECT * FROM accounts WHERE (domain IS NULL OR domain = '') ORDER BY id ASC LIMIT 1");
        $stmtOwner->execute();
        $ownerAccount = $stmtOwner->fetch();
        $contactAccount = $ownerAccount ? self::formatAccount($ownerAccount) : null;

        $vapidKeys = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublicPoint = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapidKeys['vapid_public_key']);
        $vapidPublicKeyBase64 = \KutSocial\WebPushHelper::base64url_encode($vapidPublicPoint);

        $response = [
            'uri' => $domain,
            'title' => $title,
            'short_description' => 'Servidor ligero compatible con Mastodon',
            'description' => 'KutSocial: Alternativa ultraligera construida sobre PHP y SQLite.',
            'email' => 'admin@' . $domain,
            'version' => '4.6.0-kutsocial',
            'kutsocial_version' => \KutSocial\Database::getVersion(),
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
                'urls' => [
                    'streaming' => 'wss://' . $domain . '/api/v1/streaming'
                ],
                'vapid' => [
                    'public_key' => $vapidPublicKeyBase64
                ],
                'accounts' => [
                    'max_featured_tags' => 10
                ],
                'statuses' => [
                    'max_characters' => $maxChars,
                    'max_media_attachments' => 4,
                    'characters_reserved_per_url' => 23
                ],
                'media_attachments' => [
                    'supported_mime_types' => [
                        'image/jpeg', 'image/png', 'image/gif', 'image/heic', 'image/heif',
                        'image/webp', 'image/avif',
                        'video/webm', 'video/mp4', 'video/quicktime', 'video/ogg',
                        'audio/wave', 'audio/wav', 'audio/x-wav', 'audio/x-patchwork-wav',
                        'audio/aac', 'audio/m4a', 'audio/x-m4a', 'audio/mp4', 'audio/mp3',
                        'audio/mpeg', 'audio/ogg', 'audio/vorbis', 'audio/vnd.wav',
                        'audio/flac', 'audio/opus'
                    ],
                    'image_size_limit' => 16777216,
                    'image_matrix_limit' => 33177600,
                    'video_size_limit' => 103809024,
                    'video_frame_rate_limit' => 120,
                    'video_matrix_limit' => 8294400
                ],
                'polls' => [
                    'max_options' => 4,
                    'max_characters_per_option' => 50,
                    'min_expiration' => 300,
                    'max_expiration' => 2629746
                ]
            ],
            'contact_account' => $contactAccount,
            'rules' => []
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

        // Obtener cuenta del administrador/propietario para contact.account
        $stmtOwner = $db->prepare("SELECT * FROM accounts WHERE (domain IS NULL OR domain = '') ORDER BY id ASC LIMIT 1");
        $stmtOwner->execute();
        $ownerAccount = $stmtOwner->fetch();
        $contactAccount = $ownerAccount ? self::formatAccount($ownerAccount) : null;

        $vapidKeys = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublicPoint = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapidKeys['vapid_public_key']);
        $vapidPublicKeyBase64 = \KutSocial\WebPushHelper::base64url_encode($vapidPublicPoint);

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
                'url' => "$proto://$domain/assets/thumbnail.png",
                'blurhash' => null,
                'versions' => (object)[]
            ],
            'languages' => ['es'],
            'configuration' => [
                'urls' => [
                    'streaming' => 'wss://' . $domain . '/api/v1/streaming'
                ],
                'vapid' => [
                    'public_key' => $vapidPublicKeyBase64
                ],
                'accounts' => [
                    'max_featured_tags' => 10,
                    'max_pinned_statuses' => 5
                ],
                'statuses' => [
                    'max_characters' => $maxChars,
                    'max_media_attachments' => 4,
                    'characters_reserved_per_url' => 23
                ],
                'media_attachments' => [
                    'supported_mime_types' => [
                        'image/jpeg', 'image/png', 'image/gif', 'image/heic', 'image/heif',
                        'image/webp', 'image/avif',
                        'video/webm', 'video/mp4', 'video/quicktime', 'video/ogg',
                        'audio/wave', 'audio/wav', 'audio/x-wav', 'audio/x-patchwork-wav',
                        'audio/aac', 'audio/m4a', 'audio/x-m4a', 'audio/mp4', 'audio/mp3',
                        'audio/mpeg', 'audio/ogg', 'audio/vorbis', 'audio/vnd.wav',
                        'audio/flac', 'audio/opus'
                    ],
                    'image_size_limit' => 16777216,
                    'image_matrix_limit' => 33177600,
                    'video_size_limit' => 103809024,
                    'video_frame_rate_limit' => 120,
                    'video_matrix_limit' => 8294400
                ],
                'polls' => [
                    'max_options' => 4,
                    'max_characters_per_option' => 50,
                    'min_expiration' => 300,
                    'max_expiration' => 2629746
                ],
                'translation' => [
                    'enabled' => false
                ]
            ],
            'registrations' => [
                'enabled' => false,
                'approval_required' => false,
                'message' => null
            ],
            'contact' => [
                'email' => 'admin@' . $domain,
                'account' => $contactAccount
            ],
            'rules' => []
        ];

        Router::json($response);
    }

    /**
     * Endpoint GET /.well-known/nodeinfo
     */
    public static function wellKnownNodeinfo(): void {
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        Router::json([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.gene.ar/ns/schema/2.0',
                    'href' => "$proto://$domain/nodeinfo/2.0"
                ]
            ]
        ]);
    }

    /**
     * Endpoint GET /nodeinfo/2.0
     */
    public static function nodeinfo20(): void {
        $db = Database::connect();
        
        $userCount = self::getTableRowCount('accounts', "domain IS NULL");
        $statusCount = self::getTableRowCount('statuses');

        Router::json([
            'version' => '2.0',
            'software' => [
                'name' => 'mastodon',
                'version' => '4.6.0-kutsocial'
            ],
            'protocols' => [
                'activitypub'
            ],
            'services' => [
                'inbound' => [],
                'outbound' => []
            ],
            'openRegistrations' => false,
            'usage' => [
                'users' => [
                    'total' => $userCount,
                    'activeHalfyear' => $userCount,
                    'activeMonth' => $userCount
                ],
                'localPosts' => $statusCount
            ],
            'metadata' => (object)[]
        ]);
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

        $vapidKeys = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublicPoint = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapidKeys['vapid_public_key']);
        $vapidPublicKeyBase64 = \KutSocial\WebPushHelper::base64url_encode($vapidPublicPoint);

        Router::json([
            'id' => '1',
            'name' => $clientName,
            'website' => null,
            'redirect_uri' => $redirectUris,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'vapid_key' => $vapidPublicKeyBase64
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
        $clientId = htmlspecialchars($_GET['client_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $redirectUri = htmlspecialchars($_GET['redirect_uri'] ?? '', ENT_QUOTES, 'UTF-8');
        $responseType = htmlspecialchars($_GET['response_type'] ?? '', ENT_QUOTES, 'UTF-8');
        $scope = htmlspecialchars($_GET['scope'] ?? '', ENT_QUOTES, 'UTF-8');

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

    private static function showOtpAuthorize(array $account, array $params, ?string $error = null): void {
        $clientId = htmlspecialchars($params['client_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $redirectUri = htmlspecialchars($params['redirect_uri'] ?? '', ENT_QUOTES, 'UTF-8');
        $responseType = htmlspecialchars($params['response_type'] ?? '', ENT_QUOTES, 'UTF-8');
        $scope = htmlspecialchars($params['scope'] ?? '', ENT_QUOTES, 'UTF-8');
        $username = htmlspecialchars($params['username'] ?? '', ENT_QUOTES, 'UTF-8');
        $password = htmlspecialchars($params['password'] ?? '', ENT_QUOTES, 'UTF-8');

        $errorHtml = '';
        if ($error) {
            $errorHtml = '<div class="error">' . htmlspecialchars($error) . '</div>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verificación 2FA - KutSocial</title>
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
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(15, 23, 42, 0.6);
            color: white;
            box-sizing: border-box;
            text-align: center;
            font-size: 18px;
            letter-spacing: 5px;
            transition: all 0.3s;
        }
        input[type="text"]:focus {
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
        <h2>Verificación de Dos Factores</h2>
        <p>Introduce el código de verificación de 6 dígitos generado por tu aplicación de autenticación para continuar.</p>
        
        {$errorHtml}
        
        <form method="POST" action="/oauth/authorize">
            <input type="hidden" name="client_id" value="{$clientId}">
            <input type="hidden" name="redirect_uri" value="{$redirectUri}">
            <input type="hidden" name="response_type" value="{$responseType}">
            <input type="hidden" name="scope" value="{$scope}">
            <input type="hidden" name="username" value="{$username}">
            <input type="hidden" name="password" value="{$password}">
            
            <div class="form-group">
                <label for="otp_code">Código 2FA</label>
                <input type="text" id="otp_code" name="otp_code" required placeholder="123456" pattern="\d{6}" maxlength="6" autofocus autocomplete="one-time-code">
            </div>
            
            <button type="submit" class="btn">Verificar y Autorizar</button>
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

        \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: username=$username, clientId=$clientId, redirectUri=$redirectUri");

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: password verify failed for username=$username");
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
            return;
        }

        // Verificar 2FA si está activo
        if (!empty($account['otp_secret'])) {
            $otpCode = trim($_POST['otp_code'] ?? '');
            \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: 2FA is active, otpCode received=" . $otpCode);
            if (empty($otpCode)) {
                \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: otpCode is empty, showing OTP page");
                self::showOtpAuthorize($account, $_POST);
                return;
            }
            if (!\KutSocial\TotpHelper::verifyCode($account['otp_secret'], $otpCode)) {
                \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: OTP verify failed");
                self::showOtpAuthorize($account, $_POST, "Código 2FA incorrecto o expirado");
                return;
            }
            \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: OTP verify success");
        }

        $code = self::generateCodeForUser((int)$account['id']);
        \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: auth code generated=" . $code);

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
            \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: redirecting browser to " . $redirectUrl);
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

        \KutSocial\Controllers\ActivityPubController::log("postToken: request body=" . json_encode($body));

        if ($grantType === 'authorization_code') {
            $code = $body['code'] ?? '';
            $userId = self::verifyCodeAndGetUserId($code);
            \KutSocial\Controllers\ActivityPubController::log("postToken: code received=" . $code . ", resolved userId=" . ($userId ?: 'null'));
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

            // Verificar 2FA si está habilitado
            if (!empty($account['otp_secret'])) {
                $otp = $_SERVER['HTTP_X_MASTODON_OTP'] ?? $body['otp'] ?? $body['otp_code'] ?? '';
                if (empty($otp)) {
                    Router::json(['error' => 'two_factor_required', 'error_description' => 'Autenticación de dos factores requerida'], 400);
                }
                if (!\KutSocial\TotpHelper::verifyCode($account['otp_secret'], $otp)) {
                    Router::json(['error' => 'invalid_grant', 'error_description' => 'Código 2FA incorrecto o expirado'], 400);
                }
            }
        } else {
            Router::json(['error' => 'unsupported_grant_type'], 400);
        }

        // Generar token firmado sin guardar estado en DB
        $token = self::generateTokenForUser((int)$account['id']);

        $requestedScope = $body['scope'] ?? 'read write follow push';
        Router::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'scope' => $requestedScope,
            'created_at' => time()
        ]);
    }

    /**
     * Endpoint GET /api/v1/accounts/verify_credentials
     */
    public static function verifyCredentials(): void {
        \KutSocial\Controllers\ActivityPubController::log("verifyCredentials: request received. Headers=" . json_encode(Router::getAllHeaders()));
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            \KutSocial\Controllers\ActivityPubController::log("verifyCredentials: Unauthorized");
            Router::json(['error' => 'Unauthorized'], 401);
        }

        \KutSocial\Controllers\ActivityPubController::log("verifyCredentials: success for username=" . $account['username']);
        
        $formatted = self::formatCredentialAccount($account);
        Router::json($formatted);
    }

    /**
     * Endpoint GET /api/v1/preferences
     */
    public static function getPreferences(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Router::json([
            'posting:default:visibility' => 'public',
            'posting:default:sensitive' => false,
            'posting:default:language' => 'es',
            'reading:expand:media' => 'default',
            'reading:expand:spoilers' => false
        ]);
    }

    /**
     * Endpoint GET /api/v1/collections (Novedad Mastodon 4.6 - Listas Públicas Federadas)
     */
    public static function getCollections(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM collections WHERE account_id = ? ORDER BY id DESC");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (string)$row['id'],
                'title' => $row['title'],
                'name' => $row['title'],
                'description' => $row['description']
            ];
        }

        Router::json([
            'items' => $items
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
            return;
        }

        // Obtener tipos soportados por el cliente
        $supportedTypes = $_GET['supported_types'] ?? null;
        if (is_string($supportedTypes)) {
            $supportedTypes = [$supportedTypes];
        }

        $db = Database::connect();
        $currUserId = (int)$account['id'];
        $notifications = [];

        // 1. Obtener notificaciones de Seguimientos (Follows)
        $stmtFollows = $db->prepare("
            SELECT f.id, f.created_at, 
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields
            FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ?
              AND ('follow_' || f.id) NOT IN (
                  SELECT notification_id FROM dismissed_notifications WHERE account_id = ?
              )
            ORDER BY f.id DESC LIMIT 40
        ");
        $stmtFollows->execute([$currUserId, $currUserId]);
        $followRows = $stmtFollows->fetchAll();
        foreach ($followRows as $row) {
            $notifications[] = [
                'id' => 'follow_' . $row['id'],
                'type' => 'follow',
                'created_at' => date('c', strtotime($row['created_at'])),
                'account' => self::formatAccount($row)
            ];
        }

        // 2. Obtener notificaciones de Favoritos (Favourites)
        $stmtFavs = $db->prepare("
            SELECT fav.id, fav.created_at, 
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields,
                   s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments
            FROM favourites fav
            JOIN accounts a ON fav.account_id = a.id
            JOIN statuses s ON fav.status_id = s.id
            WHERE s.account_id = ?
              AND ('favourite_' || fav.id) NOT IN (
                  SELECT notification_id FROM dismissed_notifications WHERE account_id = ?
              )
            ORDER BY fav.id DESC LIMIT 40
        ");
        $stmtFavs->execute([$currUserId, $currUserId]);
        $favRows = $stmtFavs->fetchAll();
        foreach ($favRows as $row) {
            $notifications[] = [
                'id' => 'favourite_' . $row['id'],
                'type' => 'favourite',
                'created_at' => date('c', strtotime($row['created_at'])),
                'account' => self::formatAccount($row),
                'status' => self::formatStatus($row, $currUserId)
            ];
        }

        // 3. Obtener notificaciones de Menciones y Respuestas (Mentions)
        $stmtMentions = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE s.account_id != ?
              AND (
                  s.content LIKE ? 
                  OR s.content LIKE ? 
                  OR s.in_reply_to_id IN (SELECT id FROM statuses WHERE account_id = ?)
              )
              AND ('mention_' || s.id) NOT IN (
                  SELECT notification_id FROM dismissed_notifications WHERE account_id = ?
              )
            ORDER BY s.id DESC LIMIT 40
        ");
        $stmtMentions->execute([
            $currUserId, 
            '%@' . $account['username'] . '%', 
            '%/users/' . $account['username'] . '%', 
            $currUserId, 
            $currUserId
        ]);
        $mentionRows = $stmtMentions->fetchAll();
        foreach ($mentionRows as $row) {
            // Validar que sea mención real (evitar falsos positivos de substrings como @iam vs @iamdavidobrien)
            $isRealMention = false;
            
            // 1. Si es respuesta a un post del usuario
            if ($row['in_reply_to_id']) {
                $stmtCheckReply = $db->prepare("SELECT 1 FROM statuses WHERE id = ? AND account_id = ? LIMIT 1");
                $stmtCheckReply->execute([$row['in_reply_to_id'], $currUserId]);
                if ($stmtCheckReply->fetchColumn()) {
                    $isRealMention = true;
                }
            }
            
            // 2. Si contiene mención real en el texto
            if (!$isRealMention) {
                $username = $account['username'];
                $content = $row['status_content'];
                $mentionPattern = '/@' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                $urlPattern = '/\/users\/' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                if (preg_match($mentionPattern, $content) || preg_match($urlPattern, $content)) {
                    $isRealMention = true;
                }
            }

            if (!$isRealMention) {
                continue; // Falso positivo, ignorar
            }

            $notifications[] = [
                'id' => 'mention_' . $row['status_id'],
                'type' => 'mention',
                'created_at' => date('c', strtotime($row['status_created_at'])),
                'account' => self::formatAccount($row),
                'status' => self::formatStatus($row, $currUserId)
            ];
        }

        // 4. Obtener notificaciones de Re-toots (Reblogs)
        $stmtReblogs = $db->prepare("
            SELECT s.id as status_id, s.created_at as status_created_at,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields,
                   orig.id as orig_status_id, orig.uri as orig_status_uri, orig.content as orig_status_content,
                   orig.visibility as orig_status_visibility, orig.created_at as orig_status_created_at,
                   orig.in_reply_to_id as orig_in_reply_to_id, orig.sensitive as orig_sensitive,
                   orig.spoiler_text as orig_spoiler_text, orig.media_attachments as orig_media_attachments
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            JOIN statuses orig ON s.reblog_of_id = orig.id
            WHERE orig.account_id = ?
              AND s.content = ''
              AND ('reblog_' || s.id) NOT IN (
                  SELECT notification_id FROM dismissed_notifications WHERE account_id = ?
              )
            ORDER BY s.id DESC LIMIT 40
        ");
        $stmtReblogs->execute([$currUserId, $currUserId]);
        $reblogRows = $stmtReblogs->fetchAll();
        foreach ($reblogRows as $row) {
            $origRow = [
                'status_id' => $row['orig_status_id'],
                'status_uri' => $row['orig_status_uri'],
                'status_content' => $row['orig_status_content'],
                'status_visibility' => $row['orig_status_visibility'],
                'status_created_at' => $row['orig_status_created_at'],
                'in_reply_to_id' => $row['orig_in_reply_to_id'],
                'sensitive' => $row['orig_sensitive'],
                'spoiler_text' => $row['orig_spoiler_text'],
                'media_attachments' => $row['orig_media_attachments'],
                'account_id' => $currUserId,
                'username' => $account['username'],
                'domain' => null,
                'display_name' => $account['display_name'],
                'avatar' => $account['avatar'],
                'header' => $account['header'],
                'avatar_description' => $account['avatar_description'],
                'header_description' => $account['header_description'],
                'note' => $account['note'],
                'created_at' => $account['created_at'],
                'account_created_at' => $account['created_at'],
                'locked' => $account['locked'],
                'discoverable' => $account['discoverable'],
                'fields' => $account['fields']
            ];

            $notifications[] = [
                'id' => 'reblog_' . $row['status_id'],
                'type' => 'reblog',
                'created_at' => date('c', strtotime($row['status_created_at'])),
                'account' => self::formatAccount($row),
                'status' => self::formatStatus($origRow, $currUserId)
            ];
        }

        // Ordenar notificaciones por fecha descendente
        usort($notifications, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        // Limitar la respuesta
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;
        $notifications = array_slice($notifications, 0, $limit);

        $readAt = !empty($account['notifications_read_at']) ? strtotime($account['notifications_read_at']) : 0;

        // Lógica de fallback para tipos no soportados
        $filtered = [];
        foreach ($notifications as $notification) {
            if ($supportedTypes !== null && !in_array($notification['type'], $supportedTypes)) {
                $notification['type'] = 'mention'; // Fallback a mención genérica
            }
            $createdTime = strtotime($notification['created_at']);
            $notification['read'] = ($createdTime <= $readAt);
            $filtered[] = $notification;
        }

        Router::json($filtered);
    }

    /**
     * Endpoint GET /api/v1/notifications/:id
     */
    public static function getNotification(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = $params['id'] ?? '';
        if (empty($id)) {
            Router::json(['error' => 'Bad Request'], 400);
            return;
        }

        $db = Database::connect();
        $currUserId = (int)$account['id'];

        if (str_starts_with($id, 'test_push_')) {
            $notification = [
                'id' => $id,
                'type' => 'mention',
                'created_at' => date('c'),
                'account' => self::formatAccount($account),
                'status' => [
                    'id' => '1',
                    'created_at' => date('c'),
                    'in_reply_to_id' => null,
                    'in_reply_to_account_id' => null,
                    'sensitive' => false,
                    'spoiler_text' => '',
                    'visibility' => 'public',
                    'language' => 'es',
                    'uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/statuses/1',
                    'url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/statuses/1',
                    'replies_count' => 0,
                    'reblogs_count' => 0,
                    'favourites_count' => 0,
                    'content' => '<p>¡Esta es la notificación de prueba que esperabas ver en la app!</p>',
                    'account' => self::formatAccount($account),
                    'media_attachments' => [],
                    'mentions' => [],
                    'tags' => [],
                    'emojis' => [],
                    'card' => null,
                    'poll' => null
                ]
            ];
            Router::json($notification);
            return;
        } elseif (str_starts_with($id, 'follow_')) {
            $followId = (int)substr($id, 7);
            $stmt = $db->prepare("
                SELECT f.id, f.created_at, 
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable, a.fields
                FROM follows f
                JOIN accounts a ON f.account_id = a.id
                WHERE f.id = ? AND f.target_account_id = ? LIMIT 1
            ");
            $stmt->execute([$followId, $currUserId]);
            $row = $stmt->fetch();
            if ($row) {
                $notification = [
                    'id' => 'follow_' . $row['id'],
                    'type' => 'follow',
                    'created_at' => date('c', strtotime($row['created_at'])),
                    'account' => self::formatAccount($row)
                ];
                Router::json($notification);
                return;
            }
        } elseif (str_starts_with($id, 'favourite_')) {
            $favId = (int)substr($id, 10);
            $stmt = $db->prepare("
                SELECT f.id, f.created_at, f.status_id,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable, a.fields
                FROM favourites f
                JOIN accounts a ON f.account_id = a.id
                JOIN statuses s ON f.status_id = s.id
                WHERE f.id = ? AND s.account_id = ? LIMIT 1
            ");
            $stmt->execute([$favId, $currUserId]);
            $row = $stmt->fetch();
            if ($row) {
                $statusRow = self::fetchStatusRow($db, (int)$row['status_id']);
                $notification = [
                    'id' => 'favourite_' . $row['id'],
                    'type' => 'favourite',
                    'created_at' => date('c', strtotime($row['created_at'])),
                    'account' => self::formatAccount($row),
                    'status' => self::formatStatus($statusRow, $currUserId)
                ];
                Router::json($notification);
                return;
            }
        } elseif (str_starts_with($id, 'reblog_')) {
            $reblogId = (int)substr($id, 7);
            $stmt = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       s.reblog_of_id,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable, a.fields
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ? LIMIT 1
            ");
            $stmt->execute([$reblogId]);
            $row = $stmt->fetch();
            if ($row && $row['reblog_of_id']) {
                $origStatusId = (int)$row['reblog_of_id'];
                $stmtCheck = $db->prepare("SELECT account_id FROM statuses WHERE id = ? LIMIT 1");
                $stmtCheck->execute([$origStatusId]);
                $origOwnerId = $stmtCheck->fetchColumn();
                if ((int)$origOwnerId === $currUserId) {
                    $origRow = self::fetchStatusRow($db, $origStatusId);
                    $notification = [
                        'id' => 'reblog_' . $row['status_id'],
                        'type' => 'reblog',
                        'created_at' => date('c', strtotime($row['status_created_at'])),
                        'account' => self::formatAccount($row),
                        'status' => self::formatStatus($origRow, $currUserId)
                    ];
                    Router::json($notification);
                    return;
                }
            }
        } elseif (str_starts_with($id, 'mention_')) {
            $parts = explode('_', $id);
            $statusId = isset($parts[1]) ? (int)$parts[1] : 0;
            
            $stmt = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable, a.fields
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ? LIMIT 1
            ");
            $stmt->execute([$statusId]);
            $row = $stmt->fetch();
            if ($row) {
                $isRealMention = false;
                if ($row['in_reply_to_id']) {
                    $stmtCheckReply = $db->prepare("SELECT 1 FROM statuses WHERE id = ? AND account_id = ? LIMIT 1");
                    $stmtCheckReply->execute([$row['in_reply_to_id'], $currUserId]);
                    if ($stmtCheckReply->fetchColumn()) {
                        $isRealMention = true;
                    }
                }
                if (!$isRealMention) {
                    $username = $account['username'];
                    $content = $row['status_content'];
                    $mentionPattern = '/@' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                    $urlPattern = '/\/users\/' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                    if (preg_match($mentionPattern, $content) || preg_match($urlPattern, $content)) {
                        $isRealMention = true;
                    }
                }
                
                if ($isRealMention) {
                    $notification = [
                        'id' => $id,
                        'type' => 'mention',
                        'created_at' => date('c', strtotime($row['status_created_at'])),
                        'account' => self::formatAccount($row),
                        'status' => self::formatStatus($row, $currUserId)
                    ];
                    Router::json($notification);
                    return;
                }
            }
        }

        Router::json(['error' => 'Record not found'], 404);
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
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("UPDATE accounts SET notifications_read_at = datetime('now') WHERE id = ?");
        $stmt->execute([$account['id']]);

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

    public static function generateTokenForUser(int $userId): string {
        $secret = self::getSigningSecret();
        $hash = hash_hmac('sha256', $userId, $secret);
        return "token_" . $userId . "_" . $hash;
    }

    public static function getAuthenticatedAccount(): ?array {
        $token = Router::getBearerToken();
        \KutSocial\Controllers\ActivityPubController::log("getAuthenticatedAccount: token received=" . ($token ?: 'null') . " for URI=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        if (!$token) {
            return null;
        }

        $parts = explode('_', $token);
        if (count($parts) !== 3 || $parts[0] !== 'token') {
            \KutSocial\Controllers\ActivityPubController::log("getAuthenticatedAccount: token format invalid");
            return null;
        }

        $userId = (int)$parts[1];
        $hash = $parts[2];

        $secret = self::getSigningSecret();
        $expectedHash = hash_hmac('sha256', $userId, $secret);

        if (!hash_equals($expectedHash, $hash)) {
            \KutSocial\Controllers\ActivityPubController::log("getAuthenticatedAccount: token hash mismatch");
            return null;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $account = $stmt->fetch();

        \KutSocial\Controllers\ActivityPubController::log("getAuthenticatedAccount: resolved account=" . ($account ? $account['username'] : 'null'));
        return $account ?: null;
    }

    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $resolve = (($_GET['resolve'] ?? '') === 'true' || ($_GET['resolve'] ?? '') === '1');
        $type = $_GET['type'] ?? null;
        
        $db = Database::connect();

        $account = null;
        $currUserId = null;
        try {
            $account = self::getAuthenticatedAccount();
            if ($account) {
                $currUserId = (int)$account['id'];
            }
        } catch (\Exception $e) {
            // No autenticado
        }
        
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
                    if ($currUserId !== null) {
                        $sql = "
                            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                                   s.visibility as status_visibility, s.created_at as status_created_at,
                                   s.media_attachments as status_media, s.sensitive as status_sensitive,
                                   s.spoiler_text as status_spoiler,
                                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                            FROM statuses s
                            JOIN accounts a ON s.account_id = a.id
                            WHERE s.id IN (SELECT rowid FROM statuses_fts WHERE statuses_fts MATCH ?)
                              AND (
                                  s.visibility IN ('public', 'unlisted')
                                  OR (s.visibility = 'private' AND (s.account_id = ? OR s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND status = 'accepted')))
                                  OR (s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))
                              )
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $queryParams = [$cleanQ . '*', $currUserId, $currUserId, $currUserId, '%@' . $account['username'] . '%', '%/users/' . $account['username'] . '%'];
                    } else {
                        $sql = "
                            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                                   s.visibility as status_visibility, s.created_at as status_created_at,
                                   s.media_attachments as status_media, s.sensitive as status_sensitive,
                                   s.spoiler_text as status_spoiler,
                                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                            FROM statuses s
                            JOIN accounts a ON s.account_id = a.id
                            WHERE s.id IN (SELECT rowid FROM statuses_fts WHERE statuses_fts MATCH ?)
                              AND s.visibility IN ('public', 'unlisted')
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $queryParams = [$cleanQ . '*'];
                    }
                    $stmt = $db->prepare($sql);
                    $stmt->execute($queryParams);
                    $localStatuses = $stmt->fetchAll();
                } else {
                    $localStatuses = [];
                }
                
                if (empty($localStatuses)) {
                    if ($currUserId !== null) {
                        $sql = "
                            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                                   s.visibility as status_visibility, s.created_at as status_created_at,
                                   s.media_attachments as status_media, s.sensitive as status_sensitive,
                                   s.spoiler_text as status_spoiler,
                                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                            FROM statuses s
                            JOIN accounts a ON s.account_id = a.id
                            WHERE s.content LIKE ?
                              AND (
                                  s.visibility IN ('public', 'unlisted')
                                  OR (s.visibility = 'private' AND (s.account_id = ? OR s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND status = 'accepted')))
                                  OR (s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))
                              )
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $queryParams = ['%' . $q . '%', $currUserId, $currUserId, $currUserId, '%@' . $account['username'] . '%', '%/users/' . $account['username'] . '%'];
                    } else {
                        $sql = "
                            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                                   s.visibility as status_visibility, s.created_at as status_created_at,
                                   s.media_attachments as status_media, s.sensitive as status_sensitive,
                                   s.spoiler_text as status_spoiler,
                                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                            FROM statuses s
                            JOIN accounts a ON s.account_id = a.id
                            WHERE s.content LIKE ?
                              AND s.visibility IN ('public', 'unlisted')
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $queryParams = ['%' . $q . '%'];
                    }
                    $stmt = $db->prepare($sql);
                    $stmt->execute($queryParams);
                    $localStatuses = $stmt->fetchAll();
                }
                
                // Filtrar según bloqueos de moderación
                $localStatuses = self::filterStatuses($localStatuses);

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

    public static function formatAccount(array $account, bool $plainTextBio = false): array {
        // En consultas de notificaciones a veces 'id' representa la relación y 'account_id' la cuenta
        $accountId = $account['account_id'] ?? $account['id'] ?? null;
        
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
        $isRemote = !empty($account['domain']);
        if ($plainTextBio) {
            $bio = $account['note'] ?: '';
        } else {
            $bio = self::formatAccountNote($account['note'] ?: '', $isRemote);
        }

        // Procesar metadatos (campos adicionales)
        $fields = [];
        if (!empty($account['fields'])) {
            $fieldsArr = json_decode($account['fields'], true);
            if (is_array($fieldsArr)) {
                foreach ($fieldsArr as $f) {
                    $name = $f['name'] ?? '';
                    $val = $f['value'] ?? '';
                    
                    // Si el valor contiene un enlace HTML o es una URL pura, formatearlo de forma limpia sin clases
                    if (str_contains($val, '<a ')) {
                        $val = preg_replace_callback('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', function($matches) {
                            $href = $matches[1];
                            $innerHtml = $matches[2];
                            $cleanText = strip_tags($innerHtml);
                            if (empty($cleanText)) {
                                $cleanText = preg_replace('/^https?:\/\/(www\.)?/', '', $href);
                            }
                            return sprintf('<a href="%s" rel="me nofollow noopener noreferrer" target="_blank">%s</a>', htmlspecialchars($href), htmlspecialchars($cleanText));
                        }, $val);
                    } elseif (filter_var($val, FILTER_VALIDATE_URL)) {
                        $label = preg_replace('/^https?:\/\/(www\.)?/', '', $val);
                        $val = sprintf('<a href="%s" rel="me nofollow noopener noreferrer" target="_blank">%s</a>', htmlspecialchars($val), htmlspecialchars($label));
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
        
        if (!empty($account['domain'])) {
            $followersCount = (int)($account['followers_count'] ?? 0);
            $followingCount = (int)($account['following_count'] ?? 0);
            $statusesCount = (int)($account['statuses_count'] ?? 0);
        } else {
            // Contar seguidores recibidos
            $stmtFollowers = $db->prepare("SELECT COUNT(*) FROM follows WHERE target_account_id = ? AND status = 'accepted'");
            $stmtFollowers->execute([$accountId]);
            $followersCount = (int)$stmtFollowers->fetchColumn();

            // Contar seguidos realizados
            $stmtFollowing = $db->prepare("SELECT COUNT(*) FROM follows WHERE account_id = ? AND status = 'accepted'");
            $stmtFollowing->execute([$accountId]);
            $followingCount = (int)$stmtFollowing->fetchColumn();

            // Contar toots creados
            $stmtStatuses = $db->prepare("SELECT COUNT(*) FROM statuses WHERE account_id = ?");
            $stmtStatuses->execute([$accountId]);
            $statusesCount = (int)$stmtStatuses->fetchColumn();
        }

        return [
            'id' => (string)$accountId,
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
            'created_at' => date('c', strtotime($account['account_created_at'] ?? $account['created_at'] ?? 'now')),
            'note' => $bio,
            'url' => (!empty($account['url']) && !empty($account['domain'])) ? $account['url'] : ($account['domain'] ? "https://{$account['domain']}/@{$account['username']}" : "$proto://$domain/@" . $account['username']),
            'avatar' => $avatarUrl,
            'avatar_static' => $avatarUrl,
            'header' => $headerUrl,
            'header_static' => $headerUrl,
            'followers_count' => $followersCount,
            'following_count' => $followingCount,
            'statuses_count' => $statusesCount,
            'last_status_at' => null,
            'emojis' => self::extractAccountEmojis($account),
            'fields' => $fields,
            'role' => [
                'id' => '1',
                'name' => $account['role'] ?? 'user',
                'color' => '',
                'permissions' => '0',
                'highlighted' => false
            ],
            'avatar_description' => $account['avatar_description'] ?? '',
            'header_description' => $account['header_description'] ?? ''
        ];
    }

    /**
     * Formatea una cuenta con los bloques CredentialAccount requeridos por verify_credentials y update_credentials.
     */
    public static function formatCredentialAccount(array $account): array {
        $formatted = self::formatAccount($account);
        
        $db = Database::connect();
        $stmtPending = $db->prepare("SELECT COUNT(*) FROM follows WHERE target_account_id = ? AND status = 'requested'");
        $stmtPending->execute([$account['id']]);
        $followRequestsCount = (int)$stmtPending->fetchColumn();
        
        $rawFields = [];
        if (!empty($account['fields'])) {
            $fieldsArr = json_decode($account['fields'], true);
            if (is_array($fieldsArr)) {
                foreach ($fieldsArr as $f) {
                    $rawFields[] = [
                        'name' => $f['name'] ?? '',
                        'value' => $f['value'] ?? '',
                        'verified_at' => $f['verified_at'] ?? null
                    ];
                }
            }
        }

        $formatted['source'] = [
            'privacy' => 'public',
            'sensitive' => false,
            'language' => 'es',
            'note' => $account['note'] ?: '',
            'fields' => $rawFields,
            'follow_requests_count' => $followRequestsCount
        ];

        return $formatted;
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

        \KutSocial\Controllers\ActivityPubController::log("updateCredentials: Method=" . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . ", POST=" . json_encode($_POST) . ", FILES=" . json_encode(array_keys($_FILES)));

        $body = Router::getRequestBody();

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
        $displayName = $_POST['display_name'] ?? $body['display_name'] ?? null;
        if ($displayName !== null) {
            $fieldsToUpdate[] = "display_name = ?";
            $params[] = trim($displayName);
        }

        $note = $_POST['note'] ?? $body['note'] ?? null;
        if ($note !== null) {
            $fieldsToUpdate[] = "note = ?";
            $params[] = strip_tags(trim($note));
        }

        // 3. Manejo de Campos de Metadatos Personalizados (Estilo Mastodon)
        $fieldsAttributes = $_POST['fields_attributes'] ?? $body['fields_attributes'] ?? null;
        if (is_array($fieldsAttributes)) {
            $metaFields = [];
            foreach ($fieldsAttributes as $f) {
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

        Router::json(self::formatCredentialAccount($account));
    }

    /**
     * Verifica de fondo si un enlace remoto enlaza de vuelta a este perfil con rel="me".
     */
    private static function verifyLinkRelation(string $url, string $username): bool {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_MAXFILESIZE => 1048576, // 1 MB
            CURLOPT_USERAGENT => 'KutSocial-LinkVerifier/1.0',
            CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
            CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        
        \KutSocial\Controllers\ActivityPubController::log("verifyLinkRelation: Crawling URL=$url, HTTP Status=$httpCode, Curl Error=" . ($curlErr ?: 'None') . ", HTML Length=" . strlen($html ?: ''));

        if ($httpCode !== 200 || empty($html)) {
            return false;
        }

        // Encontrar todas las etiquetas <a> y <link>
        if (!preg_match_all('/<(a|link)\s+([^>]+)>/i', $html, $matches, PREG_SET_ORDER)) {
            \KutSocial\Controllers\ActivityPubController::log("verifyLinkRelation: No 'a' or 'link' tags found on $url");
            return false;
        }

        foreach ($matches as $match) {
            $attrs = $match[2];
            
            if (!preg_match('/href=["\']([^"\']+)["\']/i', $attrs, $hrefMatch)) {
                continue;
            }
            $href = html_entity_decode($hrefMatch[1]);
            
            $hasRelMe = false;
            if (preg_match('/rel=["\']([^"\']+)["\']/i', $attrs, $relMatch)) {
                if (preg_match('/\bme\b/i', $relMatch[1])) {
                    $hasRelMe = true;
                }
            } elseif (preg_match('/rel=me/i', $attrs)) {
                $hasRelMe = true;
            }
            
            if (!$hasRelMe) {
                continue;
            }
            
            $cleanHref = rtrim(strtolower($href), '/');
            
            $target1_http = rtrim(strtolower("http://$domain/users/$username"), '/');
            $target1_https = rtrim(strtolower("https://$domain/users/$username"), '/');
            $target2_http = rtrim(strtolower("http://$domain/@$username"), '/');
            $target2_https = rtrim(strtolower("https://$domain/@$username"), '/');
            $target3_http = rtrim(strtolower("http://$domain"), '/');
            $target3_https = rtrim(strtolower("https://$domain"), '/');
            
            \KutSocial\Controllers\ActivityPubController::log("verifyLinkRelation: Found rel=\"me\" link: $cleanHref. Comparing with targets: $target1_https, $target2_https, $target3_https");

            if ($cleanHref === $target1_http || $cleanHref === $target1_https || 
                $cleanHref === $target2_http || $cleanHref === $target2_https ||
                $cleanHref === $target3_http || $cleanHref === $target3_https) {
                \KutSocial\Controllers\ActivityPubController::log("verifyLinkRelation: SUCCESS matching link $cleanHref on $url");
                return true;
            }
        }
        
        \KutSocial\Controllers\ActivityPubController::log("verifyLinkRelation: FAILED, no matching rel=\"me\" link found pointing back to this profile on $url");
        return false;
    }

    /**
     * Endpoint GET /api/v1/accounts
     * Obtiene información sobre varias cuentas especificadas por ID (como array)
     */
    public static function getAccounts(): void {
        $ids = $_GET['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            Router::json([]);
            return;
        }

        $db = Database::connect();
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }

        $formatted = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $formatted[] = self::formatAccount($byId[$id]);
            }
        }

        Router::json($formatted);
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

        if (!empty($account['domain'])) {
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

    private static function fetchAndImportRemoteStatuses(array $account): void {
        if ($account['domain'] === null) {
            return;
        }

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $actorUrl = "https://{$account['domain']}/users/{$account['username']}";

        try {
            // 1. Obtener la URL de la outbox del perfil del actor remoto
            $ch = curl_init($actorUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$domain)"
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
                CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            if (!$resp) return;
            $json = json_decode($resp, true);
            if (!$json || empty($json['outbox'])) return;

            $outboxUrl = $json['outbox'];

            // 2. Consultar la outbox
            $ch = curl_init($outboxUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$domain)"
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
                CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
            ]);
            $outboxResp = curl_exec($ch);
            curl_close($ch);

            if (!$outboxResp) return;
            $outboxJson = json_decode($outboxResp, true);
            if (!$outboxJson) return;

            $items = [];
            if (isset($outboxJson['orderedItems'])) {
                $items = $outboxJson['orderedItems'];
            } elseif (isset($outboxJson['first'])) {
                $firstPageUrl = is_array($outboxJson['first']) ? ($outboxJson['first']['id'] ?? '') : $outboxJson['first'];
                if ($firstPageUrl) {
                    $ch = curl_init($firstPageUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            "Accept: application/activity+json, application/ld+json",
                            "User-Agent: KutSocial/1.0; (+https://$domain)"
                        ],
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
                        CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
                    ]);
                    $pageResp = curl_exec($ch);
                    curl_close($ch);
                    if ($pageResp) {
                        $pageJson = json_decode($pageResp, true);
                        $items = $pageJson['orderedItems'] ?? $pageJson['items'] ?? [];
                    }
                }
            }

            $db = Database::connect();

            // 3. Procesar e insertar notas en la base de datos local
            foreach ($items as $item) {
                $activityType = $item['type'] ?? '';
                $object = null;
                if ($activityType === 'Create' && isset($item['object'])) {
                    $object = $item['object'];
                } elseif (isset($item['type']) && ($item['type'] === 'Note' || $item['type'] === 'Question')) {
                    $object = $item;
                }

                if (!$object) continue;

                $uri = $object['id'] ?? '';
                if (empty($uri)) continue;

                // Evitar duplicados por URI de estado
                $stmtCheck = $db->prepare("SELECT id FROM statuses WHERE uri = ? LIMIT 1");
                $stmtCheck->execute([$uri]);
                if ($stmtCheck->fetchColumn()) {
                    continue;
                }

                $content = $object['content'] ?? '';
                $visibility = self::determineVisibility($object);
                $createdAt = $object['published'] ?? date('c');

                // Procesar adjuntos (media)
                $attachments = [];
                if (!empty($object['attachment'])) {
                    foreach ($object['attachment'] as $att) {
                        $attType = $att['type'] ?? '';
                        if ($attType === 'Document' || $attType === 'Image') {
                            $url = '';
                            if (isset($att['url'])) {
                                if (is_string($att['url'])) {
                                    $url = $att['url'];
                                } elseif (is_array($att['url'])) {
                                    if (isset($att['url']['href'])) {
                                        $url = $att['url']['href'];
                                    } else {
                                        foreach ($att['url'] as $subUrl) {
                                            if (is_array($subUrl) && isset($subUrl['href'])) {
                                                $url = $subUrl['href'];
                                                break;
                                            } elseif (is_string($subUrl)) {
                                                $url = $subUrl;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                            if (empty($url)) {
                                continue;
                            }

                            $description = $att['name'] ?? $att['summary'] ?? $att['description'] ?? '';

                            $attachments[] = [
                                'id' => bin2hex(random_bytes(6)),
                                'type' => 'image',
                                'url' => $url,
                                'preview_url' => $url,
                                'remote_url' => $url,
                                'description' => $description
                            ];
                        }
                    }
                }
                $mediaJson = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

                $stmtIns = $db->prepare("
                    INSERT INTO statuses (account_id, uri, content, visibility, created_at, media_attachments)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $account['id'],
                    $uri,
                    $content,
                    $visibility,
                    date('Y-m-d H:i:s', strtotime($createdAt)),
                    $mediaJson
                ]);
            }
        } catch (\Exception $e) {
            // Ignorar errores de conexión
        }
    }

    /**
     * Endpoint GET /api/v1/accounts/:id/statuses
     * Obtiene las publicaciones creadas por una cuenta específica
     */
    public static function getAccountStatuses(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();

        $stmtAccount = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmtAccount->execute([$id]);
        $account = $stmtAccount->fetch();

        if ($account && !empty($account['domain'])) {
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM statuses WHERE account_id = ?");
            $stmtCount->execute([$id]);
            $localCount = (int)$stmtCount->fetchColumn();

            $lastUpdated = strtotime($account['updated_at']);
            if ($localCount === 0 || (time() - $lastUpdated >= 300)) {
                self::fetchAndImportRemoteStatuses($account);
            }
        }

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;

        $currUser = self::getAuthenticatedAccount();
        $currUserId = $currUser ? (int)$currUser['id'] : null;

        $whereClauses = ["s.account_id = ?"];
        $queryParams = [$id];

        if ($currUserId === $id) {
            // El dueño ve todos sus estados
        } else {
            // Verificar si el visor sigue a esta cuenta
            $isFollowing = false;
            if ($currUserId !== null) {
                $stmtFollowCheck = $db->prepare("SELECT 1 FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'accepted' LIMIT 1");
                $stmtFollowCheck->execute([$currUserId, $id]);
                $isFollowing = (bool)$stmtFollowCheck->fetchColumn();
            }

            if ($isFollowing) {
                $whereClauses[] = "s.visibility IN ('public', 'unlisted', 'private')";
            } else {
                $whereClauses[] = "s.visibility IN ('public', 'unlisted')";
            }
        }

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
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable
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

        $whereClauses = [
            "(
                (s.visibility IN ('public', 'unlisted', 'private') AND (s.account_id = ? OR s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND status = 'accepted')))
                OR
                (s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))
                OR
                (s.visibility IN ('public', 'unlisted') AND EXISTS (
                    SELECT 1 FROM followed_hashtags fh 
                    WHERE fh.account_id = ? 
                      AND (s.content LIKE '%#' || fh.hashtag || '%' OR s.content LIKE '%/tags/' || fh.hashtag || '%')
                ))
            )"
        ];
        $queryParams = [
            $account['id'],
            $account['id'],
            $account['id'],
            '%@' . $account['username'] . '%',
            '%/users/' . $account['username'] . '%',
            $account['id']
        ];

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
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable
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

        $whereClauses = ["s.visibility = 'public'"];
        $queryParams = [];

        $local = isset($_GET['local']) && ($_GET['local'] === 'true' || $_GET['local'] === '1');
        if ($local) {
            $whereClauses[] = "(a.domain IS NULL OR a.domain = '')";
        }

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

        $whereSql = "WHERE " . implode(" AND ", $whereClauses);

        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable
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

        if ($inReplyToId !== null) {
            $stmtCheckParent = $db->prepare("SELECT id FROM statuses WHERE id = ? LIMIT 1");
            $stmtCheckParent->execute([$inReplyToId]);
            if (!$stmtCheckParent->fetchColumn()) {
                $inReplyToId = null;
            }
        }
        
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Cargar límite de caracteres
        $stmtLimit = $db->prepare("SELECT value FROM options WHERE key = 'max_toot_chars' LIMIT 1");
        $stmtLimit->execute();
        $maxChars = (int)($stmtLimit->fetchColumn() ?: 500);

        $contentLen = function_exists('mb_strlen') ? \mb_strlen($content) : strlen($content);
        if ($contentLen > $maxChars) {
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
                $matches = array_filter($matches ?: [], function($f) {
                    return pathinfo($f, PATHINFO_EXTENSION) !== 'txt';
                });
                $matches = array_values($matches);
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

                    // Cargar descripción de archivo si existe
                    $descFile = $uploadsDir . "/media_" . $mId . ".txt";
                    $description = null;
                    if (file_exists($descFile)) {
                        $description = trim(file_get_contents($descFile));
                    }

                    $mediaApiType = self::detectMediaTypeFromUrl($fileUrl);
                    $mediaAttachments[] = [
                        'id' => $mId,
                        'type' => $mediaApiType,
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
                        'description' => $description,
                        'blurhash' => 'L6PZfHnd.Txu_N%M_Mx]URt7bIWA'
                    ];
                }
            }
        }

        $language = !empty($body['language']) ? strtolower(trim($body['language'])) : (isset($_POST['language']) ? strtolower(trim($_POST['language'])) : null);

        $quoteId = $body['quote_id'] ?? $_POST['quote_id'] ?? null;
        $reblogOfId = null;
        if ($quoteId) {
            $quoteId = preg_replace('/[^a-zA-Z0-9]/', '', $quoteId);
            $stmtQuote = $db->prepare("SELECT id, uri FROM statuses WHERE id = ? LIMIT 1");
            $stmtQuote->execute([$quoteId]);
            $quoteRow = $stmtQuote->fetch();
            if ($quoteRow) {
                $reblogOfId = $quoteRow['id'];
                // Si hay texto propio del usuario, es un Quote Post.
                // Agregamos el enlace al post citado al final de la publicación para que Mona/Ice Cubes rendericen la cita.
                if (!empty($content)) {
                    $hasLink = str_contains($content, $quoteRow['uri']);
                    if (!$hasLink) {
                        $path = parse_url($quoteRow['uri'], PHP_URL_PATH);
                        if ($path && str_contains($content, $path)) {
                            $hasLink = true;
                        }
                    }
                    if (!$hasLink) {
                        $content .= " <a href=\"{$quoteRow['uri']}\" class=\"mention\">{$quoteRow['uri']}</a>";
                    }
                }
            }
        }

        // Insertar status
        $stmt = $db->prepare("
            INSERT INTO statuses (account_id, uri, content, visibility, in_reply_to_id, sensitive, spoiler_text, media_attachments, language, reblog_of_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$account['id'], $uri, $content, $visibility, $inReplyToId, $sensitive, $spoilerText, !empty($mediaAttachments) ? json_encode($mediaAttachments) : null, $language, $reblogOfId]);
        $newId = $db->lastInsertId();

        \KutSocial\NotificationHelper::fetchAndSaveLinkCard((int)$newId, $content);
        \KutSocial\NotificationHelper::notifyMentionOrReply((int)$newId);

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

        // FEDERACIÓN: Encolar la actividad Create Note
        $actorUrl = "$proto://$domain/users/{$account['username']}";
        
        // 1. Procesar menciones
        $mentionedActors = [];
        $mentionedInboxes = [];
        $tags = [];
        preg_match_all('/@([a-zA-Z0-9_-]+)(?:@([a-zA-Z0-9.-]+))?/', $content, $mMatches, PREG_SET_ORDER);
        foreach ($mMatches as $match) {
            $mUsername = $match[1];
            $mDomain = isset($match[2]) && !empty($match[2]) ? strtolower($match[2]) : null;
            if ($mDomain !== null && strcasecmp($mDomain, $domain) === 0) {
                $mDomain = null;
            }
            
            $mAcc = null;
            if ($mDomain === null) {
                $stmtM = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
                $stmtM->execute([$mUsername]);
                $mAcc = $stmtM->fetch();
            } else {
                $stmtM = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
                $stmtM->execute([$mUsername, $mDomain]);
                $mAcc = $stmtM->fetch();
                if (!$mAcc) {
                    try {
                        $mAcc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger("@{$mUsername}@{$mDomain}");
                    } catch (\Exception $e) {
                        // Ignorar fallos de resolución
                    }
                }
            }
            
            if ($mAcc) {
                $mActorUrl = $mAcc['domain'] 
                    ? "https://{$mAcc['domain']}/users/{$mAcc['username']}"
                    : "$proto://$domain/users/{$mAcc['username']}";
                if (!in_array($mActorUrl, $mentionedActors)) {
                    $mentionedActors[] = $mActorUrl;
                }
                if (!empty($mAcc['inbox_url']) && !in_array($mAcc['inbox_url'], $mentionedInboxes)) {
                    $mentionedInboxes[] = $mAcc['inbox_url'];
                }

                $mentionName = $mAcc['domain']
                    ? "@{$mAcc['username']}@{$mAcc['domain']}"
                    : "@{$mAcc['username']}";

                $tagExists = false;
                foreach ($tags as $t) {
                    if (isset($t['href']) && $t['href'] === $mActorUrl) {
                        $tagExists = true;
                        break;
                    }
                }
                if (!$tagExists) {
                    $tags[] = [
                        'type' => 'Mention',
                        'href' => $mActorUrl,
                        'name' => $mentionName
                    ];
                }
            }
        }

        // 2. Procesar respuesta a post padre
        $parentActorUrl = null;
        $parentInboxUrl = null;
        $inReplyToUri = null;
        if ($inReplyToId) {
            $stmtParent = $db->prepare("
                SELECT s.uri, a.username, a.domain, a.inbox_url FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ? LIMIT 1
            ");
            $stmtParent->execute([$inReplyToId]);
            $parent = $stmtParent->fetch();
            if ($parent) {
                $inReplyToUri = $parent['uri'];
                $parentActorUrl = $parent['domain']
                    ? "https://{$parent['domain']}/users/{$parent['username']}"
                    : "$proto://$domain/users/{$parent['username']}";
                if (!empty($parent['inbox_url'])) {
                    $parentInboxUrl = $parent['inbox_url'];
                }

                $tagExists = false;
                foreach ($tags as $t) {
                    if (isset($t['href']) && $t['href'] === $parentActorUrl) {
                        $tagExists = true;
                        break;
                    }
                }
                if (!$tagExists) {
                    $parentMentionName = $parent['domain']
                        ? "@{$parent['username']}@{$parent['domain']}"
                        : "@{$parent['username']}";
                    $tags[] = [
                        'type' => 'Mention',
                        'href' => $parentActorUrl,
                        'name' => $parentMentionName
                    ];
                }
            }
        }

        // 3. Determinar destinatarios (to/cc) según visibilidad
        $to = [];
        $cc = [];

        if ($visibility === 'public') {
            $to[] = 'https://www.w3.org/ns/activitystreams#Public';
            $cc[] = "$actorUrl/followers";
            foreach ($mentionedActors as $ma) {
                if (!in_array($ma, $cc)) $cc[] = $ma;
            }
            if ($parentActorUrl && !in_array($parentActorUrl, $cc)) {
                $cc[] = $parentActorUrl;
            }
        } elseif ($visibility === 'unlisted') {
            $to[] = "$actorUrl/followers";
            $cc[] = 'https://www.w3.org/ns/activitystreams#Public';
            foreach ($mentionedActors as $ma) {
                if (!in_array($ma, $cc)) $cc[] = $ma;
            }
            if ($parentActorUrl && !in_array($parentActorUrl, $cc)) {
                $cc[] = $parentActorUrl;
            }
        } elseif ($visibility === 'private') {
            $to[] = "$actorUrl/followers";
            foreach ($mentionedActors as $ma) {
                if (!in_array($ma, $to)) $to[] = $ma;
            }
            if ($parentActorUrl && !in_array($parentActorUrl, $to)) {
                $to[] = $parentActorUrl;
            }
        } else { // direct
            foreach ($mentionedActors as $ma) {
                if (!in_array($ma, $to)) $to[] = $ma;
            }
            if ($parentActorUrl && !in_array($parentActorUrl, $to)) {
                $to[] = $parentActorUrl;
            }
            if (empty($to)) {
                $to[] = $actorUrl;
            }
        }

        // 4. Determinar bandejas de entrada (inboxes) destino
        $targetInboxes = [];
        if ($visibility !== 'direct') {
            $followersStmt = $db->prepare("
                SELECT a.inbox_url FROM follows f
                JOIN accounts a ON f.account_id = a.id
                WHERE f.target_account_id = ? AND f.status = 'accepted' AND a.domain IS NOT NULL
            ");
            $followersStmt->execute([$account['id']]);
            $targetInboxes = $followersStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach ($mentionedInboxes as $mib) {
            if (!empty($mib) && !in_array($mib, $targetInboxes)) {
                $targetInboxes[] = $mib;
            }
        }
        if ($parentInboxUrl && !empty($parentInboxUrl) && !in_array($parentInboxUrl, $targetInboxes)) {
            $targetInboxes[] = $parentInboxUrl;
        }

        // 5. Construir y encolar actividades
        if (!empty($targetInboxes)) {
            $htmlContent = self::formatLocalContentToHtml($content, $domain, $db, $proto);
            
            $quoteUrlVal = null;
            if ($reblogOfId) {
                $stmtQuoteUri = $db->prepare("SELECT uri FROM statuses WHERE id = ? LIMIT 1");
                $stmtQuoteUri->execute([$reblogOfId]);
                $quoteUrlVal = $stmtQuoteUri->fetchColumn() ?: null;
            }

            $noteObject = [
                'id' => $uri,
                'type' => 'Note',
                'published' => date('c'),
                'attributedTo' => $actorUrl,
                'content' => $htmlContent,
                'to' => $to,
                'cc' => $cc
            ];

            if ($quoteUrlVal) {
                $noteObject['quoteUrl'] = $quoteUrlVal;
            }

            if (!empty($tags)) {
                $noteObject['tag'] = $tags;
            }

            if ($inReplyToUri) {
                $noteObject['inReplyTo'] = $inReplyToUri;
            }

            if (!empty($mediaAttachments)) {
                $attachmentsList = [];
                foreach ($mediaAttachments as $att) {
                    $attachmentsList[] = [
                        'type' => 'Document',
                        'mediaType' => 'image/' . pathinfo($att['url'], PATHINFO_EXTENSION),
                        'url' => $att['url'],
                        'name' => !empty($att['description']) ? $att['description'] : null
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
                'to' => $to,
                'cc' => $cc,
                'object' => $noteObject
            ];

            foreach ($targetInboxes as $inboxUrl) {
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

        $stream = $_GET['stream'] ?? 'public';
        $tag = $_GET['tag'] ?? null;
        $listId = isset($_GET['list_id']) ? (int)$_GET['list_id'] : null;

        $currUser = self::getAuthenticatedAccount();
        $currUserId = $currUser ? (int)$currUser['id'] : null;

        $whereClauses = ["s.id > ?"];
        $queryParams = [0]; // Reemplazado por $lastId en cada ciclo

        if ($stream === 'public:local') {
            $whereClauses[] = "s.visibility = 'public'";
            $whereClauses[] = "(a.domain IS NULL OR a.domain = '')";
        } elseif ($stream === 'user') {
            if (!$currUserId) {
                exit;
            }
            $whereClauses[] = "(
                (s.visibility IN ('public', 'unlisted', 'private') AND (s.account_id = ? OR s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND status = 'accepted')))
                OR
                (s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))
            )";
            $queryParams[] = $currUserId;
            $queryParams[] = $currUserId;
            $queryParams[] = $currUserId;
            $queryParams[] = '%@' . $currUser['username'] . '%';
            $queryParams[] = '%/users/' . $currUser['username'] . '%';
        } elseif ($stream === 'hashtag' && $tag) {
            $whereClauses[] = "s.visibility IN ('public', 'unlisted')";
            $whereClauses[] = "(s.content LIKE ? OR s.content LIKE ?)";
            $queryParams[] = '%#' . $tag . '%';
            $queryParams[] = '%/tags/' . $tag . '%';
        } elseif ($stream === 'list' && $listId) {
            $whereClauses[] = "s.visibility IN ('public', 'unlisted')";
            $whereClauses[] = "s.account_id IN (SELECT account_id FROM list_accounts WHERE list_id = ?)";
            $queryParams[] = $listId;
        } else {
            $whereClauses[] = "s.visibility = 'public'";
        }

        $whereSql = implode(" AND ", $whereClauses);

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

            $queryParams[0] = $lastId;

            $stmt = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE $whereSql
                ORDER BY s.id ASC
            ");
            $stmt->execute($queryParams);
            $newStatuses = $stmt->fetchAll();

            foreach ($newStatuses as $row) {
                $lastId = max($lastId, (int)$row['status_id']);
                $statusFormatted = self::formatStatus($row, $currUserId);
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

    private static function formatLocalContentToHtml(string $content, string $domain, \PDO $db, string $proto): string {
        // 1. Extraer etiquetas <a> temporales del contenido en crudo (antes de escapar)
        $placeholders = [];
        $result = '';
        $offset = 0;
        $len = strlen($content);
        
        while ($offset < $len) {
            $posA = stripos($content, '<a', $offset);
            if ($posA === false) {
                $result .= substr($content, $offset);
                break;
            }
            
            $result .= substr($content, $offset, $posA - $offset);
            
            $posClose = stripos($content, '</a>', $posA);
            if ($posClose === false) {
                $result .= substr($content, $posA);
                break;
            }
            
            $tagLen = $posClose + 4 - $posA;
            $tagHtml = substr($content, $posA, $tagLen);
            
            $placeholderKey = '___LINK_PLACEHOLDER_' . count($placeholders) . '___';
            $placeholders[$placeholderKey] = $tagHtml;
            $result .= $placeholderKey;
            
            $offset = $posA + $tagLen;
        }
        
        // 2. Escapar las partes restantes (que no son etiquetas aisladas)
        $escaped = htmlspecialchars($result, ENT_NOQUOTES, 'UTF-8');
        
        // 3. Linkificar URLs en la parte escapada (no tocará los placeholders)
        $escaped = preg_replace_callback('/\bhttps?:\/\/[^\s<>\'\"]+/i', function($m) {
            $rawUrl = htmlspecialchars_decode($m[0], ENT_NOQUOTES);
            $cleanRawUrl = rtrim($rawUrl, '.,;:!?)-');
            $trailingRaw = substr($rawUrl, strlen($cleanRawUrl));
            $cleanVisibleUrl = htmlspecialchars($cleanRawUrl, ENT_NOQUOTES, 'UTF-8');
            $trailingVisible = htmlspecialchars($trailingRaw, ENT_NOQUOTES, 'UTF-8');
            return '<a href="' . htmlspecialchars($cleanRawUrl, ENT_COMPAT, 'UTF-8') . '" target="_blank" rel="nofollow noopener noreferrer">' . $cleanVisibleUrl . '</a>' . $trailingVisible;
        }, $escaped);

        // 4. Convertir menciones en enlaces
        $escaped = preg_replace_callback('/@([a-zA-Z0-9_-]+)(?:@([a-zA-Z0-9.-]+))?/i', function($matches) use ($domain, $db, $proto) {
            $mUsername = $matches[1];
            $mDomain = isset($matches[2]) && !empty($matches[2]) ? strtolower($matches[2]) : null;
            
            if ($mDomain !== null && (strcasecmp($mDomain, $domain) === 0)) {
                $mDomain = null;
            }
            
            $mAcc = null;
            if ($mDomain === null) {
                $stmtM = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
                $stmtM->execute([$mUsername]);
                $mAcc = $stmtM->fetch();
            } else {
                $stmtM = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
                $stmtM->execute([$mUsername, $mDomain]);
                $mAcc = $stmtM->fetch();
            }
            
            if ($mAcc) {
                $mActorUrl = $mAcc['domain'] 
                    ? "https://{$mAcc['domain']}/users/{$mAcc['username']}"
                    : "$proto://$domain/users/{$mAcc['username']}";
                
                return '<span class="h-card"><a href="' . htmlspecialchars($mActorUrl) . '" class="u-url mention">@<span>' . htmlspecialchars($mUsername) . '</span></a></span>';
            }
            
            return $matches[0];
        }, $escaped);

        // 5. Restaurar etiquetas <a> en crudo
        if (!empty($placeholders)) {
            $escaped = strtr($escaped, $placeholders);
        }
        
        return '<p>' . nl2br($escaped) . '</p>';
    }

    /**
     * Helper: Formatea la nota/bio de una cuenta para la API.
     * Las bios de cuentas remotas ya vienen en HTML desde ActivityPub y no deben re-escaparse.
     * Las bios de cuentas locales se formatean como HTML limpio.
     */
    private static function formatAccountNote(string $note, bool $isRemote): string {
        if (empty($note)) {
            return '<p></p>';
        }
        // Si es cuenta remota, la nota ya viene en HTML desde ActivityPub
        if ($isRemote) {
            if (str_contains($note, '<p>') || str_contains($note, '<br') || str_contains($note, '<a ') || str_contains($note, '<span')) {
                return $note;
            }
        }
        // Para cuentas locales o notas sin HTML, formatear
        if (str_contains($note, '<p>') || str_contains($note, '<br')) {
            return $note;
        }
        $paragraphs = preg_split('/\r?\n\r?\n/', $note);
        $formattedParagraphs = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p !== '') {
                $formattedParagraphs[] = "<p>" . nl2br(htmlspecialchars($p)) . "</p>";
            }
        }
        return implode("", $formattedParagraphs) ?: '<p></p>';
    }

    /**
     * Endpoint GET /api/v1/statuses/:id
     * Retorna un status individual por su ID.
     */
    public static function getSingleStatus(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();

        $account = null;
        $currUserId = null;
        try {
            $account = self::getAuthenticatedAccount();
            if ($account) {
                $currUserId = (int)$account['id'];
            }
        } catch (\Exception $e) {}

        $row = self::fetchStatusRow($db, $id);
        if (!$row) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        // Verificar permisos de visibilidad
        $visibility = $row['status_visibility'] ?? 'public';
        $statusAuthorId = (int)$row['account_id'];

        if ($visibility === 'direct') {
            if ($currUserId === null) {
                Router::json(['error' => 'Record not found'], 404);
                return;
            }
            if ($currUserId !== $statusAuthorId) {
                $username = $account['username'] ?? '';
                $content = $row['status_content'] ?? '';
                if (!str_contains($content, "/users/$username") && !preg_match('/@' . preg_quote($username, '/') . '\b/i', $content)) {
                    Router::json(['error' => 'Record not found'], 404);
                    return;
                }
            }
        } elseif ($visibility === 'private') {
            if ($currUserId === null) {
                Router::json(['error' => 'Record not found'], 404);
                return;
            }
            if ($currUserId !== $statusAuthorId) {
                $stmtFollow = $db->prepare("SELECT 1 FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'accepted' LIMIT 1");
                $stmtFollow->execute([$currUserId, $statusAuthorId]);
                if (!$stmtFollow->fetchColumn()) {
                    Router::json(['error' => 'Record not found'], 404);
                    return;
                }
            }
        }

        Router::json(self::formatStatus($row, $currUserId));
    }

    public static function getMultipleStatuses(): void {
        $ids = $_GET['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            Router::json([]);
            return;
        }

        $account = null;
        $currUserId = null;
        try {
            $account = self::getAuthenticatedAccount();
            if ($account) {
                $currUserId = (int)$account['id'];
            }
        } catch (\Exception $e) {}

        $db = Database::connect();
        $statuses = [];

        foreach ($ids as $id) {
            $row = self::fetchStatusRow($db, $id);
            if (!$row) {
                continue;
            }

            // Verificar permisos de visibilidad
            $visibility = $row['status_visibility'] ?? 'public';
            $statusAuthorId = (int)$row['account_id'];

            if ($visibility === 'direct') {
                if ($currUserId === null) {
                    continue;
                }
                if ($currUserId !== $statusAuthorId) {
                    $username = $account['username'] ?? '';
                    $content = $row['status_content'] ?? '';
                    if (!str_contains($content, "/users/$username") && !preg_match('/@' . preg_quote($username, '/') . '\b/i', $content)) {
                        continue;
                    }
                }
            } elseif ($visibility === 'private') {
                if ($currUserId === null) {
                    continue;
                }
                if ($currUserId !== $statusAuthorId) {
                    $stmtFollow = $db->prepare("SELECT 1 FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'accepted' LIMIT 1");
                    $stmtFollow->execute([$currUserId, $statusAuthorId]);
                    if (!$stmtFollow->fetchColumn()) {
                        continue;
                    }
                }
            }

            $statuses[] = self::formatStatus($row, $currUserId);
        }

        Router::json($statuses);
    }

    /**
     * Helper: Detecta el tipo de media Mastodon API a partir de un mediaType MIME.
     */
    public static function detectMediaType(string $mediaType): string {
        $mediaType = strtolower(trim($mediaType));
        if (str_starts_with($mediaType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mediaType, 'audio/')) {
            return 'audio';
        }
        if ($mediaType === 'image/gif') {
            return 'gifv';
        }
        return 'image';
    }

    /**
     * Helper: Detecta el tipo de media a partir de la extensión de URL cuando no hay mediaType.
     */
    public static function detectMediaTypeFromUrl(string $url): string {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $videoExts = ['mp4', 'webm', 'mov', 'ogv', 'avi', 'mkv', 'm4v'];
        $audioExts = ['mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a', 'opus', 'wma'];
        if (in_array($ext, $videoExts)) {
            return 'video';
        }
        if (in_array($ext, $audioExts)) {
            return 'audio';
        }
        if ($ext === 'gif') {
            return 'gifv';
        }
        return 'image';
    }

    /**
     * Helper: Extrae emojis personalizados de una cadena de contenido y de datos almacenados.
     * Los emojis en Mastodon usan formato :shortcode: en el contenido.
     * Los emojis remotos se almacenan como JSON en la columna 'emojis' del status.
     */
    private static $emojisCache = ['accounts' => [], 'statuses' => []];

    private static function extractEmojisFromContent(string $content, array $row): array {
        $statusId = $row['status_id'] ?? $row['id'] ?? null;
        $emojisJson = $row['emojis'] ?? $row['status_emojis'] ?? null;

        if ($emojisJson === null && $statusId !== null) {
            $statusId = (int)$statusId;
            if (isset(self::$emojisCache['statuses'][$statusId])) {
                $emojisJson = self::$emojisCache['statuses'][$statusId];
            } else {
                try {
                    $db = Database::connect();
                    $stmt = $db->prepare("SELECT emojis FROM statuses WHERE id = ? LIMIT 1");
                    $stmt->execute([$statusId]);
                    $emojisJson = $stmt->fetchColumn() ?: '';
                    self::$emojisCache['statuses'][$statusId] = $emojisJson;
                } catch (\Exception $e) {
                    $emojisJson = '';
                }
            }
        }

        $emojis = [];
        $seenShortcodes = [];

        // 1. Primero, extraer emojis almacenados del status (guardados desde ActivityPub)
        if (!empty($emojisJson) && is_string($emojisJson)) {
            $storedEmojis = json_decode($emojisJson, true);
            if (is_array($storedEmojis)) {
                foreach ($storedEmojis as $emoji) {
                    $shortcode = $emoji['shortcode'] ?? '';
                    if (!empty($shortcode) && !isset($seenShortcodes[$shortcode])) {
                        $seenShortcodes[$shortcode] = true;
                        $emojis[] = [
                            'shortcode' => $shortcode,
                            'url' => $emoji['url'] ?? '',
                            'static_url' => $emoji['static_url'] ?? $emoji['url'] ?? '',
                            'visible_in_picker' => $emoji['visible_in_picker'] ?? true,
                            'category' => $emoji['category'] ?? ''
                        ];
                    }
                }
            }
        }

        // 2. Extraer emojis de la cuenta del autor (display_name puede contener emojis)
        $accountEmojisJson = $row['account_emojis'] ?? null;
        if ($accountEmojisJson === null) {
            $accountId = $row['account_id'] ?? $row['id'] ?? null;
            if ($accountId !== null) {
                $accountId = (int)$accountId;
                if (isset(self::$emojisCache['accounts'][$accountId])) {
                    $accountEmojisJson = self::$emojisCache['accounts'][$accountId];
                } else {
                    try {
                        $db = Database::connect();
                        $stmt = $db->prepare("SELECT emojis FROM accounts WHERE id = ? LIMIT 1");
                        $stmt->execute([$accountId]);
                        $accountEmojisJson = $stmt->fetchColumn() ?: '';
                        self::$emojisCache['accounts'][$accountId] = $accountEmojisJson;
                    } catch (\Exception $e) {
                        $accountEmojisJson = '';
                    }
                }
            }
        }

        if (!empty($accountEmojisJson) && is_string($accountEmojisJson)) {
            $accountEmojis = json_decode($accountEmojisJson, true);
            if (is_array($accountEmojis)) {
                foreach ($accountEmojis as $emoji) {
                    $shortcode = $emoji['shortcode'] ?? '';
                    if (!empty($shortcode) && !isset($seenShortcodes[$shortcode])) {
                        $seenShortcodes[$shortcode] = true;
                        $emojis[] = [
                            'shortcode' => $shortcode,
                            'url' => $emoji['url'] ?? '',
                            'static_url' => $emoji['static_url'] ?? $emoji['url'] ?? '',
                            'visible_in_picker' => false,
                            'category' => ''
                        ];
                    }
                }
            }
        }

        return $emojis;
    }

    /**
     * Helper: Extrae emojis personalizados almacenados de una cuenta.
     */
    private static function extractAccountEmojis(array $account): array {
        $accountId = $account['account_id'] ?? $account['id'] ?? null;
        $emojisJson = $account['emojis'] ?? $account['account_emojis'] ?? null;

        if ($emojisJson === null && $accountId !== null) {
            $accountId = (int)$accountId;
            if (isset(self::$emojisCache['accounts'][$accountId])) {
                $emojisJson = self::$emojisCache['accounts'][$accountId];
            } else {
                try {
                    $db = Database::connect();
                    $stmt = $db->prepare("SELECT emojis FROM accounts WHERE id = ? LIMIT 1");
                    $stmt->execute([$accountId]);
                    $emojisJson = $stmt->fetchColumn() ?: '';
                    self::$emojisCache['accounts'][$accountId] = $emojisJson;
                } catch (\Exception $e) {
                    $emojisJson = '';
                }
            }
        }

        $emojis = [];
        if (!empty($emojisJson) && is_string($emojisJson)) {
            $stored = json_decode($emojisJson, true);
            if (is_array($stored)) {
                foreach ($stored as $emoji) {
                    $shortcode = $emoji['shortcode'] ?? '';
                    if (!empty($shortcode)) {
                        $emojis[] = [
                            'shortcode' => $shortcode,
                            'url' => $emoji['url'] ?? '',
                            'static_url' => $emoji['static_url'] ?? $emoji['url'] ?? '',
                            'visible_in_picker' => $emoji['visible_in_picker'] ?? false,
                            'category' => $emoji['category'] ?? ''
                        ];
                    }
                }
            }
        }
        return $emojis;
    }

    public static function formatStatus(array $row, ?int $currentAccountId = null): array {
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
            'locked' => (bool)($row['locked'] ?? 0),
            'bot' => false,
            'discoverable' => (bool)($row['discoverable'] ?? 1),
            'group' => false,
            'created_at' => date('c', strtotime($row['account_created_at'] ?? $row['created_at'] ?? 'now')),
            'note' => self::formatAccountNote($row['note'] ?? '', !empty($row['domain'])),
            'url' => (!empty($row['account_url']) && !empty($row['domain'])) ? $row['account_url'] : ($row['domain'] ? "$proto://{$row['domain']}/users/{$row['username']}" : "$proto://$domain/users/" . $row['username']),
            'avatar' => $avatarUrl,
            'avatar_static' => $avatarUrl,
            'header' => $headerUrl,
            'header_static' => $headerUrl,
            'followers_count' => 0,
            'following_count' => 0,
            'statuses_count' => 0,
            'last_status_at' => null,
            'emojis' => self::extractAccountEmojis($row),
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
            $isRemote = !empty($row['domain']);
            if ($isRemote) {
                $application = null;
            } else {
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
            if (is_array($attachments)) {
                foreach ($attachments as &$att) {
                    $url = $att['url'] ?? '';
                    $currentType = $att['type'] ?? 'image';
                    
                    // Si el tipo actual es 'image', pero la extensión de la URL o el nombre dice que es video/audio
                    if ($currentType === 'image') {
                        $inferredType = self::detectMediaTypeFromUrl($url);
                        if ($inferredType !== 'image') {
                            $att['type'] = $inferredType;
                        }
                    }
                }
            } else {
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
 
        // Reblogs count
        $stmtReb = $db->prepare("SELECT COUNT(*) FROM statuses WHERE reblog_of_id = ? AND content = ''");
        $stmtReb->execute([$statusId]);
        $reblogsCount = (int)$stmtReb->fetchColumn();
 
        // Reblogged check
        $reblogged = false;
        if ($currentAccountId !== null) {
            $stmtRebCheck = $db->prepare("SELECT 1 FROM statuses WHERE account_id = ? AND reblog_of_id = ? LIMIT 1");
            $stmtRebCheck->execute([$currentAccountId, $statusId]);
            $reblogged = (bool)$stmtRebCheck->fetchColumn();
        }
 
        // Cargar reblogged status o quote status
        $reblog = null;
        $quote = null;
        $reblogOfId = null;
        if (array_key_exists('reblog_of_id', $row)) {
            $reblogOfId = $row['reblog_of_id'];
        } else {
            $stmtRebOf = $db->prepare("SELECT reblog_of_id FROM statuses WHERE id = ? LIMIT 1");
            $stmtRebOf->execute([$statusId]);
            $reblogOfId = $stmtRebOf->fetchColumn() ?: null;
        }
 
        $hasOwnContent = !empty(trim($row['status_content'] ?? $row['content'] ?? ''));
        if ($reblogOfId) {
            if (!$hasOwnContent) {
                // Compartido / Reblog simple
                $origRow = self::fetchStatusRow($db, (int)$reblogOfId);
                if ($origRow) {
                    $reblog = self::formatStatus($origRow, $currentAccountId);
                }
            } else {
                // Cita / Quote Post nativo (Mastodon v4.4+)
                $origRow = self::fetchStatusRow($db, (int)$reblogOfId);
                if ($origRow) {
                    $quote = self::formatStatus($origRow, $currentAccountId);
                }
            }
        }
 
        $formattedContent = (!empty($row['domain']) || str_contains($row['status_content'], '<p>')) 
            ? $row['status_content'] 
            : self::formatLocalContentToHtml($row['status_content'], $domain, $db, $proto);
 
        $inReplyToAccountId = null;
        if (!empty($row['in_reply_to_id'])) {
            $stmtPar = $db->prepare("SELECT account_id FROM statuses WHERE id = ? LIMIT 1");
            $stmtPar->execute([$row['in_reply_to_id']]);
            $inReplyToAccountId = $stmtPar->fetchColumn() ?: null;
            if ($inReplyToAccountId) {
                $inReplyToAccountId = (string)$inReplyToAccountId;
            }
        }
 
        return [
            'id' => (string)$row['status_id'],
            'created_at' => date('c', strtotime($row['status_created_at'])),
            'in_reply_to_id' => !empty($row['in_reply_to_id']) ? (string)$row['in_reply_to_id'] : null,
            'in_reply_to_account_id' => $inReplyToAccountId,
            'sensitive' => !empty($row['sensitive']),
            'spoiler_text' => $row['spoiler_text'] ?? '',
            'visibility' => $row['status_visibility'] ?? 'public',
            'language' => $row['language'] ?? 'es',
            'uri' => $row['status_uri'],
            'url' => $row['status_uri'],
            'replies_count' => $repCount,
            'reblogs_count' => $reblogsCount,
            'favourites_count' => $favCount,
            'favourited' => $favourited,
            'reblogged' => $reblogged,
            'reblog' => $reblog,
            'quote' => $quote ? [
                'state' => 'accepted',
                'quoted_status' => $quote
            ] : null,
            'muted' => false,
            'bookmarked' => $bookmarked,
            'content' => $formattedContent,
            'text' => $row['status_content'],
            'account' => $account,
            'media_attachments' => $attachments,
            'mentions' => [],
            'tags' => [],
            'emojis' => self::extractEmojisFromContent($formattedContent, $row),
            'card' => (function() use ($row, $db) {
                if (array_key_exists('card', $row)) {
                    return !empty($row['card']) ? json_decode($row['card'], true) : null;
                }
                $statusId = $row['status_id'] ?? $row['id'] ?? null;
                if ($statusId) {
                    $stmt = $db->prepare("SELECT card FROM statuses WHERE id = ? LIMIT 1");
                    $stmt->execute([$statusId]);
                    $cardStr = $stmt->fetchColumn();
                    return !empty($cardStr) ? json_decode($cardStr, true) : null;
                }
                return null;
            })(),
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
                'following' => ($followStatus === 'accepted'),
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

            if (!$isRemote && $status === 'accepted') {
                \KutSocial\NotificationHelper::notifyFollow((int)$targetId, (int)$account['id']);
            }
        } else {
            $stmt = $db->prepare("UPDATE follows SET status = ? WHERE id = ?");
            $stmt->execute([$status, $existing]);

            if (!$isRemote && $status === 'accepted') {
                \KutSocial\NotificationHelper::notifyFollow((int)$targetId, (int)$account['id']);
            }
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

        // Verificar si la cuenta objetivo nos sigue
        $stmtFollowedBy = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtFollowedBy->execute([$targetId, $account['id']]);
        $followedByStatus = $stmtFollowedBy->fetchColumn();

        Router::json([
            'id' => (string)$targetId,
            'following' => ($status === 'accepted' || $status === 'pending'),
            'showing_reblogs' => true,
            'notifying' => false,
            'languages' => null,
            'followed_by' => ($followedByStatus === 'accepted'),
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

        // Verificar si la cuenta objetivo nos sigue
        $stmtFollowedBy = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtFollowedBy->execute([$targetId, $account['id']]);
        $followedByStatus = $stmtFollowedBy->fetchColumn();

        Router::json([
            'id' => (string)$targetId,
            'following' => false,
            'showing_reblogs' => false,
            'notifying' => false,
            'languages' => null,
            'followed_by' => ($followedByStatus === 'accepted'),
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

    public static function removeFromFollowers(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $followerId = (int)$params['id'];
        $db = Database::connect();

        $stmt = $db->prepare("DELETE FROM follows WHERE account_id = ? AND target_account_id = ?");
        $stmt->execute([$followerId, $account['id']]);

        // Get updated relationship
        $stmtF = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtF->execute([$account['id'], $followerId]);
        $followStatus = $stmtF->fetchColumn();

        Router::json([
            'id' => (string)$followerId,
            'following' => ($followStatus === 'accepted'),
            'showing_reblogs' => true,
            'notifying' => false,
            'languages' => null,
            'followed_by' => false,
            'blocking' => false,
            'blocked_by' => false,
            'muting' => false,
            'muting_notifications' => false,
            'requested' => ($followStatus === 'pending'),
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

        $statusFetcher = function($statusId) use ($db, $currUserId, $account) {
            $stmt = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                       s.visibility as status_visibility, s.created_at as status_created_at, 
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$statusId]);
            $row = $stmt->fetch();
            
            if ($row) {
                $statusVisibility = $row['status_visibility'] ?? 'public';
                $statusAuthorId = (int)$row['account_id'];
                
                $authorized = false;
                if ($statusVisibility === 'public' || $statusVisibility === 'unlisted') {
                    $authorized = true;
                } elseif ($statusVisibility === 'private') {
                    if ($currUserId !== null) {
                        if ($currUserId === $statusAuthorId) {
                            $authorized = true;
                        } else {
                            $stmtFollowCheck = $db->prepare("SELECT 1 FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'accepted' LIMIT 1");
                            $stmtFollowCheck->execute([$currUserId, $statusAuthorId]);
                            $authorized = (bool)$stmtFollowCheck->fetchColumn();
                        }
                    }
                } elseif ($statusVisibility === 'direct') {
                    if ($currUserId !== null) {
                        if ($currUserId === $statusAuthorId) {
                            $authorized = true;
                        } else {
                            $username = $account['username'] ?? '';
                            $actorUrlPattern = "/users/" . $username;
                            if (str_contains($row['status_content'], $actorUrlPattern) ||
                                preg_match('/@' . preg_quote($username, '/') . '\b/i', $row['status_content'])) {
                                $authorized = true;
                            }
                        }
                    }
                }
                
                if (!$authorized) {
                    return null;
                }

                $filtered = self::filterStatuses([$row]);
                if (empty($filtered)) {
                    return null;
                }
            }
            
            return $row ? self::formatStatus($row, $currUserId) : null;
        };

        $mainStatus = $statusFetcher($id);
        if (!$mainStatus) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

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
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.in_reply_to_id = ?
                ORDER BY s.id ASC
            ");
            $stmtReplies->execute([$currentId]);
            $replies = self::filterStatuses($stmtReplies->fetchAll());

            foreach ($replies as $row) {
                $statusVisibility = $row['status_visibility'] ?? 'public';
                $statusAuthorId = (int)$row['account_id'];
                
                $authorized = false;
                if ($statusVisibility === 'public' || $statusVisibility === 'unlisted') {
                    $authorized = true;
                } elseif ($statusVisibility === 'private') {
                    if ($currUserId !== null) {
                        if ($currUserId === $statusAuthorId) {
                            $authorized = true;
                        } else {
                            $stmtFollowCheck = $db->prepare("SELECT 1 FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'accepted' LIMIT 1");
                            $stmtFollowCheck->execute([$currUserId, $statusAuthorId]);
                            $authorized = (bool)$stmtFollowCheck->fetchColumn();
                        }
                    }
                } elseif ($statusVisibility === 'direct') {
                    if ($currUserId !== null) {
                        if ($currUserId === $statusAuthorId) {
                            $authorized = true;
                        } else {
                            $username = $account['username'] ?? '';
                            $actorUrlPattern = "/users/" . $username;
                            if (str_contains($row['status_content'], $actorUrlPattern) ||
                                preg_match('/@' . preg_quote($username, '/') . '\b/i', $row['status_content'])) {
                                $authorized = true;
                            }
                        }
                    }
                }
                
                if ($authorized) {
                    $descendants[] = self::formatStatus($row, $currUserId);
                    $replyQueue[] = (int)$row['status_id'];
                }
            }
        }

        Router::json([
            'status' => $mainStatus,
            'ancestors' => $ancestors,
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

        \KutSocial\NotificationHelper::notifyFavourite((int)$id, (int)$account['id']);

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

    public static function fetchStatusRow(PDO $db, int $id): ?array {
        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments, s.reblog_of_id,
                   s.emojis as status_emojis,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.emojis as account_emojis
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
        
        $currUser = null;
        try {
            $currUser = self::getAuthenticatedAccount();
        } catch (\Exception $e) {}

        $blocks = $db->query("SELECT type, target FROM moderation_blocks")->fetchAll();
        
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
            // 1. Filtrar visibilidad direct (DMs) para evitar fugas de privacidad
            $visibility = $row['status_visibility'] ?? $row['visibility'] ?? 'public';
            if ($visibility === 'direct') {
                if (!$currUser) {
                    continue; // Usuarios no autenticados no pueden ver DMs
                }
                $authorId = (int)($row['account_id'] ?? $row['id'] ?? 0);
                if ($authorId !== (int)$currUser['id']) {
                    $username = $currUser['username'];
                    $content = $row['status_content'] ?? $row['content'] ?? '';
                    
                    $mentionPattern = '/@' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                    $urlPattern = '/\/users\/' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                    
                    $isRealMention = preg_match($mentionPattern, $content) || preg_match($urlPattern, $content);
                    if (!$isRealMention) {
                        continue; // No es el destinatario
                    }
                }
            }

            $domain = strtolower($row['domain'] ?? '');
            if (!empty($domain) && in_array($domain, $blockedDomains)) {
                continue;
            }

            $username = strtolower($row['username'] ?? '');
            $acct = $domain ? $username . '@' . $domain : $username;
            if (in_array($username, $blockedAccounts) || in_array($acct, $blockedAccounts)) {
                continue;
            }

            $content = strtolower($row['status_content'] ?? $row['content'] ?? '');
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

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'm4v', 'webm', 'mov', 'mp3', 'm4a', 'ogg', 'wav', 'aac', 'opus'];
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

        $description = trim($_POST['description'] ?? $_GET['description'] ?? '');
        if (!empty($description)) {
            $descFile = $uploadsDir . '/media_' . $mediaId . '.txt';
            file_put_contents($descFile, $description);
        }

        $mediaApiType = self::detectMediaTypeFromUrl($fileUrl);

        $attachment = [
            'id' => $mediaId,
            'type' => $mediaApiType,
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
            'description' => !empty($description) ? $description : null,
            'blurhash' => 'L6PZfHnd.Txu_N%M_Mx]URt7bIWA'
        ];

        Router::json($attachment);
    }

    public static function updateMedia(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $mediaId = preg_replace('/[^a-zA-Z0-9]/', '', $params['id']);
        $uploadsDir = dirname(Database::getDbPath()) . '/uploads';
        
        $matches = glob($uploadsDir . "/media_" . $mediaId . ".*");
        if (empty($matches)) {
            Router::json(['error' => 'Media not found'], 404);
            return;
        }

        $filePath = $matches[0];
        $filename = basename($filePath);

        $body = Router::getRequestBody();
        $description = trim($body['description'] ?? $_POST['description'] ?? $_GET['description'] ?? '');

        $descFile = $uploadsDir . '/media_' . $mediaId . '.txt';
        if (!empty($description)) {
            file_put_contents($descFile, $description);
        } else {
            if (file_exists($descFile)) {
                unlink($descFile);
            }
        }

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $fileUrl = "$proto://$domain/data/uploads/$filename";

        $width = 600;
        $height = 400;
        $aspect = 1.5;
        if ($size = @getimagesize($filePath)) {
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
            'description' => !empty($description) ? $description : null,
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

    /**
     * Endpoint POST /api/v1/follows/resend_pending
     * Re-envía actividades Follow para todos los follows salientes en estado 'pending'.
     * Útil después de reinstalar la instancia para que los servidores remotos re-envíen Accept.
     */
    public static function resendPendingFollows(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $localActorUrl = "$proto://$domain/users/{$account['username']}";

        // Obtener todos los follows pendientes salientes a cuentas remotas
        $stmt = $db->prepare("
            SELECT f.id as follow_id, a.* FROM follows f
            JOIN accounts a ON f.target_account_id = a.id
            WHERE f.account_id = ? AND f.status = 'pending' AND a.domain IS NOT NULL
        ");
        $stmt->execute([$account['id']]);
        $pendingFollows = $stmt->fetchAll();

        $reenqueued = 0;
        $skipped = 0;

        foreach ($pendingFollows as $target) {
            if (empty($target['inbox_url'])) {
                $skipped++;
                continue;
            }

            $remoteActorUrl = $target['url'] ?? "https://{$target['domain']}/users/{$target['username']}";

            $followActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActorUrl . '/activities/follow-' . bin2hex(random_bytes(8)),
                'type' => 'Follow',
                'actor' => $localActorUrl,
                'object' => $remoteActorUrl
            ];

            \KutSocial\Queue::enqueue('Follow', $followActivity, $target['inbox_url']);
            $reenqueued++;
        }

        if ($reenqueued > 0) {
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json([
            'success' => true,
            'resent' => $reenqueued,
            'skipped' => $skipped,
            'total_pending' => count($pendingFollows),
            'message' => "Se re-enviaron $reenqueued solicitudes de Follow. Los servidores remotos responderán con Accept gradualmente."
        ]);
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
                    // Si ya existe la cuenta localmente y ya la seguimos, omitir
                    $cleanAddress = ltrim($address, '@');
                    $parts = explode('@', $cleanAddress);
                    $uname = $parts[0] ?? '';
                    $udomain = $parts[1] ?? null;
                    if (!empty($uname)) {
                        if ($udomain) {
                            $stmtAcc = $db->prepare("SELECT id FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
                            $stmtAcc->execute([$uname, $udomain]);
                        } else {
                            $stmtAcc = $db->prepare("SELECT id FROM accounts WHERE username = ? AND (domain IS NULL OR domain = '') LIMIT 1");
                            $stmtAcc->execute([$uname]);
                        }
                        $targetId = $stmtAcc->fetchColumn();
                        if ($targetId) {
                            $stmtFollow = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                            $stmtFollow->execute([$account['id'], $targetId]);
                            if ($stmtFollow->fetchColumn()) {
                                continue; // Ya lo seguimos, omitir importación
                            }
                        }
                    }

                    // Encolar de forma no bloqueante para evitar timeouts del servidor al resolver remotamente cada usuario
                    $stmtJob = $db->prepare("INSERT INTO jobs (activity_type, payload, inbox_url, status, next_attempt) VALUES ('ImportFollow', ?, 'local', 'pending', datetime('now'))");
                    $stmtJob->execute([
                        json_encode([
                            'account_id' => $account['id'],
                            'address' => $address
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    ]);
                    $importedCount++;
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

    public static function getFollowRequests(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT a.* FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ? AND f.status = 'pending'
            ORDER BY f.id DESC
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            $results[] = self::formatAccount($row);
        }

        Router::json($results);
    }

    public static function authorizeFollowRequest(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $followerId = (int)$params['id'];
        $db = Database::connect();

        $stmtFollow = $db->prepare("SELECT * FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'pending' LIMIT 1");
        $stmtFollow->execute([$followerId, $account['id']]);
        $follow = $stmtFollow->fetch();

        if (!$follow) {
            Router::json(['error' => 'Solicitud de seguimiento no encontrada'], 404);
            return;
        }

        $stmtUpdate = $db->prepare("UPDATE follows SET status = 'accepted' WHERE id = ?");
        $stmtUpdate->execute([$follow['id']]);

        $stmtFollower = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmtFollower->execute([$followerId]);
        $follower = $stmtFollower->fetch();

        if ($follower && !empty($follower['domain']) && !empty($follower['inbox_url'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$account['username']}";

            $followActivity = [
                'id' => $follow['uri'],
                'type' => 'Follow',
                'actor' => $follower['domain'] 
                    ? "https://{$follower['domain']}/users/{$follower['username']}" 
                    : "$proto://$domain/users/{$follower['username']}",
                'object' => $localActorUrl
            ];

            $acceptActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActorUrl . '/activities/accept-' . bin2hex(random_bytes(8)),
                'type' => 'Accept',
                'actor' => $localActorUrl,
                'object' => $followActivity
            ];

            \KutSocial\Queue::enqueue('Accept', $acceptActivity, $follower['inbox_url']);
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json([]);
    }

    public static function rejectFollowRequest(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $followerId = (int)$params['id'];
        $db = Database::connect();

        $stmtDelete = $db->prepare("DELETE FROM follows WHERE account_id = ? AND target_account_id = ? AND status = 'pending'");
        $stmtDelete->execute([$followerId, $account['id']]);

        $stmtFollower = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmtFollower->execute([$followerId]);
        $follower = $stmtFollower->fetch();

        if ($follower && !empty($follower['domain']) && !empty($follower['inbox_url'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$account['username']}";

            $followActivity = [
                'type' => 'Follow',
                'actor' => "https://{$follower['domain']}/users/{$follower['username']}",
                'object' => $localActorUrl
            ];

            $rejectActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActorUrl . '/activities/reject-' . bin2hex(random_bytes(8)),
                'type' => 'Reject',
                'actor' => $localActorUrl,
                'object' => $followActivity
            ];

            \KutSocial\Queue::enqueue('Reject', $rejectActivity, $follower['inbox_url']);
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json([]);
    }

    // ==========================================
    // --- LISTAS (LISTS) ---
    // ==========================================

    public static function getLists(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM lists WHERE account_id = ? ORDER BY id DESC");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();
        $lists = [];
        foreach ($rows as $row) {
            $lists[] = [
                'id' => (string)$row['id'],
                'title' => $row['title'],
                'replies_policy' => $row['replies_policy']
            ];
        }
        Router::json($lists);
    }

    public static function createList(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $body = Router::getRequestBody();
        $title = trim($body['title'] ?? $_POST['title'] ?? '');
        $repliesPolicy = trim($body['replies_policy'] ?? $_POST['replies_policy'] ?? 'followed');
        if (empty($title)) {
            Router::json(['error' => 'El título es requerido'], 400);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO lists (account_id, title, replies_policy) VALUES (?, ?, ?)");
        $stmt->execute([$account['id'], $title, $repliesPolicy]);
        $id = $db->lastInsertId();
        Router::json([
            'id' => (string)$id,
            'title' => $title,
            'replies_policy' => $repliesPolicy
        ]);
    }

    public static function getList(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM lists WHERE id = ? AND account_id = ? LIMIT 1");
        $stmt->execute([$listId, $account['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            Router::json(['error' => 'Lista no encontrada'], 404);
            return;
        }
        Router::json([
            'id' => (string)$row['id'],
            'title' => $row['title'],
            'replies_policy' => $row['replies_policy']
        ]);
    }

    public static function updateList(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $body = Router::getRequestBody();
        $title = trim($body['title'] ?? $_POST['title'] ?? '');
        $repliesPolicy = trim($body['replies_policy'] ?? $_POST['replies_policy'] ?? '');
        
        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT * FROM lists WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$listId, $account['id']]);
        $row = $stmtCheck->fetch();
        if (!$row) {
            Router::json(['error' => 'Lista no encontrada'], 404);
            return;
        }

        $newTitle = !empty($title) ? $title : $row['title'];
        $newPolicy = !empty($repliesPolicy) ? $repliesPolicy : $row['replies_policy'];

        $stmtUpdate = $db->prepare("UPDATE lists SET title = ?, replies_policy = ? WHERE id = ?");
        $stmtUpdate->execute([$newTitle, $newPolicy, $listId]);

        Router::json([
            'id' => (string)$listId,
            'title' => $newTitle,
            'replies_policy' => $newPolicy
        ]);
    }

    public static function deleteList(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM lists WHERE id = ? AND account_id = ?");
        $stmt->execute([$listId, $account['id']]);
        Router::json((object)[]);
    }

    public static function getListAccounts(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT id FROM lists WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$listId, $account['id']]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Lista no encontrada'], 404);
            return;
        }

        $stmtAccs = $db->prepare("
            SELECT a.* FROM accounts a
            JOIN list_accounts la ON a.id = la.account_id
            WHERE la.list_id = ?
        ");
        $stmtAccs->execute([$listId]);
        $rows = $stmtAccs->fetchAll();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = self::formatAccount($row);
        }
        Router::json($accounts);
    }

    public static function addAccountsToList(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $body = Router::getRequestBody();
        $accountIds = $body['account_ids'] ?? $_POST['account_ids'] ?? [];
        if (!is_array($accountIds)) {
            $accountIds = [$accountIds];
        }

        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT id FROM lists WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$listId, $account['id']]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Lista no encontrada'], 404);
            return;
        }

        $stmtInsert = $db->prepare("INSERT OR IGNORE INTO list_accounts (list_id, account_id) VALUES (?, ?)");
        foreach ($accountIds as $accId) {
            $stmtInsert->execute([$listId, (int)$accId]);
        }
        Router::json((object)[]);
    }

    public static function removeAccountsFromList(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $body = Router::getRequestBody();
        $accountIds = $body['account_ids'] ?? $_GET['account_ids'] ?? $_POST['account_ids'] ?? [];
        if (!is_array($accountIds)) {
            $accountIds = [$accountIds];
        }

        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT id FROM lists WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$listId, $account['id']]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Lista no encontrada'], 404);
            return;
        }

        $stmtDelete = $db->prepare("DELETE FROM list_accounts WHERE list_id = ? AND account_id = ?");
        foreach ($accountIds as $accId) {
            $stmtDelete->execute([$listId, (int)$accId]);
        }
        Router::json((object)[]);
    }

    public static function getListTimeline(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $listId = (int)$params['id'];
        $db = Database::connect();

        $stmtCheck = $db->prepare("SELECT id FROM lists WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$listId, $account['id']]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Lista no encontrada'], 404);
            return;
        }

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;

        $whereClauses = [
            "s.visibility IN ('public', 'unlisted', 'private')",
            "s.account_id IN (SELECT account_id FROM list_accounts WHERE list_id = ?)"
        ];
        $queryParams = [$listId];

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
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE $whereSql
            ORDER BY s.id DESC
            LIMIT ?
        ");
        
        $queryParams[] = $limit;
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll();

        $rows = self::filterStatuses($rows);

        $statuses = [];
        foreach ($rows as $s) {
            $statuses[] = self::formatStatus($s, (int)$account['id']);
        }

        Router::json($statuses);
    }

    // ==========================================
    // --- COLECCIONES (COLLECTIONS) ---
    // ==========================================

    public static function createCollection(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $body = Router::getRequestBody();
        $title = trim($body['title'] ?? $_POST['title'] ?? $body['name'] ?? $_POST['name'] ?? '');
        $description = trim($body['description'] ?? $_POST['description'] ?? '');
        if (empty($title)) {
            Router::json(['error' => 'El título es requerido'], 400);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO collections (account_id, title, description) VALUES (?, ?, ?)");
        $stmt->execute([$account['id'], $title, $description]);
        $id = $db->lastInsertId();
        Router::json([
            'id' => (string)$id,
            'title' => $title,
            'name' => $title,
            'description' => $description,
            'items' => []
        ]);
    }

    public static function getCollection(array $params): void {
        $collectionId = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM collections WHERE id = ? LIMIT 1");
        $stmt->execute([$collectionId]);
        $row = $stmt->fetch();
        if (!$row) {
            Router::json(['error' => 'Colección no encontrada'], 404);
            return;
        }

        $stmtAccs = $db->prepare("
            SELECT a.* FROM accounts a
            JOIN collection_accounts ca ON a.id = ca.account_id
            WHERE ca.collection_id = ?
        ");
        $stmtAccs->execute([$collectionId]);
        $accRows = $stmtAccs->fetchAll();
        $items = [];
        foreach ($accRows as $accRow) {
            $items[] = self::formatAccount($accRow);
        }

        Router::json([
            'id' => (string)$row['id'],
            'title' => $row['title'],
            'name' => $row['title'],
            'description' => $row['description'],
            'items' => $items
        ]);
    }

    public static function updateCollection(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $collectionId = (int)$params['id'];
        $body = Router::getRequestBody();
        $title = trim($body['title'] ?? $_POST['title'] ?? $body['name'] ?? $_POST['name'] ?? '');
        $description = trim($body['description'] ?? $_POST['description'] ?? '');

        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT * FROM collections WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$collectionId, $account['id']]);
        $row = $stmtCheck->fetch();
        if (!$row) {
            Router::json(['error' => 'Colección no encontrada o no autorizada'], 404);
            return;
        }

        $newTitle = !empty($title) ? $title : $row['title'];
        $newDescription = !empty($description) ? $description : $row['description'];

        $stmtUpdate = $db->prepare("UPDATE collections SET title = ?, description = ? WHERE id = ?");
        $stmtUpdate->execute([$newTitle, $newDescription, $collectionId]);

        Router::json([
            'id' => (string)$collectionId,
            'title' => $newTitle,
            'name' => $newTitle,
            'description' => $newDescription
        ]);
    }

    public static function deleteCollection(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $collectionId = (int)$params['id'];
        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM collections WHERE id = ? AND account_id = ?");
        $stmt->execute([$collectionId, $account['id']]);
        Router::json((object)[]);
    }

    public static function addAccountsToCollection(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $collectionId = (int)$params['id'];
        $body = Router::getRequestBody();
        $accountIds = $body['account_ids'] ?? $_POST['account_ids'] ?? [];
        if (!is_array($accountIds)) {
            $accountIds = [$accountIds];
        }

        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT id FROM collections WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$collectionId, $account['id']]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Colección no encontrada'], 404);
            return;
        }

        $stmtInsert = $db->prepare("INSERT OR IGNORE INTO collection_accounts (collection_id, account_id) VALUES (?, ?)");
        foreach ($accountIds as $accId) {
            $stmtInsert->execute([$collectionId, (int)$accId]);
        }
        Router::json((object)[]);
    }

    public static function removeAccountsFromCollection(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $collectionId = (int)$params['id'];
        $body = Router::getRequestBody();
        $accountIds = $body['account_ids'] ?? $_GET['account_ids'] ?? $_POST['account_ids'] ?? [];
        if (!is_array($accountIds)) {
            $accountIds = [$accountIds];
        }

        $db = Database::connect();
        $stmtCheck = $db->prepare("SELECT id FROM collections WHERE id = ? AND account_id = ? LIMIT 1");
        $stmtCheck->execute([$collectionId, $account['id']]);
        if (!$stmtCheck->fetchColumn()) {
            Router::json(['error' => 'Colección no encontrada'], 404);
            return;
        }

        $stmtDelete = $db->prepare("DELETE FROM collection_accounts WHERE collection_id = ? AND account_id = ?");
        foreach ($accountIds as $accId) {
            $stmtDelete->execute([$collectionId, (int)$accId]);
        }
        Router::json((object)[]);
    }

    // ==========================================
    // --- HASHTAGS SEGUIDOS (FOLLOWED TAGS) ---
    // ==========================================

    public static function getFollowedTags(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $db = Database::connect();
        $stmt = $db->prepare("SELECT hashtag FROM followed_hashtags WHERE account_id = ? ORDER BY id DESC");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $tags = [];
        foreach ($rows as $row) {
            $h = $row['hashtag'];
            $tags[] = [
                'name' => $h,
                'url' => "$proto://$domain/tags/$h",
                'history' => [],
                'following' => true
            ];
        }
        Router::json($tags);
    }

    public static function followTag(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $tagName = ltrim(trim($params['name']), '#');
        if (empty($tagName)) {
            Router::json(['error' => 'Hashtag inválido'], 400);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("INSERT OR IGNORE INTO followed_hashtags (account_id, hashtag) VALUES (?, ?)");
        $stmt->execute([$account['id'], $tagName]);

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        Router::json([
            'name' => $tagName,
            'url' => "$proto://$domain/tags/$tagName",
            'history' => [],
            'following' => true
        ]);
    }

    public static function unfollowTag(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }
        $tagName = ltrim(trim($params['name']), '#');
        if (empty($tagName)) {
            Router::json(['error' => 'Hashtag inválido'], 400);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM followed_hashtags WHERE account_id = ? AND hashtag = ?");
        $stmt->execute([$account['id'], $tagName]);

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        Router::json([
            'name' => $tagName,
            'url' => "$proto://$domain/tags/$tagName",
            'history' => [],
            'following' => false
        ]);
    }

    public static function getTagTimeline(array $params): void {
        $account = null;
        $currUserId = null;
        try {
            $account = self::getAuthenticatedAccount();
            if ($account) {
                $currUserId = (int)$account['id'];
            }
        } catch (\Exception $e) {
            // No autenticado
        }

        $tagName = ltrim(trim($params['name']), '#');
        $db = Database::connect();

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) $limit = 30;
        if ($limit > 80) $limit = 80;

        $whereClauses = [
            "s.visibility IN ('public', 'unlisted')",
            "(s.content LIKE ? OR s.content LIKE ?)"
        ];
        $queryParams = ['%#' . $tagName . '%', '%/tags/' . $tagName . '%'];

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
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            WHERE $whereSql
            ORDER BY s.id DESC
            LIMIT ?
        ");

        $queryParams[] = $limit;
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll();

        $rows = self::filterStatuses($rows);

        $statuses = [];
        foreach ($rows as $s) {
            $statuses[] = self::formatStatus($s, $currUserId);
        }

        Router::json($statuses);
    }

    public static function getFavourites(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields
            FROM favourites f
            JOIN statuses s ON f.status_id = s.id
            JOIN accounts a ON s.account_id = a.id
            WHERE f.account_id = ?
            ORDER BY f.id DESC
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[] = self::formatStatus($row, $account['id']);
        }

        Router::json($statuses);
    }

    public static function proxyMedia(): void {
        $url = $_GET['url'] ?? '';
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            header("HTTP/1.1 400 Bad Request");
            echo "URL inválida o ausente.";
            return;
        }

        $cacheDir = dirname(Database::getDbPath()) . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $urlHash = md5($url);
        $cachePath = $cacheDir . '/' . $urlHash;
        $metaPath = $cachePath . '.meta';

        $cachedMeta = [];
        if (file_exists($metaPath)) {
            $cachedMeta = json_decode(file_get_contents($metaPath), true) ?: [];
        }

        $etag = $cachedMeta['etag'] ?? null;
        $lastModified = $cachedMeta['last_modified'] ?? null;
        $contentType = $cachedMeta['content_type'] ?? 'image/jpeg';
        $lastVerified = $cachedMeta['last_verified'] ?? 0;

        $hasCache = file_exists($cachePath);
        
        // Cachear verificación durante 1 hora (3600 segundos) para optimización extrema
        if ($hasCache && (time() - $lastVerified < 3600)) {
            header("Content-Type: " . $contentType);
            header("Cache-Control: public, max-age=86400");
            readfile($cachePath);
            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KutSocialImageProxy/1.0');

        $headers = [];
        if ($hasCache) {
            if ($etag) {
                $headers[] = "If-None-Match: " . $etag;
            }
            if ($lastModified) {
                $headers[] = "If-Modified-Since: " . $lastModified;
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($hasCache && ($httpCode == 304 || $response === false)) {
            $cachedMeta['last_verified'] = time();
            file_put_contents($metaPath, json_encode($cachedMeta));

            header("Content-Type: " . $contentType);
            header("Cache-Control: public, max-age=86400");
            readfile($cachePath);
            return;
        }

        if ($httpCode == 200 && $response !== false) {
            $headerText = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            $newEtag = null;
            $newLastMod = null;
            $newType = 'image/jpeg';

            $lines = explode("\r\n", $headerText);
            foreach ($lines as $line) {
                if (stripos($line, 'ETag:') === 0) {
                    $newEtag = trim(substr($line, 5));
                } elseif (stripos($line, 'Last-Modified:') === 0) {
                    $newLastMod = trim(substr($line, 14));
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $newType = trim(substr($line, 13));
                }
            }

            file_put_contents($cachePath, $body);
            file_put_contents($metaPath, json_encode([
                'etag' => $newEtag,
                'last_modified' => $newLastMod,
                'content_type' => $newType,
                'last_verified' => time()
            ]));

            header("Content-Type: " . $newType);
            header("Cache-Control: public, max-age=86400");
            echo $body;
            return;
        }

        if ($hasCache) {
            header("Content-Type: " . $contentType);
            header("Cache-Control: public, max-age=86400");
            readfile($cachePath);
        } else {
            header("Location: " . $url);
        }
    }

    public static function reblogStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();
        
        // Verificar que el status existe
        $stmt = $db->prepare("SELECT id, visibility FROM statuses WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $orig = $stmt->fetch();
        if (!$orig) {
            Router::json(['error' => 'Status not found'], 404);
            return;
        }

        // Verificar si ya ha sido reblogueado por el usuario
        $stmtCheck = $db->prepare("SELECT id FROM statuses WHERE account_id = ? AND reblog_of_id = ? AND content = '' LIMIT 1");
        $stmtCheck->execute([$account['id'], $id]);
        $existing = $stmtCheck->fetchColumn();
        
        $newId = $existing;
        if (!$existing) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $statusIdStr = bin2hex(random_bytes(8));
            $uri = "$proto://$domain/users/{$account['username']}/statuses/$statusIdStr";

            $stmtIns = $db->prepare("
                INSERT INTO statuses (account_id, uri, content, visibility, reblog_of_id, created_at)
                VALUES (?, ?, '', ?, ?, datetime('now'))
            ");
            $stmtIns->execute([$account['id'], $uri, $orig['visibility'], $id]);
            $newId = $db->lastInsertId();

            \KutSocial\NotificationHelper::notifyReblog((int)$id, (int)$account['id']);
        }

        $row = self::fetchStatusRow($db, (int)$newId);
        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    public static function unreblogStatus(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = (int)$params['id'];
        $db = Database::connect();

        // Eliminar el reblog (retoot simple o cita)
        $stmtDel = $db->prepare("DELETE FROM statuses WHERE account_id = ? AND reblog_of_id = ?");
        $stmtDel->execute([$account['id'], $id]);

        // Retornar el status original formateado
        $row = self::fetchStatusRow($db, $id);
        if (!$row) {
            Router::json(['error' => 'Status not found'], 404);
            return;
        }
        Router::json(self::formatStatus($row, (int)$account['id']));
    }

    public static function resolveStatusUrl(): void {
        $url = $_GET['url'] ?? '';
        if (empty($url)) {
            Router::json(['error' => 'URL vacía'], 400);
            return;
        }

        $db = Database::connect();
        $statusId = \KutSocial\Controllers\ActivityPubController::fetchAndRegisterRemoteStatus($url);
        if (!$statusId) {
            Router::json(['error' => 'No se pudo resolver la publicación remota.'], 404);
            return;
        }

        $row = self::fetchStatusRow($db, $statusId);
        if (!$row) {
            Router::json(['error' => 'No se pudo encontrar en base de datos.'], 404);
            return;
        }

        $account = null;
        $currUserId = null;
        try {
            $account = self::getAuthenticatedAccount();
            if ($account) {
                $currUserId = (int)$account['id'];
            }
        } catch (\Exception $e) {}

        Router::json(self::formatStatus($row, $currUserId));
    }

    /**
     * Endpoint GET /api/v1/instance/peers
     */
    public static function getPeers(): void {
        $db = Database::connect();
        $stmt = $db->query("SELECT DISTINCT domain FROM accounts WHERE domain IS NOT NULL");
        $peers = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        Router::json($peers);
    }

    /**
     * Endpoint POST /api/v1/push/subscription
     */
    public static function createPushSubscription(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $body = Router::getRequestBody();
        $subscription = $body['subscription'] ?? null;
        if (!$subscription || empty($subscription['endpoint'])) {
            Router::json(['error' => 'Missing subscription data'], 400);
            return;
        }

        $endpoint = $subscription['endpoint'];
        $keys = $subscription['keys'] ?? [];
        $p256dh = $keys['p256dh'] ?? '';
        $auth = $keys['auth'] ?? '';

        // Extract alerts
        $data = $body['data'] ?? [];
        $alerts = $data['alerts'] ?? [
            'mention' => true,
            'status' => true,
            'reblog' => true,
            'follow' => true,
            'follow_request' => true,
            'favourite' => true,
            'poll' => true,
            'update' => true,
            'admin.sign_up' => false,
            'admin.report' => false
        ];
        $alertsJson = json_encode($alerts);

        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO web_push_subscriptions (account_id, endpoint, key_p256dh, key_auth, alerts)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$account['id'], $endpoint, $p256dh, $auth, $alertsJson]);
        $subId = $db->lastInsertId();

        $vapid = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublic = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapid['vapid_public_key']);
        $serverKey = \KutSocial\WebPushHelper::base64url_encode($vapidPublic);

        Router::json([
            'id' => (string)$subId,
            'endpoint' => $endpoint,
            'alerts' => $alerts,
            'server_key' => $serverKey
        ]);
    }

    /**
     * Endpoint GET /api/v1/push/subscription
     */
    public static function getPushSubscription(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM web_push_subscriptions WHERE account_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$account['id']]);
        $sub = $stmt->fetch();

        if (!$sub) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $vapid = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublic = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapid['vapid_public_key']);
        $serverKey = \KutSocial\WebPushHelper::base64url_encode($vapidPublic);

        Router::json([
            'id' => (string)$sub['id'],
            'endpoint' => $sub['endpoint'],
            'alerts' => json_decode($sub['alerts'], true),
            'server_key' => $serverKey
        ]);
    }

    /**
     * Endpoint PUT /api/v1/push/subscription
     */
    public static function updatePushSubscription(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $body = Router::getRequestBody();
        $data = $body['data'] ?? [];
        $alerts = $data['alerts'] ?? null;
        if (!$alerts) {
            Router::json(['error' => 'Missing alerts data'], 400);
            return;
        }
        $alertsJson = json_encode($alerts);

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM web_push_subscriptions WHERE account_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$account['id']]);
        $sub = $stmt->fetch();

        if (!$sub) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $stmtUpdate = $db->prepare("UPDATE web_push_subscriptions SET alerts = ? WHERE id = ?");
        $stmtUpdate->execute([$alertsJson, $sub['id']]);

        $vapid = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublic = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapid['vapid_public_key']);
        $serverKey = \KutSocial\WebPushHelper::base64url_encode($vapidPublic);

        Router::json([
            'id' => (string)$sub['id'],
            'endpoint' => $sub['endpoint'],
            'alerts' => $alerts,
            'server_key' => $serverKey
        ]);
    }

    /**
     * Endpoint DELETE /api/v1/push/subscription
     */
    public static function deletePushSubscription(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $body = Router::getRequestBody();
        $endpoint = $body['endpoint'] ?? $_POST['endpoint'] ?? $_GET['endpoint'] ?? '';

        $db = Database::connect();
        if ($endpoint) {
            $stmt = $db->prepare("DELETE FROM web_push_subscriptions WHERE account_id = ? AND endpoint = ?");
            $stmt->execute([$account['id'], $endpoint]);
        } else {
            $stmt = $db->prepare("DELETE FROM web_push_subscriptions WHERE account_id = ?");
            $stmt->execute([$account['id']]);
        }

        Router::json([]);
    }
}

