<?php
$basePath = $basePath ?? '';
$uiTheme = $uiTheme ?? 'classic';
if (!in_array($uiTheme, ['classic', 'mastodon'], true)) {
    $uiTheme = 'classic';
}
$ver = htmlspecialchars(defined('KUTSOCIAL_VERSION') ? KUTSOCIAL_VERSION : '1.0.0');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(class_exists('\KutSocial\I18n') ? \KutSocial\I18n::getLang() : 'es') ?>" data-ui-theme="<?= htmlspecialchars($uiTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'KutSocial - Cliente Web') ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/pages.css?v=<?= $ver ?>">
    <link id="ui-theme-mastodon-css" rel="stylesheet" href="/assets/theme-mastodon.css?v=<?= $ver ?>" <?= $uiTheme === 'mastodon' ? '' : 'disabled' ?>>
    <link rel="stylesheet" href="/assets/fonts.css?v=<?= $ver ?>">
    <!-- DOMPurify para Saneamiento XSS -->
    <script src="/assets/js/purify.min.js?v=<?= $ver ?>" defer></script>
    <?php if ($uiTheme === 'mastodon'): ?>
    <script>
    (function() {
        try {
            if (localStorage.getItem('kutsocial_m5_sidebar_collapsed') === '1') {
                document.documentElement.classList.add('m5-sidebar-collapsed');
            }
        } catch (e) {}
    })();
    </script>
    <?php endif; ?>
    <style>
    .material-icons, .material-icons-outlined { width: 1em; height: 1em; overflow: hidden; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; vertical-align: middle; }
    #tab-<?= htmlspecialchars($section) ?> { display: <?= in_array($section, ['lists', 'collections']) ? 'flex' : 'block' ?>; }
    #tab-users-list { display: none; }
    #modal-composer .composer-card { border: none !important; margin-bottom: 0 !important; padding: 0 !important; background: transparent !important; }
    </style>
    <script>
    (function () {
        try {
            var t = localStorage.getItem('kutsocial_token') || window.KUTSOCIAL_AUTO_TOKEN;
            if (!t) document.documentElement.classList.add('is-guest');
        } catch (e) { document.documentElement.classList.add('is-guest'); }
    })();
    </script>
    <style>
    html.is-guest .auth-only,
    html.is-guest #home-timeline-header,
    html.is-guest #tab-feed .composer-card,
    html.is-guest #modal-composer { display: none !important; }
    html:not(.is-guest) .guest-only { display: none !important; }
    </style>
</head>
<body>

    <!-- Pantalla de Login / Carga -->
    <div id="login-container" class="full-screen-container" style="display: none;">
        <div class="login-card">
            <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
                <div style="display: inline-flex; gap: 4px; background: rgba(255,255,255,0.06); padding: 2px 4px; border-radius: 6px; font-size: 11px; font-weight: 700;">
                    <a href="/lang/es" style="color: white; text-decoration: none; padding: 2px 6px; border-radius: 4px; <?= \KutSocial\I18n::getLang() === 'es' ? 'background: var(--primary);' : 'opacity: 0.6;' ?>">ES</a>
                    <a href="/lang/en" style="color: white; text-decoration: none; padding: 2px 6px; border-radius: 4px; <?= \KutSocial\I18n::getLang() === 'en' ? 'background: var(--primary);' : 'opacity: 0.6;' ?>">EN</a>
                </div>
            </div>
            <img src="/kutsocial_logo.svg" alt="Logo" style="width: 64px; height: 64px; margin: 0 auto 15px auto; display: block;">
            <div class="logo-text">KutSocial</div>
            <div class="logo-subtitle"><?= __('login') ?></div>
            
            <div id="login-error" style="color: var(--error); font-size: 14px; margin-bottom: 15px; display: none;"></div>

            <form id="login-form" method="POST" action="javascript:void(0);" onsubmit="return false;">
                <div class="form-group">
                    <label for="username"><?= __('username') ?></label>
                    <input type="text" id="username" required placeholder="ej. admin">
                </div>
                <div class="form-group">
                    <label for="password"><?= __('password') ?></label>
                    <input type="password" id="password" required placeholder="••••••••">
                </div>
                <button type="submit"><?= __('login') ?></button>
            </form>
        </div>
    </div>

    <!-- Layout Principal -->
    <div id="app-container" class="app-layout <?= empty($showRightSidebar) ? 'no-right-sidebar' : '' ?>">
        
        <!-- Sidebar Izquierdo -->
        <aside class="sidebar">
            <div>
                <?php if ($uiTheme === 'mastodon'): ?>
                <!-- Cabecera estilo Mastodon 5.0 -->
                <div class="m5-sidebar-header">
                    <a href="<?= $basePath ?>/home" class="m5-brand">
                        <img src="/kutsocial_logo.svg" alt="Logo" class="m5-brand-logo">
                        <div class="m5-brand-text">
                            <span class="m5-brand-title">KutSocial</span>
                            <span class="m5-brand-sub">powered by KutSocial</span>
                        </div>
                    </a>
                    <button type="button" id="m5-sidebar-toggle" class="m5-collapse-btn" onclick="toggleM5Sidebar()" title="Contraer / Expandir barra lateral">
                        <span class="material-icons-outlined m5-icon-close">close</span>
                        <span class="material-icons-outlined m5-icon-open">menu</span>
                    </button>
                </div>

                <!-- Barra de iconos superior (horizontal expandida, vertical al cerrar) -->
                <div class="m5-icon-bar">
                    <?php if (in_array('home', $enabledNavItems, true)): ?>
                    <a href="<?= $basePath ?>/home" class="m5-icon-btn auth-only <?= ($section === 'feed' && $currentTimeline === 'home') ? 'active' : '' ?>" title="<?= __('nav_home') ?>">
                        <span class="material-icons-outlined">home</span>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="m5-icon-btn" onclick="toggleM5SearchPopover()" title="<?= __('search_placeholder') ?>">
                        <span class="material-icons-outlined">search</span>
                    </button>
                    <?php if (in_array('direct', $enabledNavItems, true)): ?>
                    <a href="<?= $basePath ?>/direct" class="m5-icon-btn auth-only <?= ($section === 'feed' && $currentTimeline === 'direct') ? 'active' : '' ?>" title="<?= __('nav_direct') ?>">
                        <span class="material-icons-outlined">mail_outline</span>
                    </a>
                    <?php endif; ?>
                    <?php if (in_array('notifications', $enabledNavItems, true)): ?>
                    <a href="<?= $basePath ?>/notifications" class="m5-icon-btn auth-only <?= ($section === 'notifications') ? 'active' : '' ?>" title="<?= __('nav_notifications') ?>" style="position: relative;">
                        <span class="material-icons-outlined">notifications</span>
                        <span id="m5-notif-dot" class="m5-notif-dot" style="display: none;"></span>
                    </a>
                    <?php endif; ?>
                    <div class="m5-account-wrap auth-only" style="position: relative;">
                        <button type="button" class="m5-icon-btn m5-avatar-btn" onclick="toggleM5AccountMenu(event)" title="<?= __('nav_profile') ?>">
                            <img id="m5-my-avatar" src="<?= htmlspecialchars($localUser['avatar'] ?: '/assets/default-avatar.svg') ?>" alt="Avatar">
                        </button>
                        <div id="m5-account-menu" class="m5-popover-menu" style="display: none;">
                            <div class="m5-account-menu-header">
                                <img src="<?= htmlspecialchars($localUser['avatar'] ?: '/assets/default-avatar.svg') ?>" alt="Avatar">
                                <div style="min-width: 0;">
                                    <div class="m5-menu-name"><?= htmlspecialchars($localUser['display_name'] ?: ($localUser['username'] ?? '')) ?></div>
                                    <div class="m5-menu-handle">@<?= htmlspecialchars($localUser['username'] ?? '') ?></div>
                                </div>
                            </div>
                            <a href="<?= $basePath ?>/@<?= htmlspecialchars($localUser['username'] ?? '') ?>" class="m5-menu-item">
                                <span class="material-icons-outlined">person</span> <span><?= __('nav_profile') ?></span>
                            </a>
                            <a href="<?= $basePath ?>/profile" class="m5-menu-item">
                                <span class="material-icons-outlined">tune</span> <span><?= __('nav_settings') ?></span>
                            </a>
                            <?php if (in_array('collections', $enabledNavItems, true)): ?>
                            <a href="<?= $basePath ?>/collections" class="m5-menu-item">
                                <span class="material-icons-outlined">folder</span> <span><?= __('nav_collections') ?></span>
                            </a>
                            <?php endif; ?>
                            <a href="<?= $basePath ?>/favourites" class="m5-menu-item">
                                <span class="material-icons-outlined">favorite_border</span> <span><?= __('nav_liked_posts', 'Publicaciones que me gustan') ?></span>
                            </a>
                            <div class="m5-menu-divider"></div>
                            <div class="m5-menu-section-label"><?= __('nav_relationships', 'Relaciones') ?></div>
                            <a href="<?= $basePath ?>/followers" class="m5-menu-item">
                                <span class="material-icons-outlined">group</span> <span><?= __('followers', 'Seguidores') ?></span>
                            </a>
                            <a href="<?= $basePath ?>/following" class="m5-menu-item">
                                <span class="material-icons-outlined">person_add</span> <span><?= __('following', 'Siguiendo') ?></span>
                            </a>
                            <a href="<?= $basePath ?>/mutes" class="m5-menu-item">
                                <span class="material-icons-outlined">notifications_off</span> <span><?= __('nav_muted', 'Silenciados') ?></span>
                            </a>
                            <a href="<?= $basePath ?>/blocks" class="m5-menu-item">
                                <span class="material-icons-outlined">block</span> <span><?= __('nav_blocked', 'Bloqueados') ?></span>
                            </a>
                            <?php if (in_array('admin', $enabledNavItems, true)): ?>
                            <a href="/admin/update" class="m5-menu-item">
                                <span class="material-icons-outlined">settings</span> <span><?= __('nav_admin') ?></span>
                            </a>
                            <?php endif; ?>
                            <div class="m5-menu-divider"></div>
                            <div class="m5-menu-lang">
                                <span style="font-size: 12px; color: var(--text-muted);"><?= __('language') ?></span>
                                <div style="display: flex; gap: 4px;">
                                    <a href="/lang/es" class="sidebar-lang-btn <?= \KutSocial\I18n::getLang() === 'es' ? 'active' : '' ?>">ES</a>
                                    <a href="/lang/en" class="sidebar-lang-btn <?= \KutSocial\I18n::getLang() === 'en' ? 'active' : '' ?>">EN</a>
                                </div>
                            </div>
                            <button type="button" class="m5-menu-item m5-menu-danger" onclick="logout()">
                                <span class="material-icons-outlined">logout</span> <span><?= __('logout', 'Cerrar sesión') ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Botón principal New Post -->
                <button type="button" class="m5-new-post-btn guest-only" onclick="showLoginModal()" title="<?= __('sign_in', 'Iniciar sesión') ?>">
                    <span class="material-icons-outlined">login</span>
                    <span class="m5-new-post-label"><?= __('sign_in', 'Iniciar sesión') ?></span>
                </button>
                <button type="button" class="m5-new-post-btn auth-only" onclick="openM5NewPost()" title="<?= __('publish', 'Publicar') ?>">
                    <span class="material-icons-outlined">edit</span>
                    <span class="m5-new-post-label"><?= __('publish', 'Publicar') ?></span>
                </button>
                <?php else: ?>
                <div class="nav-logo" style="display: flex; align-items: center; gap: 10px;">
                    <img src="/kutsocial_logo.svg" alt="Logo" style="width: 28px; height: 28px;">
                    <span>KutSocial</span>
                </div>
                <?php endif; ?>

                <ul class="nav-menu">
                    <?php if (in_array('home', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/home" id="nav-home" class="nav-link <?= ($section === 'feed' && $currentTimeline === 'home') ? 'active' : '' ?>" title="<?= __('nav_home') ?>">
                            <span class="material-icons-outlined">home</span> <span class="nav-label"><?= __('nav_home') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/explore" id="nav-explore" class="nav-link <?= ($section === 'explore') ? 'active' : '' ?>" title="<?= __('nav_explore', 'Explorar') ?>">
                            <span class="material-icons-outlined">explore</span> <span class="nav-label"><?= __('nav_explore', 'Explorar') ?></span>
                        </a>
                    </li>
                    <?php if (in_array('catchup', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/catchup" id="nav-catchup" class="nav-link <?= ($section === 'catchup') ? 'active' : '' ?>" title="<?= __('nav_catchup') ?>">
                            <span class="material-icons-outlined">bolt</span> <span class="nav-label"><?= __('nav_catchup') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('local', $enabledNavItems, true)): ?>
                    <li class="nav-item">
                        <a href="<?= $basePath ?>/local" id="nav-local" class="nav-link <?= ($section === 'feed' && $currentTimeline === 'local') ? 'active' : '' ?>" title="<?= __('nav_local') ?>">
                            <span class="material-icons-outlined">groups</span> <span class="nav-label"><?= __('nav_local') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('public', $enabledNavItems, true)): ?>
                    <li class="nav-item">
                        <a href="<?= $basePath ?>/public" id="nav-public" class="nav-link <?= ($section === 'feed' && $currentTimeline === 'public') ? 'active' : '' ?>" title="<?= __('nav_public') ?>">
                            <span class="material-icons-outlined">public</span> <span class="nav-label"><?= __('nav_public') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('notifications', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/notifications" id="nav-notifications" class="nav-link <?= ($section === 'notifications') ? 'active' : '' ?>" title="<?= __('nav_notifications') ?>">
                            <span class="material-icons-outlined">notifications</span> <span class="nav-label"><?= __('nav_notifications') ?></span>
                            <span id="nav-notifications-count" class="notification-badge" style="display: none; background: var(--error, #ef4444); color: white; border-radius: 10px; padding: 2px 6px; font-size: 11px; font-weight: bold; margin-left: auto;">0</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('bookmarks', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/bookmarks" id="nav-bookmarks" class="nav-link <?= ($section === 'feed' && $currentTimeline === 'bookmarks') ? 'active' : '' ?>" title="<?= __('nav_bookmarks') ?>">
                            <span class="material-icons-outlined">bookmark_border</span> <span class="nav-label"><?= __('nav_bookmarks') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('direct', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/direct" id="nav-direct" class="nav-link <?= ($section === 'feed' && $currentTimeline === 'direct') ? 'active' : '' ?>" title="<?= __('nav_direct') ?>">
                            <span class="material-icons-outlined">mail_outline</span> <span class="nav-label"><?= __('nav_direct') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('lists', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/lists" id="nav-lists" class="nav-link <?= ($section === 'lists') ? 'active' : '' ?>" title="<?= __('nav_lists') ?>">
                            <span class="material-icons-outlined">list</span> <span class="nav-label"><?= __('nav_lists') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('collections', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/collections" id="nav-collections" class="nav-link <?= ($section === 'collections') ? 'active' : '' ?>" title="<?= __('nav_collections') ?>">
                            <span class="material-icons-outlined">folder</span> <span class="nav-label"><?= __('nav_collections') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('hashtags', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/followed-hashtags" id="nav-hashtags" class="nav-link <?= ($section === 'followed-hashtags') ? 'active' : '' ?>" title="<?= __('nav_hashtags') ?>">
                            <span class="material-icons-outlined">tag</span> <span class="nav-label"><?= __('nav_hashtags') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('profile', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/@<?= htmlspecialchars($localUser['username'] ?? '') ?>" id="nav-profile" class="nav-link <?= ($section === 'profile-view' && $activeProfileViewId === ($localUser['id'] ?? null)) ? 'active' : '' ?>" title="<?= __('nav_profile') ?>">
                            <span class="material-icons-outlined">person</span> <span class="nav-label"><?= __('nav_profile') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('settings', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only">
                        <a href="<?= $basePath ?>/profile" id="nav-settings" class="nav-link <?= ($section === 'profile') ? 'active' : '' ?>" title="<?= __('nav_settings') ?>">
                            <span class="material-icons-outlined">tune</span> <span class="nav-label"><?= __('nav_settings') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array('admin', $enabledNavItems, true)): ?>
                    <li class="nav-item auth-only" id="nav-admin-settings">
                        <a href="/admin/update" class="nav-link" title="<?= __('nav_admin') ?>">
                            <span class="material-icons-outlined">settings</span> <span class="nav-label"><?= __('nav_admin') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item" id="nav-item-owner" style="display: none;">
                        <a href="<?= $basePath ?>/@<?= htmlspecialchars($localUser['username'] ?? '') ?>" id="nav-owner-profile" class="nav-link <?= ($section === 'profile-view' && $activeProfileViewId === ($localUser['id'] ?? null)) ? 'active' : '' ?>" title="<?= __('nav_owner') ?>">
                            <span class="material-icons-outlined">verified_user</span> <span class="nav-label"><?= __('nav_owner') ?></span>
                        </a>
                    </li>
                </ul>

                <!-- Selector de Idioma en Sidebar -->
                <div class="sidebar-lang-selector">
                    <span class="sidebar-lang-label">
                        <span class="material-icons-outlined">language</span> <?= __('language') ?>
                    </span>
                    <div class="sidebar-lang-options">
                        <a href="/lang/es" class="sidebar-lang-btn <?= \KutSocial\I18n::getLang() === 'es' ? 'active' : '' ?>">ES</a>
                        <a href="/lang/en" class="sidebar-lang-btn <?= \KutSocial\I18n::getLang() === 'en' ? 'active' : '' ?>">EN</a>
                    </div>
                </div>
            </div>

            <!-- Perfil del usuario actual -->
            <?php if ($uiTheme !== 'mastodon'): ?>
            <div class="user-profile-summary-guest guest-only" style="padding: 12px 0;">
                <button type="button" onclick="showLoginModal()" style="margin: 0; width: 100%; padding: 12px; font-weight: 600; background: var(--primary); border: none; border-radius: 10px; color: white; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px;"><span class="material-icons-outlined">login</span> <?= __('sign_in', 'Iniciar sesión') ?></button>
            </div>
            <?php endif; ?>
            <div class="user-profile-summary auth-only" style="cursor: pointer;" onclick="const u = (typeof currentProfileData !== 'undefined' && currentProfileData?.username) ? currentProfileData.username : '<?= htmlspecialchars($localUser['username'] ?? '') ?>'; if(u) window.location.href='<?= $basePath ?>/@' + u;">
                <img id="my-avatar" class="user-avatar" src="<?= htmlspecialchars($localUser['avatar'] ?: '/assets/default-avatar.svg') ?>" alt="Mi Avatar">
                <div class="user-info">
                    <div id="my-display-name" class="user-name"><?= htmlspecialchars($localUser['display_name'] ?: ($localUser['username'] ?? 'Cargando...')) ?></div>
                    <div id="my-username" class="user-handle">@<?= htmlspecialchars($localUser['username'] ?? 'cargando') ?></div>
                </div>
                <button class="btn-logout" title="Cerrar Sesión" onclick="event.stopPropagation(); logout()">✕</button>
            </div>
            <?php if ($uiTheme === 'mastodon'): ?>
            <!-- Botón Ir Arriba / Nuevos Toots en Sidebar (Tema Mastodon 5.0) -->
            <div id="sidebar-scroll-top-container" class="sidebar-scroll-top-container m5-scroll-top" style="display: none;">
                <button id="btn-sidebar-scroll-top" class="sidebar-scroll-top-btn" onclick="scrollToTopTimeline()" type="button" title="<?= __('timeline_scroll_to_top', 'Volver al principio') ?>">
                    <span class="material-icons-outlined" style="font-size: 20px;">arrow_upward</span>
                    <span id="sidebar-scroll-top-text" class="m5-scroll-top-text"><?= __('timeline_scroll_to_top', 'Volver al principio') ?></span>
                </button>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Columna Central: ÚNICA vista cargada en esta página -->
        <main class="main-content">
            <?php 
            if (!empty($contentView) && file_exists(__DIR__ . '/' . $contentView)) {
                include __DIR__ . '/' . $contentView;
            } else {
                echo '<div style="padding: 40px; text-align: center; color: var(--text-muted);">Página no encontrada.</div>';
            }
            if (($section === 'profile-view' || $section === 'users-list') && $contentView !== 'users-list.php' && file_exists(__DIR__ . '/users-list.php')) {
                include __DIR__ . '/users-list.php';
            }
            ?>
        </main>

        <!-- Columna Derecha (Instancia) -->
        <aside class="right-sidebar" <?= empty($showRightSidebar) ? 'style="display: none;"' : '' ?>>
            <!-- Buscador de Usuarios / Contenido -->
            <div class="sidebar-search right-panel-search">
                <div class="search-input-wrapper">
                    <span class="material-icons-outlined search-icon">search</span>
                    <input type="text" class="sidebar-search-input" placeholder="<?= __('search_placeholder') ?>" 
                           onkeydown="if(event.key === 'Enter') performSearch(this.value)">
                </div>
            </div>

            <!-- Actualizaciones del Servidor -->
            <div class="widget-card">
                <div class="update-indicator">
                    <span class="update-dot"></span>
                    <span id="stat-version">v0.0.0</span>
                </div>
                <div style="font-size: 15px; font-weight: 700; margin-bottom: 5px;"><?= __('server_active') ?></div>
                <div style="font-size: 12.5px; color: var(--text-muted); line-height: 1.4;"><?= __('server_desc') ?></div>
            </div>

            <!-- Estadísticas Locales -->
            <div class="widget-card">
                <h3 class="widget-title"><?= __('stats') ?></h3>
                <div class="stat-item">
                    <span class="stat-label"><?= __('stats_users') ?></span>
                    <span class="stat-value" id="stat-users">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?= __('stats_posts') ?></span>
                    <span class="stat-value" id="stat-toots">-</span>
                </div>
            </div>

            <?php if ($uiTheme !== 'mastodon'): ?>
            <!-- Botón Ir Arriba / Nuevos Toots en Sidebar -->
            <div id="sidebar-scroll-top-container" class="sidebar-scroll-top-container" style="display: none;">
                <button id="btn-sidebar-scroll-top" class="sidebar-scroll-top-btn" onclick="scrollToTopTimeline()" type="button">
                    <span class="material-icons-outlined" style="font-size: 20px;">arrow_upward</span>
                    <span id="sidebar-scroll-top-text"><?= __('timeline_scroll_to_top', 'Volver al principio') ?></span>
                </button>
            </div>
            <?php endif; ?>
        </aside>

    </div>

    <!-- Modal: Editar Perfil -->
    <div id="modal-edit-profile" class="modal-overlay" style="display: none; z-index: 2500;" onclick="if(event.target === this) closeEditProfileModal()">
        <div class="modal-card" style="max-width: 680px; width: 100%; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 14px; margin-bottom: 22px; gap: 15px; flex-wrap: wrap;">
                <h3 class="modal-title" style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <span class="material-icons-outlined" style="color: var(--primary);">tune</span> <?= __('profile_edit') ?>
                </h3>
                <div class="pill-toggle-group" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px; border-radius: 10px; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); flex-shrink: 0;">
                    <button type="button" id="btn-modal-subtab-profile-edit" onclick="switchModalProfileSettingsTab('edit')" class="profile-subtab-btn active">
                        <span>👤</span> <span><?= __('profile_tab_profile') ?></span>
                    </button>
                    <button type="button" id="btn-modal-subtab-profile-privacy" onclick="switchModalProfileSettingsTab('privacy')" class="profile-subtab-btn">
                        <span>🔒</span> <span><?= __('profile_tab_privacy') ?></span>
                    </button>
                    <button type="button" id="btn-modal-subtab-profile-import" onclick="switchModalProfileSettingsTab('import')" class="profile-subtab-btn">
                        <span>📥</span> <span><?= __('profile_import_export') ?></span>
                    </button>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeEditProfileModal()" aria-label="<?= __('close') ?>">&times;</button>
            </div>
            
            <form id="modal-profile-form" enctype="multipart/form-data">
                <!-- Subtab 1: Perfil -->
                <div id="modal-subtab-content-profile-edit">
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label for="modal-profile-header-input" style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_header') ?></label>
                        <input type="file" id="modal-profile-header-input" name="header" accept="image/*" class="modal-input" style="padding: 10px 14px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label for="modal-profile-avatar-input" style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_avatar') ?></label>
                        <input type="file" id="modal-profile-avatar-input" name="avatar" accept="image/*" class="modal-input" style="padding: 10px 14px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label for="modal-profile-display-name" style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_display_name') ?></label>
                        <input type="text" id="modal-profile-display-name" name="display_name" class="modal-input" style="padding: 11px 14px;" placeholder="<?= __('profile_display_name_placeholder') ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label for="modal-profile-note" style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_note') ?></label>
                        <textarea id="modal-profile-note" name="note" class="modal-input" style="height: 100px; padding: 12px 14px; resize: vertical;" placeholder="<?= __('profile_note_placeholder') ?>"></textarea>
                    </div>

                    <div class="form-group" style="margin-bottom: 22px;">
                        <label for="modal-profile-also-known-as" style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_also_known_as') ?></label>
                        <input type="text" id="modal-profile-also-known-as" name="also_known_as" class="modal-input" style="padding: 11px 14px;" placeholder="https://mastodon.social/users/tu_antiguo_usuario">
                        <small style="display: block; color: var(--text-muted); font-size: 12px; margin-top: 8px; line-height: 1.5;">
                            <?= __('profile_also_known_as_desc') ?>
                        </small>
                    </div>
                    
                    <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_fields') ?></h3>
                    <div id="modal-metadata-fields-container">
                        <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
                            <input type="text" id="modal-field-name-0" name="fields_attributes[0][name]" placeholder="<?= __('profile_field_label') ?>" class="modal-input" style="flex: 1; padding: 11px 14px;">
                            <input type="text" id="modal-field-value-0" name="fields_attributes[0][value]" placeholder="<?= __('profile_field_value') ?>" class="modal-input" style="flex: 2; padding: 11px 14px;">
                            <span id="modal-field-verified-0" style="font-size: 11.5px; font-weight: 600; width: 95px; text-align: right; color: var(--success, #10b981);"></span>
                        </div>
                        <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
                            <input type="text" id="modal-field-name-1" name="fields_attributes[1][name]" placeholder="<?= __('profile_field_label') ?>" class="modal-input" style="flex: 1; padding: 11px 14px;">
                            <input type="text" id="modal-field-value-1" name="fields_attributes[1][value]" placeholder="<?= __('profile_field_value') ?>" class="modal-input" style="flex: 2; padding: 11px 14px;">
                            <span id="modal-field-verified-1" style="font-size: 11.5px; font-weight: 600; width: 95px; text-align: right; color: var(--success, #10b981);"></span>
                        </div>
                        <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
                            <input type="text" id="modal-field-name-2" name="fields_attributes[2][name]" placeholder="<?= __('profile_field_label') ?>" class="modal-input" style="flex: 1; padding: 11px 14px;">
                            <input type="text" id="modal-field-value-2" name="fields_attributes[2][value]" placeholder="<?= __('profile_field_value') ?>" class="modal-input" style="flex: 2; padding: 11px 14px;">
                            <span id="modal-field-verified-2" style="font-size: 11.5px; font-weight: 600; width: 95px; text-align: right; color: var(--success, #10b981);"></span>
                        </div>
                        <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
                            <input type="text" id="modal-field-name-3" name="fields_attributes[3][name]" placeholder="<?= __('profile_field_label') ?>" class="modal-input" style="flex: 1; padding: 11px 14px;">
                            <input type="text" id="modal-field-value-3" name="fields_attributes[3][value]" placeholder="<?= __('profile_field_value') ?>" class="modal-input" style="flex: 2; padding: 11px 14px;">
                            <span id="modal-field-verified-3" style="font-size: 11.5px; font-weight: 600; width: 95px; text-align: right; color: var(--success, #10b981);"></span>
                        </div>
                    </div>

                    <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 20px; margin-bottom: 24px; line-height: 1.5; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 14px 16px; border-radius: 8px;">
                        <?= str_replace(':url', '<code id="modal-profile-verification-url-preview"></code>', __('profile_verification_tip')) ?>
                    </p>

                    <div class="modal-actions" style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="button" class="modal-btn-cancel" onclick="closeEditProfileModal()"><?= __('cancel') ?></button>
                        <button type="submit" class="modal-btn-confirm" id="modal-profile-submit-btn"><?= __('profile_save_changes') ?></button>
                    </div>
                    <div id="modal-profile-save-status" style="margin-top: 14px; font-size: 13.5px; text-align: center; font-weight: 600;"></div>
                </div>

                <!-- Subtab 2: Privacidad -->
                <div id="modal-subtab-content-profile-privacy" style="display: none;">
                    <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 5px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_privacy_settings') ?></h3>
                    <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-profile-discoverable" name="discoverable" style="width: auto;">
                            <span><?= __('profile_privacy_discoverable') ?></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-profile-auto-accept" name="auto_accept" style="width: auto;">
                            <span><?= __('profile_privacy_auto_accept') ?></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-profile-searchable" name="searchable" style="width: auto;">
                            <span><?= __('profile_privacy_searchable') ?></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-profile-indexable" name="indexable" style="width: auto;">
                            <span><?= __('profile_privacy_indexable') ?></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-profile-show-source" name="show_source" style="width: auto;">
                            <span><?= __('profile_privacy_show_source') ?></span>
                        </label>
                    </div>

                    <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_publication_preferences') ?></h3>
                    <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-pref-warn-missing-alt" style="width: auto;">
                            <span><?= __('pref_warn_missing_alt_desc') ?></span>
                        </label>
                    </div>

                    <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_timeline_preferences') ?></h3>
                    <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                            <input type="checkbox" id="modal-pref-auto-scroll-new" style="width: auto;">
                            <span><?= __('pref_auto_scroll_new_desc') ?></span>
                        </label>
                    </div>

                    <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_interface_language') ?></h3>
                    <div style="margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                        <label for="modal-select-profile-language" style="display: block; margin-bottom: 10px; font-size: 14px; color: var(--text-color); text-transform: none;"><?= __('profile_interface_language_desc') ?></label>
                        <select id="modal-select-profile-language" onchange="window.location.href='/lang/' + this.value" style="max-width: 280px; width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: white;">
                            <option value="es" <?= \KutSocial\I18n::getLang() === 'es' ? 'selected' : '' ?>><?= __('spanish') ?> (Spanish)</option>
                            <option value="en" <?= \KutSocial\I18n::getLang() === 'en' ? 'selected' : '' ?>><?= __('english') ?> (English)</option>
                        </select>
                    </div>

                    <div class="modal-actions" style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="button" class="modal-btn-cancel" onclick="closeEditProfileModal()"><?= __('cancel') ?></button>
                        <button type="submit" class="modal-btn-confirm" id="modal-profile-privacy-submit-btn"><?= __('profile_save_privacy') ?></button>
                    </div>
                    <div id="modal-profile-privacy-save-status" style="margin-top: 14px; font-size: 13.5px; text-align: center; font-weight: 600;"></div>
                </div>
            </form>

            <!-- Subtab 3: Importar & Exportar -->
            <div id="modal-subtab-content-profile-import" style="display: none;">
                <!-- Exportar -->
                <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
                    <h4 style="font-size: 14px; margin-bottom: 6px; color: var(--text-color); font-weight: 700;">📤 <?= __('profile_export_title') ?></h4>
                    <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; line-height: 1.5;">
                        <?= __('profile_export_desc') ?>
                    </p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
                        <button onclick="downloadExport('/api/v1/export/posts', 'posts.json')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_posts') ?></button>
                        <button onclick="downloadExport('/api/v1/export/follows', 'following.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_follows') ?></button>
                        <button onclick="downloadExport('/api/v1/export/followers', 'followers.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_followers') ?></button>
                        <button onclick="downloadExport('/api/v1/export/lists', 'lists.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_lists') ?></button>
                        <button onclick="downloadExport('/api/v1/export/mutes', 'mutes.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_mutes') ?></button>
                        <button onclick="downloadExport('/api/v1/export/blocks', 'blocks.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_blocks') ?></button>
                        <button onclick="downloadExport('/api/v1/export/domain_blocks', 'domain_blocks.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_domain_blocks') ?></button>
                        <button onclick="downloadExport('/api/v1/export/bookmarks', 'bookmarks.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_bookmarks') ?></button>
                        <button onclick="downloadExport('/api/v1/export/filters', 'filters.json')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_filters') ?></button>
                    </div>
                </div>

                <!-- Importar -->
                <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
                    <h4 style="font-size: 14px; margin-bottom: 6px; color: var(--text-color); font-weight: 700;">📥 <?= __('profile_import_title') ?></h4>
                    <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; line-height: 1.5;">
                        <?= __('profile_import_desc') ?>
                    </p>
                    <form id="modal-import-form" style="display: flex; flex-direction: column; gap: 15px;">
                        <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label for="modal-import-type" style="margin-bottom: 8px;"><?= __('profile_import_data_type') ?></label>
                                <select id="modal-import-type" name="type" style="width:100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; color: white; font-family: inherit;">
                                    <option value="follows" style="background:#161c26;"><?= __('profile_import_opt_follows') ?></option>
                                    <option value="followers" style="background:#161c26;"><?= __('profile_import_opt_followers') ?></option>
                                    <option value="lists" style="background:#161c26;"><?= __('profile_import_opt_lists') ?></option>
                                    <option value="bookmarks" style="background:#161c26;"><?= __('profile_import_opt_bookmarks') ?></option>
                                    <option value="blocks" style="background:#161c26;"><?= __('profile_import_opt_blocks') ?></option>
                                    <option value="mutes" style="background:#161c26;"><?= __('profile_import_opt_mutes') ?></option>
                                    <option value="domain_blocks" style="background:#161c26;"><?= __('profile_import_opt_domain_blocks') ?></option>
                                </select>
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label for="modal-import-file" style="margin-bottom: 8px;"><?= __('profile_import_file_label') ?></label>
                                <input type="file" id="modal-import-file" name="file" accept=".csv,text/csv" required class="modal-input" style="padding: 9px 12px;">
                            </div>
                        </div>
                        <button type="submit" style="width: auto; align-self: flex-start; padding: 10px 20px; font-size: 13.5px; margin-top: 5px;"><?= __('profile_import_start_btn') ?></button>
                    </form>
                    <div id="modal-import-status-msg" style="margin-top: 14px; font-size: 13px; font-weight: 600;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Información Técnica de Perfil -->
    <div id="modal-profile-tech-info" class="modal-overlay" style="display: none;">
        <div class="modal-card">
            <h3 class="modal-title"><?= __('profile_tech_info_title', '🛠️ Información Técnica') ?></h3>
            <div id="profile-tech-info-content" style="display: flex; flex-direction: column; gap: 10px; font-size: 13.5px; color: var(--text-color);"></div>
            <div class="modal-actions">
                <button class="modal-btn-confirm" onclick="closeProfileTechInfoModal()">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- Modal: Organizar Cuenta (Añadir a listas / colecciones) -->
    <div id="modal-organize-account" class="modal-overlay" style="display: none;">
        <div class="modal-card" style="max-width: 460px;">
            <h3 class="modal-title">📂 <?= __('organize_account_title') ?></h3>
            <p style="font-size: 13px; color: var(--text-muted); margin: 0;"><?= str_replace(':handle', '<strong id="organize-account-handle" style="color:var(--text-color);">@...</strong>', __('organize_account_desc')) ?></p>
            
            <div style="margin-top: 5px;">
                <h4 style="font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; font-weight: bold; letter-spacing: 0.5px;"><?= __('organize_my_lists') ?></h4>
                <div id="organize-lists-checkboxes" style="max-height: 120px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding: 2px;"></div>
            </div>

            <div style="margin-top: 10px;">
                <h4 style="font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; font-weight: bold; letter-spacing: 0.5px;"><?= __('organize_my_collections') ?></h4>
                <div id="organize-collections-checkboxes" style="max-height: 120px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding: 2px;"></div>
            </div>

            <div class="modal-actions" style="margin-top: 10px;">
                <button class="modal-btn-confirm" onclick="closeOrganizeModal()"><?= __('done') ?></button>
            </div>
        </div>
    </div>

    <!-- Modal: Crear/Editar Lista -->
    <div id="modal-manage-list" class="modal-overlay" style="display: none;">
        <div class="modal-card">
            <h3 class="modal-title" id="list-modal-title"><?= __('lists_create') ?></h3>
            <input type="text" id="list-modal-input-title" class="modal-input" placeholder="<?= __('lists_name_placeholder') ?>">
            <div class="modal-actions">
                <button class="modal-btn-cancel" onclick="closeListModal()"><?= __('cancel') ?></button>
                <button class="modal-btn-confirm" onclick="saveListModal()"><?= __('save') ?></button>
            </div>
        </div>
    </div>

    <!-- Modal: Importar Listas -->
    <div id="modal-import-lists" class="modal-overlay" style="display: none; z-index: 2500;" onclick="if(event.target === this) closeImportListsModal()">
        <div class="modal-card" style="max-width: 440px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                <h3 class="modal-title" style="margin: 0; font-size: 16px;">📥 <?= __('lists_import_title') ?></h3>
                <button type="button" class="modal-close-btn" onclick="closeImportListsModal()" aria-label="<?= __('close') ?>">&times;</button>
            </div>
            <form id="lists-import-form" onsubmit="handleListsImport(event)">
                <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 10px 0;">
                    <?= __('lists_import_desc') ?>
                </p>
                <div class="form-group" style="margin-bottom: 15px;">
                    <input type="file" id="lists-import-file" name="file" accept=".csv,text/csv" required class="modal-input" style="padding: 8px;">
                </div>
                <div class="modal-actions" style="margin: 0;">
                    <button type="button" class="modal-btn-cancel" onclick="closeImportListsModal()"><?= __('cancel') ?></button>
                    <button type="submit" class="modal-btn-confirm" id="lists-import-submit-btn"><?= __('profile_import_submit_btn') ?></button>
                </div>
                <div id="lists-import-status" style="margin-top: 10px; font-size: 13px; text-align: center; font-weight: 600;"></div>
            </form>
        </div>
    </div>

    <!-- Modal: Crear/Editar Colección -->
    <div id="modal-manage-collection" class="modal-overlay" style="display: none;">
        <div class="modal-card">
            <h3 class="modal-title" id="collection-modal-title"><?= __('collections_create') ?></h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <input type="text" id="collection-modal-input-title" class="modal-input" placeholder="<?= __('collections_name_placeholder') ?>">
                <textarea id="collection-modal-input-desc" class="modal-input" style="height: 80px; resize: none;" placeholder="<?= __('collections_desc_placeholder') ?>"></textarea>
            </div>
            <div class="modal-actions" style="margin-top: 5px;">
                <button class="modal-btn-cancel" onclick="closeCollectionModal()"><?= __('cancel') ?></button>
                <button class="modal-btn-confirm" onclick="saveCollectionModal()"><?= __('save') ?></button>
            </div>
        </div>
    </div>

    <!-- Modal: Buscador de GIFs (GIPHY) -->
    <div id="modal-gif-picker" class="modal-overlay" style="display: none; z-index: 3100;" onclick="if(event.target === this) closeGifModal()">
        <div class="modal-card" style="max-width: 500px; width: 90%; max-height: 85vh; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 class="modal-title" style="margin: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="material-icons-outlined" style="color: var(--primary); font-size: 28px;">gif_box</span> <?= __('gif_search_title') ?>
                </h3>
                <span onclick="closeGifModal()" style="color:var(--text-muted); cursor:pointer; font-size:24px; user-select:none; padding: 2px 6px;">✕</span>
            </div>
            
            <div style="display: flex; gap: 8px; margin-bottom: 15px;">
                <input type="text" id="gif-search-input" class="modal-input" placeholder="<?= __('gif_search_placeholder') ?>" style="margin: 0; flex-grow: 1;" onkeydown="if(event.key === 'Enter') searchGifs()">
                <button class="modal-btn-confirm" onclick="searchGifs()" style="padding: 0 20px; margin: 0; height: 42px; border-radius: 6px;"><?= __('search') ?></button>
            </div>
            
            <div id="gif-results-container" style="flex-grow: 1; overflow-y: auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; min-height: 250px; max-height: 450px; padding: 2px;"></div>
            
            <div style="display: flex; justify-content: flex-end; align-items: center; margin-top: 12px; font-size: 11px; color: var(--text-muted);">
                Powered by GIPHY
            </div>
        </div>
    </div>

    <!-- Modal: Visor de Imágenes -->
    <div id="modal-image-viewer" class="modal-overlay" style="display: none; background: rgba(0, 0, 0, 0.88); z-index: 3000; padding: 20px;" onclick="closeImageViewerModal(event)">
        <!-- Botón anterior -->
        <button id="image-viewer-prev" type="button" onclick="prevImageViewerModal(event)" style="display: none; position: fixed; left: 24px; top: 50%; transform: translateY(-50%); background: rgba(20, 22, 28, 0.75); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 50%; width: 50px; height: 50px; cursor: pointer; align-items: center; justify-content: center; z-index: 3010; backdrop-filter: blur(8px); transition: all 0.2s;" onmouseover="this.style.background='var(--primary)'; this.style.transform='translateY(-50%) scale(1.1)';" onmouseout="this.style.background='rgba(20, 22, 28, 0.75)'; this.style.transform='translateY(-50%) scale(1)';">
            <span class="material-icons-outlined" style="font-size: 32px; pointer-events: none;">chevron_left</span>
        </button>

        <div style="position: relative; max-width: 90vw; max-height: 90vh; display: flex; justify-content: center; align-items: center;">
            <div id="image-viewer-counter" style="display: none; position: absolute; top: -42px; left: 0; color: #fff; font-size: 13px; font-weight: 600; background: rgba(20, 22, 28, 0.8); padding: 4px 12px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.15); user-select: none;"></div>
            <span id="image-viewer-close" style="position: absolute; top: -45px; right: 0; font-size: 35px; color: #fff; cursor: pointer; user-select: none; transition: color 0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='#fff'">&times;</span>
            <img id="image-viewer-img" src="" style="max-width: 90vw; max-height: 90vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);" onclick="event.stopPropagation()">
        </div>

        <!-- Botón siguiente -->
        <button id="image-viewer-next" type="button" onclick="nextImageViewerModal(event)" style="display: none; position: fixed; right: 24px; top: 50%; transform: translateY(-50%); background: rgba(20, 22, 28, 0.75); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 50%; width: 50px; height: 50px; cursor: pointer; align-items: center; justify-content: center; z-index: 3010; backdrop-filter: blur(8px); transition: all 0.2s;" onmouseover="this.style.background='var(--primary)'; this.style.transform='translateY(-50%) scale(1.1)';" onmouseout="this.style.background='rgba(20, 22, 28, 0.75)'; this.style.transform='translateY(-50%) scale(1)';">
            <span class="material-icons-outlined" style="font-size: 32px; pointer-events: none;">chevron_right</span>
        </button>
    </div>

    <!-- Modal: Reportar Usuario o Publicación -->
    <div id="modal-report-toot" class="modal-overlay" style="display: none; z-index: 3200;" onclick="if(event.target === this) closeReportModal()">
        <div class="modal-card" style="max-width: 480px; width: 95%;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                <h3 class="modal-title" style="margin: 0; font-size: 16px; color: var(--error, #ef4444); display: flex; align-items: center; gap: 8px;">
                    <span class="material-icons-outlined" style="font-size: 20px;">flag</span>
                    <span id="report-modal-title"><?= __('report_account_title') ?></span>
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeReportModal()" aria-label="<?= __('close') ?>">&times;</button>
            </div>
            <form id="report-modal-form" onsubmit="handleReportSubmit(event)">
                <input type="hidden" id="report-account-id" name="account_id">
                <input type="hidden" id="report-status-id" name="status_id">
                
                <p id="report-modal-target-desc" style="font-size: 13.5px; color: var(--text-color); margin: 8px 0 12px 0;">
                    <?= str_replace(':handle', '<strong id="report-target-handle">@...</strong>', __('report_target_desc')) ?>
                </p>

                <div id="report-status-preview" style="display: none; background: rgba(0,0,0,0.25); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; font-size: 12.5px; color: var(--text-muted); max-height: 90px; overflow-y: auto;">
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="report-category" style="display: block; font-size: 12.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;"><?= __('report_main_reason') ?></label>
                    <select id="report-category" name="category" class="modal-input" style="cursor: pointer;">
                        <option value="spam"><?= __('report_opt_spam') ?></option>
                        <option value="violation"><?= __('report_opt_violation') ?></option>
                        <option value="legal"><?= __('report_opt_legal') ?></option>
                        <option value="other"><?= __('report_opt_other') ?></option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="report-comment" style="display: block; font-size: 12.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;"><?= __('report_comments') ?></label>
                    <textarea id="report-comment" name="comment" class="modal-input" style="height: 85px; resize: vertical;" placeholder="<?= __('report_comments_placeholder') ?>" required></textarea>
                </div>

                <div id="report-forward-container" style="display: none; margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-color); cursor: pointer;">
                        <input type="checkbox" id="report-forward" name="forward" value="1" checked style="cursor: pointer;">
                        <span><?= str_replace(':domain', '<strong id="report-remote-domain">...</strong>', __('report_forward_desc')) ?></span>
                    </label>
                </div>

                <div class="modal-actions" style="margin-top: 10px;">
                    <button type="button" class="modal-btn-cancel" onclick="closeReportModal()"><?= __('cancel') ?></button>
                    <button type="submit" class="modal-btn-confirm" id="report-submit-btn" style="background: var(--error, #ef4444);"><?= __('report_submit_btn') ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($contentView) && $contentView !== 'feed.php'): ?>
    <!-- Modal Composer para responder o publicar en páginas independientes (Notificaciones, Hilos, etc.) -->
    <div id="modal-composer" class="modal-overlay" style="display: none; z-index: 2500;" onclick="if(event.target === this) closeComposerModal()">
        <div class="modal-card" style="max-width: 580px; width: 95%; padding: 0; background: var(--bg-color, #1a1e28); overflow: visible; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.6);">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; border-bottom: 1px solid var(--border-color);">
                <h3 class="modal-title" style="margin: 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <span class="material-icons-outlined" style="font-size: 18px; color: var(--primary);">reply</span>
                    <span id="modal-composer-title">Responder</span>
                </h3>
                <span onclick="closeComposerModal()" style="cursor: pointer; color: var(--text-muted); font-size: 20px; line-height: 1; padding: 2px 6px;">✕</span>
            </div>
            <div style="padding: 15px;">
                <?php include __DIR__ . '/composer.php'; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="/assets/pages.js?v=<?= htmlspecialchars(defined('KUTSOCIAL_VERSION') ? KUTSOCIAL_VERSION : '1.0.0') ?>"></script>
    <?php if ($uiTheme === 'mastodon'): ?>
    <!-- Modal Buscador Mastodon 5.0 -->
    <div id="m5-search-modal" class="modal-overlay" style="display: none; z-index: 2600;" onclick="if(event.target === this) toggleM5SearchPopover(false)">
        <div class="modal-card" style="max-width: 520px; width: 92%; padding: 18px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="material-icons-outlined" style="color: var(--text-muted);">search</span>
                <input type="text" id="m5-search-input" class="modal-input" placeholder="<?= __('search_placeholder') ?>" style="flex: 1; margin: 0; border-radius: 9999px; padding: 10px 16px;" onkeydown="if(event.key==='Enter'){ toggleM5SearchPopover(false); performSearch(this.value); } else if(event.key==='Escape'){ toggleM5SearchPopover(false); }">
                <button type="button" class="modal-close-btn" onclick="toggleM5SearchPopover(false)" aria-label="<?= __('close') ?>">&times;</button>
            </div>
        </div>
    </div>
    <script>
    function toggleM5Sidebar() {
        var collapsed = document.documentElement.classList.toggle('m5-sidebar-collapsed');
        try { localStorage.setItem('kutsocial_m5_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    }
    function toggleM5AccountMenu(e) {
        if (e) e.stopPropagation();
        var menu = document.getElementById('m5-account-menu');
        if (!menu) return;
        menu.style.display = (menu.style.display === 'none' || !menu.style.display) ? 'block' : 'none';
    }
    function toggleM5SearchPopover(force) {
        var modal = document.getElementById('m5-search-modal');
        if (!modal) return;
        var show = (typeof force === 'boolean') ? force : (modal.style.display === 'none' || !modal.style.display);
        modal.style.display = show ? 'flex' : 'none';
        if (show) {
            setTimeout(function() {
                var inp = document.getElementById('m5-search-input');
                if (inp) inp.focus();
            }, 40);
        }
    }
    function openM5NewPost() {
        var modal = document.getElementById('modal-composer');
        if (modal) {
            var title = document.getElementById('modal-composer-title');
            if (title) title.textContent = '<?= __('publish', 'Publicar') ?>';
            modal.style.display = 'flex';
            setTimeout(function() {
                var ta = modal.querySelector('textarea');
                if (ta) ta.focus();
            }, 60);
            return;
        }
        var feedCard = document.querySelector('#tab-feed > .composer-card');
        if (feedCard) {
            if (typeof toggleComposerZen === 'function' && !feedCard.classList.contains('zen-mode')) {
                toggleComposerZen(true);
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            setTimeout(function() {
                var ta = feedCard.querySelector('#composer-textarea, textarea');
                if (ta) ta.focus();
            }, 80);
        } else {
            window.location.href = '<?= $basePath ?>/home';
        }
    }
    document.addEventListener('click', function(e) {
        var menu = document.getElementById('m5-account-menu');
        if (menu && menu.style.display === 'block' && !e.target.closest('.m5-account-wrap')) {
            menu.style.display = 'none';
        }
    });
    // Sincronizar badge de notificaciones y avatar en barra superior M5
    (function() {
        var badge = document.getElementById('nav-notifications-count');
        var dot = document.getElementById('m5-notif-dot');
        if (badge && dot && window.MutationObserver) {
            var syncNotif = function() {
                var visible = badge.style.display !== 'none' && parseInt(badge.textContent || '0', 10) > 0;
                dot.style.display = visible ? 'block' : 'none';
            };
            syncNotif();
            new MutationObserver(syncNotif).observe(badge, { attributes: true, childList: true, characterData: true });
        }
        var mainAv = document.getElementById('my-avatar');
        var m5Av = document.getElementById('m5-my-avatar');
        if (mainAv && m5Av && window.MutationObserver) {
            new MutationObserver(function() {
                if (mainAv.src) m5Av.src = mainAv.src;
            }).observe(mainAv, { attributes: true, attributeFilter: ['src'] });
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
