<?php
/**
 * KutSocial - Punto de entrada y Enrutador Frontal
 */

// Normalizar HTTPS detrás de proxy inverso
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// 1. Validar instalación
if (!file_exists(__DIR__ . '/config.php')) {
    // Si no está instalado y no está accediendo al instalador, redirigir
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($requestUri !== '/install.php' && !str_starts_with($requestUri, '/assets/')) {
        header("Location: /install.php");
        exit;
    }
    // Si está intentando cargar el instalador, permitir continuar
    return;
}

// 2. Cargar configuración y clases
require_once __DIR__ . '/config.php';

// Iniciar sesión para rutas administrativas
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_starts_with($requestUri, '/admin')) {
    if (session_status() === PHP_SESSION_NONE) {
        // Session security hardening
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', 1);
        session_start();
    }
}

use KutSocial\Router;
use KutSocial\Database;
use KutSocial\UpdaterService;
use KutSocial\Controllers\MastodonApiController;
use KutSocial\Controllers\ActivityPubController;
use KutSocial\Controllers\AdminController;

// 3. Inicializar base de datos
try {
    Database::setDbPath(KUTSOCIAL_DB_PATH);
    // Cache migration state to avoid running on every request
    $migrationCacheFile = dirname(KUTSOCIAL_DB_PATH) . '/.migration_version';
    $currentMigrationCount = Database::getMigrationCount();
    $cachedVersion = @file_get_contents($migrationCacheFile);
    if ($cachedVersion === false || (int)$cachedVersion !== $currentMigrationCount) {
        Database::runMigrations();
        @file_put_contents($migrationCacheFile, (string)$currentMigrationCount);
    }
} catch (Exception $e) {
    http_response_code(500);
    exit("Fallo crítico de base de datos: " . $e->getMessage());
}

// 4. Configurar Enrutador
$router = new Router();

// --- Rutas de Mastodon API ---
$router->get('/api/v1/instance', [MastodonApiController::class, 'getInstance']);
$router->get('/api/v2/instance', [MastodonApiController::class, 'getInstanceV2']);
$router->get('/api/v1/custom_emojis', [MastodonApiController::class, 'getCustomEmojis']);
$router->post('/api/v1/apps', [MastodonApiController::class, 'createApp']);
$router->get('/oauth/authorize', [MastodonApiController::class, 'showAuthorize']);
$router->post('/oauth/authorize', [MastodonApiController::class, 'handleAuthorize']);
$router->post('/oauth/token', [MastodonApiController::class, 'postToken']);
$router->get('/api/v1/accounts/verify_credentials', [MastodonApiController::class, 'verifyCredentials']);
$router->get('/api/v1/collections', [MastodonApiController::class, 'getCollections']);

// Perfil (Mastodon 4.6 plain text bio + accessibility)
$router->get('/api/v1/profile', [MastodonApiController::class, 'getProfile']);
$router->patch('/api/v1/profile', [MastodonApiController::class, 'patchProfile']);
$router->patch('/api/v1/accounts/update_credentials', [MastodonApiController::class, 'updateCredentials']);
$router->get('/api/v1/accounts/relationships', [MastodonApiController::class, 'getRelationships']);
$router->get('/api/v1/accounts/:id', [MastodonApiController::class, 'getAccountById']);
$router->get('/api/v1/accounts/:id/statuses', [MastodonApiController::class, 'getAccountStatuses']);
$router->post('/api/v1/accounts/:id/follow', [MastodonApiController::class, 'followAccount']);
$router->post('/api/v1/accounts/:id/unfollow', [MastodonApiController::class, 'unfollowAccount']);
$router->get('/api/v1/accounts/:id/followers', [MastodonApiController::class, 'getFollowers']);
$router->get('/api/v1/accounts/:id/following', [MastodonApiController::class, 'getFollowing']);
$router->post('/api/v1/accounts/:id/remove_from_followers', [MastodonApiController::class, 'removeFromFollowers']);

// Solicitudes de seguimiento (Follow Requests)
$router->get('/api/v1/follow_requests', [MastodonApiController::class, 'getFollowRequests']);
$router->post('/api/v1/follow_requests/:id/authorize', [MastodonApiController::class, 'authorizeFollowRequest']);
$router->post('/api/v1/follow_requests/:id/reject', [MastodonApiController::class, 'rejectFollowRequest']);

// Marcadores (Bookmarks)
$router->get('/api/v1/bookmarks', [MastodonApiController::class, 'getBookmarks']);

// Notificaciones con fallback
$router->get('/api/v1/notifications', [MastodonApiController::class, 'getNotifications']);
$router->post('/api/v1/notifications/:id/dismiss', [MastodonApiController::class, 'dismissNotification']);
$router->post('/api/v1/notifications/clear', [MastodonApiController::class, 'clearNotifications']);

// Búsqueda (Search API v1 & v2)
$router->get('/api/v1/search', [MastodonApiController::class, 'search']);
$router->get('/api/v2/search', [MastodonApiController::class, 'search']);

// --- Rutas de ActivityPub ---
$router->get('/.well-known/webfinger', [ActivityPubController::class, 'webfinger']);
$router->get('/users/:username', [ActivityPubController::class, 'getActor']);
$router->post('/users/:username/inbox', [ActivityPubController::class, 'postInbox']);
$router->get('/users/:username/outbox', [ActivityPubController::class, 'getOutbox']);

// --- Timelines, Posting & Streaming REST APIs ---
$router->get('/api/v1/timelines/home', [MastodonApiController::class, 'getHomeTimeline']);
$router->get('/api/v1/timelines/public', [MastodonApiController::class, 'getPublicTimeline']);
$router->post('/api/v1/statuses', [MastodonApiController::class, 'postStatus']);
$router->get('/api/v1/statuses/:id/context', [MastodonApiController::class, 'getStatusContext']);
$router->put('/api/v1/statuses/:id', [MastodonApiController::class, 'updateStatus']);
$router->delete('/api/v1/statuses/:id', [MastodonApiController::class, 'deleteStatus']);
$router->post('/api/v1/statuses/:id/favourite', [MastodonApiController::class, 'favouriteStatus']);
$router->post('/api/v1/statuses/:id/unfavourite', [MastodonApiController::class, 'unfavouriteStatus']);
$router->post('/api/v1/statuses/:id/bookmark', [MastodonApiController::class, 'bookmarkStatus']);
$router->post('/api/v1/statuses/:id/unbookmark', [MastodonApiController::class, 'unbookmarkStatus']);
$router->get('/api/v1/streaming', [MastodonApiController::class, 'getStreaming']);

// Carga Real de Multimedia (Mastodon compatible)
$router->post('/api/v1/media', [MastodonApiController::class, 'uploadMedia']);
$router->post('/api/v2/media', [MastodonApiController::class, 'uploadMedia']);

// Encuestas Reales (Mastodon compatible)
$router->post('/api/v1/polls/:id/votes', [MastodonApiController::class, 'votePoll']);
$router->get('/api/v1/polls/:id', [MastodonApiController::class, 'getPoll']);

// --- Listas (Lists) ---
$router->get('/api/v1/lists', [MastodonApiController::class, 'getLists']);
$router->post('/api/v1/lists', [MastodonApiController::class, 'createList']);
$router->get('/api/v1/lists/:id', [MastodonApiController::class, 'getList']);
$router->put('/api/v1/lists/:id', [MastodonApiController::class, 'updateList']);
$router->delete('/api/v1/lists/:id', [MastodonApiController::class, 'deleteList']);
$router->get('/api/v1/lists/:id/accounts', [MastodonApiController::class, 'getListAccounts']);
$router->post('/api/v1/lists/:id/accounts', [MastodonApiController::class, 'addAccountsToList']);
$router->delete('/api/v1/lists/:id/accounts', [MastodonApiController::class, 'removeAccountsFromList']);
$router->get('/api/v1/timelines/list/:id', [MastodonApiController::class, 'getListTimeline']);

// --- Colecciones (Collections) ---
$router->post('/api/v1/collections', [MastodonApiController::class, 'createCollection']);
$router->get('/api/v1/collections/:id', [MastodonApiController::class, 'getCollection']);
$router->patch('/api/v1/collections/:id', [MastodonApiController::class, 'updateCollection']);
$router->delete('/api/v1/collections/:id', [MastodonApiController::class, 'deleteCollection']);
$router->post('/api/v1/collections/:id/items', [MastodonApiController::class, 'addAccountsToCollection']);
$router->delete('/api/v1/collections/:id/items', [MastodonApiController::class, 'removeAccountsFromCollection']);

// --- Hashtags Seguidos (Followed Tags) ---
$router->get('/api/v1/followed_tags', [MastodonApiController::class, 'getFollowedTags']);
$router->post('/api/v1/tags/:name/follow', [MastodonApiController::class, 'followTag']);
$router->post('/api/v1/tags/:name/unfollow', [MastodonApiController::class, 'unfollowTag']);
$router->get('/api/v1/timelines/tag/:name', [MastodonApiController::class, 'getTagTimeline']);

// --- Importaciones y Exportaciones ---
$router->get('/api/v1/export/posts', [MastodonApiController::class, 'exportPosts']);
$router->get('/api/v1/export/follows', [MastodonApiController::class, 'exportFollows']);
$router->get('/api/v1/export/followers', [MastodonApiController::class, 'exportFollowers']);
$router->get('/api/v1/export/mutes', [MastodonApiController::class, 'exportMutes']);
$router->get('/api/v1/export/blocks', [MastodonApiController::class, 'exportBlocks']);
$router->get('/api/v1/export/domain_blocks', [MastodonApiController::class, 'exportDomainBlocks']);
$router->get('/api/v1/export/bookmarks', [MastodonApiController::class, 'exportBookmarks']);
$router->get('/api/v1/export/filters', [MastodonApiController::class, 'exportFilters']);
$router->post('/api/v1/import', [MastodonApiController::class, 'handleImport']);

// --- Cliente Web SPA ---
$router->get('/', function() {
    $path = __DIR__ . '/src/views/frontend.html';
    if (file_exists($path)) {
        $html = file_get_contents($path);
        $version = \KutSocial\Database::getVersion();
        $html = str_replace('<head>', "<head>\n    <script>window.KUTSOCIAL_VERSION = '{$version}';</script>", $html);
        try {
            $db = \KutSocial\Database::connect();
            $stmt = $db->query("SELECT id, username, locked, display_name, indexable FROM accounts WHERE domain IS NULL AND role = 'owner' LIMIT 1");
            $owner = $stmt->fetch();
            if ($owner) {
                if (isset($owner['indexable']) && !$owner['indexable']) {
                    $noindexTag = '<meta name="robots" content="noindex, nofollow">';
                    $html = str_replace('<head>', "<head>\n    " . $noindexTag, $html);
                }
                $ownerData = json_encode([
                    'id' => (string)$owner['id'],
                    'username' => $owner['username'],
                    'locked' => (bool)$owner['locked'],
                    'display_name' => $owner['display_name'] ?: $owner['username']
                ]);
                $html = str_replace('<head>', "<head>\n    <script>window.KUTSOCIAL_OWNER = {$ownerData};</script>", $html);
            }
        } catch (\Exception $e) {
            // Ignorar errores de base de datos
        }
        Router::html($html);
    } else {
        Router::html("<h2>Frontend no encontrado.</h2>", 404);
    }
});

// --- Panel de Administración Centralizado ---
$router->get('/admin/login', [AdminController::class, 'showLogin']);
$router->post('/admin/login', [AdminController::class, 'handleLogin']);
$router->get('/admin/logout', [AdminController::class, 'handleLogout']);
$router->get('/admin/dashboard', [AdminController::class, 'showDashboard']);
$router->post('/admin/settings', [AdminController::class, 'handleSettings']);
$router->post('/admin/security/password', [AdminController::class, 'handlePassword']);
$router->post('/admin/security/2fa/setup', [AdminController::class, 'setup2FA']);
$router->post('/admin/security/2fa/verify', [AdminController::class, 'verify2FA']);
$router->post('/admin/security/2fa/disable', [AdminController::class, 'disable2FA']);
$router->post('/admin/moderation/block', [AdminController::class, 'addBlock']);
$router->post('/admin/moderation/unblock', [AdminController::class, 'removeBlock']);
$router->post('/admin/relays', [AdminController::class, 'addRelay']);
$router->post('/admin/relays/delete', [AdminController::class, 'removeRelay']);

// Gestión de usuarios y mantenimiento
$router->post('/admin/users/create', [AdminController::class, 'createUser']);
$router->post('/admin/users/delete', [AdminController::class, 'deleteUser']);
$router->post('/admin/users/role', [AdminController::class, 'changeUserRole']);
$router->post('/admin/maintenance/clean', [AdminController::class, 'runMaintenanceClean']);
$router->post('/admin/update/action', [AdminController::class, 'handleUpdateAction']);

// Redirigir la antigua ruta del actualizador al dashboard
$router->get('/admin/update', function() {
    header("Location: /admin/dashboard");
    exit;
});
$router->post('/admin/update', [AdminController::class, 'handleUpdate']);

// 5. Despachar rutas
$router->dispatch();
