<?php
namespace KutSocial\Controllers;

use KutSocial\Router;
use KutSocial\Database;
use KutSocial\UpdaterService;
use KutSocial\TotpHelper;
use KutSocial\Queue;
use PDO;
use Exception;
use KutSocial\I18n;

class AdminController {
    /**
     * Get configured admin session duration in days.
     */
    public static function getSessionDurationDays(): int {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT value FROM options WHERE options.key = 'admin_session_days' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) {
                $days = (int)$val;
                if ($days >= 1) {
                    return $days;
                }
            }
        } catch (\Throwable $e) {}
        return 30; // 30 días por defecto
    }

    /**
     * Start session securely with configured lifetime and sliding expiration.
     */
    public static function startSecureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $days = self::getSessionDurationDays();
            $lifetime = $days * 86400;

            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            @ini_set('session.gc_maxlifetime', (string)$lifetime);

            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => $lifetime,
                    'path'     => '/',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }

            @session_start();

            // Sliding expiration para la cookie de sesión nativa si ya existe
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && isset($_COOKIE[session_name()])) {
                setcookie(
                    session_name(),
                    session_id(),
                    [
                        'expires'  => time() + $lifetime,
                        'path'     => '/',
                        'secure'   => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]
                );
            }
        }
    }

    /**
     * Issue a persistent remember token and cookie for the admin account.
     */
    public static function issueRememberToken(int $accountId): void {
        try {
            $db = Database::connect();
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $hashedValidator = hash('sha256', $validator);

            $days = self::getSessionDurationDays();
            $lifetime = $days * 86400;
            $expiresAt = time() + $lifetime;

            // Purgar tokens expirados
            $del = $db->prepare("DELETE FROM admin_sessions WHERE expires_at < ?");
            $del->execute([time()]);

            // Limitar a un máximo de 10 sesiones concurrentes por cuenta
            $stmtCount = $db->prepare("SELECT id FROM admin_sessions WHERE account_id = ? ORDER BY id ASC");
            $stmtCount->execute([$accountId]);
            $allSessions = $stmtCount->fetchAll(\PDO::FETCH_COLUMN);
            if (count($allSessions) >= 10) {
                $toRemove = array_slice($allSessions, 0, count($allSessions) - 9);
                $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                $stmtDelOld = $db->prepare("DELETE FROM admin_sessions WHERE id IN ($placeholders)");
                $stmtDelOld->execute($toRemove);
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $now = Database::nowUtc();
            $ins = $db->prepare("INSERT INTO admin_sessions (account_id, selector, hashed_validator, expires_at, ip, user_agent, created_at, last_used_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$accountId, $selector, $hashedValidator, $expiresAt, $ip, $ua, $now, $now]);

            self::setRememberCookie($selector . ':' . $validator, $expiresAt);
        } catch (\Throwable $e) {}
    }

    /**
     * Restore admin session from persistent cookie if valid.
     */
    private static function restoreSessionFromCookie(): ?int {
        $raw = $_COOKIE['kutsocial_remember'] ?? '';
        if (!$raw) return null;

        $parts = explode(':', $raw);
        if (count($parts) !== 2) {
            self::clearRememberCookie();
            return null;
        }

        [$selector, $validator] = $parts;
        if (empty($selector) || empty($validator)) {
            self::clearRememberCookie();
            return null;
        }

        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM admin_sessions WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);
            $session = $stmt->fetch();

            if (!$session) {
                self::clearRememberCookie();
                return null;
            }

            if ((int)$session['expires_at'] < time()) {
                $del = $db->prepare("DELETE FROM admin_sessions WHERE selector = ?");
                $del->execute([$selector]);
                self::clearRememberCookie();
                return null;
            }

            $calcHash = hash('sha256', $validator);
            if (!hash_equals($session['hashed_validator'], $calcHash)) {
                self::clearRememberCookie();
                return null;
            }

            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                @session_regenerate_id(true);
            }
            $_SESSION['admin_account_id'] = (int)$session['account_id'];

            // Deslizar expiración (sliding expiration) según duración configurada
            $days = self::getSessionDurationDays();
            $lifetime = $days * 86400;
            $newExpires = time() + $lifetime;

            $upd = $db->prepare("UPDATE admin_sessions SET expires_at = ?, last_used_at = ? WHERE id = ?");
            $upd->execute([$newExpires, Database::nowUtc(), $session['id']]);

            self::setRememberCookie($selector . ':' . $validator, $newExpires);

            return (int)$session['account_id'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function setRememberCookie(string $value, int $expires): void {
        if (headers_sent()) return;
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie('kutsocial_remember', $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    private static function clearRememberCookie(): void {
        if (!headers_sent()) {
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            setcookie('kutsocial_remember', '', [
                'expires'  => time() - 86400,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        unset($_COOKIE['kutsocial_remember']);
    }

    /**
     * Generate or return the per-session CSRF token for admin forms.
     */
    public static function getCsrfToken(): string {
        self::startSecureSession();
        if (empty($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['admin_csrf_token'];
    }

    /**
     * Validate CSRF token on admin POST requests.
     */
    private static function verifyCsrf(): void {
        $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $sessionToken = (string)($_SESSION['admin_csrf_token'] ?? '');
        if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
            $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
            if (str_contains($accept, 'application/json') || ($_SERVER['REQUEST_URI'] ?? '') === '/admin/update/action') {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['error' => 'Token CSRF inválido o expirado.']);
                exit;
            }
            $_SESSION['admin_error_msg'] = 'Token CSRF inválido o expirado.';
            $referer = $_SERVER['HTTP_REFERER'] ?? '/admin/dashboard';
            $path = parse_url($referer, PHP_URL_PATH) ?: '/admin/dashboard';
            if (!str_starts_with($path, '/admin/')) {
                $path = '/admin/dashboard';
            }
            header("Location: " . $path);
            exit;
        }
    }

    /**
     * Helper to verify if the admin is logged in.
     */
    private static function checkAuth(): ?array {
        $adminId = $_SESSION['admin_account_id'] ?? null;
        $db = Database::connect();

        if (!$adminId) {
            $adminId = self::restoreSessionFromCookie();
        }

        if (!$adminId) {
            header("Location: /admin/login");
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND domain IS NULL AND role IN ('admin', 'owner') LIMIT 1");
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();
        if (!$user) {
            self::clearRememberCookie();
            unset($_SESSION['admin_account_id']);
            header("Location: /admin/login");
            exit;
        }

        if (empty($_COOKIE['kutsocial_remember'])) {
            self::issueRememberToken((int)$user['id']);
        }

        return $user;
    }

    /**
     * Render the admin login page.
     */
    public static function showLogin(): void {
        if (!isset($_SESSION['admin_account_id'])) {
            self::restoreSessionFromCookie();
        }

        if (isset($_SESSION['admin_account_id'])) {
            header("Location: /admin/dashboard");
            exit;
        }

        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);

        $errorHtml = $error ? '<div class="error-msg">' . htmlspecialchars($error) . '</div>' : '';

        $show2fa = isset($_SESSION['temp_admin_id']) ? 'block' : 'none';
        $showNormal = isset($_SESSION['temp_admin_id']) ? 'none' : 'block';

        $version = KUTSOCIAL_VERSION;

        $txtTitle = htmlspecialchars(I18n::t('login') . ' - KutSocial Admin');
        $lblUser = htmlspecialchars(I18n::t('username'));
        $lblPass = htmlspecialchars(I18n::t('password'));
        $btnNext = htmlspecialchars(I18n::t('next'));
        $lbl2fa = htmlspecialchars(I18n::t('admin_2fa_title'));
        $btnVerify = htmlspecialchars(I18n::t('verify'));
        $btnCancel = htmlspecialchars(I18n::t('cancel'));
        $langCode = I18n::getLang();
        $btnEsBg = ($langCode === 'es') ? 'var(--primary)' : 'transparent';
        $btnEnBg = ($langCode === 'en') ? 'var(--primary)' : 'transparent';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="{$langCode}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$txtTitle}</title>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="/assets/admin.css?v={$version}">
        </head>
        <body class="admin-login-body">
            <div class="login-card">
                <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
                    <div style="display: inline-flex; gap: 4px; background: rgba(255,255,255,0.06); padding: 2px 4px; border-radius: 6px; font-size: 11px; font-weight: 700;">
                        <a href="/lang/es" style="color: white; text-decoration: none; padding: 2px 6px; border-radius: 4px; background: {$btnEsBg};">ES</a>
                        <a href="/lang/en" style="color: white; text-decoration: none; padding: 2px 6px; border-radius: 4px; background: {$btnEnBg};">EN</a>
                    </div>
                </div>
                <img src="/kutsocial_logo.svg" alt="Logo" style="width: 56px; height: 56px; margin: 0 auto 15px auto; display: block;">
                <h2>KutSocial Admin</h2>
                
                {$errorHtml}

                <!-- Formulario Normal -->
                <form method="POST" action="/admin/login" style="display: {$showNormal};">
                    <div class="form-group">
                        <label for="username">{$lblUser}</label>
                        <input type="text" name="username" id="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">{$lblPass}</label>
                        <input type="password" name="password" id="password" required>
                    </div>
                    <button type="submit">{$btnNext}</button>
                </form>

                <!-- Formulario 2FA -->
                <form method="POST" action="/admin/login" style="display: {$show2fa};">
                    <input type="hidden" name="step_2fa" value="1">
                    <div class="form-group">
                        <label for="otp_code">{$lbl2fa}</label>
                        <input type="text" name="otp_code" id="otp_code" placeholder="123456" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" autofocus>
                    </div>
                    <button type="submit">{$btnVerify}</button>
                    <a href="/admin/logout" class="cancel-link">{$btnCancel}</a>
                </form>
            </div>
        </body>
        </html>
        HTML;

        $btnEsBg = ($langCode === 'es') ? 'var(--primary)' : 'transparent';
        $btnEnBg = ($langCode === 'en') ? 'var(--primary)' : 'transparent';
        $html = str_replace('{$btnEsBg}', $btnEsBg, $html);
        $html = str_replace('{$btnEnBg}', $btnEnBg, $html);
        $html = str_replace('{$txtTitle}', $txtTitle, $html);
        $html = str_replace('{$lblUser}', $lblUser, $html);
        $html = str_replace('{$lblPass}', $lblPass, $html);
        $html = str_replace('{$btnNext}', $btnNext, $html);
        $html = str_replace('{$lbl2fa}', $lbl2fa, $html);
        $html = str_replace('{$btnVerify}', $btnVerify, $html);
        $html = str_replace('{$btnCancel}', $btnCancel, $html);

        // Limpiar parser de lógica simple de variables
        $html = str_replace('{$errorHtml}', $errorHtml, $html);
        $html = str_replace('{$showNormal}', $showNormal, $html);
        $html = str_replace('{$show2fa}', $show2fa, $html);
        Router::html($html);
    }

    /**
     * Handle admin login post.
     */
    public static function handleLogin(): void {
        $db = Database::connect();

        // Si es el paso de verificar el token 2FA
        if (isset($_POST['step_2fa'])) {
            $tempId = $_SESSION['temp_admin_id'] ?? null;
            if (!$tempId) {
                $_SESSION['login_error'] = "Sesión de verificación expirada.";
                header("Location: /admin/login");
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND domain IS NULL AND role IN ('admin', 'owner') LIMIT 1");
            $stmt->execute([$tempId]);
            $user = $stmt->fetch();

            $code = trim($_POST['otp_code'] ?? '');
            if ($user && TotpHelper::verifyCode($user['otp_secret'], $code)) {
                unset($_SESSION['temp_admin_id']);
                if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                    @session_regenerate_id(true);
                }
                $_SESSION['admin_account_id'] = $user['id'];
                self::issueRememberToken((int)$user['id']);
                header("Location: /admin/dashboard");
            } else {
                $_SESSION['login_error'] = "Código 2FA incorrecto o expirado.";
                header("Location: /admin/login");
            }
            exit;
        }

        // Paso normal: Usuario o correo y contraseña
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $loginId = $username;
        if (str_starts_with($loginId, '@')) {
            $loginId = explode('@', ltrim($loginId, '@'))[0];
        }

        $stmt = $db->prepare("SELECT * FROM accounts WHERE (LOWER(username) = LOWER(?) OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(?))) AND (domain IS NULL OR domain = '') AND role IN ('admin', 'owner') LIMIT 1");
        $stmt->execute([$loginId, $loginId]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['otp_secret'])) {
                // Requiere 2FA
                $_SESSION['temp_admin_id'] = $user['id'];
                header("Location: /admin/login");
            } else {
                // Login directo
                if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                    @session_regenerate_id(true);
                }
                $_SESSION['admin_account_id'] = $user['id'];
                self::issueRememberToken((int)$user['id']);
                header("Location: /admin/dashboard");
            }
        } else {
            $_SESSION['login_error'] = "Usuario o contraseña incorrectos.";
            header("Location: /admin/login");
        }
        exit;
    }

    /**
     * Handle logout.
     */
    public static function handleLogout(): void {
        $raw = $_COOKIE['kutsocial_remember'] ?? '';
        if ($raw) {
            $parts = explode(':', $raw);
            if (!empty($parts[0])) {
                try {
                    $db = Database::connect();
                    $stmt = $db->prepare("DELETE FROM admin_sessions WHERE selector = ?");
                    $stmt->execute([$parts[0]]);
                } catch (\Throwable $e) {}
            }
        }
        self::clearRememberCookie();
        unset($_SESSION['admin_account_id']);
        unset($_SESSION['kutsocial_user_id']);
        unset($_SESSION['kutsocial_auto_token']);
        unset($_SESSION['temp_admin_id']);
        unset($_SESSION['admin_csrf_token']);
        $redirect = (string)($_GET['redirect'] ?? '/admin/login');
        if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || str_contains($redirect, "\r") || str_contains($redirect, "\n")) {
            $redirect = '/admin/login';
        }
        header("Location: " . $redirect);
        exit;
    }

    /**
     * Render the admin dashboard.
     */
    public static function showDashboard(array $params = []): void {
        $admin = self::checkAuth();
        $db = Database::connect();

        $section = $params['section'] ?? 'general';
        $validSections = ['general', 'security', 'users', 'moderation', 'relays', 'update', 'maintenance', 'backups'];
        if (!in_array($section, $validSections)) {
            $section = 'general';
        }

        // 1. Obtener Límite de caracteres
        $stmtLimit = $db->prepare("SELECT value FROM options WHERE options.key = 'max_toot_chars' LIMIT 1");
        $stmtLimit->execute();
        $maxChars = (int)($stmtLimit->fetchColumn() ?: 500);

        // 2. Obtener lista de Relays
        $relays = $db->query("SELECT * FROM relays ORDER BY id DESC")->fetchAll();

        // 3. Obtener lista de Bloqueos (Moderación) y Reportes
        $blocks = $db->query("SELECT * FROM moderation_blocks ORDER BY type ASC, target ASC")->fetchAll();
        $reports = [];
        try {
            $reports = $db->query("
                SELECT r.*, 
                       a.username as reporter_username, a.domain as reporter_domain,
                       ta.username as target_username, ta.domain as target_domain
                FROM reports r
                LEFT JOIN accounts a ON a.id = r.account_id
                LEFT JOIN accounts ta ON ta.id = r.target_account_id
                ORDER BY r.id DESC
            ")->fetchAll();
        } catch (\Exception $e) {}

        // 4. Obtener lista de usuarios locales
        $localUsers = $db->query("SELECT * FROM accounts WHERE (domain IS NULL OR domain = '') AND password_hash IS NOT NULL AND password_hash != '' ORDER BY username ASC")->fetchAll();

        // 5. Datos de actualizaciones (estilo KutPod)
        $updater = new UpdaterService();
        
        $stmtToken = $db->prepare("SELECT value FROM options WHERE options.key = 'update_github_token' LIMIT 1");
        $stmtToken->execute();
        $githubToken = $stmtToken->fetchColumn() ?: '';

        $stmtGiphy = $db->prepare("SELECT value FROM options WHERE options.key = 'giphy_api_key' LIMIT 1");
        $stmtGiphy->execute();
        $giphyApiKey = $stmtGiphy->fetchColumn() ?: '';

        $stmtLang = $db->prepare("SELECT value FROM options WHERE options.key = 'site_language' LIMIT 1");
        $stmtLang->execute();
        $siteLanguage = $stmtLang->fetchColumn() ?: 'es';

        $stmtTheme = $db->prepare("SELECT value FROM options WHERE options.key = 'ui_theme' LIMIT 1");
        $stmtTheme->execute();
        $uiTheme = $stmtTheme->fetchColumn() ?: 'classic';
        if (!in_array($uiTheme, ['classic', 'mastodon'], true)) {
            $uiTheme = 'classic';
        }

        $rollbacks = $updater->getAvailableRollbacks();
        $updatesHistory = [];
        try {
            $updatesHistory = $db->query("SELECT * FROM updates ORDER BY id DESC LIMIT 20")->fetchAll();
        } catch (Exception $e) {}

        $currentVersion = \KutSocial\Database::getVersion();
        $is2faActive = !empty($admin['otp_secret']);
        $sessionDays = self::getSessionDurationDays();

        $smtpHost = htmlspecialchars($admin['smtp_host'] ?? '');
        $smtpPort = (int)($admin['smtp_port'] ?? 587);
        if ($smtpPort <= 0) $smtpPort = 587;
        $smtpUser = htmlspecialchars($admin['smtp_user'] ?? '');
        $smtpPass = htmlspecialchars($admin['smtp_pass'] ?? '');
        $smtpFrom = htmlspecialchars($admin['smtp_from'] ?? '');
        $emailNotificationsChecked = !empty($admin['email_notifications']) ? 'checked' : '';
        $attributionDomains = htmlspecialchars($admin['attribution_domains'] ?? '');
        $enabledNavItems = self::getEnabledNavItems();

        // Mensaje de éxito de ajustes
        $successMsg = $_SESSION['admin_success_msg'] ?? '';
        unset($_SESSION['admin_success_msg']);
        $errorMsg = $_SESSION['admin_error_msg'] ?? '';
        unset($_SESSION['admin_error_msg']);

        // Detección de actualización disponible para notificación y badge
        $updateCache = $updater->getCached();
        $isUpdateAvailable = false;
        $availableVersion = '';
        if ($updateCache && !empty($updateCache['available']) && !empty($updateCache['new_version'])) {
            if (version_compare($updateCache['new_version'], $currentVersion, '>')) {
                $isUpdateAvailable = true;
                $availableVersion = $updateCache['new_version'];
            }
        }
        $checkUpdateAsync = $updater->isCacheStale();

        $dbDriver = Database::getDriver();
        $backups = ($section === 'backups') ? self::listBackups() : [];

        // Incluir la vista de la plantilla
        include __DIR__ . '/../views/admin/layout.php';
    }

    /**
     * Handle Settings form post.
     */
    public static function handleSettings(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $maxChars = (int)($_POST['max_toot_chars'] ?? 500);
        $githubToken = trim($_POST['update_github_token'] ?? '');
        $giphyApiKey = trim($_POST['giphy_api_key'] ?? '');

        if ($maxChars < 500 || $maxChars > 9999) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_toot_limit_invalid', 'El límite de caracteres debe estar entre 500 y 9999.');
        } else {
            Database::upsertOption($db, 'max_toot_chars', (string)$maxChars);
            Database::upsertOption($db, 'update_github_token', $githubToken);
            Database::upsertOption($db, 'giphy_api_key', $giphyApiKey);

            // Guardar idioma de la interfaz
            $siteLanguage = trim($_POST['site_language'] ?? 'es');
            if (!in_array($siteLanguage, ['es', 'en'], true)) {
                $siteLanguage = 'es';
            }
            Database::upsertOption($db, 'site_language', $siteLanguage);
            I18n::setLang($siteLanguage);

            // Guardar tema de la interfaz
            $uiTheme = trim($_POST['ui_theme'] ?? 'classic');
            if (!in_array($uiTheme, ['classic', 'mastodon'], true)) {
                $uiTheme = 'classic';
            }
            Database::upsertOption($db, 'ui_theme', $uiTheme);

            // Guardar preferencias SMTP y Atribución
            $smtpHost = trim($_POST['smtp_host'] ?? '');
            $smtpPort = (int)($_POST['smtp_port'] ?? 587);
            $smtpUser = trim($_POST['smtp_user'] ?? '');
            $smtpPass = $_POST['smtp_pass'] ?? '';
            $smtpFrom = trim($_POST['smtp_from'] ?? '');
            $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
            $attributionDomains = trim($_POST['attribution_domains'] ?? '');

            $adminId = $_SESSION['admin_account_id'];
            $stmtUser = $db->prepare("
                UPDATE accounts SET 
                    smtp_host = ?, 
                    smtp_port = ?, 
                    smtp_user = ?, 
                    smtp_pass = ?, 
                    smtp_from = ?, 
                    email_notifications = ?, 
                    attribution_domains = ?
                WHERE id = ?
            ");
            $stmtUser->execute([
                $smtpHost ?: null,
                $smtpPort ?: null,
                $smtpUser ?: null,
                $smtpPass ?: null,
                $smtpFrom ?: null,
                $emailNotifications,
                $attributionDomains ?: null,
                $adminId
            ]);

            // Guardar opciones del menú lateral
            $submittedNav = $_POST['sidebar_nav_items'] ?? [];
            if (!is_array($submittedNav)) {
                $submittedNav = [];
            }
            $validKeys = ['home', 'catchup', 'local', 'public', 'notifications', 'bookmarks', 'direct', 'lists', 'collections', 'hashtags', 'profile', 'settings', 'admin'];
            $cleanNav = array_values(array_intersect($validKeys, $submittedNav));
            Database::upsertOption($db, 'sidebar_nav_items', json_encode($cleanNav));

            $_SESSION['admin_success_msg'] = __raw('msg_admin_settings_saved', 'Ajustes generales guardados correctamente.');
        }
        header("Location: /admin/dashboard/general");
        exit;
    }

    public static function getDefaultNavItems(): array {
        return ['home', 'catchup', 'local', 'public', 'notifications', 'bookmarks', 'direct', 'lists', 'collections', 'hashtags', 'settings', 'admin'];
    }

    public static function getEnabledNavItems(): array {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT value FROM options WHERE options.key = 'sidebar_nav_items' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return self::getDefaultNavItems();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : self::getDefaultNavItems();
    }

    /**
     * Handle Password modification form post.
     */
    public static function handlePassword(): void {
        $admin = self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $currPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';

        if (!password_verify($currPass, $admin['password_hash'])) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_password_invalid', 'La contraseña actual introducida no es válida.');
        } elseif (strlen($newPass) < 8) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_password_min_length', 'La nueva contraseña debe tener un mínimo de 8 caracteres.');
        } else {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE accounts SET password_hash = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$newHash, Database::nowUtc(), $admin['id']]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_password_updated', 'Contraseña de administrador actualizada con éxito.');
        }
        header("Location: /admin/dashboard/security");
        exit;
    }

    /**
     * Handle Session Duration update.
     */
    public static function handleSessionDuration(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $days = (int)($_POST['admin_session_days'] ?? 30);
        $validOptions = [1, 7, 30, 90, 365];
        if (!in_array($days, $validOptions, true)) {
            $days = 30;
        }

        Database::upsertOption($db, 'admin_session_days', (string)$days);

        $lifetime = $days * 86400;
        $newExpires = time() + $lifetime;
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && isset($_COOKIE[session_name()])) {
            setcookie(
                session_name(),
                session_id(),
                [
                    'expires'  => $newExpires,
                    'path'     => '/',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }

        $raw = $_COOKIE['kutsocial_remember'] ?? '';
        if ($raw) {
            $parts = explode(':', $raw);
            if (!empty($parts[0])) {
                try {
                    $upd = $db->prepare("UPDATE admin_sessions SET expires_at = ? WHERE selector = ?");
                    $upd->execute([$newExpires, $parts[0]]);
                    self::setRememberCookie($raw, $newExpires);
                } catch (\Throwable $e) {}
            }
        }

        $_SESSION['admin_success_msg'] = __raw('msg_admin_session_updated', 'Duración de la sesión actualizada correctamente (:days días).', ['days' => $days]);
        header("Location: /admin/dashboard/security");
        exit;
    }

    /**
     * Setup 2FA: generate secret and return JSON with QR.
     */
    public static function setup2FA(): void {
        $admin = self::checkAuth();
        self::verifyCsrf();
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $secret = TotpHelper::generateSecret();
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . rawurlencode(TotpHelper::getQRCodeUrl($admin['username'], $domain, $secret));

        Router::json([
            'secret' => $secret,
            'qr_url' => $qrUrl
        ]);
    }

    /**
     * Verify and activate 2FA.
     */
    public static function verify2FA(): void {
        $admin = self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $secret = trim($_POST['secret'] ?? '');
        $code = trim($_POST['otp_code'] ?? '');

        if (empty($secret) || empty($code)) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_2fa_incomplete', 'Los datos del formulario de verificación de 2FA están incompletos.');
            header("Location: /admin/dashboard/security");
            exit;
        }

        if (TotpHelper::verifyCode($secret, $code)) {
            $stmt = $db->prepare("UPDATE accounts SET otp_secret = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$secret, Database::nowUtc(), $admin['id']]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_2fa_enabled', '¡Autenticación de Dos Factores (2FA) configurada y activada con éxito!');
        } else {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_2fa_code_invalid', 'El código ingresado es incorrecto o expiró. Inténtalo de nuevo.');
        }
        header("Location: /admin/dashboard/security");
        exit;
    }

    /**
     * Disable 2FA.
     */
    public static function disable2FA(): void {
        $admin = self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (password_verify($confirmPassword, $admin['password_hash'])) {
            $stmt = $db->prepare("UPDATE accounts SET otp_secret = NULL, updated_at = ? WHERE id = ?");
            $stmt->execute([Database::nowUtc(), $admin['id']]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_2fa_disabled', 'La autenticación de dos factores (2FA) ha sido desactivada.');
        } else {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_2fa_pass_invalid', 'Contraseña de confirmación incorrecta. No se pudo desactivar el 2FA.');
        }
        header("Location: /admin/dashboard/security");
        exit;
    }

    /**
     * Add a block (moderation filter).
     */
    public static function addBlock(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $type = trim($_POST['type'] ?? '');
        $target = trim(strtolower($_POST['target'] ?? ''));

        if (empty($type) || empty($target)) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_block_params_missing', 'Faltan parámetros obligatorios para el bloqueo.');
            header("Location: /admin/dashboard/moderation");
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO moderation_blocks (type, target) VALUES (?, ?)");
            $stmt->execute([$type, $target]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_blocked_success', 'Se ha bloqueado exitosamente: :target', ['target' => htmlspecialchars($target)]);
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_block_exists', 'Este bloqueo ya existe o es inválido.');
        }
        header("Location: /admin/dashboard/moderation");
        exit;
    }

    /**
     * Remove a block.
     */
    public static function removeBlock(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM moderation_blocks WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_unblocked_success', 'Filtro de bloqueo removido correctamente.');
        }
        header("Location: /admin/dashboard/moderation");
        exit;
    }

    /**
     * Resolve a user report.
     */
    public static function resolveReport(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE reports SET action_taken = 1, action_taken_at = ? WHERE id = ?");
            $stmt->execute([Database::nowUtc(), $id]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_report_resolved', 'Reporte marcado como resuelto.');
        }
        header("Location: /admin/dashboard/moderation");
        exit;
    }

    /**
     * Delete a user report.
     */
    public static function deleteReport(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM reports WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_report_deleted', 'Reporte eliminado.');
        }
        header("Location: /admin/dashboard/moderation");
        exit;
    }

    /**
     * Add a federated Relay and enqueue Follow job.
     */
    public static function addRelay(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $inboxUrl = trim($_POST['inbox_url'] ?? '');
        if (!filter_var($inboxUrl, FILTER_VALIDATE_URL) || !\KutSocial\SecurityHelper::isSafeExternalUrl($inboxUrl)) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_relay_url_invalid', 'La dirección URL del relay no es válida.');
            header("Location: /admin/dashboard/relays");
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO relays (inbox_url, status) VALUES (?, 'pending')");
            $stmt->execute([$inboxUrl]);
            
            // Encolar tarea asíncrona de Follow para el relay
            $payload = [
                'type' => 'Follow',
                'object' => 'https://www.w3.org/ns/activitystreams#Public'
            ];
            Queue::enqueue('Follow', $payload, $inboxUrl);

            $_SESSION['admin_success_msg'] = __raw('msg_admin_relay_added', 'Relay agregado. Solicitud de suscripción enviada a la cola de tareas.');
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_relay_exists', 'Este relay ya está registrado.');
        }
        header("Location: /admin/dashboard/relays");
        exit;
    }

    /**
     * Remove a relay and enqueue Undo Follow job.
     */
    public static function removeRelay(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("SELECT inbox_url FROM relays WHERE id = ?");
            $stmt->execute([$id]);
            $inboxUrl = $stmt->fetchColumn();

            if ($inboxUrl) {
                // Encolar tarea asíncrona de Undo Follow para el relay
                $payload = [
                    'type' => 'Undo',
                    'object' => [
                        'type' => 'Follow',
                        'object' => 'https://www.w3.org/ns/activitystreams#Public'
                    ]
                ];
                Queue::enqueue('Undo', $payload, $inboxUrl);

                $stmtDel = $db->prepare("DELETE FROM relays WHERE id = ?");
                $stmtDel->execute([$id]);
                $_SESSION['admin_success_msg'] = __raw('msg_admin_relay_disconnecting', 'Desconectando del relay. La solicitud de remoción ha sido encolada.');
            }
        }
        header("Location: /admin/dashboard/relays");
        exit;
    }

    /**
     * Handle AJAX update and rollback actions (check, download, apply, rollback).
     */
    public static function handleUpdateAction(): void {
        self::checkAuth();
        self::verifyCsrf();
        header('Content-Type: application/json; charset=utf-8');

        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $updater = new UpdaterService();

        switch ($action) {
            case 'check':
                $result = $updater->checkVersion();
                echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'download':
                set_time_limit(300);
                $zipUrlRaw = $_POST['zip_url'] ?? '';
                $zipUrl = $zipUrlRaw ? base64_decode($zipUrlRaw) : '';

                if (!$zipUrl) {
                    echo json_encode(['error' => 'URL de descarga inválida o vacía.']);
                    break;
                }

                // Validar que provenga de GitHub o del servidor oficial KutStudio
                if (!preg_match('#^https://(?:(?:api\.)?github\.com/|(?:www\.)?kutstudio\.(?:org|com)/)#i', $zipUrl)) {
                    echo json_encode(['error' => 'La URL de descarga debe apuntar a GitHub o KutStudio.']);
                    break;
                }

                $dl = $updater->download($zipUrl);
                echo json_encode($dl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'apply':
                set_time_limit(300);
                ini_set('memory_limit', '256M');

                $zipPath = $_POST['zip_path'] ?? '';
                $newVersion = $_POST['new_version'] ?? '';

                if (!$zipPath || !$newVersion) {
                    echo json_encode(['error' => 'Faltan parámetros (zip_path, new_version).']);
                    break;
                }

                $realZipPath = realpath($zipPath);
                $realUpdateDir = realpath($updater->getUpdateDir());
                if (!$realZipPath || !$realUpdateDir || !str_starts_with($realZipPath, $realUpdateDir)) {
                    echo json_encode(['error' => 'Ruta del archivo ZIP no válida o fuera de actualizaciones.']);
                    break;
                }

                $newVersion = preg_replace('/[^0-9.]/', '', $newVersion);
                $result = $updater->apply($zipPath, $newVersion);
                echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'upload':
                set_time_limit(300);
                ini_set('memory_limit', '256M');

                if (empty($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
                    $errCode = $_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                    $errMap = [
                        UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize en php.ini.',
                        UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo permitido.',
                        UPLOAD_ERR_PARTIAL    => 'El archivo solo se subió parcialmente.',
                        UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal en el servidor.',
                        UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco.',
                        UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP detuvo la subida.',
                    ];
                    echo json_encode(['error' => $errMap[$errCode] ?? 'Error al subir el archivo (código ' . $errCode . ').']);
                    break;
                }

                $uploaded = $_FILES['zip_file'];
                $origName = $uploaded['name'] ?? '';
                if (!str_ends_with(strtolower($origName), '.zip')) {
                    echo json_encode(['error' => 'El archivo subido debe tener extensión .zip']);
                    break;
                }

                $destDir = $updater->getUpdateDir();
                $destPath = $destDir . '/manual-' . date('Ymd_His') . '.zip';
                if (!move_uploaded_file($uploaded['tmp_name'], $destPath)) {
                    echo json_encode(['error' => 'No se pudo mover el archivo ZIP a ' . $destDir . '. Verifica permisos de escritura.']);
                    break;
                }

                $result = $updater->applyFromZip($destPath);
                echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'rollback':
                set_time_limit(120);
                $backupFile = $_POST['backup_file'] ?? $_GET['backup_file'] ?? null;
                $result = $updater->rollback($backupFile);
                echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Acción no válida: ' . $action]);
                break;
        }
        exit;
    }

    /**
     * Create a new local user.
     */
    public static function createUser(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'user');

        if (empty($username) || empty($email) || empty($password)) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_fields_required', 'Todos los campos son requeridos para crear un usuario.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        $username = strtolower($username);
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $username)) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_format_invalid', 'El nombre de usuario debe ser alfanumérico y contener entre 1 y 30 caracteres.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_exists', 'El nombre de usuario ya está registrado localmente.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        // Generar par de llaves RSA
        $config_args = array(
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );
        $res = openssl_pkey_new($config_args);
        if (!$res) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_crypto_error', 'Error al generar las llaves criptográficas RSA para el nuevo usuario.');
            header("Location: /admin/dashboard/users");
            exit;
        }
        openssl_pkey_export($res, $privateKey);
        $pubKeyDetails = openssl_pkey_get_details($res);
        $publicKey = $pubKeyDetails["key"];

        $pwdHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $now = Database::nowUtc();
            $stmt = $db->prepare("
                INSERT INTO accounts (username, display_name, email, password_hash, public_key, private_key, role, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $username,
                $username,
                $email,
                $pwdHash,
                $publicKey,
                $privateKey,
                $role,
                $now,
                $now
            ]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_user_created', "Usuario ':username' creado correctamente.", ['username' => $username]);
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Error: " . $e->getMessage();
        }

        header("Location: /admin/dashboard/users");
        exit;
    }

    /**
     * Delete a local user.
     */
    public static function deleteUser(): void {
        $admin = self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['admin_error_msg'] = "ID inválido.";
            header("Location: /admin/dashboard/users");
            exit;
        }

        if ($id === (int)$admin['id']) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_self_delete', 'No puedes eliminar tu propia cuenta.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        $stmtUser = $db->prepare("SELECT username, role, domain FROM accounts WHERE id = ? LIMIT 1");
        $stmtUser->execute([$id]);
        $userToDelete = $stmtUser->fetch();

        if (!$userToDelete) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_not_found', 'Usuario no encontrado.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        if ($userToDelete['role'] === 'owner' && $admin['role'] !== 'owner') {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_owner_delete', 'No tienes permisos para eliminar al propietario de la instancia.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        try {
            $stmt = $db->prepare("DELETE FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_user_deleted', "El usuario ':username' ha sido eliminado.", ['username' => htmlspecialchars($userToDelete['username'])]);
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Error: " . $e->getMessage();
        }

        header("Location: /admin/dashboard/users");
        exit;
    }

    /**
     * Change a user's role.
     */
    public static function changeUserRole(): void {
        $admin = self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        $role = trim($_POST['role'] ?? 'user');

        if ($id <= 0 || !in_array($role, ['user', 'admin', 'owner'])) {
            $_SESSION['admin_error_msg'] = "Parámetros inválidos.";
            header("Location: /admin/dashboard/users");
            exit;
        }

        $stmtUser = $db->prepare("SELECT username, role FROM accounts WHERE id = ? LIMIT 1");
        $stmtUser->execute([$id]);
        $targetUser = $stmtUser->fetch();

        if (!$targetUser) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_user_not_found', 'Usuario no encontrado.');
            header("Location: /admin/dashboard/users");
            exit;
        }

        if ($id === (int)$admin['id'] && $role !== $admin['role']) {
            $_SESSION['admin_error_msg'] = __raw('msg_admin_role_self_change', 'No puedes cambiar tu propio rol (auto-democión).');
            header("Location: /admin/dashboard/users");
            exit;
        }

        if ($targetUser['role'] === 'owner' && $admin['role'] !== 'owner') {
            $_SESSION['admin_error_msg'] = "No tienes permisos.";
            header("Location: /admin/dashboard/users");
            exit;
        }

        if (($role === 'owner' || $targetUser['role'] === 'owner') && $admin['role'] !== 'owner') {
            $_SESSION['admin_error_msg'] = "No tienes permisos.";
            header("Location: /admin/dashboard/users");
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE accounts SET role = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$role, Database::nowUtc(), $id]);
            $_SESSION['admin_success_msg'] = __raw('msg_admin_role_updated', "Rol del usuario ':username' actualizado a ':role'.", [
                'username' => htmlspecialchars($targetUser['username']),
                'role' => $role
            ]);
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Error: " . $e->getMessage();
        }

        header("Location: /admin/dashboard/users");
        exit;
    }

    /**
     * Run clean up operations based on selected options.
     */
    public static function runMaintenanceClean(): void {
        self::checkAuth();
        self::verifyCsrf();
        $db = Database::connect();

        $cleanOrphans = isset($_POST['clean_orphans']) && $_POST['clean_orphans'] == '1';
        $pruneAccounts = isset($_POST['prune_accounts']) && $_POST['prune_accounts'] == '1';
        $removeStatuses = isset($_POST['remove_statuses']) && $_POST['remove_statuses'] == '1';
        $clearJobs = isset($_POST['clear_jobs']) && $_POST['clear_jobs'] == '1';
        $clearCache = isset($_POST['clear_cache']) && $_POST['clear_cache'] == '1';
        $vacuumDb = isset($_POST['vacuum_db']) && $_POST['vacuum_db'] == '1';
        $statusesDays = isset($_POST['statuses_days']) ? intval($_POST['statuses_days']) : 4;
        $cacheDays = isset($_POST['cache_days']) ? max(0, intval($_POST['cache_days'])) : 0;

        if ($statusesDays < 1) {
            $statusesDays = 4;
        }

        $messages = [];

        // 1. Eliminar Estados Remotos Antiguos
        if ($removeStatuses) {
            try {
                $cutoff = Database::agoUtc($statusesDays * 86400);
                $stmtSel = $db->prepare("
                    SELECT s.id FROM statuses s
                    JOIN accounts a ON s.account_id = a.id
                    WHERE a.domain IS NOT NULL AND a.domain != ''
                      AND s.created_at < ?
                      AND NOT EXISTS (
                          SELECT 1 FROM favourites fav
                          JOIN accounts a_local ON fav.account_id = a_local.id
                          WHERE fav.status_id = s.id AND (a_local.domain IS NULL OR a_local.domain = '')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM bookmarks b
                          JOIN accounts a_local ON b.account_id = a_local.id
                          WHERE b.status_id = s.id AND (a_local.domain IS NULL OR a_local.domain = '')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM statuses s_reply
                          JOIN accounts a_local ON s_reply.account_id = a_local.id
                          WHERE s_reply.in_reply_to_id = s.id AND (a_local.domain IS NULL OR a_local.domain = '')
                      )
                ");
                $stmtSel->execute([$cutoff]);
                $idsToDelete = $stmtSel->fetchAll(\PDO::FETCH_COLUMN);
                $deletedStatuses = 0;
                foreach (array_chunk($idsToDelete, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $delStmt = $db->prepare("DELETE FROM statuses WHERE id IN ($placeholders)");
                    $delStmt->execute($chunk);
                    $deletedStatuses += $delStmt->rowCount();
                }
                // Eliminar retoots huérfanos cuyo post original fue borrado (reblog_of_id = NULL con contenido vacío)
                $delOrphanReblogs = $db->exec("DELETE FROM statuses WHERE content = '' AND reblog_of_id IS NULL AND (media_attachments IS NULL OR media_attachments = '' OR media_attachments = '[]')");
                if ($delOrphanReblogs > 0) {
                    $deletedStatuses += $delOrphanReblogs;
                }
                $messages[] = "Se eliminaron $deletedStatuses estados remotos antiguos (> $statusesDays días).";
            } catch (Exception $e) {
                $messages[] = "Error al eliminar estados remotos: " . $e->getMessage();
            }
        }

        // 2. Eliminar Cuentas Remotas Inactivas
        if ($pruneAccounts) {
            try {
                $stmtSelAcc = $db->prepare("
                    SELECT a.id FROM accounts a
                    WHERE a.domain IS NOT NULL AND a.domain != ''
                      AND a.id NOT IN (
                          SELECT target_account_id FROM follows f
                          JOIN accounts a_local ON f.account_id = a_local.id
                          WHERE a_local.domain IS NULL OR a_local.domain = ''
                      )
                      AND a.id NOT IN (
                          SELECT account_id FROM follows f
                          JOIN accounts a_local ON f.target_account_id = a_local.id
                          WHERE a_local.domain IS NULL OR a_local.domain = ''
                      )
                      AND a.id NOT IN (
                          SELECT s.account_id FROM favourites fav
                          JOIN statuses s ON fav.status_id = s.id
                          JOIN accounts a_local ON fav.account_id = a_local.id
                          WHERE a_local.domain IS NULL OR a_local.domain = ''
                      )
                      AND a.id NOT IN (
                          SELECT s.account_id FROM bookmarks b
                          JOIN statuses s ON b.status_id = s.id
                          JOIN accounts a_local ON b.account_id = a_local.id
                          WHERE a_local.domain IS NULL OR a_local.domain = ''
                      )
                      AND a.id NOT IN (
                          SELECT s_parent.account_id FROM statuses s_reply
                          JOIN statuses s_parent ON s_reply.in_reply_to_id = s_parent.id
                          JOIN accounts a_local ON s_reply.account_id = a_local.id
                          WHERE a_local.domain IS NULL OR a_local.domain = ''
                      )
                ");
                $stmtSelAcc->execute();
                $accIdsToDelete = $stmtSelAcc->fetchAll(\PDO::FETCH_COLUMN);
                $prunedAccounts = 0;
                foreach (array_chunk($accIdsToDelete, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $delAccStmt = $db->prepare("DELETE FROM accounts WHERE id IN ($placeholders)");
                    $delAccStmt->execute($chunk);
                    $prunedAccounts += $delAccStmt->rowCount();
                }
                $messages[] = "Se eliminaron $prunedAccounts cuentas remotas inactivas.";
            } catch (Exception $e) {
                $messages[] = "Error al purgar cuentas remotas: " . $e->getMessage();
            }
        }

        // 3. Limpiar Historial de Tareas
        if ($clearJobs) {
            try {
                $stmt = $db->prepare("DELETE FROM jobs WHERE status IN ('completed', 'failed')");
                $stmt->execute();
                $clearedJobs = $stmt->rowCount();
                $messages[] = "Se eliminaron $clearedJobs tareas del historial.";
            } catch (Exception $e) {
                $messages[] = "Error al limpiar tareas en cola: " . $e->getMessage();
            }
        }

        // 4. Limpieza de Archivos Multimedia Huérfanos
        if ($cleanOrphans) {
            $uploadsDir = dirname(Database::getDbPath()) . '/uploads';
            if (is_dir($uploadsDir)) {
                $referencedFiles = [];
                $accounts = $db->query("SELECT avatar, header FROM accounts")->fetchAll();
                foreach ($accounts as $acc) {
                    if (!empty($acc['avatar'])) {
                        $ref = basename(parse_url($acc['avatar'], PHP_URL_PATH));
                        if ($ref) $referencedFiles[$ref] = true;
                    }
                    if (!empty($acc['header'])) {
                        $ref = basename(parse_url($acc['header'], PHP_URL_PATH));
                        if ($ref) $referencedFiles[$ref] = true;
                    }
                }

                $statuses = $db->query("SELECT media_attachments FROM statuses WHERE media_attachments IS NOT NULL AND media_attachments != ''")->fetchAll();
                foreach ($statuses as $stat) {
                    $atts = json_decode($stat['media_attachments'], true);
                    if (is_array($atts)) {
                        foreach ($atts as $att) {
                            if (isset($att['url']) && !empty($att['url'])) {
                                $ref = basename(parse_url($att['url'], PHP_URL_PATH));
                                if ($ref) $referencedFiles[$ref] = true;
                            }
                            if (isset($att['preview_url']) && !empty($att['preview_url'])) {
                                $ref = basename(parse_url($att['preview_url'], PHP_URL_PATH));
                                if ($ref) $referencedFiles[$ref] = true;
                            }
                        }
                    }
                }

                $deletedCount = 0;
                $savedBytes = 0;
                $files = scandir($uploadsDir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $filePath = $uploadsDir . '/' . $file;
                    if (is_file($filePath)) {
                        if (!isset($referencedFiles[$file])) {
                            $savedBytes += filesize($filePath);
                            if (@unlink($filePath)) {
                                $deletedCount++;
                            }
                        }
                    }
                }
                $savedMB = round($savedBytes / (1024 * 1024), 2);
                $messages[] = "Se eliminaron $deletedCount archivos multimedia huérfanos ($savedMB MB liberados).";
            } else {
                $messages[] = "El directorio de subidas no existe, no se buscaron huérfanos.";
            }
        }

        // 5. Limpiar Caché de Proxy y Contextos (data/cache/)
        if ($clearCache) {
            @set_time_limit(0);
            $cacheDir = defined('KUTSOCIAL_ROOT')
                ? KUTSOCIAL_ROOT . '/data/cache'
                : dirname(Database::getDbPath()) . '/cache';

            if (is_dir($cacheDir)) {
                $deletedCache = 0;
                $freedBytes = 0;
                $cutoffTs = $cacheDays > 0 ? (time() - ($cacheDays * 86400)) : null;

                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iterator as $item) {
                        if ($item->isFile() || $item->isLink()) {
                            if ($cutoffTs !== null && $item->getMTime() >= $cutoffTs) {
                                continue;
                            }
                            $size = $item->isFile() ? (int)$item->getSize() : 0;
                            if (@unlink($item->getPathname())) {
                                $deletedCache++;
                                $freedBytes += $size;
                            }
                        } elseif ($item->isDir()) {
                            @rmdir($item->getPathname());
                        }
                    }
                    $freedFormatted = $freedBytes >= (1024 * 1024 * 1024)
                        ? round($freedBytes / (1024 * 1024 * 1024), 2) . ' GB'
                        : round($freedBytes / (1024 * 1024), 2) . ' MB';
                    $messages[] = "Se eliminaron $deletedCache archivos de caché ($freedFormatted liberados).";
                } catch (\Throwable $e) {
                    $messages[] = "Error al limpiar la caché: " . $e->getMessage();
                }
            } else {
                $messages[] = "El directorio de caché no existe o ya está vacío.";
            }
        }

        // 6. Optimizar Base de Datos
        if ($vacuumDb) {
            try {
                $messages[] = Database::optimizeDb($db);
            } catch (Exception $e) {
                $messages[] = "Error al optimizar la base de datos: " . $e->getMessage();
            }
        }

        if (empty($messages)) {
            $_SESSION['admin_success_msg'] = __raw('msg_admin_maint_no_tasks', 'No se seleccionó ninguna tarea de mantenimiento.');
        } else {
            $_SESSION['admin_success_msg'] = __raw('msg_admin_maint_completed', 'Mantenimiento completado con éxito:') . "<br>• " . implode("<br>• ", $messages);
        }

        header("Location: /admin/dashboard/maintenance");
        exit;
    }

    /**
     * Create a database-only backup and store it in data/backups/.
     */
    public static function createDbBackup(): void {
        self::checkAuth();
        self::verifyCsrf();

        try {
            $backupDir = self::getBackupDir();
            $ts = gmdate('Ymd-His');
            $driver = Database::getDriver();
            $result = self::createDatabaseDump($backupDir, "kutsocial-db-{$driver}-{$ts}");
            $_SESSION['admin_success_msg'] = __raw(
                'admin_backups_msg_db_created',
                'Backup de base de datos creado correctamente: :file',
                ['file' => $result['filename']]
            );
        } catch (\Throwable $e) {
            $_SESSION['admin_error_msg'] = __raw(
                'admin_backups_err_create',
                'No se pudo crear el backup: :error',
                ['error' => $e->getMessage()]
            );
        }

        header("Location: /admin/dashboard/backups");
        exit;
    }

    /**
     * Create a full directory backup (including a fresh DB snapshot) in data/backups/.
     */
    public static function createFullBackup(): void {
        self::checkAuth();
        self::verifyCsrf();

        $tempDbPath = null;
        try {
            $root = self::getAppRoot();
            $backupDir = self::getBackupDir();
            $ts = gmdate('Ymd-His');
            $driver = Database::getDriver();

            $tempDir = sys_get_temp_dir();
            $dumpInfo = self::createDatabaseDump($tempDir, "kutsocial-tmp-db-{$driver}-{$ts}-" . bin2hex(random_bytes(4)));
            $tempDbPath = $dumpInfo['path'];

            $sqliteRelPath = '';
            if ($driver === 'sqlite') {
                $dbReal = realpath(Database::getDbPath()) ?: Database::getDbPath();
                $normRoot = rtrim(str_replace('\\', '/', $root), '/');
                $normDb = str_replace('\\', '/', $dbReal);
                if (str_starts_with($normDb, $normRoot . '/')) {
                    $sqliteRelPath = substr($normDb, strlen($normRoot) + 1);
                } else {
                    $sqliteRelPath = 'data/' . basename($normDb);
                }
                $tempDbArchiveName = $sqliteRelPath;
            } else {
                $ext = str_ends_with($dumpInfo['filename'], '.sql.gz') ? '.sql.gz' : '.sql';
                $tempDbArchiveName = 'database-backup' . $ext;
            }

            if (class_exists(\ZipArchive::class)) {
                $archiveName = "kutsocial-full-{$ts}.zip";
                $archivePath = $backupDir . '/' . $archiveName;
                self::createZipFullBackup($root, $archivePath, $tempDbPath, $tempDbArchiveName, $sqliteRelPath);
            } else {
                $archiveName = "kutsocial-full-{$ts}.tar.gz";
                $archivePath = $backupDir . '/' . $archiveName;
                self::createTarFullBackup($root, $archivePath, $tempDbPath, $tempDbArchiveName, $sqliteRelPath);
            }

            $_SESSION['admin_success_msg'] = __raw(
                'admin_backups_msg_full_created',
                'Backup completo creado correctamente: :file',
                ['file' => $archiveName]
            );
        } catch (\Throwable $e) {
            $_SESSION['admin_error_msg'] = __raw(
                'admin_backups_err_create',
                'No se pudo crear el backup: :error',
                ['error' => $e->getMessage()]
            );
        } finally {
            if ($tempDbPath !== null && is_file($tempDbPath)) {
                @unlink($tempDbPath);
            }
        }

        header("Location: /admin/dashboard/backups");
        exit;
    }

    /**
     * Stream a backup file to the authenticated administrator.
     */
    public static function downloadBackup(): void {
        self::checkAuth();

        $file = (string)($_GET['file'] ?? '');
        $targetPath = self::resolveBackupFile($file);
        if ($targetPath === null) {
            http_response_code(404);
            echo 'Backup not found.';
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($targetPath) . '"');
        header('Content-Length: ' . (string)filesize($targetPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: public');

        readfile($targetPath);
        exit;
    }

    /**
     * Delete a backup file from data/backups/.
     */
    public static function deleteBackup(): void {
        self::checkAuth();
        self::verifyCsrf();

        $file = (string)($_POST['file'] ?? '');
        $targetPath = self::resolveBackupFile($file);
        if ($targetPath === null) {
            $_SESSION['admin_error_msg'] = __raw('admin_backups_err_invalid_file', 'Nombre de archivo de backup inválido o no encontrado.');
        } elseif (@unlink($targetPath)) {
            $_SESSION['admin_success_msg'] = __raw(
                'admin_backups_msg_deleted',
                'Copia de seguridad eliminada: :file',
                ['file' => basename($targetPath)]
            );
        } else {
            $_SESSION['admin_error_msg'] = __raw('admin_backups_err_invalid_file', 'Nombre de archivo de backup inválido o no encontrado.');
        }

        header("Location: /admin/dashboard/backups");
        exit;
    }

    private static function getAppRoot(): string {
        if (defined('KUTSOCIAL_ROOT') && KUTSOCIAL_ROOT !== '') {
            return rtrim((string)KUTSOCIAL_ROOT, '/\\');
        }
        return rtrim((string)realpath(__DIR__ . '/../..'), '/\\');
    }

    private static function getBackupDir(): string {
        $dir = self::getAppRoot() . '/data/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new Exception('Cannot create backup directory.');
        }
        return $dir;
    }

    private static function isValidBackupFilename(string $filename): bool {
        if ($filename === '' || basename($filename) !== $filename) {
            return false;
        }
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $filename) !== 1) {
            return false;
        }
        $lower = strtolower($filename);
        foreach (['.db', '.sql', '.sql.gz', '.zip', '.tar.gz'] as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }
        return false;
    }

    private static function resolveBackupFile(string $filename): ?string {
        if (!self::isValidBackupFilename($filename)) {
            return null;
        }
        $backupDir = realpath(self::getBackupDir());
        if ($backupDir === false) {
            return null;
        }
        $targetPath = realpath($backupDir . DIRECTORY_SEPARATOR . $filename);
        if ($targetPath === false || !is_file($targetPath)) {
            return null;
        }
        if (!str_starts_with($targetPath, $backupDir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $targetPath;
    }

    private static function listBackups(): array {
        $dir = self::getBackupDir();
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return [];
        }

        $backups = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || !self::isValidBackupFilename($name)) {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($fullPath)) {
                continue;
            }
            $lower = strtolower($name);
            $type = (str_ends_with($lower, '.zip') || str_ends_with($lower, '.tar.gz')) ? 'Full' : 'DB';
            $backups[] = [
                'filename' => $name,
                'size'     => (int)(@filesize($fullPath) ?: 0),
                'mtime'    => (int)(@filemtime($fullPath) ?: 0),
                'type'     => $type,
            ];
        }

        usort($backups, static function (array $a, array $b): int {
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return strcmp($b['filename'], $a['filename']);
        });

        return $backups;
    }

    private static function createDatabaseDump(string $targetDir, string $filenamePrefix): array {
        $db = Database::connect();
        $cfg = Database::getConnectionConfig();
        $driver = $cfg['driver'];
        $targetDir = rtrim($targetDir, '/\\');

        if ($driver === 'sqlite') {
            $filename = $filenamePrefix . '.db';
            $outPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($outPath)) {
                @unlink($outPath);
            }
            $vacuumOk = false;
            try {
                $db->exec('VACUUM INTO ' . $db->quote($outPath));
                $vacuumOk = is_file($outPath) && filesize($outPath) > 0;
            } catch (\Throwable $e) {
                $vacuumOk = false;
            }
            if (!$vacuumOk) {
                $srcDb = $cfg['path'];
                if (!is_file($srcDb) || !@copy($srcDb, $outPath)) {
                    throw new Exception('SQLite VACUUM INTO and copy() fallback both failed.');
                }
            }
            return ['path' => $outPath, 'filename' => $filename];
        }

        $useGzip = function_exists('gzopen');
        $ext = $useGzip ? '.sql.gz' : '.sql';
        $filename = $filenamePrefix . $ext;
        $outPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $baseEnv = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];

        if ($driver === 'pgsql') {
            $cmd = [
                'pg_dump',
                '-h', $cfg['host'],
                '-p', $cfg['port'] !== '' ? $cfg['port'] : '5432',
                '-U', $cfg['user'],
                '-F', 'p',
                $cfg['name'],
            ];
            $env = $baseEnv;
            if ($cfg['pass'] !== '') {
                $env['PGPASSWORD'] = $cfg['pass'];
            }
            if (!self::runDumpProcess($cmd, $env, $outPath, $useGzip)) {
                self::exportDatabaseWithPdo($db, 'pgsql', $outPath, $useGzip);
            }
            return ['path' => $outPath, 'filename' => $filename];
        }

        // mysql / mariadb
        $dumped = false;
        $env = $baseEnv;
        if ($cfg['pass'] !== '') {
            $env['MYSQL_PWD'] = $cfg['pass'];
        }
        foreach (['mariadb-dump', 'mysqldump'] as $bin) {
            $cmd = [
                $bin,
                '-h', $cfg['host'],
                '-P', $cfg['port'] !== '' ? $cfg['port'] : '3306',
                '-u', $cfg['user'],
                '--single-transaction',
                '--quick',
                $cfg['name'],
            ];
            if (self::runDumpProcess($cmd, $env, $outPath, $useGzip)) {
                $dumped = true;
                break;
            }
        }
        if (!$dumped) {
            self::exportDatabaseWithPdo($db, 'mysql', $outPath, $useGzip);
        }
        return ['path' => $outPath, 'filename' => $filename];
    }

    private static function runDumpProcess(array $cmd, array $env, string $outFile, bool $gzip): bool {
        if (!function_exists('proc_open')) {
            return false;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            return false;
        }

        @fclose($pipes[0]);
        $handle = $gzip ? @gzopen($outFile, 'wb9') : @fopen($outFile, 'wb');
        if ($handle === false) {
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            return false;
        }

        $bytesWritten = 0;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $bytesWritten += strlen($chunk);
                if ($gzip) {
                    gzwrite($handle, $chunk);
                } else {
                    fwrite($handle, $chunk);
                }
            }
        }

        @fclose($pipes[1]);
        @stream_get_contents($pipes[2]);
        @fclose($pipes[2]);
        if ($gzip) {
            @gzclose($handle);
        } else {
            @fclose($handle);
        }

        $exitCode = proc_close($proc);
        if ($exitCode !== 0 || $bytesWritten === 0 || !is_file($outFile)) {
            @unlink($outFile);
            return false;
        }
        return true;
    }

    private static function exportDatabaseWithPdo(PDO $db, string $driver, string $outFile, bool $gzip): void {
        $handle = $gzip ? @gzopen($outFile, 'wb9') : @fopen($outFile, 'wb');
        if ($handle === false) {
            throw new Exception('Cannot open output file for SQL export.');
        }

        $write = static function (string $text) use ($handle, $gzip): void {
            if ($gzip) {
                gzwrite($handle, $text);
            } else {
                fwrite($handle, $text);
            }
        };

        $quoteIdent = static function (string $ident) use ($driver): string {
            if ($driver === 'mysql') {
                return '`' . str_replace('`', '``', $ident) . '`';
            }
            return '"' . str_replace('"', '""', $ident) . '"';
        };

        try {
            $write("-- KutSocial PDO SQL Backup\n");
            $write("-- Driver: {$driver}\n");
            $write("-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n");

            if ($driver === 'mysql') {
                $write("SET FOREIGN_KEY_CHECKS=0;\n\n");
                $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $tables = $db->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
            }

            foreach ($tables as $table) {
                $table = (string)$table;
                $qTable = $quoteIdent($table);
                $write("-- Table: {$table}\n");

                if ($driver === 'mysql') {
                    $createRow = $db->query("SHOW CREATE TABLE {$qTable}")->fetch(PDO::FETCH_NUM);
                    if (is_array($createRow) && isset($createRow[1])) {
                        $write("DROP TABLE IF EXISTS {$qTable};\n");
                        $write($createRow[1] . ";\n\n");
                    }
                }

                $stmt = $db->query("SELECT * FROM {$qTable}");
                $colCount = $stmt->columnCount();
                if ($colCount === 0) {
                    continue;
                }
                $cols = [];
                for ($i = 0; $i < $colCount; $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $cols[] = $quoteIdent((string)($meta['name'] ?? "col_{$i}"));
                }
                $colsSql = implode(', ', $cols);

                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } elseif (is_bool($val)) {
                            $vals[] = $driver === 'pgsql' ? ($val ? 'TRUE' : 'FALSE') : ($val ? '1' : '0');
                        } elseif (is_int($val) || is_float($val)) {
                            $vals[] = (string)$val;
                        } else {
                            $vals[] = $db->quote((string)$val);
                        }
                    }
                    $write("INSERT INTO {$qTable} ({$colsSql}) VALUES (" . implode(', ', $vals) . ");\n");
                }
                $write("\n");
            }

            if ($driver === 'mysql') {
                $write("SET FOREIGN_KEY_CHECKS=1;\n");
            }
        } finally {
            if ($gzip) {
                @gzclose($handle);
            } else {
                @fclose($handle);
            }
        }
    }

    private static function createZipFullBackup(
        string $root,
        string $archivePath,
        string $tempDbPath,
        string $tempDbArchiveName,
        string $sqliteRelPath
    ): void {
        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Failed to create ZIP archive.');
        }

        try {
            $normRoot = rtrim(str_replace('\\', '/', $root), '/');
            $sqliteExcludes = $sqliteRelPath !== ''
                ? [$sqliteRelPath, $sqliteRelPath . '-wal', $sqliteRelPath . '-shm']
                : [];

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $fullPath = $item->getPathname();
                $normFull = str_replace('\\', '/', $fullPath);
                if (!str_starts_with($normFull, $normRoot . '/')) {
                    continue;
                }
                $relPath = substr($normFull, strlen($normRoot) + 1);

                if (
                    $relPath === 'data/backups' || str_starts_with($relPath, 'data/backups/') ||
                    $relPath === '.git' || str_starts_with($relPath, '.git/') ||
                    $relPath === 'database' || str_starts_with($relPath, 'database/') ||
                    in_array($relPath, $sqliteExcludes, true)
                ) {
                    continue;
                }

                if ($item->isDir()) {
                    $zip->addEmptyDir($relPath);
                } elseif ($item->isFile()) {
                    $zip->addFile($fullPath, $relPath);
                }
            }

            if (is_file($tempDbPath)) {
                $zip->addFile($tempDbPath, $tempDbArchiveName);
            }
        } finally {
            $zip->close();
        }
    }

    private static function createTarFullBackup(
        string $root,
        string $archivePath,
        string $tempDbPath,
        string $tempDbArchiveName,
        string $sqliteRelPath
    ): void {
        if (!function_exists('proc_open')) {
            throw new Exception('Neither ZipArchive nor proc_open(tar) is available.');
        }

        $stagingDir = sys_get_temp_dir() . '/kutsocial-db-stage-' . bin2hex(random_bytes(4));
        $cmd = [
            'tar',
            '-czf',
            $archivePath,
            '-C',
            $root,
            '--exclude=./data/backups',
            '--exclude=./.git',
            '--exclude=./database',
        ];
        if ($sqliteRelPath !== '') {
            $cmd[] = '--exclude=./' . $sqliteRelPath;
            $cmd[] = '--exclude=./' . $sqliteRelPath . '-wal';
            $cmd[] = '--exclude=./' . $sqliteRelPath . '-shm';
        }
        $cmd[] = '.';

        $stagedFile = null;
        try {
            if (is_file($tempDbPath)) {
                $stagedFile = $stagingDir . '/' . $tempDbArchiveName;
                $stageParent = dirname($stagedFile);
                if (!is_dir($stageParent)) {
                    @mkdir($stageParent, 0755, true);
                }
                if (@copy($tempDbPath, $stagedFile)) {
                    $cmd[] = '-C';
                    $cmd[] = $stagingDir;
                    $cmd[] = $tempDbArchiveName;
                }
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                throw new Exception('Failed to execute tar.');
            }
            @fclose($pipes[0]);
            @stream_get_contents($pipes[1]);
            @fclose($pipes[1]);
            $err = (string)@stream_get_contents($pipes[2]);
            @fclose($pipes[2]);
            $exitCode = proc_close($proc);
            if ($exitCode !== 0 || !is_file($archivePath)) {
                @unlink($archivePath);
                throw new Exception('tar failed: ' . trim($err));
            }
        } finally {
            if ($stagedFile !== null && is_file($stagedFile)) {
                @unlink($stagedFile);
            }
            if (is_dir($stagingDir)) {
                // Clean up nested staging dirs if any
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($stagingDir);
            }
        }
    }
}
