<h3><?= __('admin_tab_general') ?></h3>
<form method="POST" action="/admin/settings">
    <!-- Idioma de la Interfaz -->
    <div class="form-group" style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
        <label for="site_language"><?= __('admin_language_setting') ?></label>
        <select name="site_language" id="site_language" style="max-width: 320px;">
            <option value="es" <?= ($siteLanguage ?? 'es') === 'es' ? 'selected' : '' ?>><?= __('spanish') ?> (Spanish)</option>
            <option value="en" <?= ($siteLanguage ?? 'es') === 'en' ? 'selected' : '' ?>><?= __('english') ?> (English)</option>
        </select>
        <p class="help-text"><?= __('admin_language_desc') ?></p>
    </div>

    <div class="form-group">
        <label for="max_toot_chars"><?= __('admin_toot_limit_title') ?></label>
        <input type="number" name="max_toot_chars" id="max_toot_chars" value="<?= htmlspecialchars($maxChars) ?>" min="500" max="9999" required>
        <p class="help-text"><?= __('admin_toot_limit_desc') ?></p>
    </div>

    <!-- Opciones del Menú Lateral de Navegación -->
    <div class="form-group" style="margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-color);">
        <label style="font-size: 16px; font-weight: 600;">🧭 <?= __('admin_nav_menu_title') ?></label>
        <p class="help-text"><?= __('admin_nav_menu_desc') ?></p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; margin-top: 15px; padding: 16px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 10px;">
            <?php
            $availableNavItems = [
                'home' => ['label' => __('nav_home'), 'icon' => 'home'],
                'catchup' => ['label' => __('nav_catchup'), 'icon' => 'bolt'],
                'local' => ['label' => __('nav_local'), 'icon' => 'groups'],
                'public' => ['label' => __('nav_public'), 'icon' => 'public'],
                'notifications' => ['label' => __('nav_notifications'), 'icon' => 'notifications'],
                'bookmarks' => ['label' => __('nav_bookmarks'), 'icon' => 'bookmark_border'],
                'direct' => ['label' => __('nav_direct'), 'icon' => 'mail_outline'],
                'lists' => ['label' => __('nav_lists'), 'icon' => 'list'],
                'collections' => ['label' => __('nav_collections'), 'icon' => 'folder'],
                'hashtags' => ['label' => __('nav_hashtags'), 'icon' => 'tag'],
                'profile' => ['label' => __('nav_profile'), 'icon' => 'person', 'badge' => __('admin_nav_avatar_note')],
                'settings' => ['label' => __('nav_settings'), 'icon' => 'tune'],
                'admin' => ['label' => __('nav_admin'), 'icon' => 'settings'],
            ];
            foreach ($availableNavItems as $key => $item):
                $isChecked = in_array($key, $enabledNavItems ?? [], true);
            ?>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px 10px; border-radius: 8px; background: rgba(255,255,255,0.02); border: 1px solid <?= $isChecked ? 'rgba(99, 102, 241, 0.4)' : 'rgba(255,255,255,0.05)' ?>; user-select: none;">
                    <input type="checkbox" name="sidebar_nav_items[]" value="<?= $key ?>" <?= $isChecked ? 'checked' : '' ?> style="cursor: pointer; width: 17px; height: 17px; accent-color: var(--primary);">
                    <span class="material-icons-outlined" style="font-size: 19px; color: <?= $isChecked ? 'var(--primary)' : 'var(--text-muted)' ?>;"><?= $item['icon'] ?></span>
                    <span style="font-size: 13.5px; font-weight: 500;"><?= $item['label'] ?></span>
                    <?php if (!empty($item['badge'])): ?>
                        <span style="margin-left: auto; font-size: 10px; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.08); color: var(--text-muted); font-weight: 400;"><?= $item['badge'] ?></span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="form-group" style="margin-top: 20px;">
        <label for="update_github_token"><?= __('admin_github_token') ?></label>
        <input type="password" name="update_github_token" id="update_github_token" value="<?= htmlspecialchars($githubToken) ?>" placeholder="<?= __('admin_github_token_placeholder') ?>">
        <p class="help-text"><?= __('admin_github_token_desc') ?></p>
    </div>
    <div class="form-group" style="margin-top: 20px;">
        <label for="giphy_api_key"><?= __('admin_giphy_key') ?></label>
        <input type="password" name="giphy_api_key" id="giphy_api_key" value="<?= htmlspecialchars($giphyApiKey) ?>">
        <p class="help-text"><?= __('admin_giphy_key_desc') ?></p>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 30px 0;">
    
    <h3>📧 <?= __('admin_smtp_title') ?></h3>
    <div class="form-group" style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
        <input type="checkbox" name="email_notifications" id="email_notifications" value="1" <?= $emailNotificationsChecked ?> style="width: auto; cursor: pointer;">
        <label for="email_notifications" style="margin-bottom: 0; cursor: pointer; font-weight: 600;"><?= __('admin_email_enable') ?></label>
    </div>
    <div id="smtp-settings-wrapper" style="margin-top: 15px;">
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_host"><?= __('admin_smtp_host') ?></label>
            <input type="text" name="smtp_host" id="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="smtp.ejemplo.com">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_port"><?= __('admin_smtp_port') ?></label>
            <input type="number" name="smtp_port" id="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>" min="1" max="65535" placeholder="587">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_user"><?= __('admin_smtp_user') ?></label>
            <input type="text" name="smtp_user" id="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>" placeholder="usuario@ejemplo.com">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_pass"><?= __('admin_smtp_pass') ?></label>
            <input type="password" name="smtp_pass" id="smtp_pass" value="<?= htmlspecialchars($smtpPass) ?>" placeholder="••••••••">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_from"><?= __('admin_smtp_from') ?></label>
            <input type="email" name="smtp_from" id="smtp_from" value="<?= htmlspecialchars($smtpFrom) ?>" placeholder="kutsocial@ejemplo.com">
            <p class="help-text"><?= __('admin_smtp_from_desc') ?></p>
        </div>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 30px 0;">

    <h3>✍️ <?= __('admin_attribution_title') ?></h3>
    <p class="help-text"><?= __('admin_attribution_desc') ?></p>
    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 12px; border-radius: 8px; font-family: monospace; font-size: 13px; margin: 10px 0; color: #818cf8; user-select: all; cursor: pointer;">
        &lt;meta name="fediverse:creator" content="@<?= htmlspecialchars($admin['username']) ?>@<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>"&gt;
    </div>
    <div class="form-group" style="margin-top: 15px;">
        <label for="attribution_domains"><?= __('admin_attribution_domains') ?></label>
        <textarea name="attribution_domains" id="attribution_domains" rows="5" style="font-family: monospace;" placeholder="tudominio.com&#10;www.otromedio.net"><?= htmlspecialchars($attributionDomains) ?></textarea>
        <p class="help-text"><?= __('admin_attribution_domains_desc') ?></p>
    </div>

    <button type="submit" class="btn-submit" style="margin-top: 20px;"><?= __('admin_save_settings') ?></button>
</form>
