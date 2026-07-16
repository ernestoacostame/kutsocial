<h3>Ajustes Generales</h3>
<form method="POST" action="/admin/settings">
    <div class="form-group">
        <label for="max_toot_chars">Límite de Caracteres por Toot</label>
        <input type="number" name="max_toot_chars" id="max_toot_chars" value="<?= htmlspecialchars($maxChars) ?>" min="500" max="9999" required>
        <p class="help-text">Elige la longitud máxima de caracteres permitida para los toots locales. Mínimo 500 y máximo 9999.</p>
    </div>
    <div class="form-group" style="margin-top: 20px;">
        <label for="update_github_token">Token de GitHub (PAT)</label>
        <input type="password" name="update_github_token" id="update_github_token" value="<?= htmlspecialchars($githubToken) ?>">
        <p class="help-text">Token de Acceso Personal (PAT) de GitHub para poder buscar y descargar de forma segura actualizaciones de KutSocial.</p>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 30px 0;">
    
    <h3>📧 Configuración de Correo Electrónico (Notificaciones SMTP)</h3>
    <div class="form-group" style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
        <input type="checkbox" name="email_notifications" id="email_notifications" value="1" <?= $emailNotificationsChecked ?> style="width: auto; cursor: pointer;">
        <label for="email_notifications" style="margin-bottom: 0; cursor: pointer; font-weight: 600;">Activar notificaciones por correo electrónico</label>
    </div>
    <div id="smtp-settings-wrapper" style="margin-top: 15px;">
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_host">Servidor SMTP</label>
            <input type="text" name="smtp_host" id="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="smtp.ejemplo.com">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_port">Puerto SMTP</label>
            <input type="number" name="smtp_port" id="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>" min="1" max="65535" placeholder="587">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_user">Usuario SMTP</label>
            <input type="text" name="smtp_user" id="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>" placeholder="usuario@ejemplo.com">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_pass">Contraseña SMTP</label>
            <input type="password" name="smtp_pass" id="smtp_pass" value="<?= htmlspecialchars($smtpPass) ?>" placeholder="••••••••">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="smtp_from">Correo Remitente (From)</label>
            <input type="email" name="smtp_from" id="smtp_from" value="<?= htmlspecialchars($smtpFrom) ?>" placeholder="kutsocial@ejemplo.com">
            <p class="help-text">El correo que aparecerá como remitente de las notificaciones.</p>
        </div>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 30px 0;">

    <h3>✍️ Atribución de Autor (Estilo Mastodon)</h3>
    <p class="help-text">Cuando se comparta un enlace de tus sitios autorizados, las publicaciones de KutSocial y Mastodon te atribuirán la autoría si el sitio tiene esta etiqueta en su HTML:</p>
    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 12px; border-radius: 8px; font-family: monospace; font-size: 13px; margin: 10px 0; color: #818cf8; user-select: all; cursor: pointer;">
        &lt;meta name="fediverse:creator" content="@<?= htmlspecialchars($admin['username']) ?>@<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>"&gt;
    </div>
    <div class="form-group" style="margin-top: 15px;">
        <label for="attribution_domains">Sitios web permitidos para atribuirte (Uno por línea)</label>
        <textarea name="attribution_domains" id="attribution_domains" rows="5" style="font-family: monospace;" placeholder="tudominio.com&#10;www.otromedio.net"><?= htmlspecialchars($attributionDomains) ?></textarea>
        <p class="help-text">Protege tu cuenta de falsas atribuciones. Solo se te acreditará la autoría en enlaces que provengan de los dominios aquí listados.</p>
    </div>

    <button type="submit" class="btn-submit" style="margin-top: 20px;">Guardar Ajustes</button>
</form>
