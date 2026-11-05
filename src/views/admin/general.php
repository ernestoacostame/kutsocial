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
