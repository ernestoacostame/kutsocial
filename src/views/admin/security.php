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
    
    <?php if ($is2faActive): ?>
        <div class="alert alert-success" style="display: flex; justify-content: space-between; align-items: center;">
            <span>✓ Autenticación 2FA Activa</span>
            <form method="POST" action="/admin/security/2fa/disable" style="margin: 0;">
                <input type="password" name="confirm_password" placeholder="Confirma contraseña" style="width: 150px; padding: 6px; font-size: 13px; margin-right: 10px;" required>
                <button type="submit" class="btn-danger" style="border: none;">Desactivar 2FA</button>
            </form>
        </div>
    <?php else: ?>
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
    <?php endif; ?>
</div>

<script>
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
        });
    }
</script>
