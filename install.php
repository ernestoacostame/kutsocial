<?php
require_once __DIR__ . '/version.php';
/**
 * KutSocial - Instalador Unificado (CLI y Web)
 * Construido con estética premium y diseño responsivo.
 */

// Autocargar clases de forma básica antes de la instalación completa
spl_autoload_register(function ($class) {
    $prefix = 'KutSocial\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use KutSocial\Database;

// Evitar doble instalación
if (file_exists(__DIR__ . '/config.php')) {
    if (php_sapi_name() === 'cli') {
        echo "KutSocial ya está instalado. Elimina config.php para reinstalar.\n";
        exit(0);
    } else {
        header("Location: /");
        exit;
    }
}

// 1. Validar requerimientos
$errors = [];
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    $errors[] = "Se requiere PHP 8.3 o superior. Tu versión actual es " . PHP_VERSION;
}
$required_extensions = ['pdo', 'openssl', 'curl', 'zip'];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $errors[] = "Falta la extensión requerida de PHP: $ext";
    }
}
$pdo_driver_extensions = [
    'sqlite' => 'pdo_sqlite',
    'pgsql'  => 'pdo_pgsql',
    'mysql'  => 'pdo_mysql',
];
if (!is_writable(__DIR__)) {
    $errors[] = "El directorio raíz de la aplicación no tiene permisos de escritura.";
}

function normalizeDbDriver(string $driver): string {
    $d = strtolower(trim($driver));
    if ($d === 'postgres' || $d === 'postgresql') {
        return 'pgsql';
    }
    if ($d === 'mariadb') {
        return 'mysql';
    }
    return $d;
}

// 2. Lógica del Instalador CLI
if (php_sapi_name() === 'cli') {
    if (!empty($errors)) {
        echo "ERROR: Requisitos no cumplidos:\n";
        foreach ($errors as $err) {
            echo "  - $err\n";
        }
        exit(1);
    }

    $options = getopt("", [
        "username:",
        "email:",
        "password:",
        "domain:",
        "db-driver::",
        "db-host::",
        "db-port::",
        "db-name::",
        "db-user::",
        "db-pass::",
        "db-path::",
    ]);
    $username = $options['username'] ?? null;
    $email = $options['email'] ?? null;
    $password = $options['password'] ?? null;
    $domain = $options['domain'] ?? null;

    if (!$username || !$email || !$password || !$domain) {
        echo "KutSocial CLI Installer\n";
        echo "Uso: php install.php --username=admin --email=admin@example.com --password=adminpass --domain=kutsocial.local [--db-driver=sqlite|pgsql|mysql] [--db-host=127.0.0.1] [--db-port=5432|3306] [--db-name=kutsocial] [--db-user=kutsocial] [--db-pass=secret] [--db-path=/ruta/kutsocial.db]\n";
        exit(1);
    }

    $dbDriver = normalizeDbDriver((string)($options['db-driver'] ?? 'sqlite'));
    if (!isset($pdo_driver_extensions[$dbDriver])) {
        echo "ERROR: Motor de base de datos no válido '{$dbDriver}'. Valores permitidos: sqlite, pgsql, mysql.\n";
        exit(1);
    }
    $reqPdoExt = $pdo_driver_extensions[$dbDriver];
    if (!extension_loaded($reqPdoExt)) {
        echo "ERROR: Falta la extensión PHP requerida para {$dbDriver}: {$reqPdoExt}\n";
        exit(1);
    }

    $defaultPort = $dbDriver === 'pgsql' ? '5432' : ($dbDriver === 'mysql' ? '3306' : '');
    $dbConfig = [
        'driver' => $dbDriver,
        'host'   => (string)($options['db-host'] ?? '127.0.0.1'),
        'port'   => (string)($options['db-port'] ?? $defaultPort),
        'name'   => (string)($options['db-name'] ?? 'kutsocial'),
        'user'   => (string)($options['db-user'] ?? 'kutsocial'),
        'pass'   => (string)($options['db-pass'] ?? ''),
        'path'   => (string)($options['db-path'] ?? (__DIR__ . '/data/kutsocial.db')),
    ];

    try {
        runInstallation($username, $email, $password, $domain, $dbConfig);
        echo "INSTALACIÓN COMPLETADA CON ÉXITO.\n";
        echo "Dominio: $domain\n";
        echo "Base de datos: $dbDriver\n";
        echo "Usuario Administrador: $username\n";
        exit(0);
    } catch (Exception $e) {
        echo "ERROR DURANTE LA INSTALACIÓN: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// 3. Lógica del Instalador Web (UI Premium)
$web_error = '';
$web_success = false;

$form_domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$form_username = '';
$form_email = '';
$form_db_driver = 'sqlite';
$form_db_host = '127.0.0.1';
$form_db_port = '5432';
$form_db_name = 'kutsocial';
$form_db_user = 'kutsocial';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_domain = trim($_POST['domain'] ?? $form_domain);
    $form_username = trim($_POST['username'] ?? '');
    $form_email = trim($_POST['email'] ?? '');
    $form_db_driver = normalizeDbDriver((string)($_POST['db_driver'] ?? 'sqlite'));
    $form_db_host = trim($_POST['db_host'] ?? '127.0.0.1');
    $form_db_port = trim($_POST['db_port'] ?? ($form_db_driver === 'mysql' ? '3306' : '5432'));
    $form_db_name = trim($_POST['db_name'] ?? 'kutsocial');
    $form_db_user = trim($_POST['db_user'] ?? 'kutsocial');
    $db_pass = (string)($_POST['db_pass'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($errors)) {
        $web_error = "Corrige los requerimientos del servidor antes de continuar.";
    } elseif (!isset($pdo_driver_extensions[$form_db_driver])) {
        $web_error = "Motor de base de datos no válido.";
    } elseif (!extension_loaded($pdo_driver_extensions[$form_db_driver])) {
        $reqExt = $pdo_driver_extensions[$form_db_driver];
        $web_error = "La extensión PHP requerida para {$form_db_driver} ({$reqExt}) no está instalada o habilitada.";
    } elseif (empty($form_username) || empty($form_email) || empty($password) || empty($form_domain)) {
        $web_error = "Todos los campos obligatorios deben completarse.";
    } elseif (!filter_var($form_email, FILTER_VALIDATE_EMAIL)) {
        $web_error = "Formato de correo electrónico no válido.";
    } elseif (strlen($password) < 8) {
        $web_error = "La contraseña debe tener al menos 8 caracteres.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $form_username)) {
        $web_error = "El nombre de usuario solo puede contener letras, números y guiones bajos.";
    } elseif ($form_db_driver !== 'sqlite' && ($form_db_host === '' || $form_db_name === '' || $form_db_user === '')) {
        $web_error = "Completa Host, Nombre de Base de Datos y Usuario para {$form_db_driver}.";
    } else {
        try {
            $dbConfig = [
                'driver' => $form_db_driver,
                'host'   => $form_db_host,
                'port'   => $form_db_port !== '' ? $form_db_port : ($form_db_driver === 'mysql' ? '3306' : '5432'),
                'name'   => $form_db_name,
                'user'   => $form_db_user,
                'pass'   => $db_pass,
                'path'   => __DIR__ . '/data/kutsocial.db',
            ];
            runInstallation($form_username, $form_email, $password, $form_domain, $dbConfig);
            $web_success = true;
        } catch (Exception $e) {
            $web_error = "Fallo en la instalación: " . $e->getMessage();
        }
    }
}

// Generación de configuración e inserción del Administrador
function runInstallation(string $username, string $email, string $password, string $domain, array $dbConfig = []): void {
    global $pdo_driver_extensions;

    $driver = normalizeDbDriver((string)($dbConfig['driver'] ?? 'sqlite'));
    if (!isset($pdo_driver_extensions[$driver])) {
        throw new Exception("Motor de base de datos no soportado: {$driver}");
    }
    $requiredPdoExt = $pdo_driver_extensions[$driver];
    if (!extension_loaded($requiredPdoExt)) {
        throw new Exception("La extensión PHP '{$requiredPdoExt}' es requerida para {$driver} y no está cargada.");
    }

    // 1. Crear directorios
    $dataDir = __DIR__ . '/data';
    $uploadsDir = $dataDir . '/uploads';
    $backupsDir = $dataDir . '/backups';

    foreach ([$dataDir, $uploadsDir, $backupsDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    $defaultSqlitePath = $dataDir . '/kutsocial.db';
    $dbPath = ($driver === 'sqlite') ? (string)($dbConfig['path'] ?? $defaultSqlitePath) : '';
    $dbHost = ($driver !== 'sqlite') ? (string)($dbConfig['host'] ?? '127.0.0.1') : '';
    $dbPort = ($driver !== 'sqlite') ? (string)($dbConfig['port'] ?? ($driver === 'mysql' ? '3306' : '5432')) : '';
    $dbName = ($driver !== 'sqlite') ? (string)($dbConfig['name'] ?? 'kutsocial') : '';
    $dbUser = ($driver !== 'sqlite') ? (string)($dbConfig['user'] ?? 'kutsocial') : '';
    $dbPass = ($driver !== 'sqlite') ? (string)($dbConfig['pass'] ?? '') : '';

    // 2. Configurar y probar conexión antes de crear tablas/usuario
    Database::setConfig([
        'driver' => $driver,
        'host'   => $dbHost,
        'port'   => $dbPort,
        'name'   => $dbName,
        'user'   => $dbUser,
        'pass'   => $dbPass,
        'path'   => $dbPath,
    ]);

    try {
        $db = Database::connect();
    } catch (Exception $e) {
        throw new Exception("No se pudo conectar a la base de datos ({$driver}). Verifica las credenciales y que el servidor esté accesible. Detalle: " . $e->getMessage());
    }

    // Aplicar migraciones
    Database::runMigrations();

    // 3. Generar par de llaves RSA para el administrador
    $config_args = array(
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );
    $res = openssl_pkey_new($config_args);
    if (!$res) {
        throw new Exception("Error al generar las llaves criptográficas RSA. Asegúrate de tener configurada la extensión openssl.");
    }
    openssl_pkey_export($res, $privateKey);
    $pubKeyDetails = openssl_pkey_get_details($res);
    $publicKey = $pubKeyDetails["key"];

    // 4. Crear el usuario Administrador en la base de datos
    $pwdHash = password_hash($password, PASSWORD_DEFAULT);
    $nowUtc = Database::nowUtc();

    $stmt = $db->prepare("
        INSERT INTO accounts (username, display_name, email, password_hash, public_key, private_key, role, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'owner', ?)
    ");
    $stmt->execute([
        strtolower($username),
        $username,
        $email,
        $pwdHash,
        $publicKey,
        $privateKey,
        $nowUtc
    ]);
    Database::lastInsertId($db, 'accounts');

    // Opciones iniciales de la instancia (portable entre SQLite, PostgreSQL y MySQL/MariaDB)
    Database::upsertOption($db, 'instance_title', 'KutSocial');
    Database::upsertOption($db, 'instance_version', KUTSOCIAL_VERSION);

    // 5. Escribir el archivo config.php
    $dbPathPhp = ($driver === 'sqlite' && $dbPath === $defaultSqlitePath)
        ? "__DIR__ . '/data/kutsocial.db'"
        : var_export($dbPath, true);

    $configContent = "<?php\n"
        . "// ============================================================================\n"
        . "// KutSocial · Archivo de Configuración de la Instancia\n"
        . "// Generado automáticamente por el instalador\n"
        . "// ============================================================================\n\n"
        . "define('KUTSOCIAL_INSTALLED', true);\n"
        . "require_once __DIR__ . '/version.php';\n"
        . "define('KUTSOCIAL_DOMAIN', " . var_export($domain, true) . ");\n"
        . "define('KUTSOCIAL_DB_DRIVER', " . var_export($driver, true) . ");\n"
        . "define('KUTSOCIAL_DB_PATH', " . $dbPathPhp . ");\n"
        . "define('KUTSOCIAL_DB_HOST', " . var_export($dbHost, true) . ");\n"
        . "define('KUTSOCIAL_DB_PORT', " . var_export($dbPort, true) . ");\n"
        . "define('KUTSOCIAL_DB_NAME', " . var_export($dbName, true) . ");\n"
        . "define('KUTSOCIAL_DB_USER', " . var_export($dbUser, true) . ");\n"
        . "define('KUTSOCIAL_DB_PASS', " . var_export($dbPass, true) . ");\n"
        . "define('KUTSOCIAL_VERIFY_SSL', true); // Cambiar a false si estás en desarrollo local sin certificados CA configurados\n\n"
        . "// Autocargar clases del núcleo\n"
        . "spl_autoload_register(function (\$class) {\n"
        . "    \$prefix = 'KutSocial\\\\';\n"
        . "    \$base_dir = __DIR__ . '/src/';\n"
        . "    \$len = strlen(\$prefix);\n"
        . "    if (strncmp(\$prefix, \$class, \$len) !== 0) {\n"
        . "        return;\n"
        . "    }\n"
        . "    \$relative_class = substr(\$class, \$len);\n"
        . "    \$file = \$base_dir . str_replace('\\\\', '/', \$relative_class) . '.php';\n"
        . "    if (file_exists(\$file)) {\n"
        . "        require_once \$file;\n"
        . "    }\n"
        . "});\n";

    file_put_contents(__DIR__ . '/config.php', $configContent);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador de KutSocial</title>
    <link rel="stylesheet" href="/assets/fonts.css">
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-image: radial-gradient(circle at 10% 20%, rgba(79, 70, 229, 0.1) 0%, transparent 40%),
                              radial-gradient(circle at 90% 80%, rgba(16, 185, 129, 0.08) 0%, transparent 40%);
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 580px;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-text {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #818cf8 0%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .logo-subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 5px;
        }

        h2 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .requirements {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
        }

        .req-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .req-item:last-child {
            margin-bottom: 0;
        }

        .badge {
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge.success {
            background-color: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .badge.error {
            background-color: rgba(239, 68, 68, 0.15);
            color: var(--error);
        }

        .alert {
            padding: 15px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 25px;
            line-height: 1.5;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #a7f3d0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input, select {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            font-family: inherit;
            font-size: 15px;
            transition: all 0.3s;
        }

        select option {
            background-color: #161c26;
            color: var(--text-color);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .badge.muted {
            background-color: rgba(156, 163, 175, 0.12);
            color: var(--text-muted);
        }

        .db-network-fields {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 140px;
            gap: 14px;
        }

        @media (max-width: 520px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        button {
            width: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, #6366f1 100%);
            border: none;
            border-radius: 10px;
            padding: 14px;
            color: white;
            font-family: inherit;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
            margin-top: 10px;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 30px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="logo-container">
        <div class="logo-text">KutSocial</div>
        <div class="logo-subtitle">Alternativa ultraligera para el Fediverso</div>
    </div>

    <?php if ($web_success): ?>
        <div class="alert alert-success">
            <strong>¡Instalación exitosa!</strong> El núcleo de KutSocial ha sido instalado con éxito. Ya puedes conectar tus aplicaciones compatibles con Mastodon y tu instancia de KutPod.
        </div>
        <div style="text-align: center; margin-top: 20px;">
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                Por favor, elimina el archivo <code>install.php</code> de tu servidor para mantener la seguridad.
            </p>
            <a href="/" style="display: inline-block; background: var(--primary); color: white; padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: background 0.3s;">Ir al Inicio de la Instancia</a>
        </div>
    <?php else: ?>
        <h2>Estado del Servidor</h2>
        <div class="requirements">
            <div class="req-item">
                <span>Versión PHP (8.3+)</span>
                <span class="badge <?php echo version_compare(PHP_VERSION, '8.3.0', '>=') ? 'success' : 'error'; ?>">
                    PHP <?php echo PHP_VERSION; ?>
                </span>
            </div>
            <?php foreach ($required_extensions as $ext): ?>
                <div class="req-item">
                    <span>Extensión: <?php echo $ext; ?></span>
                    <span class="badge <?php echo extension_loaded($ext) ? 'success' : 'error'; ?>">
                        <?php echo extension_loaded($ext) ? 'activo' : 'falta'; ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php foreach ($pdo_driver_extensions as $drv => $ext): ?>
                <div class="req-item">
                    <span>Driver PDO (<?php echo strtoupper($drv); ?>): <?php echo $ext; ?></span>
                    <span class="badge <?php echo extension_loaded($ext) ? 'success' : 'muted'; ?>">
                        <?php echo extension_loaded($ext) ? 'activo' : 'no cargado'; ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <div class="req-item">
                <span>Escritura Directorio Raíz</span>
                <span class="badge <?php echo is_writable(__DIR__) ? 'success' : 'error'; ?>">
                    <?php echo is_writable(__DIR__) ? 'concedido' : 'denegado'; ?>
                </span>
            </div>
        </div>

        <?php if ($web_error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($web_error); ?>
            </div>
        <?php endif; ?>

        <h2>Configuración Inicial</h2>
        <form method="POST" action="">
            <div class="form-group">
                <label for="domain">Dominio de la Instancia</label>
                <input type="text" id="domain" name="domain" value="<?php echo htmlspecialchars($form_domain); ?>" required>
            </div>

            <div class="form-group">
                <label for="db_driver">Motor de Base de Datos</label>
                <select id="db_driver" name="db_driver">
                    <option value="sqlite" <?php echo $form_db_driver === 'sqlite' ? 'selected' : ''; ?>>SQLite (Archivo local)</option>
                    <option value="pgsql" <?php echo $form_db_driver === 'pgsql' ? 'selected' : ''; ?>>PostgreSQL</option>
                    <option value="mysql" <?php echo $form_db_driver === 'mysql' ? 'selected' : ''; ?>>MariaDB / MySQL</option>
                </select>
            </div>

            <div id="db-network-fields" class="db-network-fields" style="display: <?php echo $form_db_driver !== 'sqlite' ? 'block' : 'none'; ?>;">
                <div class="form-row">
                    <div class="form-group">
                        <label for="db_host">Host</label>
                        <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($form_db_host); ?>" placeholder="127.0.0.1">
                    </div>
                    <div class="form-group">
                        <label for="db_port">Puerto</label>
                        <input type="text" id="db_port" name="db_port" value="<?php echo htmlspecialchars($form_db_port); ?>" placeholder="5432">
                    </div>
                </div>
                <div class="form-group">
                    <label for="db_name">Nombre de Base de Datos</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($form_db_name); ?>" placeholder="kutsocial">
                </div>
                <div class="form-group">
                    <label for="db_user">Usuario</label>
                    <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($form_db_user); ?>" placeholder="kutsocial">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="db_pass">Contraseña</label>
                    <input type="password" id="db_pass" name="db_pass" placeholder="••••••••" autocomplete="new-password">
                </div>
            </div>

            <div class="form-group">
                <label for="username">Nombre de Usuario (Admin)</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($form_username); ?>" placeholder="ej. admin" required pattern="^[a-zA-Z0-9_]+$">
            </div>
            <div class="form-group">
                <label for="email">Correo Electrónico</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($form_email); ?>" placeholder="admin@tudominio.com" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña (mínimo 8 caracteres)</label>
                <input type="password" id="password" name="password" minlength="8" placeholder="••••••••" required>
            </div>

            <button type="submit" <?php echo !empty($errors) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Instalar Instancia</button>
        </form>
    <?php endif; ?>

    <div class="footer-note">
        KutSocial &copy; <?php echo date('Y'); ?> &bull; Diseñado para alta concurrencia con SQLite WAL, PostgreSQL y MariaDB/MySQL
    </div>
</div>

<script>
(function () {
    const driverSelect = document.getElementById('db_driver');
    const networkBox = document.getElementById('db-network-fields');
    if (!driverSelect || !networkBox) return;

    const portInput = document.getElementById('db_port');
    const requiredIds = ['db_host', 'db_port', 'db_name', 'db_user'];

    function syncDbFields(updateDefaultPort) {
        const driver = driverSelect.value;
        const isNetwork = driver === 'pgsql' || driver === 'mysql';
        networkBox.style.display = isNetwork ? 'block' : 'none';

        requiredIds.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.required = isNetwork;
            }
        });

        if (updateDefaultPort && portInput) {
            const val = portInput.value.trim();
            if (driver === 'pgsql' && (val === '' || val === '3306')) {
                portInput.value = '5432';
            } else if (driver === 'mysql' && (val === '' || val === '5432')) {
                portInput.value = '3306';
            }
        }
    }

    driverSelect.addEventListener('change', function () {
        syncDbFields(true);
    });
    syncDbFields(false);
})();
</script>

</body>
</html>
