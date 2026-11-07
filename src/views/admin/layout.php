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
                <li>
                    <a href="/admin/dashboard/update" class="tab-btn <?= $section === 'update' ? 'active' : '' ?>">
                        <span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">system_update</span>
                        <?= __('admin_tab_update') ?>
                        <span class="sidebar-update-badge" id="kutsocial-update-badge" style="<?= !empty($isUpdateAvailable) ? 'display:inline-block;' : 'display:none;' ?>">v<?= htmlspecialchars($availableVersion) ?></span>
                    </a>
                </li>
                <li><a href="/admin/dashboard/maintenance" class="tab-btn <?= $section === 'maintenance' ? 'active' : '' ?>"><span class="material-icons-outlined" style="vertical-align: middle; margin-right: 8px;">cleaning_services</span> <?= __('admin_tab_maintenance') ?></a></li>
            </ul>
        </aside>

        <!-- Panel Central de Contenido -->
        <main>
            <!-- Banner de Actualización Disponible -->
            <div id="kutsocial-update-banner" class="update-notification-banner" style="<?= (!empty($isUpdateAvailable) && $section !== 'update') ? 'display:flex;' : 'display:none;' ?>">
                <div class="update-banner-icon">
                    <span class="material-icons-outlined">rocket_launch</span>
                </div>
                <div class="update-banner-body">
                    <div class="update-banner-title"><?= __('admin_update_available_title', '¡Nueva versión disponible!') ?></div>
                    <div class="update-banner-desc">
                        <?= __raw('admin_update_available_desc', 'Hay una nueva versión de KutSocial (:version). Actualmente tienes instalada la v:current.', [
                            'version' => '<strong id="kutsocial-update-ver-label">v' . htmlspecialchars($availableVersion) . '</strong>',
                            'current' => htmlspecialchars($currentVersion)
                        ]) ?>
                    </div>
                </div>
                <div class="update-banner-actions">
                    <a href="/admin/dashboard/update" class="btn-update-banner"><?= __('admin_update_now_btn') ?> →</a>
                    <button type="button" class="btn-dismiss-banner" onclick="dismissUpdateBanner('kutsocial')" title="Descartar">✕</button>
                </div>
            </div>

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
    <script>
    function dismissUpdateBanner(app) {
        var ver = document.getElementById(app + '-update-ver-label')?.innerText || '1';
        sessionStorage.setItem('dismissed_update_' + app, ver);
        var b = document.getElementById(app + '-update-banner');
        if (b) b.style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
        var banner = document.getElementById('kutsocial-update-banner');
        var badge = document.getElementById('kutsocial-update-badge');
        var verLabel = document.getElementById('kutsocial-update-ver-label');

        // Ocultar si ya se descartó en esta sesión
        if (banner && banner.style.display !== 'none') {
            var dismissed = sessionStorage.getItem('dismissed_update_kutsocial');
            if (dismissed && verLabel && dismissed === verLabel.innerText) {
                banner.style.display = 'none';
            }
        }

        <?php if (!empty($checkUpdateAsync)): ?>
        // Consulta en segundo plano asíncrona si la caché expiró
        fetch('/admin/update/action?action=check', { method: 'GET', headers: { 'Accept': 'application/json' } })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data && data.available && data.new_version) {
                    var currentVer = '<?= htmlspecialchars($currentVersion) ?>';
                    if (data.new_version !== currentVer) {
                        if (verLabel) verLabel.innerText = 'v' + data.new_version;
                        if (badge) {
                            badge.innerText = 'v' + data.new_version;
                            badge.style.display = 'inline-block';
                        }
                        var dismissed = sessionStorage.getItem('dismissed_update_kutsocial');
                        if (banner && dismissed !== ('v' + data.new_version) && '<?= $section ?>' !== 'update') {
                            banner.style.display = 'flex';
                        }
                    }
                }
            })
            .catch(function() {});
        <?php endif; ?>
    });
    </script>
</body>
</html>
