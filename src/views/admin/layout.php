<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - KutSocial</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons|Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="admin-dashboard-body">
    <nav class="navbar">
        <div class="nav-logo" style="display: flex; align-items: center; gap: 10px;">
            <img src="/kutsocial_logo.svg" alt="Logo" style="width: 28px; height: 28px;">
            <span>KutSocial Admin Panel</span>
        </div>
        <div class="nav-user">
            <span style="font-size: 14.5px; font-weight: 600;">@<?= htmlspecialchars($admin['username']) ?></span>
            <a href="/" class="btn-link">Ver Web</a>
            <a href="/admin/logout" class="btn-link" style="color: var(--error);">Salir</a>
        </div>
    </nav>

    <div class="dashboard-layout">
        <!-- Sidebar menú de pestañas -->
        <aside>
            <ul class="tab-menu">
                <li><a href="/admin/dashboard/general" class="tab-btn <?= $section === 'general' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">settings</span> Ajustes Generales</a></li>
                <li><a href="/admin/dashboard/security" class="tab-btn <?= $section === 'security' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">security</span> Seguridad y 2FA</a></li>
                <li><a href="/admin/dashboard/users" class="tab-btn <?= $section === 'users' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">people</span> Gestionar Usuarios</a></li>
                <li><a href="/admin/dashboard/moderation" class="tab-btn <?= $section === 'moderation' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">gpp_good</span> Moderación / Filtros</a></li>
                <li><a href="/admin/dashboard/relays" class="tab-btn <?= $section === 'relays' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">sync</span> Relays ActivityPub</a></li>
                <li><a href="/admin/dashboard/update" class="tab-btn <?= $section === 'update' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">system_update</span> Actualizador</a></li>
                <li><a href="/admin/dashboard/maintenance" class="tab-btn <?= $section === 'maintenance' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">cleaning_services</span> Mantenimiento</a></li>
            </ul>
        </aside>

        <!-- Panel Central de Contenido -->
        <main>
            <?php if ($successMsg): ?>
                <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
                <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <div class="tab-content">
                <?php include __DIR__ . '/' . $section . '.php'; ?>
            </div>
        </main>
    </div>
</body>
</html>
