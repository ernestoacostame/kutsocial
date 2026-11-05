<!DOCTYPE html>
<html lang="<?= htmlspecialchars(class_exists('\KutSocial\I18n') ? \KutSocial\I18n::getLang() : 'es') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('admin_panel_title') ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons|Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="/assets/admin.css?v=<?= KUTSOCIAL_VERSION ?>">
</head>
<body class="admin-dashboard-body">
    <nav class="navbar">
        <div class="nav-logo" style="display: flex; align-items: center; gap: 10px;">
            <img src="/kutsocial_logo.svg" alt="Logo" style="width: 28px; height: 28px;">
            <span><?= __('admin_panel_title') ?></span>
        </div>
        <div class="nav-user">
            <!-- Selector Rápido de Idioma -->
            <div style="display: inline-flex; gap: 4px; align-items: center; margin-right: 15px; background: rgba(255,255,255,0.06); padding: 3px 6px; border-radius: 6px; font-size: 11.5px; font-weight: 700;">
                <a href="/lang/es" style="color: white; text-decoration: none; padding: 2px 6px; border-radius: 4px; <?= \KutSocial\I18n::getLang() === 'es' ? 'background: var(--primary);' : 'opacity: 0.6;' ?>">ES</a>
                <a href="/lang/en" style="color: white; text-decoration: none; padding: 2px 6px; border-radius: 4px; <?= \KutSocial\I18n::getLang() === 'en' ? 'background: var(--primary);' : 'opacity: 0.6;' ?>">EN</a>
            </div>
            <span style="font-size: 14.5px; font-weight: 600;">@<?= htmlspecialchars($admin['username']) ?></span>
            <a href="/" class="btn-link"><?= __('nav_view_web') ?></a>
            <a href="/admin/logout" class="btn-link" style="color: var(--error);"><?= __('nav_exit') ?></a>
        </div>
    </nav>

    <div class="dashboard-layout">
        <!-- Sidebar menú de pestañas -->
        <aside>
            <ul class="tab-menu">
                <li><a href="/admin/dashboard/general" class="tab-btn <?= $section === 'general' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">settings</span> <?= __('admin_tab_general') ?></a></li>
                <li><a href="/admin/dashboard/security" class="tab-btn <?= $section === 'security' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">security</span> <?= __('admin_tab_security') ?></a></li>
                <li><a href="/admin/dashboard/users" class="tab-btn <?= $section === 'users' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">people</span> <?= __('admin_tab_users') ?></a></li>
                <li><a href="/admin/dashboard/moderation" class="tab-btn <?= $section === 'moderation' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">gpp_good</span> <?= __('admin_tab_moderation') ?></a></li>
                <li><a href="/admin/dashboard/relays" class="tab-btn <?= $section === 'relays' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">sync</span> <?= __('admin_tab_relays') ?></a></li>
                <li><a href="/admin/dashboard/update" class="tab-btn <?= $section === 'update' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">system_update</span> <?= __('admin_tab_update') ?></a></li>
                <li><a href="/admin/dashboard/maintenance" class="tab-btn <?= $section === 'maintenance' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">cleaning_services</span> <?= __('admin_tab_maintenance') ?></a></li>
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
