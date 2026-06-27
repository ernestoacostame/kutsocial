<?php
namespace KutSocial\Controllers;

use KutSocial\Router;
use KutSocial\Database;
use KutSocial\UpdaterService;
use KutSocial\TotpHelper;
use KutSocial\Queue;
use PDO;
use Exception;

class AdminController {
    /**
     * Helper to verify if the admin is logged in.
     */
    private static function checkAuth(): ?array {
        $adminId = $_SESSION['admin_account_id'] ?? null;
        if (!$adminId) {
            header("Location: /admin/login");
            exit;
        }
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['admin_account_id']);
            header("Location: /admin/login");
            exit;
        }
        // Verify CSRF for POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::verifyCsrfToken();
        }
        return $user;
    }

    /**
     * Generate a CSRF token and store it in the session.
     */
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify the CSRF token from POST data or fail.
     */
    private static function verifyCsrfToken(): void {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            http_response_code(403);
            exit('Solicitud inválida: token CSRF no válido. <a href="/admin/dashboard">Volver</a>');
        }
    }

    /**
     * Rate limiting helper: returns true if the request should be blocked.
     * @param string $endpoint The endpoint identifier
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     */
    public static function isRateLimited(string $endpoint, int $maxAttempts = 5, int $windowSeconds = 300): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $db = Database::connect();
            
            // Clean old entries
            $db->exec("DELETE FROM rate_limits WHERE datetime(last_attempt, '+{$windowSeconds} seconds') < datetime('now')");
            
            $stmt = $db->prepare("SELECT attempts, first_attempt FROM rate_limits WHERE ip_address = ? AND endpoint = ? LIMIT 1");
            $stmt->execute([$ip, $endpoint]);
            $row = $stmt->fetch();
            
            if ($row) {
                $firstAttempt = strtotime($row['first_attempt']);
                if (time() - $firstAttempt > $windowSeconds) {
                    // Window expired, reset
                    $del = $db->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND endpoint = ?");
                    $del->execute([$ip, $endpoint]);
                    $ins = $db->prepare("INSERT INTO rate_limits (ip_address, endpoint) VALUES (?, ?)");
                    $ins->execute([$ip, $endpoint]);
                    return false;
                }
                if ((int)$row['attempts'] >= $maxAttempts) {
                    return true;
                }
                $upd = $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = datetime('now') WHERE ip_address = ? AND endpoint = ?");
                $upd->execute([$ip, $endpoint]);
            } else {
                $ins = $db->prepare("INSERT INTO rate_limits (ip_address, endpoint) VALUES (?, ?)");
                $ins->execute([$ip, $endpoint]);
            }
            return false;
        } catch (\Exception $e) {
            // If rate limiting fails, don't block the request
            return false;
        }
    }

    /**
     * Render the admin login page.
     */
    public static function showLogin(): void {
        if (isset($_SESSION['admin_account_id'])) {
            header("Location: /admin/dashboard");
            exit;
        }

        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);

        $errorHtml = $error ? '<div class="error-msg">' . htmlspecialchars($error) . '</div>' : '';

        $show2fa = isset($_SESSION['temp_admin_id']) ? 'block' : 'none';
        $showNormal = isset($_SESSION['temp_admin_id']) ? 'none' : 'block';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Iniciar Sesión - KutSocial Admin</title>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
            <style>
                :root {
                    --bg-color: #0d0f14;
                    --card-bg: rgba(22, 28, 38, 0.7);
                    --border-color: rgba(255, 255, 255, 0.08);
                    --primary: #4f46e5;
                    --text-color: #f3f4f6;
                    --text-muted: #9ca3af;
                    --error: #ef4444;
                }
                body {
                    font-family: 'Outfit', sans-serif;
                    background-color: var(--bg-color);
                    color: var(--text-color);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background-image: radial-gradient(circle at 10% 20%, rgba(79, 70, 229, 0.1) 0%, transparent 40%);
                }
                .login-card {
                    width: 100%;
                    max-width: 400px;
                    background: var(--card-bg);
                    backdrop-filter: blur(16px);
                    border: 1px solid var(--border-color);
                    border-radius: 20px;
                    padding: 40px;
                    box-shadow: 0 20px 40px rgba(0,0,0,0.5);
                }
                h2 {
                    text-align: center;
                    margin-bottom: 25px;
                    font-weight: 700;
                    background: linear-gradient(135deg, #818cf8 0%, #34d399 100%);
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                }
                .form-group {
                    margin-bottom: 20px;
                }
                label {
                    display: block;
                    font-size: 13px;
                    font-weight: 600;
                    margin-bottom: 8px;
                    color: var(--text-muted);
                }
                input {
                    width: 100%;
                    background: rgba(255,255,255,0.03);
                    border: 1px solid var(--border-color);
                    border-radius: 8px;
                    padding: 12px;
                    color: white;
                    box-sizing: border-box;
                    font-family: inherit;
                    transition: border-color 0.3s;
                }
                input:focus {
                    border-color: var(--primary);
                    outline: none;
                }
                button {
                    width: 100%;
                    background: var(--primary);
                    color: white;
                    border: none;
                    border-radius: 8px;
                    padding: 12px;
                    font-size: 15px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s;
                }
                button:hover {
                    opacity: 0.9;
                }
                .error-msg {
                    color: var(--error);
                    font-size: 13px;
                    margin-bottom: 15px;
                    text-align: center;
                }
                .cancel-link {
                    display: block;
                    text-align: center;
                    margin-top: 15px;
                    font-size: 13px;
                    color: var(--text-muted);
                    text-decoration: none;
                }
            </style>
        </head>
        <body>
            <div class="login-card">
                <h2>KutSocial Admin</h2>
                
                {$errorHtml}

                <!-- Formulario Normal -->
                <form method="POST" action="/admin/login" style="display: {$showNormal};">
                    <div class="form-group">
                        <label for="username">Usuario Administrador</label>
                        <input type="text" name="username" id="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input type="password" name="password" id="password" required>
                    </div>
                    <button type="submit">Siguiente</button>
                </form>

                <!-- Formulario 2FA -->
                <form method="POST" action="/admin/login" style="display: {$show2fa};">
                    <input type="hidden" name="step_2fa" value="1">
                    <div class="form-group">
                        <label for="otp_code">Código de Autenticación 2FA</label>
                        <input type="text" name="otp_code" id="otp_code" placeholder="123456" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" autofocus>
                    </div>
                    <button type="submit">Verificar Código</button>
                    <a href="/admin/logout" class="cancel-link">Cancelar e iniciar de nuevo</a>
                </form>
            </div>
        </body>
        </html>
        HTML;

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
        // Rate limiting: max 5 login attempts per 5 minutes
        if (self::isRateLimited('admin_login', 5, 300)) {
            $_SESSION['login_error'] = "Demasiados intentos de inicio de sesión. Intenta de nuevo en unos minutos.";
            header("Location: /admin/login");
            exit;
        }

        $db = Database::connect();

        // Si es el paso de verificar el token 2FA
        if (isset($_POST['step_2fa'])) {
            $tempId = $_SESSION['temp_admin_id'] ?? null;
            if (!$tempId) {
                $_SESSION['login_error'] = "Sesión de verificación expirada.";
                header("Location: /admin/login");
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND domain IS NULL LIMIT 1");
            $stmt->execute([$tempId]);
            $user = $stmt->fetch();

            $code = trim($_POST['otp_code'] ?? '');
            if ($user && TotpHelper::verifyCode($user['otp_secret'], $code)) {
                unset($_SESSION['temp_admin_id']);
                $_SESSION['admin_account_id'] = $user['id'];
                header("Location: /admin/dashboard");
            } else {
                $_SESSION['login_error'] = "Código 2FA incorrecto o expirado.";
                header("Location: /admin/login");
            }
            exit;
        }

        // Paso normal: Usuario y contraseña
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $db->prepare("SELECT * FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['otp_secret'])) {
                // Requiere 2FA
                $_SESSION['temp_admin_id'] = $user['id'];
                header("Location: /admin/login");
            } else {
                // Login directo
                $_SESSION['admin_account_id'] = $user['id'];
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
        unset($_SESSION['admin_account_id']);
        unset($_SESSION['temp_admin_id']);
        header("Location: /admin/login");
        exit;
    }

    /**
     * Render the admin dashboard.
     */
    public static function showDashboard(): void {
        $admin = self::checkAuth();
        $db = Database::connect();

        // 1. Obtener Límite de caracteres
        $stmtLimit = $db->prepare("SELECT value FROM options WHERE key = 'max_toot_chars' LIMIT 1");
        $stmtLimit->execute();
        $maxChars = (int)($stmtLimit->fetchColumn() ?: 500);

        // 2. Obtener lista de Relays
        $relays = $db->query("SELECT * FROM relays ORDER BY id DESC")->fetchAll();

        // 3. Obtener lista de Bloqueos (Moderación)
        $blocks = $db->query("SELECT * FROM moderation_blocks ORDER BY type ASC, target ASC")->fetchAll();

        // 4. Obtener lista de usuarios locales
        $localUsers = $db->query("SELECT * FROM accounts WHERE domain IS NULL ORDER BY username ASC")->fetchAll();

        // 5. Datos de actualizaciones (estilo KutPod)
        $updater = new UpdaterService();
        
        $stmtToken = $db->prepare("SELECT value FROM options WHERE key = 'update_github_token' LIMIT 1");
        $stmtToken->execute();
        $githubToken = $stmtToken->fetchColumn() ?: '';

        $rollbacks = $updater->getAvailableRollbacks();
        $updatesHistory = [];
        try {
            $updatesHistory = $db->query("SELECT * FROM updates ORDER BY id DESC LIMIT 20")->fetchAll();
        } catch (Exception $e) {}

        $currentVersion = \KutSocial\Database::getVersion();
        $is2faActive = !empty($admin['otp_secret']);

        $smtpHost = htmlspecialchars($admin['smtp_host'] ?? '');
        $smtpPort = (int)($admin['smtp_port'] ?? 587);
        if ($smtpPort <= 0) $smtpPort = 587;
        $smtpUser = htmlspecialchars($admin['smtp_user'] ?? '');
        // Show masked password in the form — never expose the real password
        $smtpPassRaw = $admin['smtp_pass'] ?? '';
        $smtpPass = !empty($smtpPassRaw) ? '••••••••' : '';
        $smtpFrom = htmlspecialchars($admin['smtp_from'] ?? '');
        $emailNotificationsChecked = !empty($admin['email_notifications']) ? 'checked' : '';
        $attributionDomains = htmlspecialchars($admin['attribution_domains'] ?? '');

        // Mensaje de éxito de ajustes
        $successMsg = $_SESSION['admin_success_msg'] ?? '';
        unset($_SESSION['admin_success_msg']);
        $errorMsg = $_SESSION['admin_error_msg'] ?? '';
        unset($_SESSION['admin_error_msg']);

        $successAlert = $successMsg ? "<div class='alert alert-success'>{$successMsg}</div>" : '';
        $errorAlert = $errorMsg ? "<div class='alert alert-error'>{$errorMsg}</div>" : '';

        // Rellenar filas de Bloqueos de moderación
        $blocksRows = '';
        foreach ($blocks as $row) {
            $typeLabel = match($row['type']) {
                'domain' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">public</span> Dominio',
                'account' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">person</span> Cuenta',
                'word' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">description</span> Palabra',
                'hashtag' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">tag</span> Hashtag',
                default => $row['type']
            };
            $blocksRows .= "<tr>
                <td><strong>{$typeLabel}</strong></td>
                <td><code>" . htmlspecialchars($row['target']) . "</code></td>
                <td>" . date('d/m/Y H:i', strtotime($row['created_at'])) . "</td>
                <td>
                    <form method='POST' action='/admin/moderation/unblock' style='margin:0;'>
                        <input type='hidden' name='id' value='{$row['id']}'>
                        <button type='submit' class='btn-danger'>Desbloquear</button>
                    </form>
                </td>
            </tr>";
        }

        // Rellenar filas de Relays
        $relaysRows = '';
        foreach ($relays as $row) {
            $statusBadge = ($row['status'] === 'accepted') 
                ? "<span class='badge badge-success'>Aceptado</span>" 
                : "<span class='badge badge-pending'>Pendiente</span>";
            $relaysRows .= "<tr>
                <td><code>" . htmlspecialchars($row['inbox_url']) . "</code></td>
                <td>{$statusBadge}</td>
                <td>" . date('d/m/Y H:i', strtotime($row['created_at'])) . "</td>
                <td>
                    <form method='POST' action='/admin/relays/delete' style='margin:0;'>
                        <input type='hidden' name='id' value='{$row['id']}'>
                        <button type='submit' class='btn-danger'>Desconectar</button>
                    </form>
                </td>
            </tr>";
        }

        // Rellenar filas de Usuarios
        $localUsersRows = '';
        foreach ($localUsers as $u) {
            $isSelf = ((int)$u['id'] === (int)$admin['id']);
            $isOwner = ($u['role'] === 'owner');
            
            $selectDisabled = $isSelf ? 'disabled' : '';
            if ($isOwner && $admin['role'] !== 'owner') {
                $selectDisabled = 'disabled';
            }
            
            $selectedUser = ($u['role'] === 'user') ? 'selected' : '';
            $selectedAdmin = ($u['role'] === 'admin') ? 'selected' : '';
            $selectedOwner = ($u['role'] === 'owner') ? 'selected' : '';

            $deleteButton = '';
            if (!$isSelf) {
                if (!$isOwner || $admin['role'] === 'owner') {
                    $deleteButton = "
                        <form method='POST' action='/admin/users/delete' style='margin:0; display:inline-block;' onsubmit='return confirm(\"¿Estás seguro de que deseas eliminar este usuario?\");'>
                            <input type='hidden' name='id' value='{$u['id']}'>
                            <button type='submit' class='btn-danger'>Eliminar</button>
                        </form>
                    ";
                }
            } else {
                $deleteButton = "<span style='color: var(--text-muted); font-size:12px;'>Tú</span>";
            }

            $localUsersRows .= "<tr>
                <td><strong>@" . htmlspecialchars($u['username']) . "</strong></td>
                <td>" . htmlspecialchars($u['email'] ?? '') . "</td>
                <td>
                    <form method='POST' action='/admin/users/role' style='margin:0; display:inline;'>
                        <input type='hidden' name='id' value='{$u['id']}'>
                        <select name='role' onchange='this.form.submit()' {$selectDisabled} style='padding: 6px 10px; font-size: 13px; width: auto; background: rgba(255,255,255,0.05); border-radius: 6px; border: 1px solid var(--border-color); color: white;'>
                            <option value='user' {$selectedUser}>Usuario</option>
                            <option value='admin' {$selectedAdmin}>Administrador</option>
                            <option value='owner' {$selectedOwner}>Propietario</option>
                        </select>
                    </form>
                </td>
                <td>" . date('d/m/Y H:i', strtotime($u['created_at'])) . "</td>
                <td>{$deleteButton}</td>
            </tr>";
        }

        // Preparar opciones de rollback
        $rollbackOptions = '';
        foreach ($rollbacks as $rb) {
            $rollbackOptions .= "<option value='" . htmlspecialchars($rb['filename'], ENT_QUOTES) . "'>v" . htmlspecialchars($rb['version'], ENT_QUOTES) . " (" . htmlspecialchars($rb['date'], ENT_QUOTES) . " - " . round($rb['size'] / (1024*1024), 2) . " MB)</option>";
        }

        // Preparar filas de historial de actualizaciones
        $historyRows = '';
        if (empty($updatesHistory)) {
            $historyRows = "<tr><td colspan='4' style='text-align: center; color: var(--text-muted); padding: 15px;'>Sin actualizaciones registradas todavía.</td></tr>";
        } else {
            foreach ($updatesHistory as $h) {
                $statusColor = $h['status'] === 'applied' ? '#10b981' : '#f59e0b';
                $statusLabel = $h['status'] === 'applied' ? 'Aplicada' : 'Revertida';
                $dateStr = date('d/m/Y H:i', strtotime($h['applied_at']));
                $rolledBackDate = !empty($h['rolled_back_at']) ? "<br><small style='color: var(--text-muted);'>Revertida: " . date('d/m/Y H:i', strtotime($h['rolled_back_at'])) . "</small>" : '';
                $backupInfo = !empty($h['backup_path']) && file_exists($h['backup_path']) ? "✓ " . basename($h['backup_path']) : "—";
                
                $historyRows .= "
                <tr>
                    <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'>v" . htmlspecialchars($h['from_version'], ENT_QUOTES) . " → <strong>v" . htmlspecialchars($h['to_version'], ENT_QUOTES) . "</strong></td>
                    <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'><span class='badge' style='background: {$statusColor}22; color: {$statusColor};'>{$statusLabel}</span>{$rolledBackDate}</td>
                    <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'>{$dateStr}</td>
                    <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-family: monospace; font-size: 11px; color: var(--text-muted);'>{$backupInfo}</td>
                </tr>";
            }
        }

        // Construir la página HTML del Dashboard
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Panel de Administración - KutSocial</title>
            <link rel="icon" type="image/svg+xml" href="/favicon.svg">
            <meta name="csrf-token" content="{$csrfToken}">
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
            <link href="https://fonts.googleapis.com/icon?family=Material+Icons|Material+Icons+Outlined" rel="stylesheet">
            <style>
                :root {
                    --bg-color: #0d0f14;
                    --card-bg: rgba(22, 28, 38, 0.7);
                    --border-color: rgba(255, 255, 255, 0.08);
                    --primary: #4f46e5;
                    --primary-hover: #4338ca;
                    --text-color: #f3f4f6;
                    --text-muted: #9ca3af;
                    --success: #10b981;
                    --error: #ef4444;
                }
                body {
                    font-family: 'Outfit', sans-serif;
                    background-color: var(--bg-color);
                    color: var(--text-color);
                    min-height: 100vh;
                    margin: 0;
                    background-image: radial-gradient(circle at 10% 20%, rgba(79, 70, 229, 0.08) 0%, transparent 45%);
                }
                .navbar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 15px 30px;
                    border-bottom: 1px solid var(--border-color);
                    background: rgba(13, 15, 20, 0.8);
                    backdrop-filter: blur(10px);
                    position: sticky;
                    top: 0;
                    z-index: 100;
                }
                .nav-logo {
                    font-weight: 700;
                    font-size: 20px;
                    background: linear-gradient(135deg, #818cf8 0%, #34d399 100%);
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                }
                .nav-user {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                }
                .btn-link {
                    color: var(--text-muted);
                    text-decoration: none;
                    font-size: 14px;
                    transition: color 0.3s;
                }
                .btn-link:hover {
                    color: white;
                }
                .dashboard-layout {
                    max-width: 1100px;
                    margin: 40px auto;
                    padding: 0 20px;
                    display: grid;
                    grid-template-columns: 240px 1fr;
                    gap: 40px;
                }
                .tab-menu {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }
                .tab-btn {
                    width: 100%;
                    text-align: left;
                    background: none;
                    border: none;
                    padding: 12px 18px;
                    border-radius: 8px;
                    color: var(--text-muted);
                    font-size: 14.5px;
                    font-family: inherit;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s;
                }
                .tab-btn.active {
                    background: rgba(79, 70, 229, 0.15);
                    color: #818cf8;
                    border-left: 3px solid var(--primary);
                }
                .tab-content {
                    background: var(--card-bg);
                    border: 1px solid var(--border-color);
                    border-radius: 16px;
                    padding: 30px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                }
                h3 {
                    margin-top: 0;
                    font-size: 22px;
                    font-weight: 700;
                    border-bottom: 1px solid var(--border-color);
                    padding-bottom: 15px;
                    margin-bottom: 25px;
                }
                .form-group {
                    margin-bottom: 22px;
                }
                label {
                    display: block;
                    font-size: 13.5px;
                    font-weight: 600;
                    margin-bottom: 8px;
                    color: var(--text-muted);
                }
                input, select {
                    width: 100%;
                    background: rgba(255,255,255,0.02);
                    border: 1px solid var(--border-color);
                    border-radius: 8px;
                    padding: 12px;
                    color: white;
                    box-sizing: border-box;
                    font-family: inherit;
                }
                select option {
                    background: #161c26;
                    color: white;
                }
                input:focus, select:focus {
                    outline: none;
                    border-color: var(--primary);
                }
                .help-text {
                    font-size: 12.5px;
                    color: var(--text-muted);
                    margin-top: 5px;
                }
                .btn-submit {
                    background: var(--primary);
                    color: white;
                    border: none;
                    border-radius: 8px;
                    padding: 12px 24px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.3s;
                }
                .btn-submit:hover {
                    background: var(--primary-hover);
                }
                .alert {
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 25px;
                    font-size: 14.5px;
                }
                .alert-success {
                    background: rgba(16, 185, 129, 0.15);
                    border: 1px solid var(--success);
                    color: #34d399;
                }
                .alert-error {
                    background: rgba(239, 68, 68, 0.15);
                    border: 1px solid var(--error);
                    color: #f87171;
                }
                .list-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .list-table th, .list-table td {
                    padding: 12px 15px;
                    text-align: left;
                    border-bottom: 1px solid var(--border-color);
                }
                .list-table th {
                    color: var(--text-muted);
                    font-weight: 600;
                    font-size: 13px;
                    text-transform: uppercase;
                }
                .btn-danger {
                    background: rgba(239, 68, 68, 0.15);
                    color: #f87171;
                    border: 1px solid var(--error);
                    padding: 6px 12px;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 12.5px;
                }
                .btn-danger:hover {
                    background: var(--error);
                    color: white;
                }
                .badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 12px;
                    font-size: 11.5px;
                    font-weight: 600;
                }
                .badge-success { background: rgba(16, 185, 129, 0.2); color: #34d399; }
                .badge-pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
                .setup-qr-box {
                    background: white;
                    padding: 15px;
                    border-radius: 10px;
                    display: inline-block;
                    margin-bottom: 15px;
                }
                .changelog {
                    background: rgba(0,0,0,0.2);
                    border-radius: 8px;
                    padding: 15px;
                    font-family: monospace;
                    font-size: 12.5px;
                    color: #34d399;
                    margin-top: 15px;
                    white-space: pre-wrap;
                    max-height: 150px;
                    overflow-y: auto;
                }
                .update-card {
                    background: rgba(255,255,255,0.02);
                    border: 1px solid var(--border-color);
                    border-radius: 12px;
                    padding: 20px;
                    margin-bottom: 25px;
                }
            </style>
        </head>
        <body>
            <nav class="navbar">
                <div class="nav-logo">KutSocial Admin Panel</div>
                <div class="nav-user">
                    <span style="font-size: 14.5px; font-weight: 600;">@{$admin['username']}</span>
                    <a href="/" class="btn-link">Ver Web</a>
                    <a href="/admin/logout" class="btn-link" style="color: var(--error);">Salir</a>
                </div>
            </nav>

            <div class="dashboard-layout">
                <!-- Sidebar menú de pestañas -->
                <aside>
                    <ul class="tab-menu">
                        <li><button class="tab-btn active" data-tab="general" onclick="switchTab('general', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">settings</span> Ajustes Generales</button></li>
                        <li><button class="tab-btn" data-tab="security" onclick="switchTab('security', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">security</span> Seguridad y 2FA</button></li>
                        <li><button class="tab-btn" data-tab="users" onclick="switchTab('users', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">people</span> Gestionar Usuarios</button></li>
                        <li><button class="tab-btn" data-tab="moderation" onclick="switchTab('moderation', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">gpp_good</span> Moderación / Filtros</button></li>
                        <li><button class="tab-btn" data-tab="relays" onclick="switchTab('relays', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">sync</span> Relays ActivityPub</button></li>
                        <li><button class="tab-btn" data-tab="update" onclick="switchTab('update', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">system_update</span> Actualizador</button></li>
                        <li><button class="tab-btn" data-tab="maintenance" onclick="switchTab('maintenance', this)"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">cleaning_services</span> Mantenimiento</button></li>
                    </ul>
                </aside>

                <!-- Panel Central de Contenido -->
                <main>
                    {$successAlert}
                    {$errorAlert}

                    <!-- PESTAÑA: Ajustes Generales -->
                    <div id="pane-general" class="tab-pane">
                        <h3>Ajustes Generales</h3>
                        <form method="POST" action="/admin/settings">
                            <div class="form-group">
                                <label for="max_toot_chars">Límite de Caracteres por Toot</label>
                                <input type="number" name="max_toot_chars" id="max_toot_chars" value="{$maxChars}" min="500" max="9999" required>
                                <p class="help-text">Elige la longitud máxima de caracteres permitida para los toots locales. Mínimo 500 y máximo 9999.</p>
                            </div>
                            <div class="form-group" style="margin-top: 20px;">
                                <label for="update_github_token">Token de GitHub (PAT)</label>
                                <input type="password" name="update_github_token" id="update_github_token" value="{$githubToken}" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white;">
                                <p class="help-text">Token de Acceso Personal (PAT) de GitHub para poder buscar y descargar de forma segura actualizaciones de KutSocial.</p>
                            </div>

                            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 30px 0;">
                            
                            <h3>📧 Configuración de Correo Electrónico (Notificaciones SMTP)</h3>
                            <div class="form-group" style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="email_notifications" id="email_notifications" value="1" {$emailNotificationsChecked} style="width: auto; cursor: pointer;">
                                <label for="email_notifications" style="margin-bottom: 0; cursor: pointer; font-weight: 600;">Activar notificaciones por correo electrónico</label>
                            </div>
                            <div id="smtp-settings-wrapper" style="margin-top: 15px;">
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="smtp_host">Servidor SMTP</label>
                                    <input type="text" name="smtp_host" id="smtp_host" value="{$smtpHost}" placeholder="smtp.ejemplo.com" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white;">
                                </div>
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="smtp_port">Puerto SMTP</label>
                                    <input type="number" name="smtp_port" id="smtp_port" value="{$smtpPort}" min="1" max="65535" placeholder="587" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white;">
                                </div>
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="smtp_user">Usuario SMTP</label>
                                    <input type="text" name="smtp_user" id="smtp_user" value="{$smtpUser}" placeholder="usuario@ejemplo.com" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white;">
                                </div>
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="smtp_pass">Contraseña SMTP</label>
                                    <input type="password" name="smtp_pass" id="smtp_pass" value="{$smtpPass}" placeholder="••••••••" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white;">
                                </div>
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="smtp_from">Correo Remitente (From)</label>
                                    <input type="email" name="smtp_from" id="smtp_from" value="{$smtpFrom}" placeholder="kutsocial@ejemplo.com" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white;">
                                    <p class="help-text">El correo que aparecerá como remitente de las notificaciones.</p>
                                </div>
                            </div>

                            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 30px 0;">

                            <h3>✍️ Atribución de Autor (Estilo Mastodon)</h3>
                            <p class="help-text">Cuando se comparta un enlace de tus sitios autorizados, las publicaciones de KutSocial y Mastodon te atribuirán la autoría si el sitio tiene esta etiqueta en su HTML:</p>
                            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 12px; border-radius: 8px; font-family: monospace; font-size: 13px; margin: 10px 0; color: #818cf8; user-select: all; cursor: pointer;">
                                &lt;meta name="fediverse:creator" content="@{$admin['username']}@{$_SERVER['HTTP_HOST']}"&gt;
                            </div>
                            <div class="form-group" style="margin-top: 15px;">
                                <label for="attribution_domains">Sitios web permitidos para atribuirte (Uno por línea)</label>
                                <textarea name="attribution_domains" id="attribution_domains" rows="5" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: white; font-family: monospace;" placeholder="tudominio.com&#10;www.otromedio.net">{$attributionDomains}</textarea>
                                <p class="help-text">Protege tu cuenta de falsas atribuciones. Solo se te acreditará la autoría en enlaces que provengan de los dominios aquí listados.</p>
                            </div>

                            <button type="submit" class="btn-submit" style="margin-top: 20px;">Guardar Ajustes</button>
                        </form>
                    </div>

                    <!-- PESTAÑA: Seguridad y 2FA -->
                    <div id="pane-security" class="tab-pane" style="display: none;">
                        <h3>Seguridad</h3>
                        
                        <!-- Cambiar Contraseña -->
                        <div style="margin-bottom: 40px; padding-bottom: 30px; border-bottom: 1px solid var(--border-color);">
                            <h4 style="margin: 0 0 15px 0;">Cambiar Contraseña de Administrador</h4>
                            <form method="POST" action="/admin/security/password">
                                <div class="form-group">
                                    <label for="current_password">Contraseña Actual</label>
                                    <input type="password" name="current_password" id="current_password" required>
                                </div>
                                <div class="form-group">
                                    <label for="new_password">Nueva Contraseña</label>
                                    <input type="password" name="new_password" id="new_password" required>
                                </div>
                                <button type="submit" class="btn-submit">Actualizar Contraseña</button>
                            </form>
                        </div>

                        <!-- Configuración de 2FA -->
                        <div>
                            <h4 style="margin: 0 0 10px 0;">Autenticación de Dos Factores (2FA)</h4>
                            <p class="help-text" style="margin-bottom: 20px;">Protege tu cuenta de administrador requiriendo un código TOTP de tu celular para iniciar sesión.</p>
                            
                            {if_2fa_active}
                                <div class="alert alert-success" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>✓ Autenticación 2FA Activa</span>
                                    <form method="POST" action="/admin/security/2fa/disable" style="margin: 0;">
                                        <input type="password" name="confirm_password" placeholder="Confirma contraseña" style="width: 150px; padding: 6px; font-size: 13px; margin-right: 10px;" required>
                                        <button type="submit" class="btn-danger" style="border: none;">Desactivar 2FA</button>
                                    </form>
                                </div>
                            {else_2fa}
                                <div style="background: rgba(255,255,255,0.02); border: 1px dashed var(--border-color); border-radius: 8px; padding: 20px; text-align: center;">
                                    <p style="margin-top: 0;">La autenticación de dos factores está desactivada.</p>
                                    <button class="btn-submit" onclick="start2FASetup()">Activar Autenticación 2FA</button>
                                    
                                    <div id="setup-2fa-section" style="display: none; margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 30px;">
                                        <p style="font-size: 14.5px;">1. Escanea el siguiente código QR con tu aplicación preferida (ej: Google Authenticator, Authy):</p>
                                        <div class="setup-qr-box">
                                            <img id="setup-qr-img" src="" alt="QR Code" style="width: 180px; height: 180px; display: block; border: 0;">
                                        </div>
                                        <p class="help-text">Código secreto de respaldo (Base32): <strong id="setup-secret-txt" style="color: white; font-size: 14px;">-</strong></p>
                                        
                                        <p style="font-size: 14.5px; margin-top: 25px;">2. Introduce el código de 6 dígitos que te muestra la aplicación para confirmar:</p>
                                        <form method="POST" action="/admin/security/2fa/verify" style="max-width: 250px; margin: 0 auto;">
                                            <input type="hidden" name="secret" id="setup-secret-val">
                                            <input type="text" name="otp_code" placeholder="123456" pattern="\d{6}" maxlength="6" required style="text-align: center; font-size: 18px; letter-spacing: 5px; margin-bottom: 15px;">
                                            <button type="submit" class="btn-submit">Confirmar y Activar</button>
                                        </form>
                                    </div>
                                </div>
                            {endif_2fa}
                        </div>
                    </div>

                    <!-- PESTAÑA: Moderación / Filtros -->
                    <div id="pane-moderation" class="tab-pane" style="display: none;">
                        <h3>Moderación y Bloqueos</h3>
                        <form method="POST" action="/admin/moderation/block" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
                            <div style="display: grid; grid-template-columns: 200px 1fr 120px; gap: 15px; align-items: flex-end;">
                                <div class="form-group" style="margin: 0;">
                                    <label for="block_type">Tipo de Filtro</label>
                                    <select name="type" id="block_type" required>
                                        <option value="domain">Dominio completo</option>
                                        <option value="account">Cuenta / Usuario</option>
                                        <option value="word">Palabra clave</option>
                                        <option value="hashtag">Hashtag</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label for="block_target">Valor a Bloquear</label>
                                    <input type="text" name="target" id="block_target" placeholder="ej: spamdomain.com o spambot" required>
                                </div>
                                <button type="submit" class="btn-submit" style="height: 43px;">Bloquear</button>
                            </div>
                            <p class="help-text">Los bloqueos ocultarán automáticamente de las timelines locales y federadas cualquier toot que coincida con el filtro.</p>
                        </form>

                        <h4 style="margin: 30px 0 10px 0;">Bloqueos Activos ({$blocksCount})</h4>
                        {if_blocks_exist}
                            <table class="list-table">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Destino / Valor</th>
                                        <th>Creado el</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$blocksRows}
                                </tbody>
                            </table>
                        {else_blocks}
                            <p style="color: var(--text-muted); font-size: 14.5px;">No hay ningún bloqueo activo.</p>
                        {endif_blocks}
                    </div>

                    <!-- PESTAÑA: Relays -->
                    <div id="pane-relays" class="tab-pane" style="display: none;">
                        <h3>Relays de ActivityPub</h3>
                        <p class="help-text" style="margin-bottom: 25px;">Los relays envían y reciben contenidos públicos compartidos en el Fediverso, permitiendo que tu instancia descubra más usuarios y toots.</p>

                        <form method="POST" action="/admin/relays" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
                            <div style="display: grid; grid-template-columns: 1fr 140px; gap: 15px; align-items: flex-end;">
                                <div class="form-group" style="margin: 0;">
                                    <label for="relay_inbox">Inbox URL del Relay</label>
                                    <input type="url" name="inbox_url" id="relay_inbox" placeholder="https://relay.ejemplo.org/inbox" required>
                                </div>
                                <button type="submit" class="btn-submit" style="height: 43px;">Suscribirse</button>
                            </div>
                        </form>

                        <h4 style="margin: 30px 0 10px 0;">Relays Suscritos ({$relaysCount})</h4>
                        {if_relays_exist}
                            <table class="list-table">
                                <thead>
                                    <tr>
                                        <th>Inbox URL</th>
                                        <th>Estado</th>
                                        <th>Suscrito el</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$relaysRows}
                                </tbody>
                            </table>
                        {else_relays}
                            <p style="color: var(--text-muted); font-size: 14.5px;">No estás conectado a ningún relay en este momento.</p>
                        {endif_relays}
                    </div>

                    <!-- PESTAÑA: Actualizador -->
                    <div id="pane-update" class="tab-pane" style="display: none;">
                        <h3>Actualización de KutSocial</h3>
                        <p class="help-text" style="margin-bottom: 25px;">Mantén tu instancia de KutSocial al día con actualización en 1 clic y reversión automática ante fallos.</p>
                        
                        <div class="update-card" style="margin-bottom: 25px;">
                            <h4 style="margin: 0 0 10px 0;">Buscar Actualizaciones</h4>
                            <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">Consulta el repositorio de GitHub para verificar si hay una nueva versión disponible.</p>
                            
                            <button class="btn-submit" id="btn-check" type="button" onclick="kpUpdateCheck()">Buscar Actualizaciones</button>
                            
                            <div id="update-status" style="display: none; margin-top: 15px; padding: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px;"></div>
                            
                            <div id="update-available" style="display: none; margin-top: 20px; padding: 20px; background: rgba(16, 185, 129, 0.05); border: 1px solid var(--success); border-radius: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <div>
                                        <h4 id="update-version-label" style="margin: 0; color: var(--success); font-size: 18px;">¡Nueva versión disponible!</h4>
                                        <small id="update-date-label" style="color: var(--text-muted);"></small>
                                    </div>
                                    <button class="btn-submit" id="btn-apply" onclick="kpUpdateApply()" style="background: var(--success);">Actualizar Ahora</button>
                                </div>
                                <div style="margin-top: 15px; border-top: 1px solid var(--border-color); padding-top: 15px;">
                                    <strong style="display: block; font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Notas de la versión:</strong>
                                    <pre id="update-changelog-body" style="font-family: inherit; font-size: 14px; margin: 0; white-space: pre-wrap; line-height: 1.5; color: var(--text-color);"></pre>
                                </div>
                            </div>
                            
                            <div id="update-progress" style="display: none; margin-top: 20px; padding: 20px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px;">
                                <div style="font-weight: 600; margin-bottom: 10px; font-size: 14px;" id="progress-label">Preparando...</div>
                                <div style="background: rgba(255,255,255,0.05); border-radius: 6px; height: 8px; overflow: hidden; margin-bottom: 10px;">
                                    <div id="progress-bar" style="background: var(--primary); height: 100%; width: 0%; transition: width 0.4s ease;"></div>
                                </div>
                                <small id="progress-sub" style="color: var(--text-muted);"></small>
                            </div>
                        </div>

                        <!-- Revertir actualización (Rollback) -->
                        <div class="update-card" style="margin-bottom: 25px; {$hasRollbackClass}">
                            <h4 style="margin: 0 0 10px 0;">Revertir Actualización (Rollback)</h4>
                            <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">Restaura una versión previamente guardada de KutSocial. Las bases de datos no se verán afectadas.</p>
                            
                            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                                <select class="select" id="rollback-select" style="padding: 10px; font-size: 14px; border-radius: 8px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: white; min-width: 250px;">
                                    {$rollbackOptions}
                                </select>
                                <button class="btn-submit" id="btn-rollback" onclick="kpUpdateRollback()" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid #fbbf24; padding: 10px 20px;">Revertir a esta versión</button>
                            </div>
                            <div id="rollback-status" style="display: none; margin-top: 15px; padding: 12px; border-radius: 8px; font-size: 14px;"></div>
                        </div>

                        <!-- Historial -->
                        <div class="update-card" style="padding: 0;">
                            <h4 style="padding: 20px 25px 10px 25px; margin: 0;">Historial de Actualizaciones</h4>
                            <table class="list-table" style="margin-top: 10px;">
                                <thead>
                                    <tr>
                                        <th style="padding: 12px 15px;">De → A</th>
                                        <th style="padding: 12px 15px;">Estado</th>
                                        <th style="padding: 12px 15px;">Fecha</th>
                                        <th style="padding: 12px 15px;">Copia de Seguridad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$historyRows}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- PESTAÑA: Gestionar Usuarios -->
                    <div id="pane-users" class="tab-pane" style="display: none;">
                        <h3>Gestión de Usuarios ({$localUsersCount})</h3>
                        <form method="POST" action="/admin/users/create" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 120px 120px; gap: 15px; align-items: flex-end;">
                                <div class="form-group" style="margin: 0;">
                                    <label for="new_username">Usuario</label>
                                    <input type="text" name="username" id="new_username" placeholder="ej: maria" required pattern="^[a-zA-Z0-9_]{1,30}$">
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label for="new_email">Correo</label>
                                    <input type="email" name="email" id="new_email" placeholder="maria@ejemplo.com" required>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label for="new_pwd">Contraseña</label>
                                    <input type="password" name="password" id="new_pwd" placeholder="Min. 8 car." required minlength="8">
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label for="new_role">Rol</label>
                                    <select name="role" id="new_role" required>
                                        <option value="user">Usuario</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-submit" style="height: 43px;">Crear</button>
                            </div>
                        </form>

                        <table class="list-table">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Correo</th>
                                    <th>Rol</th>
                                    <th>Creado el</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$localUsersRows}
                            </tbody>
                        </table>
                    </div>

                    <!-- PESTAÑA: Mantenimiento -->
                    <div id="pane-maintenance" class="tab-pane" style="display: none;">
                        <h3>Mantenimiento del Sistema</h3>
                        <p class="help-text" style="margin-bottom: 25px;">Ejecuta tareas de limpieza y optimización para mantener el servidor funcionando de manera eficiente y liberar espacio en disco.</p>
                        
                        <form method="POST" action="/admin/maintenance/clean" onsubmit="return confirm('¿Estás seguro de que deseas iniciar las tareas de mantenimiento seleccionadas?');">
                            
                            <!-- Opción 1: Multimedia Huérfanos -->
                            <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
                                <input type="checkbox" name="clean_orphans" id="clean_orphans" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
                                <div>
                                    <label for="clean_orphans" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">🧹 Limpieza de Archivos Multimedia Huérfanos</label>
                                    <span class="help-text">Escanea la carpeta de subidas (<code>data/uploads/</code>) y elimina de forma segura cualquier imagen, avatar o archivo adjunto que ya no esté referenciado en perfiles de usuarios ni en publicaciones de la base de datos.</span>
                                </div>
                            </div>
                            
                            <!-- Opción 2: Cuentas Remotas Inactivas -->
                            <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
                                <input type="checkbox" name="prune_accounts" id="prune_accounts" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
                                <div>
                                    <label for="prune_accounts" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">👤 Eliminar Cuentas Remotas Inactivas</label>
                                    <span class="help-text">Elimina cuentas de otras instancias federadas con las que ningún usuario local ha interactuado (no nos siguen, no los seguimos y no tienen estados guardados o marcados como favoritos).</span>
                                </div>
                            </div>
                            
                            <!-- Opción 3: Estados Remotos Antiguos -->
                            <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
                                <input type="checkbox" name="remove_statuses" id="remove_statuses" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
                                <div>
                                    <label for="remove_statuses" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">📝 Eliminar Estados Remotos Antiguos</label>
                                    <span class="help-text" style="display: block; margin-bottom: 10px;">Elimina publicaciones remotas federadas con las que no ha habido interacción (no están marcadas como favorito o marcador, ni tienen respuestas locales).</span>
                                    <div class="form-group" style="margin-bottom: 0; display: inline-flex; align-items: center; gap: 10px;">
                                        <label for="statuses_days" style="font-size: 13px; font-weight: normal; margin-bottom: 0;">Antigüedad de estados a eliminar:</label>
                                        <input type="number" name="statuses_days" id="statuses_days" value="4" min="1" max="365" style="width: 80px; padding: 6px 10px; border-radius: 6px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: white;">
                                        <span style="font-size: 13px; color: var(--text-muted);">días o más</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Opción 4: Limpiar Cola de Tareas (Jobs) -->
                            <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
                                <input type="checkbox" name="clear_jobs" id="clear_jobs" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
                                <div>
                                    <label for="clear_jobs" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">⚙️ Limpiar Historial de Tareas en Cola</label>
                                    <span class="help-text">Elimina permanentemente de la base de datos el registro de tareas en cola (jobs) ya finalizadas o fallidas.</span>
                                </div>
                            </div>
                            
                            <!-- Opción 5: Optimizar Base de Datos (VACUUM) -->
                            <div class="update-card" style="margin-bottom: 25px; display: flex; align-items: flex-start; gap: 15px;">
                                <input type="checkbox" name="vacuum_db" id="vacuum_db" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
                                <div>
                                    <label for="vacuum_db" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">🗄️ Optimizar Base de Datos (VACUUM)</label>
                                    <span class="help-text">Ejecuta el comando <code>VACUUM</code> en la base de datos SQLite para compactar el archivo, reconstruir índices y recuperar todo el espacio libre en el disco tras las eliminaciones.</span>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-submit" style="width: 100%; font-size: 15px;">Ejecutar Tareas Seleccionadas</button>
                        </form>
                    </div>
                </main>
            </div>

            <script>
                function switchTab(tabId, btn) {
                    // Ocultar todas las secciones
                    document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
                    // Desactivar botones
                    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                    
                    // Mostrar sección activa
                    const pane = document.getElementById('pane-' + tabId);
                    if (pane) pane.style.display = 'block';
                    
                    // Activar botón pulsado
                    const targetBtn = btn || document.querySelector('.tab-btn[data-tab="' + tabId + '"]');
                    if (targetBtn) {
                        targetBtn.classList.add('active');
                    }

                    // Guardar pestaña en localStorage
                    try {
                        localStorage.setItem('kutsocial_admin_tab', tabId);
                    } catch (e) {}
                }

                // Restaurar pestaña activa tras recargar
                window.onload = function() {
                    let activeTab = 'general';
                    try {
                        activeTab = localStorage.getItem('kutsocial_admin_tab') || 'general';
                    } catch (e) {}
                    const targetBtn = document.querySelector('.tab-btn[data-tab="' + activeTab + '"]');
                    if (targetBtn) {
                        switchTab(activeTab, targetBtn);
                    } else {
                        switchTab('general');
                    }
                };

                // Iniciar flujo 2FA vía API
                function start2FASetup() {
                    fetch('/admin/security/2fa/setup', { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        document.getElementById('setup-qr-img').src = data.qr_url;
                        document.getElementById('setup-secret-txt').innerText = data.secret;
                        document.getElementById('setup-secret-val').value = data.secret;
                        document.getElementById('setup-2fa-section').style.display = 'block';
                    })
                    .catch(err => alert("Error al iniciar configuración 2FA: " + err));
                }

                // --- SISTEMA DE ACTUALIZACIÓN (ESTILO KUTPOD) ---
                let _updateData = null;

                function kpUpdateCheck() {
                    const btn = document.getElementById('btn-check');
                    const status = document.getElementById('update-status');
                    const available = document.getElementById('update-available');

                    btn.disabled = true;
                    btn.textContent = 'Buscando...';
                    status.style.display = 'block';
                    status.style.color = 'var(--text-muted)';
                    status.style.borderColor = 'var(--border-color)';
                    status.innerHTML = 'Consultando últimas versiones en GitHub...';
                    available.style.display = 'none';

                    fetch('/admin/update/action', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: 'action=check'
                    })
                    .then(r => r.json())
                    .then(data => {
                        btn.disabled = false;
                        btn.textContent = 'Buscar Actualizaciones';
                        
                        if (data.error) {
                            status.style.color = '#f87171';
                            status.style.borderColor = 'var(--error)';
                            status.innerHTML = '❌ ' + data.error;
                            return;
                        }
                        if (data.up_to_date) {
                            status.style.color = '#34d399';
                            status.style.borderColor = 'var(--success)';
                            status.innerHTML = '✅ KutSocial está al día (versión v' + data.version + ').';
                            return;
                        }
                        if (data.available) {
                            _updateData = data;
                            status.style.display = 'none';
                            available.style.display = 'block';
                            document.getElementById('update-version-label').textContent = '¡Nueva actualización ' + data.tag + ' disponible!';
                            document.getElementById('update-date-label').textContent = 'Publicada el: ' + data.published_at;
                            document.getElementById('update-changelog-body').textContent = data.changelog;
                        }
                    })
                    .catch(err => {
                        btn.disabled = false;
                        btn.textContent = 'Buscar Actualizaciones';
                        status.style.color = '#f87171';
                        status.style.borderColor = 'var(--error)';
                        status.innerHTML = '❌ Error al buscar actualizaciones.';
                    });
                }

                function kpUpdateApply() {
                    if (!_updateData) return;
                    if (!confirm('¿Estás seguro de que deseas actualizar KutSocial a la versión ' + _updateData.new_version + ' ahora?\\nSe creará una copia de seguridad automática.')) {
                        return;
                    }

                    const available = document.getElementById('update-available');
                    const progress = document.getElementById('update-progress');
                    const progressLabel = document.getElementById('progress-label');
                    const progressBar = document.getElementById('progress-bar');
                    const progressSub = document.getElementById('progress-sub');

                    available.style.display = 'none';
                    progress.style.display = 'block';

                    // Paso 1: Descargar ZIP
                    progressLabel.textContent = 'Descargando actualización...';
                    progressBar.style.width = '25%';
                    progressSub.textContent = 'Obteniendo paquete desde GitHub...';

                    fetch('/admin/update/action', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: 'action=download&zip_url=' + encodeURIComponent(btoa(_updateData.zip_url))
                    })
                    .then(r => r.json())
                    .then(dlData => {
                        if (dlData.error) {
                            throw new Error(dlData.error);
                        }

                        // Paso 2: Aplicar
                        progressLabel.textContent = 'Aplicando actualización...';
                        progressBar.style.width = '75%';
                        progressSub.textContent = 'Realizando copia de seguridad e instalando fuentes...';

                        return fetch('/admin/update/action', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            },
                            body: 'action=apply&zip_path=' + encodeURIComponent(dlData.path) + '&new_version=' + encodeURIComponent(_updateData.new_version)
                        });
                    })
                    .then(r => r.json())
                    .then(applyData => {
                        if (applyData.error) {
                            throw new Error(applyData.error);
                        }

                        progressBar.style.width = '100%';
                        progressLabel.textContent = '¡Actualización completada!';
                        progressLabel.style.color = '#34d399';
                        progressSub.innerHTML = 'KutSocial se ha actualizado con éxito a la versión v' + _updateData.new_version + '. Recargando panel...';

                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    })
                    .catch(err => {
                        progressLabel.textContent = 'Error en la actualización';
                        progressLabel.style.color = '#f87171';
                        progressBar.style.backgroundColor = 'var(--error)';
                        progressSub.textContent = '❌ ' + err.message;
                    });
                }

                function kpUpdateRollback() {
                    const select = document.getElementById('rollback-select');
                    const backupFile = select.value;
                    if (!backupFile) return;

                    if (!confirm('¿Estás seguro de que deseas revertir KutSocial al respaldo seleccionado?\\nEsto restaurará los archivos fuente a ese momento.')) {
                        return;
                    }

                    const btn = document.getElementById('btn-rollback');
                    const status = document.getElementById('rollback-status');

                    btn.disabled = true;
                    btn.textContent = 'Restaurando...';
                    status.style.display = 'block';
                    status.style.color = 'var(--text-muted)';
                    status.style.background = 'rgba(255,255,255,0.02)';
                    status.style.border = '1px solid var(--border-color)';
                    status.innerHTML = 'Restaurando copia de seguridad y aplicando cambios...';

                    fetch('/admin/update/action', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: 'action=rollback&backup_file=' + encodeURIComponent(backupFile)
                    })
                    .then(r => r.json())
                    .then(data => {
                        btn.disabled = false;
                        btn.textContent = 'Revertir a esta versión';

                        if (data.error) {
                            status.style.color = '#f87171';
                            status.style.background = 'rgba(239, 68, 68, 0.05)';
                            status.style.border = '1px solid var(--error)';
                            status.innerHTML = '❌ ' + data.error;
                            return;
                        }

                        status.style.color = '#34d399';
                        status.style.background = 'rgba(16, 185, 129, 0.05)';
                        status.style.border = '1px solid var(--success)';
                        status.innerHTML = '✅ Rollback completado con éxito. Recargando panel...';

                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    })
                    .catch(err => {
                        btn.disabled = false;
                        btn.textContent = 'Revertir a esta versión';
                        status.style.color = '#f87171';
                        status.style.background = 'rgba(239, 68, 68, 0.05)';
                        status.style.border = '1px solid var(--error)';
                        status.innerHTML = '❌ Error al revertir la actualización.';
                    });
                }
            </script>
        </body>
        </html>
        HTML;

        // Reemplazos de plantillas simples
        $html = str_replace('{$currentVersion}', $currentVersion, $html);
        $html = str_replace('{$maxChars}', (string)$maxChars, $html);
        $html = str_replace('{$githubToken}', htmlspecialchars($githubToken, ENT_QUOTES), $html);
        $html = str_replace('{$rollbackOptions}', $rollbackOptions, $html);
        $html = str_replace('{$historyRows}', $historyRows, $html);
        $html = str_replace('{$hasRollbackClass}', empty($rollbacks) ? 'display: none;' : '', $html);
        $html = str_replace('{$successAlert}', $successAlert, $html);
        $html = str_replace('{$errorAlert}', $errorAlert, $html);
        $html = str_replace('{$blocksCount}', (string)count($blocks), $html);
        $html = str_replace('{$blocksRows}', $blocksRows, $html);
        $html = str_replace('{$relaysCount}', (string)count($relays), $html);
        $html = str_replace('{$relaysRows}', $relaysRows, $html);
        $html = str_replace('{$localUsersCount}', (string)count($localUsers), $html);
        $html = str_replace('{$localUsersRows}', $localUsersRows, $html);
        $html = str_replace('{$csrfToken}', $csrfToken, $html);
        
        $html = str_replace('{if_2fa_active}', $is2faActive ? '' : '<!--', $html);
        $html = str_replace('{else_2fa}', $is2faActive ? '<!--' : '-->', $html);
        $html = str_replace('{endif_2fa}', $is2faActive ? '-->' : '', $html);

        $hasBlocks = count($blocks) > 0;
        $html = str_replace('{if_blocks_exist}', $hasBlocks ? '' : '<!--', $html);
        $html = str_replace('{else_blocks}', $hasBlocks ? '<!--' : '-->', $html);
        $html = str_replace('{endif_blocks}', $hasBlocks ? '-->' : '', $html);

        $hasRelays = count($relays) > 0;
        $html = str_replace('{if_relays_exist}', $hasRelays ? '' : '<!--', $html);
        $html = str_replace('{else_relays}', $hasRelays ? '<!--' : '-->', $html);
        $html = str_replace('{endif_relays}', $hasRelays ? '-->' : '', $html);

        // Inject CSRF token into all POST forms
        $csrfToken = self::generateCsrfToken();
        $csrfInput = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        $html = preg_replace(
            '/(<form\s[^>]*method="POST"[^>]*>)/i',
            '$1' . "\n" . '            ' . $csrfInput,
            $html
        );

        Router::html($html);
    }

    /**
     * Handle Settings form post.
     */
    public static function handleSettings(): void {
        self::checkAuth();
        $db = Database::connect();

        $maxChars = (int)($_POST['max_toot_chars'] ?? 500);
        $githubToken = trim($_POST['update_github_token'] ?? '');

        if ($maxChars < 500 || $maxChars > 9999) {
            $_SESSION['admin_error_msg'] = "El límite de caracteres debe estar entre 500 y 9999.";
        } else {
            $stmt = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('max_toot_chars', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
            $stmt->execute([(string)$maxChars]);

            $stmt2 = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_github_token', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
            $stmt2->execute([$githubToken]);

            // Guardar preferencias SMTP y Atribución
            $smtpHost = trim($_POST['smtp_host'] ?? '');
            $smtpPort = (int)($_POST['smtp_port'] ?? 587);
            $smtpUser = trim($_POST['smtp_user'] ?? '');
            $smtpPass = $_POST['smtp_pass'] ?? '';
            // Only re-encrypt if the password was actually changed (not the masked placeholder)
            if ($smtpPass === '••••••••' || $smtpPass === '') {
                // Keep existing password — fetch current value
                $stmtCurr = $db->prepare("SELECT smtp_pass FROM accounts WHERE id = ? LIMIT 1");
                $stmtCurr->execute([$_SESSION['admin_account_id']]);
                $smtpPass = $stmtCurr->fetchColumn() ?: null;
            } else {
                // Encrypt new password before storage
                $smtpPass = \KutSocial\CryptoHelper::encrypt($smtpPass);
            }
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

            $_SESSION['admin_success_msg'] = "Ajustes generales guardados correctamente.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Handle Password modification form post.
     */
    public static function handlePassword(): void {
        $admin = self::checkAuth();
        $db = Database::connect();

        $currPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';

        if (!password_verify($currPass, $admin['password_hash'])) {
            $_SESSION['admin_error_msg'] = "La contraseña actual introducida no es válida.";
        } elseif (strlen($newPass) < 8) {
            $_SESSION['admin_error_msg'] = "La nueva contraseña debe tener un mínimo de 8 caracteres.";
        } else {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE accounts SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$newHash, $admin['id']]);
            $_SESSION['admin_success_msg'] = "Contraseña de administrador actualizada con éxito.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Setup 2FA: generate secret and return JSON with QR.
     */
    public static function setup2FA(): void {
        $admin = self::checkAuth();
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
        $db = Database::connect();

        $secret = trim($_POST['secret'] ?? '');
        $code = trim($_POST['otp_code'] ?? '');

        if (empty($secret) || empty($code)) {
            $_SESSION['admin_error_msg'] = "Los datos del formulario de verificación de 2FA están incompletos.";
            header("Location: /admin/dashboard");
            exit;
        }

        if (TotpHelper::verifyCode($secret, $code)) {
            $stmt = $db->prepare("UPDATE accounts SET otp_secret = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$secret, $admin['id']]);
            $_SESSION['admin_success_msg'] = "¡Autenticación de Dos Factores (2FA) configurada y activada con éxito!";
        } else {
            $_SESSION['admin_error_msg'] = "El código ingresado es incorrecto o expiró. Inténtalo de nuevo.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Disable 2FA.
     */
    public static function disable2FA(): void {
        $admin = self::checkAuth();
        $db = Database::connect();

        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (password_verify($confirmPassword, $admin['password_hash'])) {
            $stmt = $db->prepare("UPDATE accounts SET otp_secret = NULL, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$admin['id']]);
            $_SESSION['admin_success_msg'] = "La autenticación de dos factores (2FA) ha sido desactivada.";
        } else {
            $_SESSION['admin_error_msg'] = "Contraseña de confirmación incorrecta. No se pudo desactivar el 2FA.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Add a block (moderation filter).
     */
    public static function addBlock(): void {
        self::checkAuth();
        $db = Database::connect();

        $type = trim($_POST['type'] ?? '');
        $target = trim(strtolower($_POST['target'] ?? ''));

        if (empty($type) || empty($target)) {
            $_SESSION['admin_error_msg'] = "Faltan parámetros obligatorios para el bloqueo.";
            header("Location: /admin/dashboard");
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO moderation_blocks (type, target) VALUES (?, ?)");
            $stmt->execute([$type, $target]);
            $_SESSION['admin_success_msg'] = "Se ha bloqueado exitosamente: " . htmlspecialchars($target);
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Este bloqueo ya existe o es inválido.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Remove a block.
     */
    public static function removeBlock(): void {
        self::checkAuth();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM moderation_blocks WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['admin_success_msg'] = "Filtro de bloqueo removido correctamente.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Add a federated Relay and enqueue Follow job.
     */
    public static function addRelay(): void {
        self::checkAuth();
        $db = Database::connect();

        $inboxUrl = trim($_POST['inbox_url'] ?? '');
        if (!filter_var($inboxUrl, FILTER_VALIDATE_URL)) {
            $_SESSION['admin_error_msg'] = "La dirección URL del relay no es válida.";
            header("Location: /admin/dashboard");
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

            $_SESSION['admin_success_msg'] = "Relay agregado. Solicitud de suscripción enviada a la cola de tareas.";
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Este relay ya está registrado.";
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Remove a relay and enqueue Undo Follow job.
     */
    public static function removeRelay(): void {
        self::checkAuth();
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
                $_SESSION['admin_success_msg'] = "Desconectando del relay. La solicitud de remoción ha sido encolada.";
            }
        }
        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Handle AJAX update and rollback actions (check, download, apply, rollback).
     */
    public static function handleUpdateAction(): void {
        self::checkAuth();
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

                // Validar que provenga de github
                if (!preg_match('#^https://(?:api\.)?github\.com/#i', $zipUrl)) {
                    echo json_encode(['error' => 'La URL de descarga debe apuntar a GitHub.']);
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

            case 'rollback':
                set_time_limit(120);
                $backupFile = $_POST['backup_file'] ?? $_GET['backup_file'] ?? null;
                // Validate backup file path to prevent path traversal
                if ($backupFile) {
                    $realBackupPath = realpath($backupFile);
                    $realUpdateDir = realpath($updater->getUpdateDir());
                    if (!$realBackupPath || !$realUpdateDir || !str_starts_with($realBackupPath, $realUpdateDir)) {
                        echo json_encode(['error' => 'Ruta del archivo de respaldo no válida.']);
                        break;
                    }
                }
                $result = $updater->rollback($backupFile);
                echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Acción no válida.']);
                break;
        }
        exit;
    }

    /**
     * Create a new local user.
     */
    public static function createUser(): void {
        self::checkAuth();
        $db = Database::connect();

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'user');

        if (empty($username) || empty($email) || empty($password)) {
            $_SESSION['admin_error_msg'] = "Todos los campos son requeridos para crear un usuario.";
            header("Location: /admin/dashboard");
            exit;
        }

        $username = strtolower($username);
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $username)) {
            $_SESSION['admin_error_msg'] = "El nombre de usuario debe ser alfanumérico y contener entre 1 y 30 caracteres.";
            header("Location: /admin/dashboard");
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM accounts WHERE username = ? AND domain IS NULL LIMIT 1");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $_SESSION['admin_error_msg'] = "El nombre de usuario ya está registrado localmente.";
            header("Location: /admin/dashboard");
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
            $_SESSION['admin_error_msg'] = "Error al generar las llaves criptográficas RSA para el nuevo usuario.";
            header("Location: /admin/dashboard");
            exit;
        }
        openssl_pkey_export($res, $privateKey);
        $pubKeyDetails = openssl_pkey_get_details($res);
        $publicKey = $pubKeyDetails["key"];

        $pwdHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $db->prepare("
                INSERT INTO accounts (username, display_name, email, password_hash, public_key, private_key, role, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([
                $username,
                $username,
                $email,
                $pwdHash,
                $publicKey,
                $privateKey,
                $role
            ]);
            $_SESSION['admin_success_msg'] = "Usuario '$username' creado correctamente.";
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Error al crear el usuario: " . $e->getMessage();
        }

        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Delete a local user.
     */
    public static function deleteUser(): void {
        $admin = self::checkAuth();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['admin_error_msg'] = "ID de usuario inválido.";
            header("Location: /admin/dashboard");
            exit;
        }

        if ($id === (int)$admin['id']) {
            $_SESSION['admin_error_msg'] = "No puedes eliminar tu propia cuenta.";
            header("Location: /admin/dashboard");
            exit;
        }

        $stmtUser = $db->prepare("SELECT username, role, domain FROM accounts WHERE id = ? LIMIT 1");
        $stmtUser->execute([$id]);
        $userToDelete = $stmtUser->fetch();

        if (!$userToDelete) {
            $_SESSION['admin_error_msg'] = "Usuario no encontrado.";
            header("Location: /admin/dashboard");
            exit;
        }

        if ($userToDelete['role'] === 'owner' && $admin['role'] !== 'owner') {
            $_SESSION['admin_error_msg'] = "No tienes permisos para eliminar al propietario de la instancia.";
            header("Location: /admin/dashboard");
            exit;
        }

        try {
            $stmt = $db->prepare("DELETE FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['admin_success_msg'] = "El usuario '" . htmlspecialchars($userToDelete['username']) . "' ha sido eliminado.";
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Error al eliminar el usuario: " . $e->getMessage();
        }

        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Change a user's role.
     */
    public static function changeUserRole(): void {
        $admin = self::checkAuth();
        $db = Database::connect();

        $id = (int)($_POST['id'] ?? 0);
        $role = trim($_POST['role'] ?? 'user');

        if ($id <= 0 || !in_array($role, ['user', 'admin', 'owner'])) {
            $_SESSION['admin_error_msg'] = "Parámetros inválidos para cambiar el rol.";
            header("Location: /admin/dashboard");
            exit;
        }

        $stmtUser = $db->prepare("SELECT username, role FROM accounts WHERE id = ? LIMIT 1");
        $stmtUser->execute([$id]);
        $targetUser = $stmtUser->fetch();

        if (!$targetUser) {
            $_SESSION['admin_error_msg'] = "Usuario no encontrado.";
            header("Location: /admin/dashboard");
            exit;
        }

        if ($id === (int)$admin['id'] && $role !== $admin['role']) {
            $_SESSION['admin_error_msg'] = "No puedes cambiar tu propio rol (auto-democión).";
            header("Location: /admin/dashboard");
            exit;
        }

        if ($targetUser['role'] === 'owner' && $admin['role'] !== 'owner') {
            $_SESSION['admin_error_msg'] = "No tienes permisos para cambiar el rol del propietario.";
            header("Location: /admin/dashboard");
            exit;
        }

        if (($role === 'owner' || $targetUser['role'] === 'owner') && $admin['role'] !== 'owner') {
            $_SESSION['admin_error_msg'] = "Sólo el propietario de la instancia puede asignar o modificar el rol de propietario.";
            header("Location: /admin/dashboard");
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE accounts SET role = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$role, $id]);
            $_SESSION['admin_success_msg'] = "Rol del usuario '" . htmlspecialchars($targetUser['username']) . "' actualizado a '$role'.";
        } catch (Exception $e) {
            $_SESSION['admin_error_msg'] = "Error al actualizar el rol: " . $e->getMessage();
        }

        header("Location: /admin/dashboard");
        exit;
    }

    /**
     * Run clean up operations based on selected options.
     */
    public static function runMaintenanceClean(): void {
        self::checkAuth();
        $db = Database::connect();

        $cleanOrphans = isset($_POST['clean_orphans']) && $_POST['clean_orphans'] == '1';
        $pruneAccounts = isset($_POST['prune_accounts']) && $_POST['prune_accounts'] == '1';
        $removeStatuses = isset($_POST['remove_statuses']) && $_POST['remove_statuses'] == '1';
        $clearJobs = isset($_POST['clear_jobs']) && $_POST['clear_jobs'] == '1';
        $vacuumDb = isset($_POST['vacuum_db']) && $_POST['vacuum_db'] == '1';
        $statusesDays = isset($_POST['statuses_days']) ? intval($_POST['statuses_days']) : 4;

        if ($statusesDays < 1) {
            $statusesDays = 4;
        }

        $messages = [];

        // 1. Eliminar Estados Remotos Antiguos
        if ($removeStatuses) {
            try {
                $stmt = $db->prepare("
                    DELETE FROM statuses WHERE id IN (
                        SELECT s.id FROM statuses s
                        JOIN accounts a ON s.account_id = a.id
                        WHERE a.domain IS NOT NULL
                          AND s.created_at < datetime('now', :days)
                          AND NOT EXISTS (
                              SELECT 1 FROM favourites fav
                              JOIN accounts a_local ON fav.account_id = a_local.id
                              WHERE fav.status_id = s.id AND a_local.domain IS NULL
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM bookmarks b
                              JOIN accounts a_local ON b.account_id = a_local.id
                              WHERE b.status_id = s.id AND a_local.domain IS NULL
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM statuses s_reply
                              JOIN accounts a_local ON s_reply.account_id = a_local.id
                              WHERE s_reply.in_reply_to_id = s.id AND a_local.domain IS NULL
                          )
                    )
                ");
                $stmt->execute([':days' => "-$statusesDays days"]);
                $deletedStatuses = $stmt->rowCount();
                $messages[] = "Se eliminaron $deletedStatuses estados remotos antiguos (> $statusesDays días).";
            } catch (Exception $e) {
                $messages[] = "Error al eliminar estados remotos: " . $e->getMessage();
            }
        }

        // 2. Eliminar Cuentas Remotas Inactivas
        if ($pruneAccounts) {
            try {
                $stmt = $db->prepare("
                    DELETE FROM accounts
                    WHERE domain IS NOT NULL
                      AND id NOT IN (
                          SELECT target_account_id FROM follows f
                          JOIN accounts a_local ON f.account_id = a_local.id
                          WHERE a_local.domain IS NULL
                      )
                      AND id NOT IN (
                          SELECT account_id FROM follows f
                          JOIN accounts a_local ON f.target_account_id = a_local.id
                          WHERE a_local.domain IS NULL
                      )
                      AND id NOT IN (
                          SELECT s.account_id FROM favourites fav
                          JOIN statuses s ON fav.status_id = s.id
                          JOIN accounts a_local ON fav.account_id = a_local.id
                          WHERE a_local.domain IS NULL
                      )
                      AND id NOT IN (
                          SELECT s.account_id FROM bookmarks b
                          JOIN statuses s ON b.status_id = s.id
                          JOIN accounts a_local ON b.account_id = a_local.id
                          WHERE a_local.domain IS NULL
                      )
                      AND id NOT IN (
                          SELECT s_parent.account_id FROM statuses s_reply
                          JOIN statuses s_parent ON s_reply.in_reply_to_id = s_parent.id
                          JOIN accounts a_local ON s_reply.account_id = a_local.id
                          WHERE a_local.domain IS NULL
                      )
                ");
                $stmt->execute();
                $prunedAccounts = $stmt->rowCount();
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

        // 5. Optimizar Base de Datos (VACUUM)
        if ($vacuumDb) {
            try {
                $dbPath = Database::getDbPath();
                $initialSize = file_exists($dbPath) ? filesize($dbPath) : 0;
                $db->exec("VACUUM;");
                $finalSize = file_exists($dbPath) ? filesize($dbPath) : 0;
                $savedDBBytes = max(0, $initialSize - $finalSize);
                $savedDBMB = round($savedDBBytes / (1024 * 1024), 2);
                $messages[] = "Base de datos optimizada (VACUUM): se recuperaron $savedDBMB MB de espacio.";
            } catch (Exception $e) {
                $messages[] = "Error al optimizar la base de datos (VACUUM): " . $e->getMessage();
            }
        }

        if (empty($messages)) {
            $_SESSION['admin_success_msg'] = "No se seleccionó ninguna tarea de mantenimiento.";
        } else {
            $_SESSION['admin_success_msg'] = "Mantenimiento completado con éxito:<br>• " . implode("<br>• ", $messages);
        }

        header("Location: /admin/dashboard");
        exit;
    }
}
