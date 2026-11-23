<?php
namespace KutSocial\Controllers;

use KutSocial\Database;
use KutSocial\Router;
use PDO;
use Exception;

class MastodonApiController {
    private static array $statusCtx = [
        'viewer_id' => null,
        'fav_counts' => [],
        'reply_counts' => [],
        'reblog_counts' => [],
        'favourited' => [],
        'bookmarked' => [],
        'reblogged' => [],
        'polls' => [],
        'poll_votes' => [],
        'user_poll_votes' => [],
        'show_source' => [],
        'in_reply_to_accounts' => [],
        'cards' => [],
        'reblog_of_ids' => [],
        'account_stats' => [],
    ];
    private static ?array $memoModerationBlocks = null;
    private static array $memoExclusiveTargets = [];
    private static array $memoUserMutes = [];
    private static array $memoUserBlocks = [];

    /**
     * Endpoint GET /api/v1/instance
     */
    public static function getInstance(): void {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT value FROM options WHERE options.key = 'instance_title' LIMIT 1");
        $stmt->execute();
        $title = $stmt->fetchColumn() ?: 'KutSocial';

        $stmtLimit = $db->prepare("SELECT value FROM options WHERE options.key = 'max_toot_chars' LIMIT 1");
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
                'user_count' => self::getTableRowCount('accounts', "(domain IS NULL OR domain = '') AND password_hash IS NOT NULL AND password_hash != ''"),
                'status_count' => self::getTableRowCount('statuses', "account_id IN (SELECT id FROM accounts WHERE domain IS NULL OR domain = '')"),
                'domain_count' => self::getTableRowCount('accounts', "domain IS NOT NULL AND domain != ''")
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
        $stmt = $db->prepare("SELECT value FROM options WHERE options.key = 'instance_title' LIMIT 1");
        $stmt->execute();
        $title = $stmt->fetchColumn() ?: 'KutSocial';

        $stmtLimit = $db->prepare("SELECT value FROM options WHERE options.key = 'max_toot_chars' LIMIT 1");
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
                    'active_month' => self::getTableRowCount('accounts', "(domain IS NULL OR domain = '') AND password_hash IS NOT NULL AND password_hash != ''")
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
        
        $userCount = self::getTableRowCount('accounts', "(domain IS NULL OR domain = '') AND password_hash IS NOT NULL AND password_hash != ''");
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
        $clientName = trim((string)($body['client_name'] ?? 'KutSocial Client'));
        $redirectUris = trim((string)($body['redirect_uris'] ?? 'urn:ietf:wg:oauth:2.0:oob'));
        $scopes = trim((string)($body['scopes'] ?? 'read write follow push'));
        $website = isset($body['website']) ? trim((string)$body['website']) : null;

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        $clientSecretHash = hash('sha256', $clientSecret);

        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO oauth_apps (client_name, client_id, client_secret_hash, redirect_uris, scopes, website) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$clientName, $clientId, $clientSecretHash, $redirectUris, $scopes, $website]);
        $appId = (string)Database::lastInsertId($db, 'oauth_apps');

        $vapidKeys = \KutSocial\WebPushHelper::getVapidKeys();
        $vapidPublicPoint = \KutSocial\WebPushHelper::pemToUncompressedEcPoint($vapidKeys['vapid_public_key']);
        $vapidPublicKeyBase64 = \KutSocial\WebPushHelper::base64url_encode($vapidPublicPoint);

        Router::json([
            'id' => $appId,
            'name' => $clientName,
            'website' => $website,
            'redirect_uri' => $redirectUris,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'vapid_key' => $vapidPublicKeyBase64
        ]);
    }

    private static function isRedirectUriAllowed(string $registeredUris, string $requestedUri): bool {
        if ($requestedUri === '') {
            return false;
        }
        $allowed = preg_split('/[\s,]+/', trim($registeredUris)) ?: [];
        return in_array($requestedUri, $allowed, true);
    }

    private static function generateCodeForUser(int $userId, string $clientId, string $redirectUri, string $scopes = 'read write follow push'): string {
        $code = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $code);
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO oauth_codes (code_hash, client_id, account_id, redirect_uri, scopes, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$codeHash, $clientId, $userId, $redirectUri, $scopes, time() + 600]);
        return $code;
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
        $loginId = $username;
        if (str_starts_with($loginId, '@')) {
            $loginId = explode('@', ltrim($loginId, '@'))[0];
        }
        $stmt = $db->prepare("SELECT * FROM accounts WHERE (LOWER(username) = LOWER(?) OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(?))) AND (domain IS NULL OR domain = '') LIMIT 1");
        $stmt->execute([$loginId, $loginId]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            \KutSocial\Controllers\ActivityPubController::log("handleAuthorize: password verify failed for username=$username");
            $eClientId = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');
            $eRedirectUri = htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8');
            $eResponseType = htmlspecialchars($responseType, ENT_QUOTES, 'UTF-8');
            $eScope = htmlspecialchars($scope, ENT_QUOTES, 'UTF-8');
            $eUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
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
            <input type="hidden" name="client_id" value="{$eClientId}">
            <input type="hidden" name="redirect_uri" value="{$eRedirectUri}">
            <input type="hidden" name="response_type" value="{$eResponseType}">
            <input type="hidden" name="scope" value="{$eScope}">
            
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required value="{$eUsername}">
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
            \KutSocial\Controllers\ActivityPubController::debugLog("handleAuthorize: 2FA is active");
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

        $db = Database::connect();
        $appStmt = $db->prepare("SELECT * FROM oauth_apps WHERE client_id = ? LIMIT 1");
        $appStmt->execute([$clientId]);
        $app = $appStmt->fetch();
        if (!$app || !self::isRedirectUriAllowed((string)$app['redirect_uris'], $redirectUri)) {
            Router::json(['error' => 'invalid_client', 'error_description' => 'Aplicación OAuth o redirect_uri inválidos'], 400);
        }

        $requestedScope = trim((string)($_POST['scope'] ?? $app['scopes'] ?? 'read write follow push'));
        $code = self::generateCodeForUser((int)$account['id'], $clientId, $redirectUri, $requestedScope);
        \KutSocial\Controllers\ActivityPubController::debugLog("handleAuthorize: auth code generated");

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
            \KutSocial\Controllers\ActivityPubController::debugLog("handleAuthorize: redirecting browser");
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
        $clientId = trim((string)($body['client_id'] ?? ''));
        $clientSecret = (string)($body['client_secret'] ?? '');

        \KutSocial\Controllers\ActivityPubController::debugLog("postToken: grant_type=" . $grantType);

        $db = Database::connect();
        $requestedScope = trim((string)($body['scope'] ?? 'read write follow push'));

        if ($grantType === 'authorization_code') {
            $code = (string)($body['code'] ?? '');
            $redirectUri = trim((string)($body['redirect_uri'] ?? ''));

            if ($clientId === '' || $clientSecret === '' || $code === '') {
                Router::json(['error' => 'invalid_request', 'error_description' => 'Faltan parámetros OAuth obligatorios'], 400);
            }

            $appStmt = $db->prepare("SELECT * FROM oauth_apps WHERE client_id = ? LIMIT 1");
            $appStmt->execute([$clientId]);
            $app = $appStmt->fetch();
            if (!$app || !hash_equals((string)$app['client_secret_hash'], hash('sha256', $clientSecret))) {
                Router::json(['error' => 'invalid_client', 'error_description' => 'Credenciales de aplicación inválidas'], 401);
            }

            $codeHash = hash('sha256', $code);
            $codeStmt = $db->prepare("SELECT * FROM oauth_codes WHERE code_hash = ? AND client_id = ? AND used_at IS NULL AND expires_at >= ? LIMIT 1");
            $codeStmt->execute([$codeHash, $clientId, time()]);
            $codeRow = $codeStmt->fetch();

            if (!$codeRow || ($redirectUri !== '' && $codeRow['redirect_uri'] !== $redirectUri)) {
                Router::json(['error' => 'invalid_grant', 'error_description' => 'Código de autorización inválido, usado o expirado'], 400);
            }

            // Marcar código como usado (un solo uso)
            $updCode = $db->prepare("UPDATE oauth_codes SET used_at = ? WHERE id = ? AND used_at IS NULL");
            $updCode->execute([time(), $codeRow['id']]);
            if ($updCode->rowCount() === 0) {
                Router::json(['error' => 'invalid_grant', 'error_description' => 'Código de autorización ya utilizado'], 400);
            }

            $userId = (int)$codeRow['account_id'];
            $requestedScope = $codeRow['scopes'] ?: $requestedScope;
            \KutSocial\Controllers\ActivityPubController::debugLog("postToken: authorization_code resolved userId=" . $userId);

            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $account = $stmt->fetch();

            if (!$account) {
                Router::json(['error' => 'invalid_grant', 'error_description' => 'Usuario no encontrado'], 400);
            }
        } elseif ($grantType === 'password') {
            $username = $body['username'] ?? '';
            $password = $body['password'] ?? '';

            if ($clientId !== '') {
                $appStmt = $db->prepare("SELECT * FROM oauth_apps WHERE client_id = ? LIMIT 1");
                $appStmt->execute([$clientId]);
                $app = $appStmt->fetch();
                if (!$app || ($clientSecret !== '' && !hash_equals((string)$app['client_secret_hash'], hash('sha256', $clientSecret)))) {
                    Router::json(['error' => 'invalid_client', 'error_description' => 'Credenciales de aplicación inválidas'], 401);
                }
            }

            $loginId = trim((string)$username);
            if (str_starts_with($loginId, '@')) {
                $loginId = explode('@', ltrim($loginId, '@'))[0];
            }
            $stmt = $db->prepare("SELECT * FROM accounts WHERE (LOWER(username) = LOWER(?) OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(?))) AND (domain IS NULL OR domain = '') LIMIT 1");
            $stmt->execute([$loginId, $loginId]);
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

        // Generar token opaco aleatorio y persistirlo con hash y expiración en DB
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = time();
        $expiresAt = $now + (90 * 86400);

        $insTok = $db->prepare("INSERT INTO oauth_tokens (token_hash, client_id, account_id, scopes, expires_at) VALUES (?, ?, ?, ?, ?)");
        $insTok->execute([$tokenHash, $clientId ?: null, (int)$account['id'], $requestedScope, $expiresAt]);

        if ($grantType === 'password' && $clientId === '') {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                \KutSocial\Controllers\AdminController::startSecureSession();
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['kutsocial_user_id'] = (int)$account['id'];
                $_SESSION['kutsocial_auto_token'] = $token;
                if (in_array($account['role'] ?? '', ['admin', 'owner'], true)) {
                    $_SESSION['admin_account_id'] = (int)$account['id'];
                }
            }
        }

        Router::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'scope' => $requestedScope,
            'created_at' => $now
        ]);
    }

    /**
     * Endpoint POST /oauth/revoke
     */
    public static function revokeToken(): void {
        $body = Router::getRequestBody();
        $token = trim((string)($body['token'] ?? ''));
        if ($token !== '') {
            $db = Database::connect();
            $stmt = $db->prepare("UPDATE oauth_tokens SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL");
            $stmt->execute([time(), hash('sha256', $token)]);
        }
        Router::json(new \stdClass(), 200);
    }

    /**
     * Endpoint GET /api/v1/accounts/verify_credentials
     */
    public static function verifyCredentials(): void {
        \KutSocial\Controllers\ActivityPubController::debugLog("verifyCredentials: request received");
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
            $fieldsToUpdate[] = "updated_at = ?";
            $params[] = Database::nowUtc();
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
        $statusRowCache = [];

        $dismissedMap = [];
        try {
            $stmtDismissed = $db->prepare("SELECT notification_id FROM dismissed_notifications WHERE account_id = ?");
            $stmtDismissed->execute([$currUserId]);
            foreach ($stmtDismissed->fetchAll(\PDO::FETCH_COLUMN) as $dnId) {
                $dismissedMap[(string)$dnId] = true;
            }
        } catch (\Throwable $e) {}

        // 1. Obtener notificaciones de Seguimientos (Follows)
        $stmtFollows = $db->prepare("
            SELECT f.id, f.created_at, 
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields
            FROM follows f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.target_account_id = ?
            ORDER BY f.id DESC LIMIT 40
        ");
        $stmtFollows->execute([$currUserId]);
        $followRows = $stmtFollows->fetchAll();
        foreach ($followRows as $row) {
            $nid = 'follow_' . $row['id'];
            if (isset($dismissedMap[$nid])) continue;
            $notifications[] = [
                'id' => $nid,
                'type' => 'follow',
                'created_at' => date('c', strtotime($row['created_at'])),
                'account' => self::formatAccount($row)
            ];
        }

        // 2. Obtener notificaciones de Favoritos (Favourites)
        $stmtFavs = $db->prepare("
            SELECT fav.id, fav.created_at, fav.status_id,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields
            FROM favourites fav
            JOIN accounts a ON fav.account_id = a.id
            JOIN statuses s ON fav.status_id = s.id
            WHERE s.account_id = ?
            ORDER BY fav.id DESC LIMIT 40
        ");
        $stmtFavs->execute([$currUserId]);
        $favRows = $stmtFavs->fetchAll();

        // 3. Obtener notificaciones de Menciones y Respuestas (Mentions) usando índices
        $mentionSelect = "
            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                   s.visibility as status_visibility, s.created_at as status_created_at, 
                   s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
        ";

        // 3a. Respuestas directas a mis posts (usa índice en in_reply_to_id + account_id)
        $stmtReplies = $db->prepare($mentionSelect . "
            JOIN statuses p ON s.in_reply_to_id = p.id
            WHERE p.account_id = ? AND s.account_id != ?
            ORDER BY s.id DESC LIMIT 40
        ");
        $stmtReplies->execute([$currUserId, $currUserId]);
        $replyRows = $stmtReplies->fetchAll();

        // 3b. Menciones en texto (usa índice GIN en pgsql, FULLTEXT en mysql, LIKE en sqlite)
        $driver = Database::getDriver();
        if ($driver === 'pgsql') {
            $stmtTextMentions = $db->prepare($mentionSelect . "
                WHERE s.account_id != ?
                  AND to_tsvector('simple', s.content) @@ plainto_tsquery('simple', ?)
                ORDER BY s.id DESC LIMIT 40
            ");
            $stmtTextMentions->execute([$currUserId, $account['username']]);
        } elseif ($driver === 'mysql') {
            $stmtTextMentions = $db->prepare($mentionSelect . "
                WHERE s.account_id != ?
                  AND MATCH(s.content) AGAINST(? IN BOOLEAN MODE)
                ORDER BY s.id DESC LIMIT 40
            ");
            $stmtTextMentions->execute([$currUserId, '+' . $account['username']]);
        } else {
            $stmtTextMentions = $db->prepare($mentionSelect . "
                WHERE s.account_id != ?
                  AND (s.content LIKE ? OR s.content LIKE ?)
                ORDER BY s.id DESC LIMIT 40
            ");
            $stmtTextMentions->execute([$currUserId, '%@' . $account['username'] . '%', '%/users/' . $account['username'] . '%']);
        }
        $textMentionRows = $stmtTextMentions->fetchAll();

        $mentionRowsById = [];
        foreach ($replyRows as $r) {
            $r['is_reply_to_me'] = 1;
            $mentionRowsById[(int)$r['status_id']] = $r;
        }
        foreach ($textMentionRows as $r) {
            $sid = (int)$r['status_id'];
            if (!isset($mentionRowsById[$sid])) {
                $r['is_reply_to_me'] = 0;
                $mentionRowsById[$sid] = $r;
            }
        }
        krsort($mentionRowsById);
        $mentionRows = array_slice(array_values($mentionRowsById), 0, 40);

        // 4. Obtener notificaciones de Re-toots (Reblogs)
        $stmtReblogs = $db->prepare("
            SELECT s.id as status_id, s.created_at as status_created_at,
                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                   a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                   a.locked, a.discoverable, a.fields,
                   orig.id as orig_status_id
            FROM statuses s
            JOIN accounts a ON s.account_id = a.id
            JOIN statuses orig ON s.reblog_of_id = orig.id
            WHERE orig.account_id = ?
              AND s.content = ''
            ORDER BY s.id DESC LIMIT 40
        ");
        $stmtReblogs->execute([$currUserId]);
        $reblogRows = $stmtReblogs->fetchAll();

        // Precargar en lote todos los estados referenciados por favoritos y reblogs
        $neededStatusIds = [];
        foreach ($favRows as $row) {
            if (!isset($dismissedMap['favourite_' . $row['id']])) {
                $neededStatusIds[(int)$row['status_id']] = true;
            }
        }
        foreach ($reblogRows as $row) {
            if (!isset($dismissedMap['reblog_' . $row['status_id']])) {
                $neededStatusIds[(int)$row['orig_status_id']] = true;
            }
        }
        if (!empty($neededStatusIds)) {
            $idList = array_keys($neededStatusIds);
            $ph = implode(',', array_fill(0, count($idList), '?'));
            $stmtBatchStatuses = $db->prepare("
                SELECT s.id as status_id, s.uri as status_uri, s.content as status_content,
                       s.visibility as status_visibility, s.created_at as status_created_at,
                       s.in_reply_to_id, s.sensitive, s.spoiler_text, s.media_attachments,
                       a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.header,
                       a.avatar_description, a.header_description, a.note, a.created_at as account_created_at,
                       a.locked, a.discoverable, a.fields
                FROM statuses s
                JOIN accounts a ON s.account_id = a.id
                WHERE s.id IN ($ph)
            ");
            $stmtBatchStatuses->execute($idList);
            foreach ($stmtBatchStatuses->fetchAll() as $sr) {
                $statusRowCache[(int)$sr['status_id']] = $sr;
            }
        }

        // Recolectar todas las filas de estados para formatearlas en un solo lote
        $rawStatusRowsToFormat = [];
        foreach ($favRows as $row) {
            if (isset($dismissedMap['favourite_' . $row['id']])) continue;
            $sid = (int)$row['status_id'];
            if (isset($statusRowCache[$sid])) {
                $rawStatusRowsToFormat[$sid] = $statusRowCache[$sid];
            }
        }
        foreach ($reblogRows as $row) {
            if (isset($dismissedMap['reblog_' . $row['status_id']])) continue;
            $origSid = (int)$row['orig_status_id'];
            if (isset($statusRowCache[$origSid])) {
                $rawStatusRowsToFormat[$origSid] = $statusRowCache[$origSid];
            }
        }
        $validMentions = [];
        foreach ($mentionRows as $row) {
            $nid = 'mention_' . $row['status_id'];
            if (isset($dismissedMap[$nid])) continue;
            $isRealMention = !empty($row['is_reply_to_me']);
            if (!$isRealMention) {
                $username = $account['username'];
                $content = $row['status_content'];
                $mentionPattern = '/@' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                $urlPattern = '/\/users\/' . preg_quote($username, '/') . '(?![a-zA-Z0-9_\-])/i';
                if (preg_match($mentionPattern, $content) || preg_match($urlPattern, $content)) {
                    $isRealMention = true;
                }
            }
            if (!$isRealMention) continue;
            $validMentions[] = $row;
            $rawStatusRowsToFormat[(int)$row['status_id']] = $row;
        }

        $formattedStatusesMap = [];
        if (!empty($rawStatusRowsToFormat)) {
            $batchFormatted = self::formatStatusesBatch(array_values($rawStatusRowsToFormat), $currUserId);
            foreach ($batchFormatted as $fs) {
                $formattedStatusesMap[(int)$fs['id']] = $fs;
            }
        }

        foreach ($favRows as $row) {
            $nid = 'favourite_' . $row['id'];
            if (isset($dismissedMap[$nid])) continue;
            $sid = (int)$row['status_id'];
            $notifications[] = [
                'id' => $nid,
                'type' => 'favourite',
                'created_at' => date('c', strtotime($row['created_at'])),
                'account' => self::formatAccount($row),
                'status' => $formattedStatusesMap[$sid] ?? null
            ];
        }

        foreach ($validMentions as $row) {
            $sid = (int)$row['status_id'];
            $notifications[] = [
                'id' => 'mention_' . $sid,
                'type' => 'mention',
                'created_at' => date('c', strtotime($row['status_created_at'])),
                'account' => self::formatAccount($row),
                'status' => $formattedStatusesMap[$sid] ?? null
            ];
        }

        foreach ($reblogRows as $row) {
            $nid = 'reblog_' . $row['status_id'];
            if (isset($dismissedMap[$nid])) continue;
            $origStatusId = (int)$row['orig_status_id'];
            if (!isset($formattedStatusesMap[$origStatusId])) continue;

            $notifications[] = [
                'id' => $nid,
                'type' => 'reblog',
                'created_at' => date('c', strtotime($row['status_created_at'])),
                'account' => self::formatAccount($row),
                'status' => $formattedStatusesMap[$origStatusId]
            ];
        }

        // Filtrar notificaciones de usuarios/dominios bloqueados o silenciados
        $filteredNotifications = [];
        foreach ($notifications as $notification) {
            $nAcc = $notification['account'] ?? null;
            if ($nAcc) {
                $nUsername = $nAcc['username'] ?? '';
                $nAcct = $nAcc['acct'] ?? '';
                $nDomain = '';
                if (strpos($nAcct, '@') !== false) {
                    $nDomain = explode('@', $nAcct)[1];
                }
                
                if (self::isBlockedOrMuted($db, $nUsername, $nDomain)) {
                    continue;
                }
            }
            $filteredNotifications[] = $notification;
        }
        $notifications = $filteredNotifications;

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

        // Comprobar también el marcador sincronizado (Mona, Ivory, etc.)
        $markerLastReadId = null;
        $markerUpdatedAt = 0;
        try {
            $stmtMarker = $db->prepare("SELECT last_read_id, updated_at FROM markers WHERE account_id = ? AND timeline = 'notifications' LIMIT 1");
            $stmtMarker->execute([$currUserId]);
            $markerRow = $stmtMarker->fetch();
            if ($markerRow) {
                $markerLastReadId = (string)$markerRow['last_read_id'];
                $markerUpdatedAt = !empty($markerRow['updated_at']) ? strtotime($markerRow['updated_at']) : 0;
            }
        } catch (\Throwable $e) {}

        // Encontrar la fecha asociada al marcador last_read_id si existe
        $markerTargetTime = 0;
        if (!empty($markerLastReadId)) {
            foreach ($notifications as $n) {
                if ((string)$n['id'] === $markerLastReadId) {
                    $markerTargetTime = strtotime($n['created_at']);
                    break;
                }
            }
        }

        $effectiveReadTime = max($readAt, $markerUpdatedAt, $markerTargetTime);

        // Lógica de fallback para tipos no soportados
        $filtered = [];
        foreach ($notifications as $notification) {
            if ($supportedTypes !== null && !in_array($notification['type'], $supportedTypes)) {
                $notification['type'] = 'mention'; // Fallback a mención genérica
            }
            $createdTime = strtotime($notification['created_at']);
            $notification['read'] = ($createdTime <= $effectiveReadTime);
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
        $stmt = $db->prepare(Database::insertIgnoreSql('dismissed_notifications', 'account_id, notification_id', '?, ?'));
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
        $currUserId = (int)$account['id'];
        $now = Database::nowUtc();
        $stmt = $db->prepare("UPDATE accounts SET notifications_read_at = ? WHERE id = ?");
        $stmt->execute([$now, $currUserId]);

        // Sincronizar también con la tabla markers para apps como Mona, Ivory, Ice Cubes
        try {
            $latestId = self::fetchLatestNotificationId($db, $currUserId, $account['username']);
            Database::ensureMarkersTable($db);
            $driver = Database::getDriver();
            if ($driver === 'mysql') {
                $stmtM = $db->prepare("
                    INSERT INTO markers (account_id, timeline, last_read_id, updated_at)
                    VALUES (?, 'notifications', ?, ?)
                    ON DUPLICATE KEY UPDATE
                        last_read_id = VALUES(last_read_id),
                        version = version + 1,
                        updated_at = VALUES(updated_at)
                ");
            } else {
                $stmtM = $db->prepare("
                    INSERT INTO markers (account_id, timeline, last_read_id, updated_at)
                    VALUES (?, 'notifications', ?, ?)
                    ON CONFLICT(account_id, timeline) DO UPDATE SET
                        last_read_id = excluded.last_read_id,
                        version = markers.version + 1,
                        updated_at = excluded.updated_at
                ");
            }
            $stmtM->execute([$currUserId, $latestId, $now]);
        } catch (\Throwable $e) {}

        Router::json([]);
    }

    /**
     * Helper para obtener el ID de la notificación más reciente
     */
    private static function fetchLatestNotificationId(\PDO $db, int $userId, string $username): string {
        $candidates = [];
        try {
            $r = $db->query("SELECT ('follow_' || id) as nid, created_at FROM follows WHERE target_account_id = $userId ORDER BY id DESC LIMIT 1")->fetch();
            if ($r) $candidates[] = $r;
        } catch (\Throwable $e) {}
        try {
            $r = $db->query("SELECT ('favourite_' || fav.id) as nid, fav.created_at FROM favourites fav JOIN statuses s ON fav.status_id = s.id WHERE s.account_id = $userId ORDER BY fav.id DESC LIMIT 1")->fetch();
            if ($r) $candidates[] = $r;
        } catch (\Throwable $e) {}
        try {
            $stmt = $db->prepare("SELECT ('mention_' || s.id) as nid, s.created_at FROM statuses s WHERE s.account_id != ? AND (s.content LIKE ? OR s.in_reply_to_id IN (SELECT id FROM statuses WHERE account_id = ?)) ORDER BY s.id DESC LIMIT 1");
            $stmt->execute([$userId, "%@$username%", $userId]);
            $r = $stmt->fetch();
            if ($r) $candidates[] = $r;
        } catch (\Throwable $e) {}
        try {
            $r = $db->query("SELECT ('reblog_' || s.id) as nid, s.created_at FROM statuses s JOIN statuses orig ON s.reblog_of_id = orig.id WHERE orig.account_id = $userId AND s.content = '' ORDER BY s.id DESC LIMIT 1")->fetch();
            if ($r) $candidates[] = $r;
        } catch (\Throwable $e) {}

        if (empty($candidates)) {
            return '0';
        }

        usort($candidates, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return (string)$candidates[0]['nid'];
    }

    /**
     * Endpoint GET /api/v1/notifications/unread_count
     */
    public static function getNotificationsUnreadCount(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $currUserId = (int)$account['id'];
        $readAt = !empty($account['notifications_read_at']) ? strtotime($account['notifications_read_at']) : 0;

        $markerUpdatedAt = 0;
        try {
            $stmtMarker = $db->prepare("SELECT updated_at FROM markers WHERE account_id = ? AND timeline = 'notifications' LIMIT 1");
            $stmtMarker->execute([$currUserId]);
            $row = $stmtMarker->fetch();
            if ($row && !empty($row['updated_at'])) {
                $markerUpdatedAt = strtotime($row['updated_at']);
            }
        } catch (\Throwable $e) {}

        $effectiveReadTime = max($readAt, $markerUpdatedAt);
        $readDateStr = date('Y-m-d H:i:s', $effectiveReadTime);

        $count = 0;
        try {
            $hasNormalizedNotifs = false;
            try {
                $stmtHas = $db->query("SELECT 1 FROM notifications LIMIT 1");
                $hasNormalizedNotifs = (bool)$stmtHas->fetchColumn();
            } catch (\Throwable $e) {
                $hasNormalizedNotifs = false;
            }

            if ($hasNormalizedNotifs) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM notifications n
                    WHERE n.account_id = ?
                      AND n.created_at > ?
                      AND n.read_at IS NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM dismissed_notifications dn
                          WHERE dn.account_id = ?
                            AND dn.notification_id = (n.type || '_' || COALESCE(n.status_id, n.from_account_id))
                      )
                ");
                $stmt->execute([$currUserId, $readDateStr, $currUserId]);
                $count = (int)$stmt->fetchColumn();
            } else {
                // Follows
                $stmt = $db->prepare("SELECT COUNT(*) FROM follows f WHERE f.target_account_id = ? AND f.created_at > ? AND NOT EXISTS (SELECT 1 FROM dismissed_notifications dn WHERE dn.account_id = ? AND dn.notification_id = ('follow_' || f.id))");
                $stmt->execute([$currUserId, $readDateStr, $currUserId]);
                $count += (int)$stmt->fetchColumn();

                // Favourites
                $stmt = $db->prepare("SELECT COUNT(*) FROM favourites fav JOIN statuses s ON fav.status_id = s.id WHERE s.account_id = ? AND fav.created_at > ? AND NOT EXISTS (SELECT 1 FROM dismissed_notifications dn WHERE dn.account_id = ? AND dn.notification_id = ('favourite_' || fav.id))");
                $stmt->execute([$currUserId, $readDateStr, $currUserId]);
                $count += (int)$stmt->fetchColumn();

                // Mentions
                $stmt = $db->prepare("SELECT COUNT(*) FROM statuses s WHERE s.account_id != ? AND s.created_at > ? AND (s.content LIKE ? OR s.in_reply_to_id IN (SELECT id FROM statuses WHERE account_id = ?)) AND NOT EXISTS (SELECT 1 FROM dismissed_notifications dn WHERE dn.account_id = ? AND dn.notification_id = ('mention_' || s.id))");
                $stmt->execute([$currUserId, $readDateStr, '%@' . $account['username'] . '%', $currUserId, $currUserId]);
                $count += (int)$stmt->fetchColumn();

                // Reblogs
                $stmt = $db->prepare("SELECT COUNT(*) FROM statuses s JOIN statuses orig ON s.reblog_of_id = orig.id WHERE orig.account_id = ? AND s.content = '' AND s.created_at > ? AND NOT EXISTS (SELECT 1 FROM dismissed_notifications dn WHERE dn.account_id = ? AND dn.notification_id = ('reblog_' || s.id))");
                $stmt->execute([$currUserId, $readDateStr, $currUserId]);
                $count += (int)$stmt->fetchColumn();
            }
        } catch (\Throwable $e) {}

        Router::json(['count' => $count]);
    }

    /**
     * Endpoint GET /api/v2/notifications/policy
     */
    public static function getNotificationsPolicy(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Router::json([
            'for_not_following' => 'accept',
            'for_not_followers' => 'accept',
            'for_new_accounts' => 'accept',
            'for_private_mentions' => 'accept',
            'for_limited_accounts' => 'accept',
            'summary' => [
                'pending_requests_count' => 0,
                'pending_notifications_count' => 0
            ]
        ]);
    }

    /**
     * Endpoint GET /api/v1/markers
     */
    public static function getMarkers(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $timelines = $_GET['timeline'] ?? [];
        if (is_string($timelines)) {
            $timelines = [$timelines];
        }

        $db = Database::connect();
        $result = [];

        foreach ($timelines as $tl) {
            $tl = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tl);
            if (empty($tl)) continue;

            try {
                $stmt = $db->prepare("SELECT last_read_id, version, updated_at FROM markers WHERE account_id = ? AND timeline = ? LIMIT 1");
                $stmt->execute([$account['id'], $tl]);
                $row = $stmt->fetch();
                if ($row) {
                    $result[$tl] = [
                        'last_read_id' => (string)$row['last_read_id'],
                        'version' => (int)$row['version'],
                        'updated_at' => date('c', strtotime($row['updated_at']))
                    ];
                    continue;
                }
            } catch (\Throwable $e) {}

            $result[$tl] = [
                'last_read_id' => '0',
                'version' => 0,
                'updated_at' => date('c')
            ];
        }

        Router::json($result);
    }

    /**
     * Endpoint POST /api/v1/markers
     */
    public static function postMarkers(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $body = Router::getRequestBody();
        $db = Database::connect();

        try {
            Database::ensureMarkersTable($db);
        } catch (\Throwable $e) {}

        $result = [];
        $timelines = ['home', 'notifications', 'public'];
        $driver = Database::getDriver();
        $now = Database::nowUtc();

        foreach ($timelines as $tl) {
            $lastReadId = null;
            if (isset($body[$tl]['last_read_id'])) {
                $lastReadId = (string)$body[$tl]['last_read_id'];
            } elseif (isset($_POST[$tl]['last_read_id'])) {
                $lastReadId = (string)$_POST[$tl]['last_read_id'];
            }

            if ($lastReadId !== null) {
                if ($driver === 'mysql') {
                    $stmt = $db->prepare("
                        INSERT INTO markers (account_id, timeline, last_read_id, updated_at)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            last_read_id = VALUES(last_read_id),
                            version = version + 1,
                            updated_at = VALUES(updated_at)
                    ");
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO markers (account_id, timeline, last_read_id, updated_at)
                        VALUES (?, ?, ?, ?)
                        ON CONFLICT(account_id, timeline) DO UPDATE SET
                            last_read_id = excluded.last_read_id,
                            version = markers.version + 1,
                            updated_at = excluded.updated_at
                    ");
                }
                $stmt->execute([$account['id'], $tl, $lastReadId, $now]);

                if ($tl === 'notifications') {
                    try {
                        $db->prepare("UPDATE accounts SET notifications_read_at = ? WHERE id = ?")->execute([$now, $account['id']]);
                    } catch (\Throwable $e) {}
                }

                $stmtFetch = $db->prepare("SELECT last_read_id, version, updated_at FROM markers WHERE account_id = ? AND timeline = ? LIMIT 1");
                $stmtFetch->execute([$account['id'], $tl]);
                $row = $stmtFetch->fetch();
                if ($row) {
                    $result[$tl] = [
                        'last_read_id' => (string)$row['last_read_id'],
                        'version' => (int)$row['version'],
                        'updated_at' => date('c', strtotime($row['updated_at']))
                    ];
                }
            }
        }

        Router::json($result);
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
        $stmt = $db->prepare("SELECT value FROM options WHERE options.key = 'jwt_secret' LIMIT 1");
        $stmt->execute();
        $secret = $stmt->fetchColumn();
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            Database::upsertOption($db, 'jwt_secret', $secret);
        }
        return $secret;
    }

    public static function generateTokenForUser(int $userId): string {
        $secret = self::getSigningSecret();
        $hash = hash_hmac('sha256', $userId, $secret);
        return "token_" . $userId . "_" . $hash;
    }

    /** @var array<string, ?array> Caché por petición de cuentas autenticadas (clave: hash del token) */
    private static array $authAccountCache = [];

    /**
     * Invalida la caché por petición de getAuthenticatedAccount().
     * Llamar tras cambios de estado de sesión/token/cuenta dentro de una misma petición.
     */
    public static function clearAuthenticatedAccountCache(): void {
        self::$authAccountCache = [];
    }

    public static function getAuthenticatedAccount(): ?array {
        $token = Router::getBearerToken();
        // La clave nunca almacena el token en claro
        $cacheKey = $token ? 't:' . hash('sha256', $token) : 's:' . (session_status() === PHP_SESSION_ACTIVE ? session_id() : 'none');
        if (array_key_exists($cacheKey, self::$authAccountCache)) {
            return self::$authAccountCache[$cacheKey];
        }
        $account = self::resolveAuthenticatedAccount($token);
        // En el caso sin token, la sesión puede iniciarse dentro de resolve: recalcular clave
        $cacheKey = $token ? $cacheKey : 's:' . (session_status() === PHP_SESSION_ACTIVE ? session_id() : 'none');
        self::$authAccountCache[$cacheKey] = $account;
        return $account;
    }

    private static function resolveAuthenticatedAccount(?string $token): ?array {
        \KutSocial\Controllers\ActivityPubController::debugLog("getAuthenticatedAccount: " . ($token ? 'token present' : 'no token') . " for URI=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        if (!$token) {
            // Fallback a sesión activa de administrador si existe
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
            }
            $sessionUserId = $_SESSION['admin_account_id'] ?? $_SESSION['kutsocial_user_id'] ?? null;
            if (session_status() === PHP_SESSION_ACTIVE && !empty($sessionUserId)) {
                $db = Database::connect();
                $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$sessionUserId]);
                $account = $stmt->fetch();
                if ($account) {
                    return $account;
                }
            }
            return null;
        }

        $db = Database::connect();
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("
            SELECT a.* FROM accounts a
            JOIN oauth_tokens t ON t.account_id = a.id
            WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at >= ?
            LIMIT 1
        ");
        $stmt->execute([$tokenHash, time()]);
        $account = $stmt->fetch();

        \KutSocial\Controllers\ActivityPubController::debugLog("getAuthenticatedAccount: resolved account=" . ($account ? $account['username'] : 'null'));
        return $account ?: null;
    }

    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $resolve = (($_GET['resolve'] ?? '') === 'true' || ($_GET['resolve'] ?? '') === '1');
        $type = $_GET['type'] ?? null;
        
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
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
                    if (str_contains($q, '/statuses/') || preg_match('#/@.+/\d+#', $q)) {
                        try {
                            $resolvedStatusId = \KutSocial\Controllers\ActivityPubController::fetchAndRegisterRemoteStatus($q);
                            if ($resolvedStatusId) {
                                $sRow = self::fetchStatusRow($db, (int)$resolvedStatusId);
                                if ($sRow) {
                                    $statuses[] = self::formatStatus($sRow, $currUserId);
                                }
                            }
                        } catch (\Throwable $e) {}
                    }
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
            $driver = Database::getDriver();
            if (empty($accounts) && ($type === null || $type === 'accounts')) {
                $cleanQ = preg_replace('/[^\p{L}\p{N}\s]/u', '', $q);
                $localAccs = [];
                if (!empty($cleanQ)) {
                    if ($driver === 'sqlite') {
                        $stmt = $db->prepare("
                            SELECT * FROM accounts 
                            WHERE id IN (SELECT rowid FROM accounts_fts WHERE accounts_fts MATCH ?)
                               OR domain LIKE ?
                            LIMIT 10
                        ");
                        $stmt->execute([$cleanQ . '*', '%' . $q . '%']);
                        $localAccs = $stmt->fetchAll();
                    } elseif ($driver === 'pgsql') {
                        $stmt = $db->prepare("
                            SELECT * FROM accounts 
                            WHERE to_tsvector('simple', coalesce(username, '') || ' ' || coalesce(display_name, '') || ' ' || coalesce(note, '')) @@ plainto_tsquery('simple', ?)
                               OR domain ILIKE ?
                            LIMIT 10
                        ");
                        $stmt->execute([$cleanQ, '%' . $q . '%']);
                        $localAccs = $stmt->fetchAll();
                    } else {
                        $stmt = $db->prepare("
                            SELECT * FROM accounts 
                            WHERE MATCH(username, display_name, note) AGAINST(? IN BOOLEAN MODE)
                               OR domain LIKE ?
                            LIMIT 10
                        ");
                        $stmt->execute([$cleanQ . '*', '%' . $q . '%']);
                        $localAccs = $stmt->fetchAll();
                    }
                }
                
                // Fallback por si FTS no arrojó o query tiene caracteres especiales
                if (empty($localAccs)) {
                    $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
                    $stmt = $db->prepare("
                        SELECT * FROM accounts 
                        WHERE username $likeOp ? OR display_name $likeOp ? OR domain $likeOp ?
                        LIMIT 10
                    ");
                    $stmt->execute(['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']);
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
                $localStatuses = [];
                if (!empty($cleanQ)) {
                    if ($driver === 'sqlite') {
                        $ftsCondition = "s.id IN (SELECT rowid FROM statuses_fts WHERE statuses_fts MATCH ?)";
                        $ftsParam = $cleanQ . '*';
                    } elseif ($driver === 'pgsql') {
                        $ftsCondition = "to_tsvector('simple', coalesce(s.content, '')) @@ plainto_tsquery('simple', ?)";
                        $ftsParam = $cleanQ;
                    } else {
                        $ftsCondition = "MATCH(s.content) AGAINST(? IN BOOLEAN MODE)";
                        $ftsParam = $cleanQ . '*';
                    }

                    if ($currUserId !== null) {
                        $sql = "
                            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                                   s.visibility as status_visibility, s.created_at as status_created_at,
                                   s.media_attachments as status_media, s.sensitive as status_sensitive,
                                   s.spoiler_text as status_spoiler,
                                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                            FROM statuses s
                            JOIN accounts a ON s.account_id = a.id
                            WHERE $ftsCondition
                              AND (
                                  s.visibility IN ('public', 'unlisted')
                                  OR (s.visibility = 'private' AND (s.account_id = ? OR s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND status = 'accepted')))
                                  OR (s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))
                              )
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $queryParams = [$ftsParam, $currUserId, $currUserId, $currUserId, '%@' . $account['username'] . '%', '%/users/' . $account['username'] . '%'];
                    } else {
                        $sql = "
                            SELECT s.id as status_id, s.uri as status_uri, s.content as status_content, 
                                   s.visibility as status_visibility, s.created_at as status_created_at,
                                   s.media_attachments as status_media, s.sensitive as status_sensitive,
                                   s.spoiler_text as status_spoiler,
                                   a.id as account_id, a.username, a.domain, a.display_name, a.avatar, a.note
                            FROM statuses s
                            JOIN accounts a ON s.account_id = a.id
                            WHERE $ftsCondition
                              AND s.visibility IN ('public', 'unlisted')
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $queryParams = [$ftsParam];
                    }
                    $stmt = $db->prepare($sql);
                    $stmt->execute($queryParams);
                    $localStatuses = $stmt->fetchAll();
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
                $statuses = array_merge($statuses, self::formatStatusesBatch($localStatuses, $currUserId));
            }
            
            // 4. Buscar Hashtags locales
            if ($type === null || $type === 'hashtags') {
                $tag = ltrim(trim($q), '#');
                if (preg_match('/^[\p{L}\p{N}_]+$/u', $tag)) {
                    $hashtags[] = [
                        'name' => $tag,
                        'url' => "$proto://$domain/tags/$tag",
                        'history' => []
                    ];
                }
                
                try {
                    $stmtTags = $db->prepare("
                        SELECT DISTINCT hashtag FROM followed_hashtags 
                        WHERE hashtag LIKE ? AND hashtag != ?
                        LIMIT 5
                    ");
                    $stmtTags->execute(['%' . $tag . '%', $tag]);
                    $dbTags = $stmtTags->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($dbTags as $t) {
                        $hashtags[] = [
                            'name' => $t,
                            'url' => "$proto://$domain/tags/$t",
                            'history' => []
                        ];
                    }
                } catch (\Exception $e) {
                    // Ignorar fallos
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
        
        $avatarUrl = !empty($account['avatar']) ? $account['avatar'] : "$proto://$domain/assets/default-avatar.svg";
        $headerUrl = !empty($account['header']) ? $account['header'] : "$proto://$domain/assets/default-header.svg";

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
        
        $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
        $accDomain = !empty($account['domain']) ? strtolower(explode(':', $account['domain'])[0]) : '';
        $isLocal = empty($accDomain) || $accDomain === $serverHost || !empty($account['password_hash']);
        $isRemote = !$isLocal;

        $lastStatusAt = null;
        $preloadedAcc = ($accountId !== null) ? (self::$statusCtx['account_stats'][(int)$accountId] ?? null) : null;

        if ($preloadedAcc !== null) {
            if ($isRemote) {
                $followersCount = (int)($account['followers_count'] ?? $preloadedAcc['followers_count'] ?? 0);
                $followingCount = (int)($account['following_count'] ?? $preloadedAcc['following_count'] ?? 0);
                $statusesCount = max((int)($account['statuses_count'] ?? $preloadedAcc['statuses_count'] ?? 0), (int)$preloadedAcc['local_statuses']);
                $lastCreated = $preloadedAcc['last_created'];
                $lastStatusAt = $lastCreated ? date('Y-m-d', strtotime($lastCreated)) : (!empty($account['last_status_at']) ? date('Y-m-d', strtotime($account['last_status_at'])) : null);
            } else {
                $followersCount = (int)$preloadedAcc['followers_count'];
                $followingCount = (int)$preloadedAcc['following_count'];
                $statusesCount = (int)$preloadedAcc['local_statuses'];
                $lastCreated = $preloadedAcc['last_created'];
                $lastStatusAt = $lastCreated ? date('Y-m-d', strtotime($lastCreated)) : null;
            }
        } elseif ($isRemote) {
            $followersCount = (int)($account['followers_count'] ?? 0);
            $followingCount = (int)($account['following_count'] ?? 0);
            
            $stmtStatuses = $db->prepare("SELECT COUNT(*) FROM statuses WHERE account_id = ?");
            $stmtStatuses->execute([$accountId]);
            $localStatuses = (int)$stmtStatuses->fetchColumn();
            $statusesCount = max((int)($account['statuses_count'] ?? 0), $localStatuses);

            $stmtLastStatus = $db->prepare("SELECT created_at FROM statuses WHERE account_id = ? ORDER BY id DESC LIMIT 1");
            $stmtLastStatus->execute([$accountId]);
            $lastCreated = $stmtLastStatus->fetchColumn();
            $lastStatusAt = $lastCreated ? date('Y-m-d', strtotime($lastCreated)) : (!empty($account['last_status_at']) ? date('Y-m-d', strtotime($account['last_status_at'])) : null);
        } else {
            // Contar seguidores recibidos
            $stmtFollowers = $db->prepare("SELECT COUNT(*) FROM follows WHERE target_account_id = ? AND (status = 'accepted' OR status = 'pending')");
            $stmtFollowers->execute([$accountId]);
            $followersCount = (int)$stmtFollowers->fetchColumn();

            // Contar seguidos realizados
            $stmtFollowing = $db->prepare("SELECT COUNT(*) FROM follows WHERE account_id = ? AND (status = 'accepted' OR status = 'pending')");
            $stmtFollowing->execute([$accountId]);
            $followingCount = (int)$stmtFollowing->fetchColumn();

            // Contar toots creados
            $stmtStatuses = $db->prepare("SELECT COUNT(*) FROM statuses WHERE account_id = ?");
            $stmtStatuses->execute([$accountId]);
            $statusesCount = (int)$stmtStatuses->fetchColumn();

            // Fecha del último toot
            $stmtLastStatus = $db->prepare("SELECT created_at FROM statuses WHERE account_id = ? ORDER BY id DESC LIMIT 1");
            $stmtLastStatus->execute([$accountId]);
            $lastCreated = $stmtLastStatus->fetchColumn();
            $lastStatusAt = $lastCreated ? date('Y-m-d', strtotime($lastCreated)) : null;
        }

        return [
            'id' => (string)$accountId,
            'username' => $account['username'],
            'acct' => ($isRemote && !empty($account['domain'])) ? $account['username'] . '@' . $account['domain'] : $account['username'],
            'display_name' => $account['display_name'] ?: $account['username'],
            'locked' => (bool)($account['locked'] ?? 0),
            'bot' => false,
            'discoverable' => (bool)($account['discoverable'] ?? 1),
            'searchable' => (bool)($account['searchable'] ?? 1),
            'indexable' => (bool)($account['indexable'] ?? 1),
            'noindex' => !(bool)($account['indexable'] ?? 1),
            'show_source' => (bool)($account['show_source'] ?? 1),
            'group' => false,
            'suspended' => false,
            'limited' => false,
            'moved' => null,
            'created_at' => date('c', strtotime($account['account_created_at'] ?? $account['created_at'] ?? 'now')),
            'note' => $bio,
            'url' => ($isRemote && !empty($account['url'])) ? $account['url'] : ($isRemote ? "https://{$account['domain']}/@{$account['username']}" : "$proto://$domain/@" . $account['username']),
            'avatar' => $avatarUrl,
            'avatar_static' => $avatarUrl,
            'header' => $headerUrl,
            'header_static' => $headerUrl,
            'followers_count' => $followersCount,
            'following_count' => $followingCount,
            'statuses_count' => $statusesCount,
            'last_status_at' => $lastStatusAt,
            'emojis' => self::extractAccountEmojis($account),
            'fields' => $fields,
            'avatar_description' => $account['avatar_description'] ?? '',
            'header_description' => $account['header_description'] ?? '',
            'also_known_as' => !empty($account['also_known_as']) ? array_values(array_filter(array_map('trim', explode("\n", str_replace(["\r", ","], "\n", $account['also_known_as']))))) : []
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
        $formatted['role'] = [
            'id' => 1,
            'name' => $account['role'] ?? 'user',
            'color' => '',
            'permissions' => '0',
            'highlighted' => false
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

        \KutSocial\Controllers\ActivityPubController::debugLog("updateCredentials: Method=" . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . ", POST keys=" . json_encode(array_keys($_POST)) . ", FILES=" . json_encode(array_keys($_FILES)));

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

        // Migration Alias (alsoKnownAs)
        $alsoKnownAs = $_POST['also_known_as'] ?? $body['also_known_as'] ?? null;
        if ($alsoKnownAs !== null) {
            $fieldsToUpdate[] = "also_known_as = ?";
            $params[] = is_array($alsoKnownAs) ? implode("\n", array_map('trim', $alsoKnownAs)) : trim($alsoKnownAs);
        }

        if (!empty($fieldsToUpdate)) {
            $fieldsToUpdate[] = "updated_at = ?";
            $params[] = Database::nowUtc();
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
        if (!\KutSocial\SecurityHelper::isSafeExternalUrl($url)) {
            return false;
        }
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_MAXFILESIZE => 1048576, // 1 MB
            CURLOPT_USERAGENT => 'KutSocial-LinkVerifier/1.0',
            CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
            CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false
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
     * Resuelve una cuenta a partir de su ID numérico o handle (@username o @user@domain)
     */
    public static function findAccountByIdOrHandle(string|int $idOrHandle): ?array {
        $idParam = trim((string)$idOrHandle);
        if ($idParam === '') {
            return null;
        }

        $db = Database::connect();
        if (is_numeric($idParam)) {
            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$idParam]);
            $acc = $stmt->fetch();
            if ($acc) {
                return $acc;
            }
        }

        $cleanUser = ltrim($idParam, '@');
        $parts = explode('@', $cleanUser);
        $uName = $parts[0] ?? '';
        $uDomain = $parts[1] ?? null;
        $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);

        if (empty($uDomain) || strtolower($uDomain) === $serverHost) {
            $stmt = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = LOWER(?) AND (domain IS NULL OR domain = '' OR LOWER(domain) = ?) LIMIT 1");
            $stmt->execute([$uName, $serverHost]);
            $acc = $stmt->fetch();
            if ($acc) {
                return $acc;
            }
        } else {
            $stmt = $db->prepare("SELECT * FROM accounts WHERE LOWER(username) = LOWER(?) AND LOWER(domain) = LOWER(?) LIMIT 1");
            $stmt->execute([$uName, strtolower($uDomain)]);
            $acc = $stmt->fetch();
            if ($acc) {
                return $acc;
            }

            try {
                $acc = \KutSocial\Controllers\ActivityPubController::resolveWebfinger($uName . '@' . $uDomain);
                if ($acc) {
                    return $acc;
                }
            } catch (\Throwable $e) {
                // Silenciosamente ignorar fallo de webfinger
            }
        }

        return null;
    }

    /**
     * Endpoint GET /api/v1/accounts/lookup
     * Permite a clientes como Mona e Ivory resolver cuentas rápidamente por handle/username
     */
    public static function lookupAccount(): void {
        $acct = trim($_GET['acct'] ?? '');
        if (empty($acct)) {
            Router::json(['error' => 'Validation failed: acct parameter is required'], 422);
            return;
        }

        $account = self::findAccountByIdOrHandle($acct);
        if (!$account) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        Router::json(self::formatAccount($account));
    }

    /**
     * Endpoint GET /api/v1/accounts/:id
     * Obtiene el perfil de cualquier cuenta por su ID numérico o handle (@username)
     */
    public static function getAccountById(array $params): void {
        $idParam = $params['id'] ?? '';
        $account = self::findAccountByIdOrHandle($idParam);

        if (!$account) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
        $isRemote = !empty($account['domain']) && strtolower($account['domain']) !== $serverHost;

        if ($isRemote) {
            $lastUpdated = strtotime($account['updated_at'] ?? 'now');
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
        $actorUrl = !empty($account['url']) ? $account['url'] : (!empty($account['uri']) ? $account['uri'] : "https://{$account['domain']}/users/{$account['username']}");

        try {
            $db = Database::connect();
            // Actualizar marca de tiempo de última consulta de estados para no sobrecargar
            try {
                $db->prepare("UPDATE accounts SET statuses_last_fetched_at = ? WHERE id = ?")->execute([Database::nowUtc(), $account['id']]);
            } catch (\Throwable $e) {}

            // 1. Obtener la URL de la outbox del perfil del actor remoto
            $ch = curl_init($actorUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$domain)"
                ],
                CURLOPT_TIMEOUT => 8,
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
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/activity+json, application/ld+json",
                    "User-Agent: KutSocial/1.0; (+https://$domain)"
                ],
                CURLOPT_TIMEOUT => 8,
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
            } elseif (isset($outboxJson['items'])) {
                $items = $outboxJson['items'];
            } elseif (isset($outboxJson['first'])) {
                $firstPageUrl = is_array($outboxJson['first']) ? ($outboxJson['first']['id'] ?? '') : $outboxJson['first'];
                if ($firstPageUrl) {
                    $ch = curl_init($firstPageUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_HTTPHEADER => [
                            "Accept: application/activity+json, application/ld+json",
                            "User-Agent: KutSocial/1.0; (+https://$domain)"
                        ],
                        CURLOPT_TIMEOUT => 8,
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

            $importedCount = 0;
            $announceFetches = 0;
            // 3. Procesar e insertar notas y boosts en la base de datos local
            foreach ($items as $item) {
                $activityType = $item['type'] ?? '';
                if ($activityType === 'Announce' && !empty($item['object'])) {
                    $objectUrl = is_array($item['object']) ? ($item['object']['id'] ?? '') : (string)$item['object'];
                    if ($objectUrl === '') continue;

                    $announceUri = $item['id'] ?? ($objectUrl . '/reblog/' . md5((string)$account['id'] . $objectUrl));
                    $stmtCheckAnn = $db->prepare("SELECT id FROM statuses WHERE uri = ? LIMIT 1");
                    $stmtCheckAnn->execute([$announceUri]);
                    if ($stmtCheckAnn->fetchColumn()) {
                        continue;
                    }

                    $stmtCheckOrig = $db->prepare("SELECT id FROM statuses WHERE uri = ? LIMIT 1");
                    $stmtCheckOrig->execute([$objectUrl]);
                    $origStatusId = $stmtCheckOrig->fetchColumn();
                    if (!$origStatusId) {
                        if ($announceFetches >= 5) continue;
                        $announceFetches++;
                        $origStatusId = ActivityPubController::fetchAndRegisterRemoteStatus($objectUrl);
                    }
                    if (!$origStatusId) continue;

                    $stmtCheckReb = $db->prepare("SELECT id FROM statuses WHERE account_id = ? AND reblog_of_id = ? AND content = '' LIMIT 1");
                    $stmtCheckReb->execute([$account['id'], (int)$origStatusId]);
                    if ($stmtCheckReb->fetchColumn()) {
                        continue;
                    }

                    $annCreatedAt = !empty($item['published']) ? gmdate('Y-m-d H:i:s', strtotime($item['published']) ?: time()) : Database::nowUtc();
                    $stmtInsAnn = $db->prepare("
                        INSERT INTO statuses (account_id, uri, content, visibility, reblog_of_id, created_at, is_profile_fetch)
                        VALUES (?, ?, '', 'public', ?, ?, 1)
                    ");
                    $stmtInsAnn->execute([$account['id'], $announceUri, (int)$origStatusId, $annCreatedAt]);
                    $importedCount++;
                    continue;
                }

                $object = null;
                if ($activityType === 'Create' && isset($item['object'])) {
                    $object = $item['object'];
                } elseif (isset($item['type']) && in_array($item['type'], ['Note', 'Question', 'Audio', 'Article', 'Page', 'Video'])) {
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
                if (empty($content) && !empty($object['summary'])) {
                    $content = $object['summary'];
                }
                if (!empty($object['name']) && is_string($object['name']) && !str_contains($content, $object['name'])) {
                    $content = '<strong>' . htmlspecialchars($object['name'], ENT_QUOTES, 'UTF-8') . '</strong><br>' . $content;
                }

                $visibility = self::determineVisibility($object);
                $createdAt = $object['published'] ?? $object['publishedAt'] ?? $item['published'] ?? date('c');

                // Procesar adjuntos (media) soportando Document, Image, Audio, Video
                $attachments = [];
                if (!empty($object['attachment'])) {
                    $rawAttachments = $object['attachment'];
                    if (isset($rawAttachments['type']) || isset($rawAttachments['url']) || isset($rawAttachments['href'])) {
                        $rawAttachments = [$rawAttachments];
                    }
                    if (is_array($rawAttachments)) {
                        foreach ($rawAttachments as $att) {
                            if (!is_array($att)) continue;
                            $attType = $att['type'] ?? '';
                            if ($attType === 'Document' || $attType === 'Image' || $attType === 'Audio' || $attType === 'Video') {
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
                                } elseif (isset($att['href']) && is_string($att['href'])) {
                                    $url = $att['href'];
                                }

                                if (empty($url)) {
                                    continue;
                                }

                                $description = $att['name'] ?? $att['summary'] ?? $att['description'] ?? '';

                                $mimeType = $att['mediaType'] ?? $att['mimeType'] ?? '';
                                if (!empty($mimeType)) {
                                    $mediaApiType = self::detectMediaType($mimeType);
                                } else {
                                    $mediaApiType = self::detectMediaTypeFromUrl($url);
                                }
                                if ($attType === 'Audio') {
                                    $mediaApiType = 'audio';
                                } elseif ($attType === 'Video') {
                                    $mediaApiType = 'video';
                                } elseif ($attType === 'Image') {
                                    $mediaApiType = 'image';
                                }

                                if ($mediaApiType === 'unknown') {
                                    continue;
                                }

                                $previewUrl = $url;
                                if ($mediaApiType === 'video' || $mediaApiType === 'audio') {
                                    $previewUrl = $att['icon']['url'] ?? $att['preview_url'] ?? $url;
                                }

                                $attachments[] = [
                                    'id' => bin2hex(random_bytes(6)),
                                    'type' => $mediaApiType,
                                    'url' => $url,
                                    'preview_url' => $previewUrl,
                                    'remote_url' => $url,
                                    'description' => $description
                                ];
                            }
                        }
                    }
                }

                // Si el objeto es Audio y no tiene adjuntos, buscar en 'url'
                if (($object['type'] ?? '') === 'Audio' && empty($attachments) && !empty($object['url'])) {
                    $audioUrl = is_string($object['url']) ? $object['url'] : ($object['url']['href'] ?? '');
                    if (!empty($audioUrl)) {
                        $attachments[] = [
                            'id' => bin2hex(random_bytes(6)),
                            'type' => 'audio',
                            'url' => $audioUrl,
                            'preview_url' => $audioUrl,
                            'remote_url' => $audioUrl,
                            'description' => $object['name'] ?? ''
                        ];
                    }
                }

                $mediaJson = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

                $inReplyToRaw = $object['inReplyTo'] ?? $object['in_reply_to'] ?? null;
                $inReplyToUri = null;
                if (!empty($inReplyToRaw)) {
                    if (is_string($inReplyToRaw)) {
                        $inReplyToUri = $inReplyToRaw;
                    } elseif (is_array($inReplyToRaw)) {
                        $inReplyToUri = $inReplyToRaw['id'] ?? $inReplyToRaw['url'] ?? null;
                    }
                }
                
                $inReplyToId = null;
                $inReplyToAccountId = null;
                if ($inReplyToUri) {
                    $stmtParent = $db->prepare("SELECT id, account_id FROM statuses WHERE uri = ? LIMIT 1");
                    $stmtParent->execute([$inReplyToUri]);
                    if ($pRow = $stmtParent->fetch()) {
                        $inReplyToId = (int)$pRow['id'];
                        $inReplyToAccountId = (int)$pRow['account_id'];
                    }
                }

                $stmtIns = $db->prepare("
                    INSERT INTO statuses (account_id, uri, content, visibility, created_at, media_attachments, in_reply_to_id, in_reply_to_url, in_reply_to_account_id, is_profile_fetch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmtIns->execute([
                    $account['id'],
                    $uri,
                    $content,
                    $visibility,
                    date('Y-m-d H:i:s', strtotime($createdAt)),
                    $mediaJson,
                    $inReplyToId,
                    $inReplyToUri,
                    $inReplyToAccountId
                ]);

                $newStatusId = Database::lastInsertId($db, 'statuses');
                if (class_exists('\\KutSocial\\NotificationHelper')) {
                    \KutSocial\NotificationHelper::fetchAndSaveLinkCard($newStatusId, $content);
                }
                $importedCount++;
            }

            if ($importedCount > 0) {
                try {
                    $stmtCnt = $db->prepare("UPDATE accounts SET statuses_count = (SELECT COUNT(*) FROM statuses WHERE account_id = ?) WHERE id = ?");
                    $stmtCnt->execute([$account['id'], $account['id']]);
                } catch (\Throwable $e) {}
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
        $idParam = $params['id'] ?? '';
        $account = self::findAccountByIdOrHandle($idParam);
        if (!$account) {
            Router::json([]);
            return;
        }

        $id = (int)$account['id'];
        $db = Database::connect();

        $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
        $isRemote = !empty($account['domain']) && strtolower($account['domain']) !== $serverHost;

        if ($isRemote) {
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM statuses WHERE account_id = ?");
            $stmtCount->execute([$id]);
            $localCount = (int)$stmtCount->fetchColumn();

            $lastFetched = !empty($account['statuses_last_fetched_at'])
                ? strtotime($account['statuses_last_fetched_at'])
                : 0;

            // Si nunca se han traído estados, o si pasaron más de 60 segundos desde la última consulta a la outbox:
            if ($localCount === 0 || (time() - $lastFetched >= 60)) {
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

        $whereClauses = [
            "s.account_id = ?",
            "(s.content != '' OR s.reblog_of_id IS NOT NULL OR (s.media_attachments IS NOT NULL AND s.media_attachments != '' AND s.media_attachments != '[]'))"
        ];
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

        // Filtros según especificación de Mastodon para pestañas del perfil
        $pinned = isset($_GET['pinned']) && ($_GET['pinned'] === 'true' || $_GET['pinned'] === '1' || $_GET['pinned'] === true);
        if ($pinned) {
            Router::json([]);
            return;
        }

        $excludeReplies = isset($_GET['exclude_replies']) && ($_GET['exclude_replies'] === 'true' || $_GET['exclude_replies'] === '1' || $_GET['exclude_replies'] === true);
        if ($excludeReplies) {
            $whereClauses[] = "s.in_reply_to_id IS NULL";
        }

        $excludeReblogs = isset($_GET['exclude_reblogs']) && ($_GET['exclude_reblogs'] === 'true' || $_GET['exclude_reblogs'] === '1' || $_GET['exclude_reblogs'] === true);
        if ($excludeReblogs) {
            $whereClauses[] = "s.reblog_of_id IS NULL";
        }

        $onlyMedia = isset($_GET['only_media']) && ($_GET['only_media'] === 'true' || $_GET['only_media'] === '1' || $_GET['only_media'] === true);
        if ($onlyMedia) {
            $whereClauses[] = "(s.media_attachments IS NOT NULL AND s.media_attachments != '' AND s.media_attachments != '[]')";
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

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, $maxId, $sinceId, $minId, $limit);

        $timeline = self::formatStatusesBatch($rows, $currUserId);

        if ($maxId === null && $sinceId === null && $minId === null) {
            usort($timeline, function($a, $b) {
                return strtotime($b['created_at'] ?? '1970-01-01') <=> strtotime($a['created_at'] ?? '1970-01-01');
            });
        }

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    /**
     * Endpoint GET /api/v1/conversations
     */
    public static function getConversations(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();

        $maxId = isset($_GET['max_id']) && is_numeric($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $minId = isset($_GET['min_id']) && is_numeric($_GET['min_id']) ? (int)$_GET['min_id'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 20;
        if ($limit < 1) $limit = 20;
        if ($limit > 40) $limit = 40;

        $whereClauses = [
            "(s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))"
        ];
        $queryParams = [
            $account['id'],
            '%@' . $account['username'] . '%',
            '%/users/' . $account['username'] . '%'
        ];

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, $maxId, $sinceId, $minId, $limit);

        $conversations = [];
        foreach ($rows as $row) {
            $statusObj = self::formatStatus($row, (int)$account['id']);

            // Gather accounts in this conversation
            $participantAccounts = [];
            $authorAccount = $statusObj['account'];
            
            if ((int)$authorAccount['id'] !== (int)$account['id']) {
                $participantAccounts[$authorAccount['id']] = $authorAccount;
            }

            if (!empty($statusObj['mentions'])) {
                foreach ($statusObj['mentions'] as $mention) {
                    $mId = $mention['id'] ?? null;
                    if ($mId && (int)$mId !== (int)$account['id'] && !isset($participantAccounts[$mId])) {
                        $stmtAcc = $db->prepare("SELECT id, username, display_name, avatar, header, domain, locked, discoverable, note, created_at, emojis, url, avatar_description, header_description FROM accounts WHERE id = ? LIMIT 1");
                        $stmtAcc->execute([$mId]);
                        $accRow = $stmtAcc->fetch(\PDO::FETCH_ASSOC);
                        if ($accRow) {
                            $participantAccounts[$mId] = self::formatAccount($accRow);
                        }
                    }
                }
            }

            if (empty($participantAccounts)) {
                $participantAccounts[$authorAccount['id']] = $authorAccount;
            }

            $conversations[] = [
                'id' => (string)($row['status_id'] ?? $row['id']),
                'unread' => false,
                'accounts' => array_values($participantAccounts),
                'last_status' => $statusObj
            ];
        }

        self::setLinkHeader($conversations);
        Router::json($conversations);
    }

    /**
     * Endpoint POST /api/v1/conversations/:id/read
     */
    public static function readConversation(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = $params['id'] ?? null;
        if (!$id) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $db = Database::connect();
        $whereClauses = [
            "s.id = ?",
            "s.visibility = 'direct'",
            "(s.account_id = ? OR EXISTS (SELECT 1 FROM mentions m WHERE m.status_id = s.id AND m.account_id = ?))"
        ];
        $queryParams = [(int)$id, (int)$account['id'], (int)$account['id']];

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, null, null, null, 1);
        if (empty($rows)) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }
        $row = $rows[0];

        $statusObj = self::formatStatus($row, (int)$account['id']);
        $participantAccounts = [];
        $authorAccount = $statusObj['account'];
        if ((int)$authorAccount['id'] !== (int)$account['id']) {
            $participantAccounts[$authorAccount['id']] = $authorAccount;
        }

        if (!empty($statusObj['mentions'])) {
            foreach ($statusObj['mentions'] as $mention) {
                $mId = $mention['id'] ?? null;
                if ($mId && (int)$mId !== (int)$account['id'] && !isset($participantAccounts[$mId])) {
                    $stmtAcc = $db->prepare("SELECT id, username, display_name, avatar, header, domain, locked, discoverable, note, created_at, emojis, url, avatar_description, header_description FROM accounts WHERE id = ? LIMIT 1");
                    $stmtAcc->execute([$mId]);
                    $accRow = $stmtAcc->fetch(\PDO::FETCH_ASSOC);
                    if ($accRow) {
                        $participantAccounts[$mId] = self::formatAccount($accRow);
                    }
                }
            }
        }
        if (empty($participantAccounts)) {
            $participantAccounts[$authorAccount['id']] = $authorAccount;
        }

        Router::json([
            'id' => (string)($row['status_id'] ?? $row['id']),
            'unread' => false,
            'accounts' => array_values($participantAccounts),
            'last_status' => $statusObj
        ]);
    }

    /**
     * Endpoint DELETE /api/v1/conversations/:id
     */
    public static function deleteConversation(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Router::json(new \stdClass());
    }

    /**
     * Construye la condición WHERE para publicaciones en el Timeline de Inicio, Catchup y Streaming
     * aplicando:
     * 1. Publicaciones propias.
     * 2. Publicaciones de cuentas seguidas con la regla de Mastodon para respuestas.
     * 3. Filtro de exclusividad 'only_replies_to_me' por usuario seguido.
     * 4. Mensajes directos (DMs) dirigidos al usuario.
     * 5. Hashtags seguidos.
     */
    private static function buildHomeTimelineWhereCondition(array $account, array &$queryParams): string {
        $userId = (int)$account['id'];
        $username = $account['username'];
        $mention1 = '%@' . $username . '%';
        $mention2 = '%/users/' . $username . '%';

        $queryParams[] = $userId; // s.account_id = ?
        $queryParams[] = $userId; // follows.account_id = ?
        $queryParams[] = $userId; // follows.account_id (NOT IN only_replies_to_me)
        $queryParams[] = $userId; // in_reply_to_account_id = ?
        $queryParams[] = $mention1; // content LIKE ?
        $queryParams[] = $mention2; // content LIKE ?
        $queryParams[] = $userId; // in_reply_to_account_id IN follows
        $queryParams[] = $userId; // in_reply_to_id IN statuses WHERE account_id = ?
        $queryParams[] = $userId; // OR account_id IN follows
        $queryParams[] = $userId; // follows.account_id (IN only_replies_to_me)
        $queryParams[] = $userId; // in_reply_to_account_id = ?
        $queryParams[] = $mention1; // content LIKE ?
        $queryParams[] = $mention2; // content LIKE ?
        $queryParams[] = $userId; // in_reply_to_id IN statuses WHERE account_id = ?
        $queryParams[] = $userId; // DMs: s.account_id = ?
        $queryParams[] = $mention1; // DMs: s.content LIKE ?
        $queryParams[] = $mention2; // DMs: s.content LIKE ?
        $queryParams[] = $userId; // followed_hashtags: fh.account_id = ?

        return "(
            (s.content != '' OR s.reblog_of_id IS NOT NULL OR (s.media_attachments IS NOT NULL AND s.media_attachments != '' AND s.media_attachments != '[]'))
            AND (
            (
                s.visibility IN ('public', 'unlisted', 'private') AND (
                    s.account_id = ?
                    OR (
                        s.account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND (status = 'accepted' OR status = 'pending'))
                        AND (
                            -- Cuenta normal (sin filtro exclusivo only_replies_to_me)
                            (
                                s.account_id NOT IN (
                                    SELECT a2.id FROM accounts a2
                                    JOIN accounts a1 ON (LOWER(a1.username) = LOWER(a2.username) AND LOWER(COALESCE(a1.domain, '')) = LOWER(COALESCE(a2.domain, '')))
                                    JOIN follows f ON f.target_account_id = a1.id
                                    WHERE f.account_id = ? AND f.only_replies_to_me = 1
                                )
                                AND (
                                    -- Post raíz (no es respuesta)
                                    (s.in_reply_to_id IS NULL AND (s.in_reply_to_account_id IS NULL OR s.in_reply_to_account_id = s.account_id) AND (s.in_reply_to_url IS NULL OR s.in_reply_to_url = ''))
                                    OR
                                    -- Respuesta dirigida a mí o me menciona
                                    (s.in_reply_to_account_id = ? OR s.content LIKE ? OR s.content LIKE ?)
                                    OR
                                    -- Respuesta a alguien que también sigo (estándar de Mastodon)
                                    (
                                        s.in_reply_to_account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND (status = 'accepted' OR status = 'pending'))
                                        OR s.in_reply_to_id IN (SELECT id FROM statuses WHERE account_id = ? OR account_id IN (SELECT target_account_id FROM follows WHERE account_id = ? AND (status = 'accepted' OR status = 'pending')))
                                    )
                                )
                            )
                            OR
                            -- Cuenta con filtro exclusivo activado (solo respuestas a mí o menciones a mí)
                            (
                                s.account_id IN (
                                    SELECT a2.id FROM accounts a2
                                    JOIN accounts a1 ON (LOWER(a1.username) = LOWER(a2.username) AND LOWER(COALESCE(a1.domain, '')) = LOWER(COALESCE(a2.domain, '')))
                                    JOIN follows f ON f.target_account_id = a1.id
                                    WHERE f.account_id = ? AND f.only_replies_to_me = 1
                                )
                                AND (
                                    s.in_reply_to_account_id = ? 
                                    OR s.content LIKE ? 
                                    OR s.content LIKE ? 
                                    OR s.in_reply_to_id IN (SELECT id FROM statuses WHERE account_id = ?)
                                )
                            )
                        )
                    )
                )
            )
            OR
            (s.visibility = 'direct' AND (s.account_id = ? OR s.content LIKE ? OR s.content LIKE ?))
            OR
            (s.visibility IN ('public', 'unlisted') AND EXISTS (
                SELECT 1 FROM followed_hashtags fh 
                WHERE fh.account_id = ? 
                  AND (s.content LIKE '%#' || fh.hashtag || '%' OR s.content LIKE '%/tags/' || fh.hashtag || '%')
            ))
        ))";
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

        $queryParams = [];
        $whereClauses = [
            self::buildHomeTimelineWhereCondition($account, $queryParams)
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

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, $maxId, $sinceId, $minId, $limit);

        $timeline = self::formatStatusesBatch($rows, (int)$account['id']);

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    /**
     * Endpoint GET /api/v1/timelines/catchup
     */
    public static function getCatchUpTimeline(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();

        $hours = isset($_GET['hours']) && is_numeric($_GET['hours']) ? (int)$_GET['hours'] : 6;
        if ($hours < 1) $hours = 1;
        if ($hours > 72) $hours = 72;

        $queryParams = [];
        $whereClauses = [
            self::buildHomeTimelineWhereCondition($account, $queryParams),
            "s.created_at >= ?"
        ];
        $queryParams[] = Database::agoUtc($hours * 3600);

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
            LIMIT 1000
        ");
        $stmt->execute($queryParams);
        $rows = self::filterStatuses($stmt->fetchAll());

        $timeline = self::formatStatusesBatch($rows, (int)$account['id']);

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
        } else {
            // En el timeline federado público solo deben mostrarse toots relevantes para la instancia:
            // 1. Cuentas locales
            // 2. Cuentas remotas seguidas por algún usuario local
            // 3. Reblogs / retoots hechos por cuentas locales o seguidas
            // 4. Si la instancia tiene suscripciones a relays de ActivityPub (excluyendo consultas ad-hoc de perfiles no seguidos)
            $hasActiveRelays = (int)$db->query("SELECT COUNT(*) FROM relays WHERE status = 'accepted'")->fetchColumn() > 0;
            if (!$hasActiveRelays) {
                $whereClauses[] = "(
                    (a.domain IS NULL OR a.domain = '')
                    OR s.account_id IN (SELECT target_account_id FROM follows WHERE status = 'accepted' OR status = 'pending')
                    OR s.reblog_of_id IS NOT NULL
                )";
            } else {
                $whereClauses[] = "(
                    s.is_profile_fetch = 0 
                    OR (a.domain IS NULL OR a.domain = '')
                    OR s.account_id IN (SELECT target_account_id FROM follows WHERE status = 'accepted' OR status = 'pending')
                    OR s.reblog_of_id IS NOT NULL
                )";
            }
        }

        $whereClauses[] = "(s.content != '' OR s.reblog_of_id IS NOT NULL OR (s.media_attachments IS NOT NULL AND s.media_attachments != '' AND s.media_attachments != '[]'))";
        // Excluir respuestas a terceros de la vista federada pública (estándar de Mastodon: solo toots raíz o retoots)
        $whereClauses[] = "(s.in_reply_to_id IS NULL AND (s.in_reply_to_url IS NULL OR s.in_reply_to_url = ''))";
        $whereClauses[] = "NOT (
            s.content LIKE '@%' 
            OR s.content LIKE '<p>@%' 
            OR s.content LIKE '<p><span class=\"h-card\"><a%' 
            OR s.content LIKE '<p><a% class=\"u-url mention\"%'
            OR s.content LIKE '<p><a% class=\"mention\"%'
        )";

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

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, $maxId, $sinceId, $minId, $limit);

        $currUser = self::getAuthenticatedAccount();
        $currUserId = $currUser ? (int)$currUser['id'] : null;

        $timeline = self::formatStatusesBatch($rows, $currUserId);

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

        $headers = Router::getAllHeaders();
        $idempotencyKey = $headers['Idempotency-Key']
            ?? $headers['idempotency-key']
            ?? $_SERVER['HTTP_IDEMPOTENCY_KEY']
            ?? null;
        if ($idempotencyKey) {
            $idempotencyKey = trim($idempotencyKey);
        }

        $db = Database::connect();

        // 1. Manejo de idempotencia: si el cliente (ej. Mona) reintenta la misma clave, devolver el status ya creado
        if (!empty($idempotencyKey)) {
            try {
                $stmtCheckKey = $db->prepare("SELECT id FROM statuses WHERE account_id = ? AND idempotency_key = ? LIMIT 1");
                $stmtCheckKey->execute([$account['id'], $idempotencyKey]);
                $existingId = $stmtCheckKey->fetchColumn();
                if ($existingId) {
                    $existingRow = self::fetchStatusRow($db, (int)$existingId);
                    Router::json(self::formatStatus($existingRow, (int)$account['id']));
                    return;
                }
            } catch (\Throwable $e) {}
        }

        // 2. Prevención de publicaciones duplicadas en caso de reenvío rápido desde borradores (<90s)
        try {
            $dupCutoff = Database::agoUtc(90);
            if ($inReplyToId !== null) {
                $stmtDup = $db->prepare("
                    SELECT id FROM statuses 
                    WHERE account_id = ? 
                      AND content = ? 
                      AND in_reply_to_id = ?
                      AND created_at >= ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtDup->execute([$account['id'], $content, $inReplyToId, $dupCutoff]);
            } else {
                $stmtDup = $db->prepare("
                    SELECT id FROM statuses 
                    WHERE account_id = ? 
                      AND content = ? 
                      AND in_reply_to_id IS NULL
                      AND created_at >= ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtDup->execute([$account['id'], $content, $dupCutoff]);
            }
            $dupId = $stmtDup->fetchColumn();
            if ($dupId) {
                $dupRow = self::fetchStatusRow($db, (int)$dupId);
                Router::json(self::formatStatus($dupRow, (int)$account['id']));
                return;
            }
        } catch (\Throwable $e) {}

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
        $stmtLimit = $db->prepare("SELECT value FROM options WHERE options.key = 'max_toot_chars' LIMIT 1");
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
                    return !in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['txt', 'owner'], true);
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

        $inReplyToAccountId = $inReplyToId ? ($db->query("SELECT account_id FROM statuses WHERE id = " . (int)$inReplyToId)->fetchColumn() ?: null) : null;

        // Inserción atómica del status mediante transacciones de PDO y reintentos automáticos
        $newId = Database::executeWithRetry(function() use ($db, $account, $uri, $content, $visibility, $inReplyToId, $inReplyToAccountId, $sensitive, $spoilerText, $mediaAttachments, $language, $reblogOfId, $idempotencyKey) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO statuses (account_id, uri, content, visibility, in_reply_to_id, in_reply_to_account_id, sensitive, spoiler_text, media_attachments, language, reblog_of_id, idempotency_key, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $account['id'], 
                    $uri, 
                    $content, 
                    $visibility, 
                    $inReplyToId, 
                    $inReplyToAccountId,
                    $sensitive, 
                    $spoilerText, 
                    !empty($mediaAttachments) ? json_encode($mediaAttachments) : null, 
                    $language, 
                    $reblogOfId,
                    $idempotencyKey,
                    Database::nowUtc()
                ]);
                $insertedId = Database::lastInsertId($db, 'statuses');
                $db->commit();
                return $insertedId;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        });

        $canonicalUri = "$proto://$domain/users/{$account['username']}/statuses/$newId";
        try {
            Database::executeWithRetry(function() use ($db, $canonicalUri, $newId) {
                $stmtUpdateUri = $db->prepare("UPDATE statuses SET uri = ? WHERE id = ?");
                $stmtUpdateUri->execute([$canonicalUri, $newId]);
            });
            $uri = $canonicalUri;
        } catch (\Throwable $e) {}

        // Operaciones accesorias protegidas (no deben romper la creación del estado)
        try {
            \KutSocial\NotificationHelper::fetchAndSaveLinkCard((int)$newId, $content);
        } catch (\Throwable $e) {
            error_log("KutSocial link card error: " . $e->getMessage());
        }

        try {
            \KutSocial\NotificationHelper::notifyMentionOrReply((int)$newId);
        } catch (\Throwable $e) {
            error_log("KutSocial mention notification error: " . $e->getMessage());
        }

        try {
            \KutSocial\NotificationHelper::indexStatusEntities($db, (int)$newId, $content, (int)$account['id'], $inReplyToAccountId ? (int)$inReplyToAccountId : null);
        } catch (\Throwable $e) {
            error_log("KutSocial indexStatusEntities error: " . $e->getMessage());
        }

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
                
                try {
                    Database::executeWithRetry(function() use ($db, $newId, $content, $options, $expiresAt) {
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
                    });
                } catch (\Throwable $e) {
                    error_log("KutSocial poll error: " . $e->getMessage());
                }
            }
        }

        // Obtener el status insertado con los datos de la cuenta
        $row = self::fetchStatusRow($db, $newId);

        // FEDERACIÓN: Encolar la actividad Create Note de forma protegida
        try {
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
                        } catch (\Exception $e) {}
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

            // 1.5 Procesar hashtags para federación
            if (preg_match_all('/(?<=^|[\s,;:.!?()\[\]{}])#(?!\d+\b)([\p{L}\p{N}_]+)/u', $content, $hashMatches)) {
                $uniqueHashtags = array_unique($hashMatches[1]);
                foreach ($uniqueHashtags as $tName) {
                    $tagExists = false;
                    foreach ($tags as $t) {
                        if (isset($t['type']) && $t['type'] === 'Hashtag' && isset($t['name']) && strcasecmp($t['name'], '#' . $tName) === 0) {
                            $tagExists = true;
                            break;
                        }
                    }
                    if (!$tagExists) {
                        $tags[] = [
                            'type' => 'Hashtag',
                            'href' => "$proto://$domain/tags/$tName",
                            'name' => "#$tName"
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

            // 4. Determinar bandejas de entrada (inboxes) destino (deduplicadas por inbox_url)
            $targetInboxesMap = [];
            if ($visibility !== 'direct') {
                $followersStmt = $db->prepare("
                    SELECT DISTINCT a.inbox_url FROM follows f
                    JOIN accounts a ON f.account_id = a.id
                    WHERE f.target_account_id = ? AND f.status = 'accepted' AND a.domain IS NOT NULL AND a.inbox_url IS NOT NULL AND a.inbox_url != ''
                ");
                $followersStmt->execute([$account['id']]);
                foreach ($followersStmt->fetchAll(PDO::FETCH_COLUMN) as $fib) {
                    $fib = trim((string)$fib);
                    if ($fib !== '') {
                        $targetInboxesMap[$fib] = true;
                    }
                }
            }
            foreach ($mentionedInboxes as $mib) {
                $mib = trim((string)$mib);
                if ($mib !== '') {
                    $targetInboxesMap[$mib] = true;
                }
            }
            if ($parentInboxUrl && trim((string)$parentInboxUrl) !== '') {
                $targetInboxesMap[trim((string)$parentInboxUrl)] = true;
            }
            $targetInboxes = array_keys($targetInboxesMap);

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
                            'mediaType' => ActivityPubController::getMimeTypeFromUrl($att['url']),
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
        } catch (\Throwable $e) {
            error_log("KutSocial federation queue error: " . $e->getMessage());
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
            $whereClauses[] = self::buildHomeTimelineWhereCondition($currUser, $queryParams);
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
            $dbRelays = Database::connect();
            $hasActiveRelays = (int)$dbRelays->query("SELECT COUNT(*) FROM relays WHERE status = 'accepted'")->fetchColumn() > 0;
            if (!$hasActiveRelays) {
                $whereClauses[] = "(
                    (a.domain IS NULL OR a.domain = '')
                    OR s.account_id IN (SELECT target_account_id FROM follows WHERE status = 'accepted' OR status = 'pending')
                    OR s.reblog_of_id IS NOT NULL
                )";
            } else {
                $whereClauses[] = "(
                    s.is_profile_fetch = 0 
                    OR (a.domain IS NULL OR a.domain = '')
                    OR s.account_id IN (SELECT target_account_id FROM follows WHERE status = 'accepted' OR status = 'pending')
                    OR s.reblog_of_id IS NOT NULL
                )";
            }
            $whereClauses[] = "(s.in_reply_to_id IS NULL AND (s.in_reply_to_url IS NULL OR s.in_reply_to_url = ''))";
            $whereClauses[] = "NOT (
                s.content LIKE '@%' 
                OR s.content LIKE '<p>@%' 
                OR s.content LIKE '<p><span class=\"h-card\"><a%' 
                OR s.content LIKE '<p><a% class=\"u-url mention\"%'
                OR s.content LIKE '<p><a% class=\"mention\"%'
            )";
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
            $fetchedStatuses = $stmt->fetchAll();
            $newStatuses = self::filterStatuses($fetchedStatuses);

            foreach ($fetchedStatuses as $frow) {
                $lastId = max($lastId, (int)$frow['status_id']);
            }

            foreach ($newStatuses as $row) {
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

    public static function formatLocalContentToHtml(string $content, string $domain, \PDO $db, string $proto): string {
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

        // 4.5 Convertir hashtags en enlaces
        $escaped = preg_replace_callback('/<a\b[^>]*>.*?<\/a>|<[^>]+>|(?<=^|[\s,;:.!?()\[\]{}])#(?!\d+\b)([\p{L}\p{N}_]+)/us', function($matches) use ($domain, $proto) {
            if ($matches[0][0] === '<') {
                return $matches[0];
            }
            $tagName = $matches[1];
            return '<a href="' . $proto . '://' . $domain . '/tags/' . $tagName . '" class="mention hashtag" rel="tag">#<span>' . $tagName . '</span></a>';
        }, $escaped);

        // 5. Restaurar etiquetas <a> en crudo
        if (!empty($placeholders)) {
            $escaped = strtr($escaped, $placeholders);
        }
        
        // Dividir el contenido por saltos de párrafo dobles y envolver cada uno en <p>
        $paragraphs = preg_split('/\r?\n\s*\r?\n/', $escaped);
        $htmlParagraphs = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p !== '') {
                $htmlParagraphs[] = '<p>' . nl2br($p) . '</p>';
            }
        }
        return implode('', $htmlParagraphs);
    }

    /**
     * Helper to extract tags (mentions and hashtags) in ActivityPub format from raw text
     */
    public static function extractActivityPubTagsFromRawContent(string $content, string $domain, \PDO $db, string $proto): array {
        $tags = [];
        
        // 1. Mentions
        preg_match_all('/@([a-zA-Z0-9_-]+)(?:@([a-zA-Z0-9.-]+))?/i', $content, $mMatches, PREG_SET_ORDER);
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
            }
            
            if ($mAcc) {
                $mActorUrl = $mAcc['domain'] 
                    ? "https://{$mAcc['domain']}/users/{$mAcc['username']}"
                    : "$proto://$domain/users/{$mAcc['username']}";
                
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
        
        // 2. Hashtags
        if (preg_match_all('/(?<=^|[\s,;:.!?()\[\]{}])#(?!\d+\b)([\p{L}\p{N}_]+)/u', $content, $hashMatches)) {
            $uniqueHashtags = array_unique($hashMatches[1]);
            foreach ($uniqueHashtags as $tName) {
                $tags[] = [
                    'type' => 'Hashtag',
                    'href' => "$proto://$domain/tags/$tName",
                    'name' => "#$tName"
                ];
            }
        }
        
        return $tags;
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
        $db = Database::connect();
        $idParam = $params['id'] ?? '';
        $id = 0;
        if (is_numeric($idParam)) {
            $id = (int)$idParam;
        } else {
            $stmtFind = $db->prepare("SELECT id FROM statuses WHERE uri = ? OR uri LIKE ? LIMIT 1");
            $stmtFind->execute([$idParam, "%$idParam%"]);
            $id = (int)($stmtFind->fetchColumn() ?: 0);
        }

        if ($id <= 0) {
            Router::json(['error' => 'Record not found'], 404);
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

    /**
     * Endpoint GET /api/v1/statuses/:id/source
     * Retorna la fuente (texto plano / markdown y spoiler_text) de una publicación para edición en clientes como Tokodon.
     */
    public static function getStatusSource(array $params): void {
        $id = (int)$params['id'];
        $db = Database::connect();

        $stmt = $db->prepare("SELECT id, content, spoiler_text, account_id, visibility FROM statuses WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            Router::json(['error' => 'Record not found'], 404);
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

        // Verificar visibilidad básica
        $visibility = $row['visibility'] ?? 'public';
        $statusAuthorId = (int)$row['account_id'];
        if ($visibility === 'direct' || $visibility === 'private') {
            if ($currUserId === null || ($currUserId !== $statusAuthorId)) {
                Router::json(['error' => 'Record not found'], 404);
                return;
            }
        }

        $text = $row['content'] ?? '';
        if (str_contains($text, '<p>') || str_contains($text, '<br>')) {
            $textWithBreaks = preg_replace('/<br\s*\/?>/i', "\n", $text);
            $textWithBreaks = preg_replace('/<\/p>\s*<p>/i', "\n\n", $textWithBreaks);
            $text = trim(strip_tags($textWithBreaks));
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        Router::json([
            'id' => (string)$row['id'],
            'text' => $text,
            'spoiler_text' => $row['spoiler_text'] ?? ''
        ]);
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
        if (str_starts_with($mediaType, 'image/')) {
            return 'image';
        }
        if (in_array($mediaType, ['text/html', 'text/plain', 'application/json', 'application/activity+json', 'application/ld+json', 'application/pdf'])) {
            return 'unknown';
        }
        return 'unknown';
    }

    /**
     * Helper: Detecta el tipo de media a partir de la extensión de URL cuando no hay mediaType.
     */
    public static function detectMediaTypeFromUrl(string $url): string {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $videoExts = ['mp4', 'webm', 'mov', 'ogv', 'avi', 'mkv', 'm4v'];
        $audioExts = ['mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a', 'opus', 'wma'];
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'tiff'];
        if (in_array($ext, $videoExts)) {
            return 'video';
        }
        if (in_array($ext, $audioExts)) {
            return 'audio';
        }
        if (in_array($ext, $imageExts)) {
            return 'image';
        }
        if (in_array($ext, ['html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'txt', 'pdf', 'json', 'xml'])) {
            return 'unknown';
        }
        return 'unknown';
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

    public static function formatUtcIso(?string $dateStr): string {
        if (empty($dateStr)) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        $trimmed = trim($dateStr);
        if (str_contains($trimmed, 'Z') || preg_match('/[+-]\d{2}:?\d{2}$/', $trimmed)) {
            $ts = strtotime($trimmed);
        } else {
            $ts = strtotime($trimmed . ' UTC');
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts !== false ? $ts : time());
    }

    private static ?array $userFollowingIdsCache = null;
    private static ?array $userFollowerIdsCache = null;
    private static ?int $cachedForAccountId = null;

    private static function getUserFollowingIds(\PDO $db, int $accountId): array {
        if (self::$cachedForAccountId !== $accountId || self::$userFollowingIdsCache === null) {
            self::$cachedForAccountId = $accountId;
            $stmt = $db->prepare("SELECT target_account_id FROM follows WHERE account_id = ? AND (status = 'accepted' OR status = 'pending')");
            $stmt->execute([$accountId]);
            self::$userFollowingIdsCache = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        return self::$userFollowingIdsCache;
    }

    private static function getUserFollowerIds(\PDO $db, int $accountId): array {
        if (self::$cachedForAccountId !== $accountId || self::$userFollowerIdsCache === null) {
            self::$cachedForAccountId = $accountId;
            $stmt = $db->prepare("SELECT account_id FROM follows WHERE target_account_id = ? AND (status = 'accepted' OR status = 'pending')");
            $stmt->execute([$accountId]);
            self::$userFollowerIdsCache = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        return self::$userFollowerIdsCache;
    }

    public static function preloadStatusesContext(array $rows, ?int $currentAccountId = null): void {
        if (empty($rows)) {
            return;
        }
        $db = Database::connect();

        if (self::$statusCtx['viewer_id'] !== $currentAccountId) {
            self::$statusCtx['viewer_id'] = $currentAccountId;
            self::$statusCtx['favourited'] = [];
            self::$statusCtx['bookmarked'] = [];
            self::$statusCtx['reblogged'] = [];
            self::$statusCtx['user_poll_votes'] = [];
        }

        $statusIds = [];
        $authorIds = [];
        $inReplyToIds = [];
        foreach ($rows as $r) {
            $sid = (int)($r['status_id'] ?? $r['id'] ?? 0);
            if ($sid > 0) {
                $statusIds[$sid] = true;
            }
            if (!empty($r['account_id'])) {
                $authorIds[(int)$r['account_id']] = true;
            }
            if (!empty($r['in_reply_to_id'])) {
                $inReplyToIds[(int)$r['in_reply_to_id']] = true;
            }
        }
        $statusIdList = array_keys($statusIds);
        $authorIdList = array_keys($authorIds);
        $replyIdList = array_keys($inReplyToIds);

        if (!empty($statusIdList)) {
            $ph = implode(',', array_fill(0, count($statusIdList), '?'));

            foreach ($statusIdList as $sid) {
                self::$statusCtx['fav_counts'][$sid] = 0;
                self::$statusCtx['reply_counts'][$sid] = 0;
                self::$statusCtx['reblog_counts'][$sid] = 0;
                self::$statusCtx['polls'][$sid] = null;
                if ($currentAccountId !== null) {
                    self::$statusCtx['favourited'][$sid] = false;
                    self::$statusCtx['bookmarked'][$sid] = false;
                    self::$statusCtx['reblogged'][$sid] = false;
                }
            }

            // 1. Favourites counts
            $stmtFav = $db->prepare("SELECT status_id, COUNT(*) as cnt FROM favourites WHERE status_id IN ($ph) GROUP BY status_id");
            $stmtFav->execute($statusIdList);
            foreach ($stmtFav->fetchAll() as $fr) {
                self::$statusCtx['fav_counts'][(int)$fr['status_id']] = (int)$fr['cnt'];
            }

            // 2. Reply counts
            $stmtRep = $db->prepare("SELECT in_reply_to_id, COUNT(*) as cnt FROM statuses WHERE in_reply_to_id IN ($ph) GROUP BY in_reply_to_id");
            $stmtRep->execute($statusIdList);
            foreach ($stmtRep->fetchAll() as $rr) {
                self::$statusCtx['reply_counts'][(int)$rr['in_reply_to_id']] = (int)$rr['cnt'];
            }

            // 3. Reblog counts
            $stmtReb = $db->prepare("SELECT reblog_of_id, COUNT(*) as cnt FROM statuses WHERE reblog_of_id IN ($ph) AND content = '' GROUP BY reblog_of_id");
            $stmtReb->execute($statusIdList);
            foreach ($stmtReb->fetchAll() as $br) {
                self::$statusCtx['reblog_counts'][(int)$br['reblog_of_id']] = (int)$br['cnt'];
            }

            // 4. Current user's favourited / bookmarked / reblogged
            if ($currentAccountId !== null) {
                $params = array_merge([$currentAccountId], $statusIdList);
                $stmtFavMe = $db->prepare("SELECT status_id FROM favourites WHERE account_id = ? AND status_id IN ($ph)");
                $stmtFavMe->execute($params);
                foreach ($stmtFavMe->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
                    self::$statusCtx['favourited'][(int)$sid] = true;
                }

                $stmtBookMe = $db->prepare("SELECT status_id FROM bookmarks WHERE account_id = ? AND status_id IN ($ph)");
                $stmtBookMe->execute($params);
                foreach ($stmtBookMe->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
                    self::$statusCtx['bookmarked'][(int)$sid] = true;
                }

                $stmtRebMe = $db->prepare("SELECT reblog_of_id FROM statuses WHERE account_id = ? AND reblog_of_id IN ($ph)");
                $stmtRebMe->execute($params);
                foreach ($stmtRebMe->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
                    self::$statusCtx['reblogged'][(int)$sid] = true;
                }
            }

            // 5. Polls + poll_votes
            $stmtPoll = $db->prepare("SELECT * FROM polls WHERE status_id IN ($ph)");
            $stmtPoll->execute($statusIdList);
            $pollIds = [];
            foreach ($stmtPoll->fetchAll() as $pRow) {
                self::$statusCtx['polls'][(int)$pRow['status_id']] = $pRow;
                $pid = (int)$pRow['id'];
                $pollIds[] = $pid;
                self::$statusCtx['poll_votes'][$pid] = [];
            }
            if (!empty($pollIds)) {
                $pph = implode(',', array_fill(0, count($pollIds), '?'));
                $stmtVotes = $db->prepare("SELECT poll_id, choice_index, COUNT(*) as qty FROM poll_votes WHERE poll_id IN ($pph) GROUP BY poll_id, choice_index");
                $stmtVotes->execute($pollIds);
                foreach ($stmtVotes->fetchAll() as $vr) {
                    self::$statusCtx['poll_votes'][(int)$vr['poll_id']][(int)$vr['choice_index']] = (int)$vr['qty'];
                }
                if ($currentAccountId !== null) {
                    foreach ($pollIds as $pid) {
                        self::$statusCtx['user_poll_votes'][$pid] = null;
                    }
                    $stmtVoted = $db->prepare("SELECT poll_id, choice_index FROM poll_votes WHERE account_id = ? AND poll_id IN ($pph)");
                    $stmtVoted->execute(array_merge([$currentAccountId], $pollIds));
                    foreach ($stmtVoted->fetchAll() as $uv) {
                        self::$statusCtx['user_poll_votes'][(int)$uv['poll_id']] = (int)$uv['choice_index'];
                    }
                }
            }

            // 6. Batch card & reblog_of_id
            $stmtMeta = $db->prepare("SELECT id, reblog_of_id, card FROM statuses WHERE id IN ($ph)");
            $stmtMeta->execute($statusIdList);
            foreach ($stmtMeta->fetchAll() as $mr) {
                $sid = (int)$mr['id'];
                self::$statusCtx['reblog_of_ids'][$sid] = $mr['reblog_of_id'] ? (int)$mr['reblog_of_id'] : null;
                self::$statusCtx['cards'][$sid] = !empty($mr['card']) ? json_decode($mr['card'], true) : null;
            }
        }

        // 7. in_reply_to_account_id
        if (!empty($replyIdList)) {
            $rph = implode(',', array_fill(0, count($replyIdList), '?'));
            $stmtPar = $db->prepare("SELECT id, account_id FROM statuses WHERE id IN ($rph)");
            $stmtPar->execute($replyIdList);
            foreach ($stmtPar->fetchAll() as $pr) {
                self::$statusCtx['in_reply_to_accounts'][(int)$pr['id']] = (string)$pr['account_id'];
            }
        }

        // 8. Author metadata & counts
        if (!empty($authorIdList)) {
            $aph = implode(',', array_fill(0, count($authorIdList), '?'));
            $stmtAcc = $db->prepare("SELECT id, show_source, statuses_count, followers_count, following_count, domain, password_hash FROM accounts WHERE id IN ($aph)");
            $stmtAcc->execute($authorIdList);
            $localAuthors = [];
            $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
            foreach ($stmtAcc->fetchAll() as $ar) {
                $aid = (int)$ar['id'];
                self::$statusCtx['show_source'][$aid] = isset($ar['show_source']) ? (bool)$ar['show_source'] : true;
                self::$statusCtx['account_stats'][$aid] = [
                    'followers_count' => (int)($ar['followers_count'] ?? 0),
                    'following_count' => (int)($ar['following_count'] ?? 0),
                    'statuses_count' => (int)($ar['statuses_count'] ?? 0),
                    'local_statuses' => 0,
                    'last_created' => null,
                ];
                $accDomain = !empty($ar['domain']) ? strtolower(explode(':', $ar['domain'])[0]) : '';
                if (empty($accDomain) || $accDomain === $serverHost || !empty($ar['password_hash'])) {
                    $localAuthors[] = $aid;
                }
            }

            $stmtStCnt = $db->prepare("SELECT account_id, COUNT(*) as cnt, MAX(created_at) as last_at FROM statuses WHERE account_id IN ($aph) GROUP BY account_id");
            $stmtStCnt->execute($authorIdList);
            foreach ($stmtStCnt->fetchAll() as $sr) {
                $aid = (int)$sr['account_id'];
                if (isset(self::$statusCtx['account_stats'][$aid])) {
                    self::$statusCtx['account_stats'][$aid]['local_statuses'] = (int)$sr['cnt'];
                    self::$statusCtx['account_stats'][$aid]['last_created'] = $sr['last_at'];
                }
            }

            if (!empty($localAuthors)) {
                $lph = implode(',', array_fill(0, count($localAuthors), '?'));
                $stmtFlw = $db->prepare("SELECT target_account_id, COUNT(*) as cnt FROM follows WHERE target_account_id IN ($lph) AND (status = 'accepted' OR status = 'pending') GROUP BY target_account_id");
                $stmtFlw->execute($localAuthors);
                foreach ($stmtFlw->fetchAll() as $fl) {
                    $aid = (int)$fl['target_account_id'];
                    if (isset(self::$statusCtx['account_stats'][$aid])) {
                        self::$statusCtx['account_stats'][$aid]['followers_count'] = (int)$fl['cnt'];
                    }
                }
                $stmtFing = $db->prepare("SELECT account_id, COUNT(*) as cnt FROM follows WHERE account_id IN ($lph) AND (status = 'accepted' OR status = 'pending') GROUP BY account_id");
                $stmtFing->execute($localAuthors);
                foreach ($stmtFing->fetchAll() as $fg) {
                    $aid = (int)$fg['account_id'];
                    if (isset(self::$statusCtx['account_stats'][$aid])) {
                        self::$statusCtx['account_stats'][$aid]['following_count'] = (int)$fg['cnt'];
                    }
                }
            }
        }
    }

    public static function formatStatusesBatch(array $rows, ?int $currentAccountId = null): array {
        if (empty($rows)) {
            return [];
        }
        if ($currentAccountId === null) {
            $currUser = self::getAuthenticatedAccount();
            if ($currUser) {
                $currentAccountId = (int)$currUser['id'];
            }
        }
        self::preloadStatusesContext($rows, $currentAccountId);
        $formatted = [];
        foreach ($rows as $row) {
            $formatted[] = self::formatStatus($row, $currentAccountId);
        }
        return $formatted;
    }

    public static function formatStatus(array $row, ?int $currentAccountId = null): array {
        $db = Database::connect();

        if ($currentAccountId === null) {
            $currUser = self::getAuthenticatedAccount();
            if ($currUser) {
                $currentAccountId = (int)$currUser['id'];
            }
        }

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
  
        $avatarUrl = !empty($row['avatar']) ? $row['avatar'] : "$proto://$domain/assets/default-avatar.svg";
        $headerUrl = !empty($row['header']) ? $row['header'] : "$proto://$domain/assets/default-header.svg";
  
        if (!filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
            $avatarUrl = $proto . '://' . $domain . $avatarUrl;
        }
        if (!filter_var($headerUrl, FILTER_VALIDATE_URL)) {
            $headerUrl = $proto . '://' . $domain . $headerUrl;
        }
  
        $accountData = [
            'id' => $row['account_id'],
            'username' => $row['username'],
            'domain' => $row['domain'],
            'display_name' => $row['display_name'],
            'note' => $row['note'] ?? '',
            'avatar' => $avatarUrl,
            'header' => $headerUrl,
            'avatar_description' => $row['avatar_description'] ?? '',
            'header_description' => $row['header_description'] ?? '',
            'locked' => $row['locked'] ?? 0,
            'discoverable' => $row['discoverable'] ?? 1,
            'account_created_at' => $row['account_created_at'] ?? $row['created_at'] ?? 'now',
            'emojis' => $row['account_emojis'] ?? $row['emojis'] ?? null,
            'fields' => $row['fields'] ?? null,
            'url' => $row['account_url'] ?? null
        ];
        $account = self::formatAccount($accountData);

        $authorId = (int)$row['account_id'];
        $relationship = null;
        if ($currentAccountId !== null && $authorId !== $currentAccountId) {
            $isFollowing = in_array($authorId, self::getUserFollowingIds($db, $currentAccountId), true);
            $isFollowedBy = in_array($authorId, self::getUserFollowerIds($db, $currentAccountId), true);
            if ($isFollowing || $isFollowedBy) {
                $relationship = [
                    'following' => $isFollowing,
                    'followed_by' => $isFollowedBy,
                    'mutual' => ($isFollowing && $isFollowedBy)
                ];
            }
        }
        $account['relationship'] = $relationship;
 
        $showSource = true;
        if (array_key_exists($authorId, self::$statusCtx['show_source'])) {
            $showSource = self::$statusCtx['show_source'][$authorId];
        } else {
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
        if (array_key_exists($statusId, self::$statusCtx['fav_counts'])) {
            $favCount = self::$statusCtx['fav_counts'][$statusId];
        } else {
            $stmtFav = $db->prepare("SELECT COUNT(*) FROM favourites WHERE status_id = ?");
            $stmtFav->execute([$statusId]);
            $favCount = (int)$stmtFav->fetchColumn();
        }
  
        // Replies count
        if (array_key_exists($statusId, self::$statusCtx['reply_counts'])) {
            $repCount = self::$statusCtx['reply_counts'][$statusId];
        } else {
            $stmtRep = $db->prepare("SELECT COUNT(*) FROM statuses WHERE in_reply_to_id = ?");
            $stmtRep->execute([$statusId]);
            $repCount = (int)$stmtRep->fetchColumn();
        }
  
        // Checks de interacción para usuario logueado
        $favourited = false;
        $bookmarked = false;
  
        if ($currentAccountId !== null) {
            if (self::$statusCtx['viewer_id'] === $currentAccountId && array_key_exists($statusId, self::$statusCtx['favourited'])) {
                $favourited = self::$statusCtx['favourited'][$statusId];
            } else {
                $stmtFavCheck = $db->prepare("SELECT 1 FROM favourites WHERE account_id = ? AND status_id = ? LIMIT 1");
                $stmtFavCheck->execute([$currentAccountId, $statusId]);
                $favourited = (bool)$stmtFavCheck->fetchColumn();
            }
  
            if (self::$statusCtx['viewer_id'] === $currentAccountId && array_key_exists($statusId, self::$statusCtx['bookmarked'])) {
                $bookmarked = self::$statusCtx['bookmarked'][$statusId];
            } else {
                $stmtBookCheck = $db->prepare("SELECT 1 FROM bookmarks WHERE account_id = ? AND status_id = ? LIMIT 1");
                $stmtBookCheck->execute([$currentAccountId, $statusId]);
                $bookmarked = (bool)$stmtBookCheck->fetchColumn();
            }
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
        $pollRow = null;
        if (array_key_exists($statusId, self::$statusCtx['polls'])) {
            $pollRow = self::$statusCtx['polls'][$statusId];
        } else {
            $stmtPoll = $db->prepare("SELECT * FROM polls WHERE status_id = ? LIMIT 1");
            $stmtPoll->execute([$statusId]);
            $pollRow = $stmtPoll->fetch() ?: null;
        }
        if ($pollRow) {
            $options = json_decode($pollRow['options_json'], true) ?: [];
            $pollId = (int)$pollRow['id'];
            
            $votesCount = [];
            foreach ($options as $idx => $optName) {
                $votesCount[$idx] = 0;
            }
            
            $totalVotes = 0;
            if (isset(self::$statusCtx['poll_votes'][$pollId])) {
                foreach (self::$statusCtx['poll_votes'][$pollId] as $cIdx => $qty) {
                    if (isset($votesCount[$cIdx])) {
                        $votesCount[$cIdx] = $qty;
                    }
                    $totalVotes += $qty;
                }
            } else {
                $stmtVotes = $db->prepare("SELECT choice_index, COUNT(*) as qty FROM poll_votes WHERE poll_id = ? GROUP BY choice_index");
                $stmtVotes->execute([$pollId]);
                $votesRows = $stmtVotes->fetchAll();
                foreach ($votesRows as $vr) {
                    $cIdx = (int)$vr['choice_index'];
                    $qty = (int)$vr['qty'];
                    if (isset($votesCount[$cIdx])) {
                        $votesCount[$cIdx] = $qty;
                    }
                    $totalVotes += $qty;
                }
            }
  
            $voted = false;
            $ownVote = null;
            if ($currentAccountId !== null) {
                if (self::$statusCtx['viewer_id'] === $currentAccountId && array_key_exists($pollId, self::$statusCtx['user_poll_votes'])) {
                    $ownVote = self::$statusCtx['user_poll_votes'][$pollId];
                    $voted = ($ownVote !== null);
                } else {
                    $stmtVoted = $db->prepare("SELECT choice_index FROM poll_votes WHERE poll_id = ? AND account_id = ? LIMIT 1");
                    $stmtVoted->execute([$pollId, $currentAccountId]);
                    $voteVal = $stmtVoted->fetch();
                    if ($voteVal !== false) {
                        $voted = true;
                        $ownVote = (int)$voteVal['choice_index'];
                    }
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
        if (array_key_exists($statusId, self::$statusCtx['reblog_counts'])) {
            $reblogsCount = self::$statusCtx['reblog_counts'][$statusId];
        } else {
            $stmtReb = $db->prepare("SELECT COUNT(*) FROM statuses WHERE reblog_of_id = ? AND content = ''");
            $stmtReb->execute([$statusId]);
            $reblogsCount = (int)$stmtReb->fetchColumn();
        }
 
        // Reblogged check
        $reblogged = false;
        if ($currentAccountId !== null) {
            if (self::$statusCtx['viewer_id'] === $currentAccountId && array_key_exists($statusId, self::$statusCtx['reblogged'])) {
                $reblogged = self::$statusCtx['reblogged'][$statusId];
            } else {
                $stmtRebCheck = $db->prepare("SELECT 1 FROM statuses WHERE account_id = ? AND reblog_of_id = ? LIMIT 1");
                $stmtRebCheck->execute([$currentAccountId, $statusId]);
                $reblogged = (bool)$stmtRebCheck->fetchColumn();
            }
        }
 
        // Cargar reblogged status o quote status
        $reblog = null;
        $quote = null;
        $reblogOfId = null;
        if (array_key_exists('reblog_of_id', $row)) {
            $reblogOfId = $row['reblog_of_id'];
        } elseif (array_key_exists($statusId, self::$statusCtx['reblog_of_ids'])) {
            $reblogOfId = self::$statusCtx['reblog_of_ids'][$statusId];
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
 
        $rawStatusContent = $row['status_content'] ?? $row['content'] ?? '';

        $formattedContent = (!empty($row['domain']) || str_contains($rawStatusContent, '<p>')) 
            ? $rawStatusContent 
            : self::formatLocalContentToHtml($rawStatusContent, $domain, $db, $proto);

        $tags = [];
        if (preg_match_all('/href="[^"]*\/(?:tags|tag)\/([a-zA-Z0-9_\p{L}\p{N}]+)/ui', $formattedContent, $matches)) {
            $uniqueTags = array_unique($matches[1]);
            foreach ($uniqueTags as $tName) {
                $tags[] = [
                    'name' => $tName,
                    'url' => "$proto://$domain/tags/$tName"
                ];
            }
        }
 
        $inReplyToAccountId = null;
        if (!empty($row['in_reply_to_id'])) {
            $repPid = (int)$row['in_reply_to_id'];
            if (array_key_exists($repPid, self::$statusCtx['in_reply_to_accounts'])) {
                $inReplyToAccountId = self::$statusCtx['in_reply_to_accounts'][$repPid];
            } else {
                $stmtPar = $db->prepare("SELECT account_id FROM statuses WHERE id = ? LIMIT 1");
                $stmtPar->execute([$row['in_reply_to_id']]);
                $inReplyToAccountId = $stmtPar->fetchColumn() ?: null;
                if ($inReplyToAccountId) {
                    $inReplyToAccountId = (string)$inReplyToAccountId;
                }
            }
        }

        $isRemote = !empty($row['domain']) && strtolower(explode(':', $row['domain'])[0]) !== strtolower(explode(':', $domain)[0]);

        $statusWebUrl = null;
        if ($isRemote) {
            $statusUri = $row['status_uri'] ?? '';
            if (preg_match('#^https?://([^/]+)/users/([^/]+)/statuses/([^/]+)$#i', $statusUri, $m)) {
                $statusWebUrl = "https://{$m[1]}/@{$m[2]}/{$m[3]}";
            } elseif (!empty($statusUri)) {
                $statusWebUrl = $statusUri;
            } else {
                $statusWebUrl = "$proto://$domain/@{$row['username']}/{$row['status_id']}";
            }
        } else {
            $statusWebUrl = "$proto://$domain/@{$row['username']}/{$row['status_id']}";
        }

        $canonicalUri = !empty($row['status_uri']) ? $row['status_uri'] : "$proto://$domain/users/{$row['username']}/statuses/{$row['status_id']}";

        return [
            'id' => (string)$row['status_id'],
            'created_at' => self::formatUtcIso($row['status_created_at'] ?? $row['created_at'] ?? null),
            'in_reply_to_id' => !empty($row['in_reply_to_id']) ? (string)$row['in_reply_to_id'] : null,
            'in_reply_to_account_id' => $inReplyToAccountId,
            'sensitive' => !empty($row['sensitive']),
            'spoiler_text' => $row['spoiler_text'] ?? '',
            'visibility' => $row['status_visibility'] ?? 'public',
            'language' => $row['language'] ?? 'es',
            'uri' => $canonicalUri,
            'url' => $statusWebUrl,
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
            'pinned' => false,
            'edited_at' => null,
            'filtered' => [],
            'content' => $formattedContent,
            'text' => $rawStatusContent,
            'account' => $account,
            'media_attachments' => $attachments,
            'mentions' => self::extractMentionsFromStatus($rawStatusContent, $db),
            'tags' => $tags,
            'emojis' => self::extractEmojisFromContent($formattedContent, $row),
            'card' => (function() use ($row, $db) {
                if (array_key_exists('card', $row)) {
                    return !empty($row['card']) ? json_decode($row['card'], true) : null;
                }
                $statusId = (int)($row['status_id'] ?? $row['id'] ?? 0);
                if ($statusId > 0) {
                    if (array_key_exists($statusId, self::$statusCtx['cards'])) {
                        return self::$statusCtx['cards'][$statusId];
                    }
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

    private static function extractMentionsFromStatus(?string $content, \PDO $db): array {
        $content = $content ?? '';
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $mentions = [];
        
        // 1. Detect direct @username or @username@domain in text (for raw content / local posts)
        preg_match_all('/@([a-zA-Z0-9_-]+)(?:@([a-zA-Z0-9.-]+))?/i', $content, $matches, PREG_SET_ORDER);
        
        // 2. Also detect href="/users/username" or href="https://domain/users/username" inside tags
        preg_match_all('/href="([^"]+)"[^>]*class="[^"]*mention[^"]*"/i', $content, $linkMatches, PREG_SET_ORDER);
        
        $accountsToLookup = [];
        
        // Process plain text regex matches
        foreach ($matches as $match) {
            $mUsername = $match[1];
            $mDomain = isset($match[2]) && !empty($match[2]) ? strtolower($match[2]) : null;
            if ($mDomain !== null && strcasecmp($mDomain, $domain) === 0) {
                $mDomain = null;
            }
            $key = $mUsername . ':' . ($mDomain ?: '');
            $accountsToLookup[$key] = [
                'username' => $mUsername,
                'domain' => $mDomain
            ];
        }
        
        // Process HTML link matches (for remote HTML content)
        foreach ($linkMatches as $lm) {
            $url = htmlspecialchars_decode($lm[1], ENT_QUOTES);
            $path = parse_url($url, PHP_URL_PATH);
            $host = parse_url($url, PHP_URL_HOST);
            
            if ($path && preg_match('/^\/(?:users|@|u)\/([a-zA-Z0-9_-]+)$/i', $path, $pm)) {
                $mUsername = $pm[1];
                $mDomain = ($host && strcasecmp($host, $domain) !== 0) ? strtolower($host) : null;
                $key = $mUsername . ':' . ($mDomain ?: '');
                if (!isset($accountsToLookup[$key])) {
                    $accountsToLookup[$key] = [
                        'username' => $mUsername,
                        'domain' => $mDomain
                    ];
                }
            }
        }
        
        // Lookup all accounts in the database and construct Mastodon Mention object
        foreach ($accountsToLookup as $lookup) {
            $username = $lookup['username'];
            $domainVal = $lookup['domain'];
            
            $stmt = null;
            if ($domainVal === null) {
                $stmt = $db->prepare("SELECT id, username, domain FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
                $stmt->execute([$username]);
            } else {
                $stmt = $db->prepare("SELECT id, username, domain FROM accounts WHERE username = ? AND domain = ? LIMIT 1");
                $stmt->execute([$username, $domainVal]);
            }
            
            $acc = $stmt->fetch();
            if ($acc) {
                $mActorUrl = $acc['domain'] 
                    ? "https://{$acc['domain']}/users/{$acc['username']}"
                    : "$proto://$domain/users/{$acc['username']}";
                
                $mentionAcct = $acc['domain']
                    ? "{$acc['username']}@{$acc['domain']}"
                    : $acc['username'];
                    
                $mentions[] = [
                    'id' => (string)$acc['id'],
                    'username' => $acc['username'],
                    'acct' => $mentionAcct,
                    'url' => $mActorUrl
                ];
            } else {
                $mentionAcct = $domainVal ? "{$username}@{$domainVal}" : $username;
                $mActorUrl = $domainVal ? "https://{$domainVal}/@{$username}" : "$proto://$domain/@{$username}";
                $mentions[] = [
                    'id' => '0',
                    'username' => $username,
                    'acct' => $mentionAcct,
                    'url' => $mActorUrl
                ];
            }
        }
        
        return $mentions;
    }

    private static function getRelationshipObj(\PDO $db, int $currentUserId, int $targetId): array {
        $stmtAcc = $db->prepare("SELECT username, domain FROM accounts WHERE id = ? LIMIT 1");
        $stmtAcc->execute([$targetId]);
        $targetAcc = $stmtAcc->fetch();

        $followStatus = null;
        $followedByStatus = null;
        $blocking = false;
        $muting = false;
        $domainBlocking = false;

        if ($targetAcc) {
            $tUsername = strtolower($targetAcc['username']);
            $tDomain = $targetAcc['domain'] ? strtolower($targetAcc['domain']) : '';
            $tAcct = $tDomain ? $tUsername . '@' . $tDomain : $tUsername;

            // Check follow status (current user -> target)
            $stmt = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
            $stmt->execute([$currentUserId, $targetId]);
            $followStatus = $stmt->fetchColumn();

            // Check followed by status (target -> current user)
            $stmt2 = $db->prepare("SELECT status FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
            $stmt2->execute([$targetId, $currentUserId]);
            $followedByStatus = $stmt2->fetchColumn();

            // Check blocking status (scoped to current user, memoized)
            $blocking = isset(self::getMemoizedUserBlocks($db, (int)$currentUserId)[(int)$targetId]);

            // Check muting status (scoped to current user, memoized)
            $muting = isset(self::getMemoizedUserMutes($db, (int)$currentUserId)[(int)$targetId]);

            // Check domain blocking status (memoized)
            if (!empty($tDomain)) {
                $modBlocks = self::getMemoizedModerationBlocks($db);
                $domainBlocking = in_array($tDomain, $modBlocks['domains'], true);
            }
        }

        $targetLocked = (bool)($targetAcc['locked'] ?? 0);
        $isFollowing = ($followStatus === 'accepted' || (!$targetLocked && !empty($followStatus)));
        $isFollowedBy = ($followedByStatus === 'accepted' || !empty($followedByStatus));
        $isRequested = ($followStatus === 'pending' && $targetLocked);

        $stmtF = $db->prepare("SELECT only_replies_to_me, show_reblogs FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtF->execute([$currentUserId, $targetId]);
        $followRow = $stmtF->fetch();
        $onlyRepliesToMe = $followRow ? !empty($followRow['only_replies_to_me']) : false;
        $showingReblogs = $followRow && isset($followRow['show_reblogs']) ? (bool)$followRow['show_reblogs'] : true;

        return [
            'id' => (string)$targetId,
            'following' => $isFollowing,
            'showing_reblogs' => $showingReblogs,
            'notifying' => false,
            'languages' => null,
            'followed_by' => $isFollowedBy,
            'blocking' => $blocking,
            'blocked_by' => false,
            'muting' => $muting,
            'muting_notifications' => $muting,
            'requested' => $isRequested,
            'domain_blocking' => $domainBlocking,
            'endorsement' => false,
            'note' => '',
            'only_replies_to_me' => $onlyRepliesToMe
        ];
    }

    private static function isBlockedOrMuted(\PDO $db, string $username, ?string $domain): bool {
        static $cachedDomains = null;
        static $cachedAccounts = null;

        if ($cachedDomains === null) {
            $cachedDomains = [];
            $cachedAccounts = [];
            try {
                $rows = $db->query("SELECT type, LOWER(target) as target FROM moderation_blocks")->fetchAll();
                foreach ($rows as $r) {
                    if (($r['type'] ?? '') === 'domain') {
                        $cachedDomains[$r['target']] = true;
                    } else {
                        $cachedAccounts[$r['target']] = true;
                    }
                }
            } catch (\Throwable $e) {}
        }

        $acct = $domain ? $username . '@' . $domain : $username;
        $username = strtolower($username);
        $acct = strtolower($acct);
        $domain = $domain ? strtolower($domain) : '';

        if ($domain !== '' && isset($cachedDomains[$domain])) {
            return true;
        }
        return isset($cachedAccounts[$username]) || isset($cachedAccounts[$acct]);
    }

    public static function getRelationships(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $rawIds = $_GET['id'] ?? [];
        $ids = [];
        if (is_array($rawIds)) {
            $ids = $rawIds;
        } elseif (is_string($rawIds)) {
            $ids = str_contains($rawIds, ',') ? explode(',', $rawIds) : [$rawIds];
        }

        $db = Database::connect();
        $relationships = [];

        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id === '') continue;
            $targetAccId = is_numeric($id) ? (int)$id : 0;
            if ($targetAccId === 0) {
                $targetAcc = self::findAccountByIdOrHandle($id);
                if ($targetAcc) {
                    $targetAccId = (int)$targetAcc['id'];
                }
            }
            if ($targetAccId > 0) {
                $relationships[] = self::getRelationshipObj($db, (int)$account['id'], $targetAccId);
            }
        }

        Router::json($relationships);
    }

    public static function followAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

        if ($targetId === (int)$account['id']) {
            Router::json(['error' => 'No puedes seguirte a ti mismo'], 400);
            return;
        }

        $stmtCheck = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmtCheck->execute([$account['id'], $targetId]);
        $existing = $stmtCheck->fetchColumn();

        $isRemote = !empty($targetAccount['domain']);
        $status = ($targetAccount['locked'] ?? 0) ? 'pending' : 'accepted';

        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?: $_POST;
        $paramOnlyRepliesToMe = isset($body['only_replies_to_me']) ? ($body['only_replies_to_me'] ? 1 : 0) : null;
        $paramShowReblogs = isset($body['reblogs']) ? ($body['reblogs'] ? 1 : 0) : null;

        if (!$existing) {
            $stmt = $db->prepare("
                INSERT INTO follows (account_id, target_account_id, status, only_replies_to_me, show_reblogs, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $account['id'], 
                $targetId, 
                $status, 
                $paramOnlyRepliesToMe ?? 0, 
                $paramShowReblogs ?? 1,
                Database::nowUtc()
            ]);

            if (!$isRemote && $status === 'accepted') {
                \KutSocial\NotificationHelper::notifyFollow((int)$targetId, (int)$account['id']);
            }
        } else {
            $updateSql = "UPDATE follows SET status = ?";
            $updateParams = [$status];
            if ($paramOnlyRepliesToMe !== null) {
                $updateSql .= ", only_replies_to_me = ?";
                $updateParams[] = $paramOnlyRepliesToMe;
            }
            if ($paramShowReblogs !== null) {
                $updateSql .= ", show_reblogs = ?";
                $updateParams[] = $paramShowReblogs;
            }
            $updateSql .= " WHERE id = ?";
            $updateParams[] = $existing;

            $stmt = $db->prepare($updateSql);
            $stmt->execute($updateParams);

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

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
    }

    public static function toggleExclusiveReplies(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

        $stmt = $db->prepare("SELECT id, only_replies_to_me FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
        $stmt->execute([$account['id'], $targetId]);
        $row = $stmt->fetch();
        if (!$row) {
            Router::json(['error' => 'No sigues a esta cuenta'], 400);
            return;
        }

        $newVal = empty($row['only_replies_to_me']) ? 1 : 0;
        $db->prepare("UPDATE follows SET only_replies_to_me = ? WHERE id = ?")
           ->execute([$newVal, $row['id']]);

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
    }

    public static function unfollowAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

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

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
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

        $timeline = self::formatStatusesBatch($rows, (int)$account['id']);

        self::setLinkHeader($timeline);

        Router::json($timeline);
    }

    public static function getStatusContext(array $params): void {
        $db = Database::connect();
        $idParam = $params['id'] ?? '';
        $id = 0;
        if (is_numeric($idParam)) {
            $id = (int)$idParam;
        } else {
            $stmtFind = $db->prepare("SELECT id FROM statuses WHERE uri = ? OR uri LIKE ? LIMIT 1");
            $stmtFind->execute([$idParam, "%$idParam%"]);
            $id = (int)($stmtFind->fetchColumn() ?: 0);
        }

        if ($id <= 0) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }
        
        $account = self::getAuthenticatedAccount();
        $currUserId = $account ? (int)$account['id'] : null;

        // Diferir la resolución remota de ancestros/descendientes tras responder (con cooldown de 5 min)
        $ctxCacheDir = dirname(Database::getDbPath()) . '/cache';
        $ctxLockFile = $ctxCacheDir . '/ctx_' . $id . '.lock';
        $shouldResolveRemote = !file_exists($ctxLockFile) || (time() - (int)@filemtime($ctxLockFile) >= 300);
        if ($shouldResolveRemote) {
            if (!is_dir($ctxCacheDir)) {
                @mkdir($ctxCacheDir, 0755, true);
            }
            @touch($ctxLockFile);
            register_shutdown_function(function() use ($id) {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                try {
                    \KutSocial\Controllers\ActivityPubController::resolveAncestorsForStatus($id);
                    \KutSocial\Controllers\ActivityPubController::resolveDescendantsForStatus($id);
                } catch (\Throwable $e) {
                    // Silenciar errores en resolución asíncrona
                }
            });
        }

        $stmt = $db->prepare("SELECT in_reply_to_id FROM statuses WHERE id = ?");
        $stmt->execute([$id]);
        $status = $stmt->fetch();
        if (!$status) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $statusFetcher = function($statusId) use ($db, $currUserId, $account) {
            $row = self::fetchStatusRow($db, (int)$statusId);
            if (!$row) {
                return null;
            }
            
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
                        $stContent = $row['status_content'] ?? $row['content'] ?? '';
                        if (str_contains($stContent, $actorUrlPattern) ||
                            preg_match('/@' . preg_quote($username, '/') . '\b/i', $stContent)) {
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

            return self::formatStatus($row, $currUserId);
        };

        $mainStatus = $statusFetcher($id);
        if (!$mainStatus) {
            Router::json(['error' => 'Record not found'], 404);
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
            
            $stmtReplies = $db->prepare("SELECT id FROM statuses WHERE in_reply_to_id = ? ORDER BY id ASC");
            $stmtReplies->execute([$currentId]);
            $replyIds = $stmtReplies->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            foreach ($replyIds as $repId) {
                $repStatus = $statusFetcher((int)$repId);
                if ($repStatus) {
                    $descendants[] = $repStatus;
                    $replyQueue[] = (int)$repId;
                }
            }
        }

        Router::json([
            'status' => $mainStatus,
            'ancestors' => array_values($ancestors),
            'descendants' => array_values($descendants)
        ]);
    }

    /**
     * Endpoint GET /api/v1/statuses/:id/card
     * Retorna la PreviewCard asociada a una publicación.
     */
    public static function getStatusCard(array $params): void {
        $db = Database::connect();
        $idParam = $params['id'] ?? '';
        $id = 0;
        if (is_numeric($idParam)) {
            $id = (int)$idParam;
        } else {
            $stmtFind = $db->prepare("SELECT id FROM statuses WHERE uri = ? OR uri LIKE ? LIMIT 1");
            $stmtFind->execute([$idParam, "%$idParam%"]);
            $id = (int)($stmtFind->fetchColumn() ?: 0);
        }

        if ($id <= 0) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $stmt = $db->prepare("SELECT card, content FROM statuses WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Router::json(['error' => 'Record not found'], 404);
            return;
        }

        $card = !empty($row['card']) ? json_decode($row['card'], true) : null;
        if (!$card && !empty($row['content'])) {
            \KutSocial\NotificationHelper::fetchAndSaveLinkCard($id, $row['content']);
            $stmtCard = $db->prepare("SELECT card FROM statuses WHERE id = ? LIMIT 1");
            $stmtCard->execute([$id]);
            $cardStr = $stmtCard->fetchColumn();
            $card = !empty($cardStr) ? json_decode($cardStr, true) : null;
        }

        if ($card) {
            Router::json($card);
        } else {
            Router::json(new \stdClass());
        }
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

        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $db = Database::connect();
        
        $stmtCheck = $db->prepare("SELECT account_id, uri, visibility, created_at FROM statuses WHERE id = ? LIMIT 1");
        $stmtCheck->execute([$id]);
        $existingStatus = $stmtCheck->fetch();

        if (!$existingStatus) {
            Router::json(['error' => 'Status no encontrado'], 404);
            return;
        }

        if ((int)$existingStatus['account_id'] !== (int)$account['id']) {
            Router::json(['error' => 'No tienes permiso para editar este status'], 403);
            return;
        }

        // Carga de media attachments
        $mediaIds = $body['media_ids'] ?? [];
        $mediaAttachments = [];
        if (!empty($mediaIds) && is_array($mediaIds)) {
            $uploadsDir = dirname(Database::getDbPath()) . '/uploads';
            foreach ($mediaIds as $mId) {
                $mId = preg_replace('/[^a-zA-Z0-9]/', '', $mId);
                $matches = glob($uploadsDir . "/media_" . $mId . ".*");
                $matches = array_filter($matches ?: [], function($f) {
                    return !in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['txt', 'owner'], true);
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
                        'blurhash' => null
                    ];
                }
            }
        }

        $mediaJson = !empty($mediaAttachments) ? json_encode($mediaAttachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare("
            UPDATE statuses 
            SET content = ?, sensitive = ?, spoiler_text = ?, media_attachments = ? 
            WHERE id = ?
        ");
        $stmt->execute([$content, $sensitive, $spoilerText, $mediaJson, $id]);

        $stmtFetch = $db->prepare("
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
        $stmtFetch->execute([$id]);
        $row = $stmtFetch->fetch();

        // Federar actividad Update a seguidores remotos
        try {
            $statusUri = $existingStatus['uri'] ?? '';
            $visibility = $existingStatus['visibility'] ?? 'public';
            if ($statusUri !== '' && $visibility !== 'direct') {
                $actorUrl = "$proto://$domain/users/{$account['username']}";
                $to = [];
                $cc = [];
                if ($visibility === 'public') {
                    $to[] = 'https://www.w3.org/ns/activitystreams#Public';
                    $cc[] = "$actorUrl/followers";
                } elseif ($visibility === 'unlisted') {
                    $to[] = "$actorUrl/followers";
                    $cc[] = 'https://www.w3.org/ns/activitystreams#Public';
                } else {
                    $to[] = "$actorUrl/followers";
                }

                $followersStmt = $db->prepare("
                    SELECT DISTINCT a.inbox_url FROM follows f
                    JOIN accounts a ON f.account_id = a.id
                    WHERE f.target_account_id = ? AND f.status = 'accepted' AND a.domain IS NOT NULL AND a.inbox_url IS NOT NULL AND a.inbox_url != ''
                ");
                $followersStmt->execute([$account['id']]);
                $targetInboxes = array_values(array_filter(array_map('trim', $followersStmt->fetchAll(PDO::FETCH_COLUMN))));

                if (!empty($targetInboxes)) {
                    $htmlContent = self::formatLocalContentToHtml($content, $domain, $db, $proto);
                    $publishedTs = !empty($existingStatus['created_at']) ? strtotime($existingStatus['created_at'] . ' UTC') : time();
                    $noteObject = [
                        'id' => $statusUri,
                        'type' => 'Note',
                        'published' => date('c', $publishedTs ?: time()),
                        'updated' => date('c'),
                        'attributedTo' => $actorUrl,
                        'content' => $htmlContent,
                        'sensitive' => (bool)$sensitive,
                        'to' => $to,
                        'cc' => $cc
                    ];
                    if ($spoilerText !== '') {
                        $noteObject['summary'] = $spoilerText;
                    }
                    if (!empty($mediaAttachments)) {
                        $attachmentsList = [];
                        foreach ($mediaAttachments as $att) {
                            $attachmentsList[] = [
                                'type' => 'Document',
                                'mediaType' => ActivityPubController::getMimeTypeFromUrl($att['url']),
                                'url' => $att['url'],
                                'name' => !empty($att['description']) ? $att['description'] : null
                            ];
                        }
                        $noteObject['attachment'] = $attachmentsList;
                    }

                    $updateActivity = [
                        '@context' => 'https://www.w3.org/ns/activitystreams',
                        'id' => $statusUri . '#update-' . time(),
                        'type' => 'Update',
                        'actor' => $actorUrl,
                        'published' => date('c'),
                        'to' => $to,
                        'cc' => $cc,
                        'object' => $noteObject
                    ];

                    foreach ($targetInboxes as $inboxUrl) {
                        \KutSocial\Queue::enqueue('Update', $updateActivity, $inboxUrl);
                    }
                    \KutSocial\Queue::triggerAsync($domain);
                }
            }
        } catch (\Throwable $e) {
            error_log("KutSocial update federation error: " . $e->getMessage());
        }

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

        $stmt = $db->prepare(Database::insertIgnoreSql('favourites', 'account_id, status_id', '?, ?'));
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

        $stmt = $db->prepare(Database::insertIgnoreSql('bookmarks', 'account_id, status_id', '?, ?'));
        $stmt->execute([$account['id'], $id]);

        $stmt = $db->prepare(Database::insertIgnoreSql('bookmarks', 'account_id, status_id', '?, ?'));
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

    private static function getMemoizedModerationBlocks(\PDO $db): array {
        if (self::$memoModerationBlocks !== null) {
            return self::$memoModerationBlocks;
        }
        $blocks = $db->query("SELECT type, target FROM moderation_blocks")->fetchAll();
        $parsed = [
            'domains' => [],
            'accounts' => [],
            'words' => [],
            'hashtags' => [],
            'languages' => [],
        ];
        foreach ($blocks as $b) {
            $t = strtolower($b['target']);
            if ($b['type'] === 'domain') {
                $parsed['domains'][] = $t;
            } elseif ($b['type'] === 'account' || $b['type'] === 'mute') {
                $parsed['accounts'][] = $t;
            } elseif ($b['type'] === 'word') {
                $parsed['words'][] = $t;
            } elseif ($b['type'] === 'hashtag') {
                if (!str_starts_with($t, '#')) {
                    $t = '#' . $t;
                }
                $parsed['hashtags'][] = $t;
            } elseif ($b['type'] === 'language') {
                $parsed['languages'][] = $t;
            }
        }
        self::$memoModerationBlocks = $parsed;
        return $parsed;
    }

    private static function getMemoizedUserMutes(\PDO $db, int $accountId): array {
        if (isset(self::$memoUserMutes[$accountId])) {
            return self::$memoUserMutes[$accountId];
        }
        $stmt = $db->prepare("SELECT target_account_id FROM user_mutes WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::$memoUserMutes[$accountId] = array_flip($ids);
        return self::$memoUserMutes[$accountId];
    }

    private static function getMemoizedUserBlocks(\PDO $db, int $accountId): array {
        if (isset(self::$memoUserBlocks[$accountId])) {
            return self::$memoUserBlocks[$accountId];
        }
        $stmt = $db->prepare("SELECT target_account_id FROM user_blocks WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::$memoUserBlocks[$accountId] = array_flip($ids);
        return self::$memoUserBlocks[$accountId];
    }

    private static function filterStatuses(array $rows): array {
        $db = Database::connect();
        
        $currUser = null;
        try {
            $currUser = self::getAuthenticatedAccount();
        } catch (\Exception $e) {}

        $modBlocks = self::getMemoizedModerationBlocks($db);
        $blockedDomains = $modBlocks['domains'];
        $blockedAccounts = $modBlocks['accounts'];
        $blockedWords = $modBlocks['words'];
        $blockedHashtags = $modBlocks['hashtags'];
        $blockedLanguages = $modBlocks['languages'];

        // Cuentas con filtro 'only_replies_to_me' activado por el usuario actual (memoizado por petición)
        $exclusiveAccountIds = [];
        $exclusiveHandles = [];
        if ($currUser) {
            $uid = (int)$currUser['id'];
            if (!isset(self::$memoExclusiveTargets[$uid])) {
                $stmtExcl = $db->prepare("
                    SELECT DISTINCT a2.id, LOWER(a2.username) as u, LOWER(COALESCE(a2.domain, '')) as d
                    FROM accounts a2
                    JOIN accounts a1 ON (LOWER(a1.username) = LOWER(a2.username) AND LOWER(COALESCE(a1.domain, '')) = LOWER(COALESCE(a2.domain, '')))
                    JOIN follows f ON f.target_account_id = a1.id
                    WHERE f.account_id = ? AND f.only_replies_to_me = 1
                ");
                $stmtExcl->execute([$uid]);
                $exclRows = $stmtExcl->fetchAll();
                $ids = [];
                $handles = [];
                foreach ($exclRows as $er) {
                    $ids[] = (int)$er['id'];
                    if (!empty($er['d'])) {
                        $handles[] = $er['u'] . '@' . $er['d'];
                    }
                    $handles[] = $er['u'];
                }
                self::$memoExclusiveTargets[$uid] = ['ids' => $ids, 'handles' => $handles];
            }
            $exclusiveAccountIds = self::$memoExclusiveTargets[$uid]['ids'];
            $exclusiveHandles = self::$memoExclusiveTargets[$uid]['handles'];
        }

        $filtered = [];
        foreach ($rows as $row) {
            $authorId = (int)($row['account_id'] ?? $row['id'] ?? 0);
            $authorUser = strtolower($row['username'] ?? '');
            $authorDom = strtolower($row['domain'] ?? '');
            $authorHandle = $authorDom ? ($authorUser . '@' . $authorDom) : $authorUser;

            $isExclusiveTarget = (!empty($exclusiveAccountIds) && in_array($authorId, $exclusiveAccountIds, true))
                || (!empty($exclusiveHandles) && (in_array($authorHandle, $exclusiveHandles, true) || in_array($authorUser, $exclusiveHandles, true)));

            // Filtrar cuentas marcadas como exclusivas si no van dirigidas a mí
            if ($isExclusiveTarget && $currUser) {
                $myUsername = $currUser['username'];
                $content = $row['status_content'] ?? $row['content'] ?? '';
                $mentionPattern = '/@' . preg_quote($myUsername, '/') . '(?![a-zA-Z0-9_\-])/i';
                $urlPattern = '/\/users\/' . preg_quote($myUsername, '/') . '(?![a-zA-Z0-9_\-])/i';
                $isMentioned = preg_match($mentionPattern, $content) || preg_match($urlPattern, $content);

                $inReplyToAccountId = (int)($row['in_reply_to_account_id'] ?? 0);
                $isReplyToMe = ($inReplyToAccountId === (int)$currUser['id']);

                if (!$isMentioned && !$isReplyToMe) {
                    continue; // Excluir respuesta de bot a terceros
                }
            }

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

    private static function paginateAndFilterStatuses(
        \PDO $db,
        array $baseWhereClauses,
        array $baseQueryParams,
        ?int $maxId,
        ?int $sinceId,
        ?int $minId,
        int $limit
    ): array {
        $rows = [];
        $currentMaxId = $maxId;

        while (count($rows) < $limit) {
            $whereClauses = $baseWhereClauses;
            $queryParams = $baseQueryParams;

            if ($currentMaxId !== null) {
                $whereClauses[] = "s.id < ?";
                $queryParams[] = $currentMaxId;
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
            if (!empty($whereSql)) {
                $whereSql = "WHERE " . $whereSql;
            }

            $remaining = $limit - count($rows);

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
                LIMIT $remaining
            ");
            $stmt->execute($queryParams);
            $fetched = $stmt->fetchAll();

            if (empty($fetched)) {
                break;
            }

            $filtered = self::filterStatuses($fetched);
            $rows = array_merge($rows, $filtered);

            $lastRow = end($fetched);
            $currentMaxId = (int)$lastRow['status_id'];
        }

        return $rows;
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

        $allowedMimeMap = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/avif' => ['avif'],
            'video/mp4' => ['mp4', 'm4v'],
            'video/webm' => ['webm'],
            'video/quicktime' => ['mov'],
            'audio/mpeg' => ['mp3'],
            'audio/mp4' => ['m4a'],
            'audio/ogg' => ['ogg', 'opus'],
            'audio/wav' => ['wav'],
            'audio/x-wav' => ['wav'],
            'audio/aac' => ['aac']
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? strtolower((string)finfo_file($finfo, $file['tmp_name'])) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($realMime === '' || !isset($allowedMimeMap[$realMime])) {
            self::log("uploadMedia: Tipo MIME real no permitido: '$realMime'");
            Router::json(['error' => 'Tipo de archivo multimedia no permitido.'], 400);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedMimeMap[$realMime], true)) {
            $ext = $allowedMimeMap[$realMime][0];
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

        file_put_contents($uploadsDir . '/media_' . $mediaId . '.owner', (string)$account['id']);

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

        $ownerFile = $uploadsDir . '/media_' . $mediaId . '.owner';
        if (!file_exists($ownerFile) || (int)trim((string)file_get_contents($ownerFile)) !== (int)$account['id']) {
            Router::json(['error' => 'Forbidden'], 403);
            return;
        }

        $matches = glob($uploadsDir . "/media_" . $mediaId . ".*");
        $matches = array_values(array_filter($matches ?: [], fn($p) => !str_ends_with($p, '.owner') && !str_ends_with($p, '.txt')));
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
        $idParam = $params['id'] ?? '';
        $account = self::findAccountByIdOrHandle($idParam);
        if (!$account) {
            $authAcc = self::getAuthenticatedAccount();
            if ($authAcc && ($idParam === (string)$authAcc['id'] || $idParam === $authAcc['username'] || empty($idParam))) {
                $account = $authAcc;
            }
        }
        if (!$account) {
            Router::json([]);
            return;
        }

        $id = (int)$account['id'];
        $db = Database::connect();

        $limit = min(80, max(1, (int)($_GET['limit'] ?? 40)));
        $maxId = isset($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : null;

        $sql = "SELECT a.* 
                FROM accounts a 
                JOIN follows f ON a.id = f.account_id 
                WHERE f.target_account_id = ? 
                  AND (f.status = 'accepted' OR f.status = 'pending' OR f.status IS NULL OR f.status = '')";
        $queryParams = [$id];

        if ($maxId) {
            $sql .= " AND a.id < ?";
            $queryParams[] = $maxId;
        }
        if ($sinceId) {
            $sql .= " AND a.id > ?";
            $queryParams[] = $sinceId;
        }

        $sql .= " ORDER BY f.id DESC LIMIT ?";
        $queryParams[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = self::formatAccount($row);
        }
        self::setLinkHeader($accounts);
        Router::json($accounts);
    }

    public static function getFollowing(array $params): void {
        $idParam = $params['id'] ?? '';
        $account = self::findAccountByIdOrHandle($idParam);
        if (!$account) {
            $authAcc = self::getAuthenticatedAccount();
            if ($authAcc && ($idParam === (string)$authAcc['id'] || $idParam === $authAcc['username'] || empty($idParam))) {
                $account = $authAcc;
            }
        }
        if (!$account) {
            Router::json([]);
            return;
        }

        $id = (int)$account['id'];
        $db = Database::connect();

        $limit = min(80, max(1, (int)($_GET['limit'] ?? 40)));
        $maxId = isset($_GET['max_id']) ? (int)$_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : null;

        $sql = "SELECT a.* 
                FROM accounts a 
                JOIN follows f ON a.id = f.target_account_id 
                WHERE f.account_id = ? 
                  AND (f.status = 'accepted' OR f.status = 'pending' OR f.status IS NULL OR f.status = '')";
        $queryParams = [$id];

        if ($maxId) {
            $sql .= " AND a.id < ?";
            $queryParams[] = $maxId;
        }
        if ($sinceId) {
            $sql .= " AND a.id > ?";
            $queryParams[] = $sinceId;
        }

        $sql .= " ORDER BY f.id DESC LIMIT ?";
        $queryParams[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = self::formatAccount($row);
        }
        self::setLinkHeader($accounts);
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
        $posts = self::formatStatusesBatch($rows, (int)$account['id']);
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
            WHERE f.account_id = ? AND (f.status = 'accepted' OR f.status = 'pending' OR f.status IS NULL OR f.status = '')
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
            WHERE f.target_account_id = ? AND (f.status = 'accepted' OR f.status = 'pending' OR f.status IS NULL OR f.status = '')
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
        // Quitar BOM UTF-8 (\xEF\xBB\xBF) si existe
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
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
                        $stmt = $db->prepare(Database::insertIgnoreSql('moderation_blocks', 'type, target', '?, ?'));
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
                    $stmt = $db->prepare(Database::insertIgnoreSql('moderation_blocks', 'type, target', "'domain', ?"));
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
                    $h = strtolower(trim($row[0] ?? ''));
                    if ($h === 'account address' || $h === 'address' || $h === 'account') continue;
                }
                $address = trim($row[0]);
                if (!empty($address)) {
                    $stmt = $db->prepare(Database::insertIgnoreSql('moderation_blocks', 'type, target', "'account', ?"));
                    $stmt->execute([$address]);
                    $importedCount++;
                }
            }
        } elseif ($type === 'mutes') {
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    $h = strtolower(trim($row[0] ?? ''));
                    if ($h === 'account address' || $h === 'address' || $h === 'account') continue;
                }
                $address = trim($row[0], " \t\n\r\0\x0B\"'@");
                if (!empty($address)) {
                    $stmt = $db->prepare(Database::insertIgnoreSql('moderation_blocks', 'type, target', "'mute', ?"));
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
                        $stmtIns = $db->prepare(Database::insertIgnoreSql('bookmarks', 'account_id, status_id', '?, ?'));
                        $stmtIns->execute([$account['id'], $statusId]);
                        $importedCount++;
                    } else {
                        $errorCount++;
                    }
                }
            }
        } elseif ($type === 'follows') {
            $mutual = !empty($_POST['mutual']);
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    $h = strtolower(trim($row[0] ?? ''));
                    if ($h === 'account address' || $h === 'address' || $h === 'account' || $h === 'acct' || $h === 'usuario') continue;
                }
                $address = trim($row[0], " \t\n\r\0\x0B\"'");
                if (!empty($address)) {
                    $cleanAddress = ltrim($address, '@');
                    $parts = explode('@', $cleanAddress);
                    $uname = $parts[0] ?? '';
                    $udomain = $parts[1] ?? null;
                    if (!empty($uname)) {
                        if ($udomain) {
                            $stmtAcc = $db->prepare("SELECT id, domain, inbox_url, url FROM accounts WHERE LOWER(username) = LOWER(?) AND LOWER(domain) = LOWER(?) LIMIT 1");
                            $stmtAcc->execute([$uname, $udomain]);
                        } else {
                            $stmtAcc = $db->prepare("SELECT id, domain, inbox_url, url FROM accounts WHERE LOWER(username) = LOWER(?) AND (domain IS NULL OR domain = '') LIMIT 1");
                            $stmtAcc->execute([$uname]);
                        }
                        $targetAcc = $stmtAcc->fetch();
                        if ($targetAcc) {
                            $targetId = (int)$targetAcc['id'];
                            if ($targetId === (int)$account['id']) {
                                continue;
                            }
                            $stmtFollow = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                            $stmtFollow->execute([$account['id'], $targetId]);
                            if (!$stmtFollow->fetchColumn()) {
                                $isRemote = !empty($targetAcc['domain']);
                                $status = ($targetAcc['locked'] ?? 0) ? 'pending' : 'accepted';
                                $stmtIns = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, ?)");
                                $stmtIns->execute([$account['id'], $targetId, $status]);
                                if ($isRemote && !empty($targetAcc['inbox_url'])) {
                                    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                    $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                    $localActorUrl = "$proto://$domainName/users/{$account['username']}";
                                    $remoteActorUrl = $targetAcc['url'] ?? "$proto://{$targetAcc['domain']}/users/{$targetAcc['username']}";
                                    $followActivity = [
                                        '@context' => 'https://www.w3.org/ns/activitystreams',
                                        'id' => $localActorUrl . '/activities/follow-' . bin2hex(random_bytes(8)),
                                        'type' => 'Follow',
                                        'actor' => $localActorUrl,
                                        'object' => $remoteActorUrl
                                    ];
                                    \KutSocial\Queue::enqueue('Follow', $followActivity, $targetAcc['inbox_url']);
                                }
                            }
                            $importedCount++;
                            continue;
                        }
                    }

                    // Encolar de forma asíncrona
                    $stmtJob = $db->prepare("INSERT INTO jobs (activity_type, payload, inbox_url, status, next_attempt) VALUES ('ImportFollow', ?, 'local', 'pending', ?)");
                    $stmtJob->execute([
                        json_encode([
                            'account_id' => $account['id'],
                            'address' => $address,
                            'mutual' => $mutual
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        Database::nowUtc()
                    ]);
                    $importedCount++;
                }
            }
            if ($importedCount > 0) {
                try {
                    \KutSocial\Queue::process(15);
                } catch (\Exception $e) {}
                $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
                \KutSocial\Queue::triggerAsync($domainName);
            }
        } elseif ($type === 'followers') {
            $mutual = !empty($_POST['mutual']);
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || empty($row[0])) continue;
                if ($header) {
                    $header = false;
                    $h = strtolower(trim($row[0] ?? ''));
                    if ($h === 'account address' || $h === 'address' || $h === 'account' || $h === 'acct' || $h === 'usuario' || $h === 'follower') continue;
                }
                $address = trim($row[0], " \t\n\r\0\x0B\"'");
                if (!empty($address)) {
                    $cleanAddress = ltrim($address, '@');
                    $parts = explode('@', $cleanAddress);
                    $uname = $parts[0] ?? '';
                    $udomain = $parts[1] ?? null;
                    if (!empty($uname)) {
                        if ($udomain) {
                            $stmtAcc = $db->prepare("SELECT id, domain, inbox_url, url FROM accounts WHERE LOWER(username) = LOWER(?) AND LOWER(domain) = LOWER(?) LIMIT 1");
                            $stmtAcc->execute([$uname, $udomain]);
                        } else {
                            $stmtAcc = $db->prepare("SELECT id, domain, inbox_url, url FROM accounts WHERE LOWER(username) = LOWER(?) AND (domain IS NULL OR domain = '') LIMIT 1");
                            $stmtAcc->execute([$uname]);
                        }
                        $targetAcc = $stmtAcc->fetch();
                        if ($targetAcc) {
                            $targetId = (int)$targetAcc['id'];
                            // Registrar relación de seguidor
                            $stmtFollow = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                            $stmtFollow->execute([$targetId, $account['id']]);
                            if (!$stmtFollow->fetchColumn()) {
                                $stmtIns = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, 'accepted')");
                                $stmtIns->execute([$targetId, $account['id']]);
                            }
                            if ($mutual) {
                                $stmtFollowBack = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                                $stmtFollowBack->execute([$account['id'], $targetId]);
                                if (!$stmtFollowBack->fetchColumn()) {
                                    $isRemote = !empty($targetAcc['domain']);
                                    $status = ($targetAcc['locked'] ?? 0) ? 'pending' : 'accepted';
                                    $stmtInsBack = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, ?)");
                                    $stmtInsBack->execute([$account['id'], $targetId, $status]);

                                    if ($isRemote && !empty($targetAcc['inbox_url'])) {
                                        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                        $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                        $localActorUrl = "$proto://$domainName/users/{$account['username']}";
                                        $remoteActorUrl = $targetAcc['url'] ?? "$proto://{$targetAcc['domain']}/users/{$targetAcc['username']}";
                                        $followActivity = [
                                            '@context' => 'https://www.w3.org/ns/activitystreams',
                                            'id' => $localActorUrl . '/activities/follow-' . bin2hex(random_bytes(8)),
                                            'type' => 'Follow',
                                            'actor' => $localActorUrl,
                                            'object' => $remoteActorUrl
                                        ];
                                        \KutSocial\Queue::enqueue('Follow', $followActivity, $targetAcc['inbox_url']);
                                    }
                                }
                            }
                            $importedCount++;
                            continue;
                        }
                    }

                    // Encolar importación de seguidor de forma asíncrona
                    $stmtJob = $db->prepare("INSERT INTO jobs (activity_type, payload, inbox_url, status, next_attempt) VALUES ('ImportFollower', ?, 'local', 'pending', ?)");
                    $stmtJob->execute([
                        json_encode([
                            'account_id' => $account['id'],
                            'address' => $address,
                            'mutual' => $mutual
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        Database::nowUtc()
                    ]);
                    $importedCount++;
                }
            }
            if ($importedCount > 0) {
                try {
                    \KutSocial\Queue::process(15);
                } catch (\Exception $e) {}
                $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
                \KutSocial\Queue::triggerAsync($domainName);
            }
        } elseif ($type === 'lists') {
            $lines = explode("\n", str_replace("\r", "", $content));
            $header = true;
            $listsCache = [];
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (empty($row) || count($row) < 2) continue;
                if ($header) {
                    $header = false;
                    $h0 = strtolower(trim($row[0] ?? ''));
                    $h1 = strtolower(trim($row[1] ?? ''));
                    if (($h0 === 'list name' || $h0 === 'list' || $h0 === 'lista') && ($h1 === 'account address' || $h1 === 'address' || $h1 === 'account' || $h1 === 'usuario')) continue;
                }
                $listTitle = trim($row[0]);
                $address = trim($row[1], " \t\n\r\0\x0B\"'");
                if (empty($listTitle) || empty($address)) continue;

                if (!isset($listsCache[$listTitle])) {
                    $stmtList = $db->prepare("SELECT id FROM lists WHERE account_id = ? AND LOWER(title) = LOWER(?) LIMIT 1");
                    $stmtList->execute([$account['id'], $listTitle]);
                    $lId = $stmtList->fetchColumn();
                    if (!$lId) {
                        $stmtInsList = $db->prepare("INSERT INTO lists (account_id, title) VALUES (?, ?)");
                        $stmtInsList->execute([$account['id'], $listTitle]);
                        $lId = Database::lastInsertId($db, 'lists');
                    }
                    $listsCache[$listTitle] = (int)$lId;
                }
                $listId = $listsCache[$listTitle];

                $cleanAddress = ltrim($address, '@');
                $parts = explode('@', $cleanAddress);
                $uname = $parts[0] ?? '';
                $udomain = $parts[1] ?? null;
                $targetId = null;
                if (!empty($uname)) {
                    if ($udomain) {
                        $stmtAcc = $db->prepare("SELECT id FROM accounts WHERE LOWER(username) = LOWER(?) AND LOWER(domain) = LOWER(?) LIMIT 1");
                        $stmtAcc->execute([$uname, $udomain]);
                    } else {
                        $stmtAcc = $db->prepare("SELECT id FROM accounts WHERE LOWER(username) = LOWER(?) AND (domain IS NULL OR domain = '') LIMIT 1");
                        $stmtAcc->execute([$uname]);
                    }
                    $targetId = $stmtAcc->fetchColumn();
                }

                if ($targetId) {
                    $stmtAdd = $db->prepare(Database::insertIgnoreSql('list_accounts', 'list_id, account_id', '?, ?'));
                    $stmtAdd->execute([$listId, $targetId]);

                    $stmtCheckFollow = $db->prepare("SELECT id FROM follows WHERE account_id = ? AND target_account_id = ? LIMIT 1");
                    $stmtCheckFollow->execute([$account['id'], $targetId]);
                    if (!$stmtCheckFollow->fetchColumn()) {
                        $stmtInsF = $db->prepare("INSERT INTO follows (account_id, target_account_id, status) VALUES (?, ?, 'pending')");
                        $stmtInsF->execute([$account['id'], $targetId]);
                    }
                    $importedCount++;
                } else {
                    $stmtJob = $db->prepare("INSERT INTO jobs (activity_type, payload, inbox_url, status, next_attempt) VALUES ('ImportListItem', ?, 'local', 'pending', ?)");
                    $stmtJob->execute([
                        json_encode([
                            'account_id' => $account['id'],
                            'list_id' => $listId,
                            'address' => $address
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        Database::nowUtc()
                    ]);
                    $importedCount++;
                }
            }
            if ($importedCount > 0) {
                try {
                    \KutSocial\Queue::process(15);
                } catch (\Exception $e) {}
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

    public static function exportLists(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT l.title, a.username, a.domain
            FROM list_accounts la
            JOIN lists l ON la.list_id = l.id
            JOIN accounts a ON la.account_id = a.id
            WHERE l.account_id = ?
            ORDER BY l.title ASC
        ");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lists.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['List name', 'Account address']);

        foreach ($rows as $row) {
            $addr = $row['domain'] ? "{$row['username']}@{$row['domain']}" : $row['username'];
            fputcsv($output, [$row['title'], $addr]);
        }
        fclose($output);
        exit;
    }

    public static function processQueue(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $processed = \KutSocial\Queue::process(50);
        $db = Database::connect();
        $remaining = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn();

        Router::json([
            'success' => true,
            'processed' => $processed,
            'remaining' => $remaining
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
        $id = Database::lastInsertId($db, 'lists');
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

        $stmtInsert = $db->prepare(Database::insertIgnoreSql('list_accounts', 'list_id, account_id', '?, ?'));
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

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, $maxId, $sinceId, $minId, $limit);

        $statuses = self::formatStatusesBatch($rows, (int)$account['id']);

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
        $id = Database::lastInsertId($db, 'collections');
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

        $stmtInsert = $db->prepare(Database::insertIgnoreSql('collection_accounts', 'collection_id, account_id', '?, ?'));
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
        $stmt = $db->prepare(Database::insertIgnoreSql('followed_hashtags', 'account_id, hashtag', '?, ?'));
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

        $rows = self::paginateAndFilterStatuses($db, $whereClauses, $queryParams, $maxId, $sinceId, $minId, $limit);

        $statuses = self::formatStatusesBatch($rows, $currUserId);

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

        $statuses = self::formatStatusesBatch($rows, (int)$account['id']);

        Router::json($statuses);
    }

    public static function proxyMedia(): void {
        $url = trim((string)($_GET['url'] ?? ''));
        if ($url === '' || !\KutSocial\SecurityHelper::isSafeExternalUrl($url)) {
            http_response_code(400);
            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            echo "URL inválida o bloqueada.";
            return;
        }

        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
            'video/mp4',
            'video/webm'
        ];

        @ini_set('memory_limit', '256M');

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
        $contentType = strtolower(trim(explode(';', (string)($cachedMeta['content_type'] ?? 'image/jpeg'))[0]));
        if (!in_array($contentType, $allowedMimes, true)) {
            $contentType = 'image/jpeg';
        }
        $lastVerified = $cachedMeta['last_verified'] ?? 0;

        $hasCache = file_exists($cachePath);
        $clientEtag = $hasCache ? ('"' . md5($urlHash . ':' . @filemtime($cachePath) . ':' . @filesize($cachePath)) . '"') : null;
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        if ($hasCache && (time() - $lastVerified < 3600)) {
            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            header("Content-Type: " . $contentType);
            header("Cache-Control: public, max-age=86400");
            header("ETag: " . $clientEtag);
            if ($ifNoneMatch !== '' && (trim($ifNoneMatch) === $clientEtag || str_contains($ifNoneMatch, $clientEtag))) {
                http_response_code(304);
                return;
            }
            readfile($cachePath);
            return;
        }

        $maxBytes = 10 * 1024 * 1024; // 10 MB
        $body = '';
        $sizeExceeded = false;
        $newEtag = null;
        $newLastMod = null;
        $headerMime = '';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KutSocialImageProxy/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, \KutSocial\Database::verifySsl());
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, \KutSocial\Database::verifySsl() ? 2 : 0);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$newEtag, &$newLastMod, &$headerMime) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $val = trim($parts[1]);
                if ($name === 'etag') {
                    $newEtag = $val;
                } elseif ($name === 'last-modified') {
                    $newLastMod = $val;
                } elseif ($name === 'content-type') {
                    $headerMime = strtolower(trim(explode(';', $val)[0]));
                }
            }
            return $len;
        });

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, &$sizeExceeded, $maxBytes) {
            $chunkLen = strlen($chunk);
            if (strlen($body) + $chunkLen > $maxBytes) {
                $sizeExceeded = true;
                return 0;
            }
            $body .= $chunk;
            return $chunkLen;
        });

        $headers = [];
        if ($hasCache) {
            if ($etag) {
                $headers[] = "If-None-Match: " . $etag;
            }
            if ($lastModified) {
                $headers[] = "If-Modified-Since: " . $lastModified;
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $execOk = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($sizeExceeded) {
            http_response_code(413);
            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            echo "Archivo multimedia demasiado grande.";
            return;
        }

        if ($hasCache && ($httpCode == 304 || $execOk === false)) {
            $cachedMeta['last_verified'] = time();
            file_put_contents($metaPath, json_encode($cachedMeta));

            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            header("Content-Type: " . $contentType);
            header("Cache-Control: public, max-age=86400");
            if ($clientEtag) {
                header("ETag: " . $clientEtag);
                if ($ifNoneMatch !== '' && (trim($ifNoneMatch) === $clientEtag || str_contains($ifNoneMatch, $clientEtag))) {
                    http_response_code(304);
                    return;
                }
            }
            readfile($cachePath);
            return;
        }

        if ($httpCode == 200 && $execOk !== false && $body !== '') {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo ? strtolower((string)finfo_buffer($finfo, $body)) : '';
            if ($finfo) {
                finfo_close($finfo);
            }

            $finalMime = $detectedMime !== '' ? $detectedMime : $headerMime;
            if (!in_array($finalMime, $allowedMimes, true)) {
                http_response_code(415);
                header("Content-Security-Policy: sandbox");
                header("X-Content-Type-Options: nosniff");
                echo "Tipo multimedia no permitido.";
                return;
            }

            file_put_contents($cachePath, $body);
            file_put_contents($metaPath, json_encode([
                'etag' => $newEtag,
                'last_modified' => $newLastMod,
                'content_type' => $finalMime,
                'last_verified' => time()
            ]));

            $freshEtag = '"' . md5($urlHash . ':' . @filemtime($cachePath) . ':' . strlen($body)) . '"';
            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            header("Content-Type: " . $finalMime);
            header("Cache-Control: public, max-age=86400");
            header("ETag: " . $freshEtag);
            if ($ifNoneMatch !== '' && (trim($ifNoneMatch) === $freshEtag || str_contains($ifNoneMatch, $freshEtag))) {
                http_response_code(304);
                return;
            }
            echo $body;
            return;
        }

        if ($hasCache) {
            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            header("Content-Type: " . $contentType);
            header("Cache-Control: public, max-age=86400");
            if ($clientEtag) {
                header("ETag: " . $clientEtag);
                if ($ifNoneMatch !== '' && (trim($ifNoneMatch) === $clientEtag || str_contains($ifNoneMatch, $clientEtag))) {
                    http_response_code(304);
                    return;
                }
            }
            readfile($cachePath);
        } else {
            http_response_code(502);
            header("Content-Security-Policy: sandbox");
            header("X-Content-Type-Options: nosniff");
            echo "No se pudo obtener el recurso multimedia.";
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
                VALUES (?, ?, '', ?, ?, ?)
            ");
            $stmtIns->execute([$account['id'], $uri, $orig['visibility'], $id, Database::nowUtc()]);
            $newId = Database::lastInsertId($db, 'statuses');

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
        $statusId = \KutSocial\Controllers\ActivityPubController::fetchAndRegisterRemoteStatus($url, true);
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
        $driver = Database::getDriver();
        $now = Database::nowUtc();
        if ($driver === 'mysql') {
            $stmt = $db->prepare("
                INSERT INTO web_push_subscriptions (account_id, endpoint, key_p256dh, key_auth, alerts, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    key_p256dh = VALUES(key_p256dh),
                    key_auth = VALUES(key_auth),
                    alerts = VALUES(alerts)
            ");
        } else {
            $stmt = $db->prepare("
                INSERT INTO web_push_subscriptions (account_id, endpoint, key_p256dh, key_auth, alerts, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(account_id, endpoint) DO UPDATE SET
                    key_p256dh = excluded.key_p256dh,
                    key_auth = excluded.key_auth,
                    alerts = excluded.alerts
            ");
        }
        $stmt->execute([$account['id'], $endpoint, $p256dh, $auth, $alertsJson, $now]);
        $subId = 0;
        try {
            $subId = Database::lastInsertId($db, 'web_push_subscriptions');
        } catch (\Throwable $e) {}
        if ($subId <= 0) {
            $stmtSub = $db->prepare("SELECT id FROM web_push_subscriptions WHERE account_id = ? AND endpoint = ? LIMIT 1");
            $stmtSub->execute([$account['id'], $endpoint]);
            $subId = (int)$stmtSub->fetchColumn();
        }

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

    public static function muteAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

        $stmt = $db->prepare(Database::insertIgnoreSql('user_mutes', 'account_id, target_account_id', '?, ?'));
        $stmt->execute([(int)$account['id'], $targetId]);

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
    }

    public static function unmuteAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

        $stmt = $db->prepare("DELETE FROM user_mutes WHERE account_id = ? AND target_account_id = ?");
        $stmt->execute([(int)$account['id'], $targetId]);

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
    }

    public static function blockAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

        $tDomain = $targetAccount['domain'] ? strtolower($targetAccount['domain']) : '';

        $stmt = $db->prepare(Database::insertIgnoreSql('user_blocks', 'account_id, target_account_id', '?, ?'));
        $stmt->execute([(int)$account['id'], $targetId]);

        // Break follows
        $stmtBreak = $db->prepare("DELETE FROM follows WHERE (account_id = ? AND target_account_id = ?) OR (account_id = ? AND target_account_id = ?)");
        $stmtBreak->execute([$account['id'], $targetId, $targetId, $account['id']]);

        // Send federated ActivityPub Block activity if remote
        if ($tDomain && !empty($targetAccount['inbox_url'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$account['username']}";
            $targetActorUrl = $targetAccount['url'] ?: "$proto://{$tDomain}/users/{$targetAccount['username']}";

            $blockActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActorUrl . '/activities/block-' . bin2hex(random_bytes(8)),
                'type' => 'Block',
                'actor' => $localActorUrl,
                'object' => $targetActorUrl
            ];

            \KutSocial\Queue::enqueue('Block', $blockActivity, $targetAccount['inbox_url']);
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
    }

    public static function unblockAccount(array $params): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $idParam = $params['id'] ?? '';
        $targetAccount = self::findAccountByIdOrHandle($idParam);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $targetId = (int)$targetAccount['id'];
        $db = Database::connect();

        $stmt = $db->prepare("DELETE FROM user_blocks WHERE account_id = ? AND target_account_id = ?");
        $stmt->execute([(int)$account['id'], $targetId]);

        Router::json(self::getRelationshipObj($db, (int)$account['id'], $targetId));
    }

    public static function getMutedAccounts(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT a.* FROM accounts a
            JOIN user_mutes m ON m.target_account_id = a.id
            WHERE m.account_id = ?
            ORDER BY m.id DESC
        ");
        $stmt->execute([(int)$account['id']]);
        $rows = $stmt->fetchAll();

        $formatted = [];
        foreach ($rows as $row) {
            $formatted[] = self::formatAccount($row);
        }

        Router::json($formatted);
    }

    public static function getBlockedAccounts(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT a.* FROM accounts a
            JOIN user_blocks b ON b.target_account_id = a.id
            WHERE b.account_id = ?
            ORDER BY b.id DESC
        ");
        $stmt->execute([(int)$account['id']]);
        $rows = $stmt->fetchAll();

        $formatted = [];
        foreach ($rows as $row) {
            $formatted[] = self::formatAccount($row);
        }

        Router::json($formatted);
    }

    public static function getDomainBlocks(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT target FROM moderation_blocks WHERE type = 'domain' ORDER BY id DESC");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        Router::json($rows);
    }

    public static function blockDomain(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $domain = $_POST['domain'] ?? $_GET['domain'] ?? null;
        if (!$domain) {
            $input = json_decode(file_get_contents('php://input'), true);
            $domain = $input['domain'] ?? null;
        }

        if (empty($domain)) {
            Router::json(['error' => 'Dominio no proporcionado'], 400);
            return;
        }

        $domain = strtolower(trim($domain));
        $db = Database::connect();

        $stmt = $db->prepare(Database::insertIgnoreSql('moderation_blocks', 'type, target', "'domain', ?"));
        $stmt->execute([$domain]);

        // Break follows with accounts from this domain
        $stmtBreak = $db->prepare("
            DELETE FROM follows 
            WHERE account_id IN (SELECT id FROM accounts WHERE LOWER(domain) = ?) 
               OR target_account_id IN (SELECT id FROM accounts WHERE LOWER(domain) = ?)
        ");
        $stmtBreak->execute([$domain, $domain]);

        Router::json((object)[]);
    }

    public static function unblockDomain(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $domain = $_POST['domain'] ?? $_GET['domain'] ?? null;
        if (!$domain) {
            $input = json_decode(file_get_contents('php://input'), true);
            $domain = $input['domain'] ?? null;
        }

        if (empty($domain)) {
            Router::json(['error' => 'Dominio no proporcionado'], 400);
            return;
        }

        $domain = strtolower(trim($domain));
        $db = Database::connect();

        $stmt = $db->prepare("DELETE FROM moderation_blocks WHERE type = 'domain' AND LOWER(target) = ?");
        $stmt->execute([$domain]);

        Router::json((object)[]);
    }

    /**
     * Endpoint POST /api/v1/reports
     * Permite a los usuarios reportar una cuenta o publicación por spam, acoso, etc.
     */
    public static function createReport(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $targetAccountId = $input['account_id'] ?? '';
        if (empty($targetAccountId)) {
            Router::json(['error' => 'account_id es requerido'], 422);
            return;
        }

        $targetAccount = self::findAccountByIdOrHandle((string)$targetAccountId);
        if (!$targetAccount) {
            Router::json(['error' => 'Cuenta no encontrada'], 404);
            return;
        }

        $rawStatusIds = $input['status_ids'] ?? [];
        $statusIds = [];
        if (is_array($rawStatusIds)) {
            $statusIds = $rawStatusIds;
        } elseif (is_string($rawStatusIds) && $rawStatusIds !== '') {
            $statusIds = str_contains($rawStatusIds, ',') ? explode(',', $rawStatusIds) : [$rawStatusIds];
        }

        $comment = trim((string)($input['comment'] ?? ''));
        if ($comment === '') {
            $comment = 'Reportado por el usuario';
        }

        $category = trim((string)($input['category'] ?? 'spam'));
        $forward = !empty($input['forward']) && ($input['forward'] === true || $input['forward'] === 'true' || $input['forward'] === 1 || $input['forward'] === '1');

        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO reports (account_id, target_account_id, status_ids, comment, forward, category, action_taken, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
        $stmt->execute([
            $account['id'],
            $targetAccount['id'],
            !empty($statusIds) ? json_encode(array_values($statusIds)) : null,
            $comment,
            $forward ? 1 : 0,
            $category,
            Database::nowUtc()
        ]);
        $reportId = (string)Database::lastInsertId($db, 'reports');

        // Si se solicitó reenvío y la cuenta es remota, enviar actividad Flag ActivityPub
        $tDomain = !empty($targetAccount['domain']) ? strtolower($targetAccount['domain']) : '';
        if ($forward && $tDomain && !empty($targetAccount['inbox_url'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $localActorUrl = "$proto://$domain/users/{$account['username']}";
            $targetActorUrl = $targetAccount['url'] ?: "$proto://{$tDomain}/users/{$targetAccount['username']}";

            $objects = [$targetActorUrl];
            foreach ($statusIds as $sId) {
                $stmtSt = $db->prepare("SELECT uri FROM statuses WHERE id = ? LIMIT 1");
                $stmtSt->execute([(int)$sId]);
                $stUri = $stmtSt->fetchColumn();
                if ($stUri) {
                    $objects[] = $stUri;
                }
            }

            $flagActivity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => "$proto://$domain/reports/" . bin2hex(random_bytes(8)),
                'type' => 'Flag',
                'actor' => $localActorUrl,
                'object' => $objects,
                'content' => $comment
            ];

            \KutSocial\Queue::enqueue('Flag', $flagActivity, $targetAccount['inbox_url']);
            \KutSocial\Queue::triggerAsync($domain);
        }

        Router::json([
            'id' => $reportId,
            'action_taken' => false,
            'action_taken_at' => null,
            'category' => $category,
            'comment' => $comment,
            'forwarded' => $forward,
            'created_at' => date('c'),
            'status_ids' => array_map('strval', $statusIds),
            'rule_ids' => [],
            'target_account' => self::formatAccount($targetAccount)
        ]);
    }

    /**
     * Endpoint GET /api/v1/reports
     */
    public static function getReports(): void {
        $account = self::getAuthenticatedAccount();
        if (!$account) {
            Router::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT r.*, ta.*, ta.id as target_account_id FROM reports r LEFT JOIN accounts ta ON ta.id = r.target_account_id WHERE r.account_id = ? ORDER BY r.id DESC LIMIT 50");
        $stmt->execute([$account['id']]);
        $rows = $stmt->fetchAll();

        $reports = [];
        foreach ($rows as $row) {
            $statusIds = [];
            if (!empty($row['status_ids'])) {
                $decoded = json_decode($row['status_ids'], true);
                if (is_array($decoded)) $statusIds = $decoded;
            }
            $targetAcc = self::formatAccount($row);
            $reports[] = [
                'id' => (string)$row['id'],
                'action_taken' => (bool)$row['action_taken'],
                'action_taken_at' => $row['action_taken_at'],
                'category' => $row['category'] ?? 'other',
                'comment' => $row['comment'],
                'forwarded' => (bool)$row['forward'],
                'created_at' => date('c', strtotime($row['created_at'])),
                'status_ids' => array_map('strval', $statusIds),
                'rule_ids' => [],
                'target_account' => $targetAcc
            ];
        }

        Router::json($reports);
    }
}

