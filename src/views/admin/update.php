<h3>Actualización de KutSocial</h3>
<p class="help-text" style="margin-bottom: 25px;">Mantén tu instancia de KutSocial al día con actualización en 1 clic y reversión automática ante fallos.</p>

<div class="update-card" style="margin-bottom: 25px;">
    <h4 style="margin: 0 0 10px 0;">Buscar Actualizaciones</h4>
    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">Consulta el repositorio de GitHub para verificar si hay una nueva versión disponible.</p>
    
    <button class="btn-submit" id="btn-check" type="button" onclick="kpUpdateCheck()">Buscar Actualizaciones</button>
    
    <div id="update-status" style="display: none; margin-top: 15px; padding: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px;"></div>
    
    <div id="update-available" style="display: none; margin-top: 20px; padding: 20px; background: rgba(16, 185, 129, 0.05); border: 1px solid var(--success); border-radius: 12px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <div>
                <h4 id="update-version-label" style="margin: 0; color: var(--success); font-size: 18px;">¡Nueva versión disponible!</h4>
                <small id="update-date-label" style="color: var(--text-muted);"></small>
            </div>
            <button class="btn-submit" id="btn-apply" onclick="kpUpdateApply()" style="background: var(--success);">Actualizar Ahora</button>
        </div>
        <div style="margin-top: 15px; border-top: 1px solid var(--border-color); padding-top: 15px;">
            <strong style="display: block; font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Notas de la versión:</strong>
            <pre id="update-changelog-body" style="font-family: inherit; font-size: 14px; margin: 0; white-space: pre-wrap; line-height: 1.5; color: var(--text-color);"></pre>
        </div>
    </div>
    
    <div id="update-progress" style="display: none; margin-top: 20px; padding: 20px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px;">
        <div style="font-weight: 600; margin-bottom: 10px; font-size: 14px;" id="progress-label">Preparando...</div>
        <div style="background: rgba(255,255,255,0.05); border-radius: 6px; height: 8px; overflow: hidden; margin-bottom: 10px;">
            <div id="progress-bar" style="background: var(--primary); height: 100%; width: 0%; transition: width 0.4s ease;"></div>
        </div>
        <small id="progress-sub" style="color: var(--text-muted);"></small>
    </div>
</div>

<!-- Revertir actualización (Rollback) -->
<div class="update-card" style="margin-bottom: 25px; <?= empty($rollbacks) ? 'display: none;' : '' ?>">
    <h4 style="margin: 0 0 10px 0;">Revertir Actualización (Rollback)</h4>
    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">Restaura una versión previamente guardada de KutSocial. Las bases de datos no se verán afectadas.</p>
    
    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <select class="select" id="rollback-select" style="padding: 10px; font-size: 14px; border-radius: 8px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: white; min-width: 250px;">
            <?php foreach ($rollbacks as $rb): ?>
                <option value="<?= htmlspecialchars($rb['filename'], ENT_QUOTES) ?>">v<?= htmlspecialchars($rb['version'], ENT_QUOTES) ?> (<?= htmlspecialchars($rb['date'], ENT_QUOTES) ?> - <?= round($rb['size'] / (1024*1024), 2) ?> MB)</option>
            <?php endforeach; ?>
        </select>
        <button class="btn-submit" id="btn-rollback" onclick="kpUpdateRollback()" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid #fbbf24; padding: 10px 20px;">Revertir a esta versión</button>
    </div>
    <div id="rollback-status" style="display: none; margin-top: 15px; padding: 12px; border-radius: 8px; font-size: 14px;"></div>
</div>

<!-- Historial -->
<div class="update-card" style="padding: 0;">
    <h4 style="padding: 20px 25px 10px 25px; margin: 0;">Historial de Actualizaciones</h4>
    <table class="list-table" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="padding: 12px 15px;">De → A</th>
                <th style="padding: 12px 15px;">Estado</th>
                <th style="padding: 12px 15px;">Fecha</th>
                <th style="padding: 12px 15px;">Copia de Seguridad</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($updatesHistory)): ?>
                <tr><td colspan='4' style='text-align: center; color: var(--text-muted); padding: 15px;'>Sin actualizaciones registradas todavía.</td></tr>
            <?php else: ?>
                <?php foreach ($updatesHistory as $h): ?>
                    <?php 
                    $statusColor = $h['status'] === 'applied' ? '#10b981' : '#f59e0b';
                    $statusLabel = $h['status'] === 'applied' ? 'Aplicada' : 'Revertida';
                    $dateStr = date('d/m/Y H:i', strtotime($h['applied_at']));
                    $rolledBackDate = !empty($h['rolled_back_at']) ? "<br><small style='color: var(--text-muted);'>Revertida: " . date('d/m/Y H:i', strtotime($h['rolled_back_at'])) . "</small>" : '';
                    $backupInfo = !empty($h['backup_path']) && file_exists($h['backup_path']) ? "✓ " . basename($h['backup_path']) : "—";
                    ?>
                    <tr>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'>v<?= htmlspecialchars($h['from_version'], ENT_QUOTES) ?> → <strong>v<?= htmlspecialchars($h['to_version'], ENT_QUOTES) ?></strong></td>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'><span class='badge' style='background: <?= $statusColor ?>22; color: <?= $statusColor ?>;'><?= $statusLabel ?></span><?= $rolledBackDate ?></td>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'><= $dateStr ?></td>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-family: monospace; font-size: 11px; color: var(--text-muted);'><?= $backupInfo ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    let _updateData = null;

    function kpUpdateCheck() {
        const btn = document.getElementById('btn-check');
        const status = document.getElementById('update-status');
        const available = document.getElementById('update-available');

        btn.disabled = true;
        btn.textContent = 'Buscando...';
        status.style.display = 'block';
        status.style.color = 'var(--text-muted)';
        status.style.borderColor = 'var(--border-color)';
        status.innerHTML = 'Consultando últimas versiones en GitHub...';
        available.style.display = 'none';

        fetch('/admin/update/action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=check'
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Buscar Actualizaciones';
            
            if (data.error) {
                status.style.color = '#f87171';
                status.style.borderColor = 'var(--error)';
                status.innerHTML = '❌ ' + data.error;
                return;
            }
            if (data.up_to_date) {
                status.style.color = '#34d399';
                status.style.borderColor = 'var(--success)';
                status.innerHTML = '✅ KutSocial está al día (versión v' + data.version + ').';
                return;
            }
            if (data.available) {
                _updateData = data;
                status.style.display = 'none';
                available.style.display = 'block';
                document.getElementById('update-version-label').textContent = '¡Nueva actualización ' + data.tag + ' disponible!';
                document.getElementById('update-date-label').textContent = 'Publicada el: ' + data.published_at;
                document.getElementById('update-changelog-body').textContent = data.changelog;
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = 'Buscar Actualizaciones';
            status.style.color = '#f87171';
            status.style.borderColor = 'var(--error)';
            status.innerHTML = '❌ Error al buscar actualizaciones.';
        });
    }

    function kpUpdateApply() {
        if (!_updateData) return;
        if (!confirm('¿Estás seguro de que deseas actualizar KutSocial a la versión ' + _updateData.new_version + ' ahora?\\nSe creará una copia de seguridad automática.')) {
            return;
        }

        const available = document.getElementById('update-available');
        const progress = document.getElementById('update-progress');
        const progressLabel = document.getElementById('progress-label');
        const progressBar = document.getElementById('progress-bar');
        const progressSub = document.getElementById('progress-sub');

        available.style.display = 'none';
        progress.style.display = 'block';

        // Paso 1: Descargar ZIP
        progressLabel.textContent = 'Descargando actualización...';
        progressBar.style.width = '25%';
        progressSub.textContent = 'Obteniendo paquete desde GitHub...';

        fetch('/admin/update/action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=download&zip_url=' + encodeURIComponent(btoa(_updateData.zip_url))
        })
        .then(r => r.json())
        .then(dlData => {
            if (dlData.error) {
                throw new Error(dlData.error);
            }

            // Paso 2: Aplicar
            progressLabel.textContent = 'Aplicando actualización...';
            progressBar.style.width = '75%';
            progressSub.textContent = 'Realizando copia de seguridad e instalando fuentes...';

            return fetch('/admin/update/action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=apply&zip_path=' + encodeURIComponent(dlData.path) + '&new_version=' + encodeURIComponent(_updateData.new_version)
            });
        })
        .then(r => r.json())
        .then(applyData => {
            if (applyData.error) {
                throw new Error(applyData.error);
            }

            progressBar.style.width = '100%';
            progressLabel.textContent = '¡Actualización completada!';
            progressLabel.style.color = '#34d399';
            progressSub.innerHTML = 'KutSocial se ha actualizado con éxito a la versión v' + _updateData.new_version + '. Recargando panel...';

            setTimeout(() => {
                window.location.reload();
            }, 2000);
        })
        .catch(err => {
            progressLabel.textContent = 'Error en la actualización';
            progressLabel.style.color = '#f87171';
            progressBar.style.backgroundColor = 'var(--error)';
            progressSub.textContent = '❌ ' + err.message;
        });
    }

    function kpUpdateRollback() {
        const select = document.getElementById('rollback-select');
        const backupFile = select.value;
        if (!backupFile) return;

        if (!confirm('¿Estás seguro de que deseas revertir KutSocial al respaldo seleccionado?\\nEsto restaurará los archivos fuente a ese momento.')) {
            return;
        }

        const btn = document.getElementById('btn-rollback');
        const status = document.getElementById('rollback-status');

        btn.disabled = true;
        btn.textContent = 'Restaurando...';
        status.style.display = 'block';
        status.style.color = 'var(--text-muted)';
        status.style.background = 'rgba(255,255,255,0.02)';
        status.style.border = '1px solid var(--border-color)';
        status.innerHTML = 'Restaurando copia de seguridad y aplicando cambios...';

        fetch('/admin/update/action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=rollback&backup_file=' + encodeURIComponent(backupFile)
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Revertir a esta versión';

            if (data.error) {
                status.style.color = '#f87171';
                status.style.background = 'rgba(239, 68, 68, 0.05)';
                status.style.border = '1px solid var(--error)';
                status.innerHTML = '❌ ' + data.error;
                return;
            }

            status.style.color = '#34d399';
            status.style.background = 'rgba(16, 185, 129, 0.05)';
            status.style.border = '1px solid var(--success)';
            status.innerHTML = '✅ Rollback completado con éxito. Recargando panel...';

            setTimeout(() => {
                window.location.reload();
            }, 2000);
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = 'Revertir a esta versión';
            status.style.color = '#f87171';
            status.style.background = 'rgba(239, 68, 68, 0.05)';
            status.style.border = '1px solid var(--error)';
            status.innerHTML = '❌ Error al revertir la actualización.';
        });
    }
</script>
