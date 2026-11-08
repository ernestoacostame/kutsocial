<?php
$updaterService = new \KutSocial\UpdaterService();
$ks_update_cache = $updaterService->getCached();
$hasAvailableUpdate = false;
if ($ks_update_cache && !empty($ks_update_cache['available']) && !empty($ks_update_cache['new_version'])) {
    if (version_compare($ks_update_cache['new_version'], \KutSocial\Database::getVersion(), '>')) {
        $hasAvailableUpdate = true;
    }
}
?>
<h3><?= __('admin_update_title') ?></h3>
<p class="help-text" style="margin-bottom: 25px;"><?= __('admin_update_desc') ?></p>

<div class="update-card" style="margin-bottom: 25px;">
    <h4 style="margin: 0 0 10px 0;"><?= __('admin_update_check_title') ?></h4>
    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;"><?= __('admin_update_check_desc') ?></p>
    
    <button class="btn-submit" id="btn-check" type="button" onclick="kpUpdateCheck()"><?= __('admin_update_check_btn') ?></button>
    
    <div id="update-status" style="display: none; margin-top: 15px; padding: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px;"></div>
    
    <div id="update-available" style="display: none; margin-top: 20px; padding: 20px; background: rgba(16, 185, 129, 0.05); border: 1px solid var(--success); border-radius: 12px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <div>
                <h4 id="update-version-label" style="margin: 0; color: var(--success); font-size: 18px;"><?= __('admin_update_available_title') ?></h4>
                <small id="update-date-label" style="color: var(--text-muted);"></small>
            </div>
            <button class="btn-submit" id="btn-apply" onclick="kpUpdateApply()" style="background: var(--success);"><?= __('admin_update_apply_btn') ?></button>
        </div>
        <div style="margin-top: 15px; border-top: 1px solid var(--border-color); padding-top: 15px;">
            <strong style="display: block; font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;"><?= __('admin_update_changelog_title') ?></strong>
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

<!-- Actualización Manual vía Archivo ZIP -->
<div class="update-card" style="margin-bottom: 25px;">
    <h4 style="margin: 0 0 10px 0;"><?= __('admin_update_manual_title') ?></h4>
    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">
        <?= __raw('admin_update_manual_desc') ?>
    </p>

    <form id="form-manual-upload" onsubmit="kpManualUpdateUpload(event)">
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="file" id="manual-zip-input" accept=".zip,application/zip" class="input" style="flex: 1; min-width: 240px; padding: 10px; font-size: 14px; border-radius: 8px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: white;" required>
            <button class="btn-submit" id="btn-manual-upload" type="submit" style="padding: 10px 20px;"><?= __('admin_update_manual_upload_btn') ?></button>
        </div>
        <small style="display: block; margin-top: 8px; color: var(--text-muted); font-size: 12px;"><?= __('admin_update_manual_note') ?></small>
    </form>

    <div id="manual-upload-status" style="display: none; margin-top: 15px; padding: 15px; border-radius: 8px; font-size: 14px;"></div>
</div>

<!-- Revertir actualización (Rollback) -->
<div class="update-card" style="margin-bottom: 25px; <?= empty($rollbacks) ? 'display: none;' : '' ?>">
    <h4 style="margin: 0 0 10px 0;"><?= __('admin_update_rollback_title') ?></h4>
    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;"><?= __('admin_update_rollback_desc') ?></p>
    
    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <select class="select" id="rollback-select" style="padding: 10px; font-size: 14px; border-radius: 8px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: white; min-width: 250px;">
            <?php foreach ($rollbacks as $rb): ?>
                <option value="<?= htmlspecialchars($rb['filename'], ENT_QUOTES) ?>">v<?= htmlspecialchars($rb['version'], ENT_QUOTES) ?> (<?= htmlspecialchars($rb['date'], ENT_QUOTES) ?> - <?= round($rb['size'] / (1024*1024), 2) ?> MB)</option>
            <?php endforeach; ?>
        </select>
        <button class="btn-submit" id="btn-rollback" onclick="kpUpdateRollback()" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid #fbbf24; padding: 10px 20px;"><?= __('admin_update_rollback_btn') ?></button>
    </div>
    <div id="rollback-status" style="display: none; margin-top: 15px; padding: 12px; border-radius: 8px; font-size: 14px;"></div>
</div>

<!-- Historial -->
<div class="update-card" style="padding: 0;">
    <h4 style="padding: 20px 25px 10px 25px; margin: 0;"><?= __('admin_update_history_title') ?></h4>
    <table class="list-table" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="padding: 12px 15px;"><?= __('admin_update_col_from_to') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_update_col_status') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_update_col_date') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_update_col_backup') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($updatesHistory)): ?>
                <tr><td colspan='4' style='text-align: center; color: var(--text-muted); padding: 15px;'><?= __('admin_update_no_history') ?></td></tr>
            <?php else: ?>
                <?php foreach ($updatesHistory as $h): ?>
                    <?php 
                    $statusColor = $h['status'] === 'applied' ? '#10b981' : '#f59e0b';
                    $statusLabel = $h['status'] === 'applied' ? __('admin_update_status_applied') : __('admin_update_status_reverted');
                    $dateStr = date('d/m/Y H:i', strtotime($h['applied_at']));
                    $rolledBackDate = !empty($h['rolled_back_at']) ? "<br><small style='color: var(--text-muted);'>" . __('admin_update_status_reverted') . ": " . date('d/m/Y H:i', strtotime($h['rolled_back_at'])) . "</small>" : '';
                    $backupInfo = !empty($h['backup_path']) && file_exists($h['backup_path']) ? "✓ " . basename($h['backup_path']) : "—";
                    ?>
                    <tr>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'>v<?= htmlspecialchars($h['from_version'], ENT_QUOTES) ?> → <strong>v<?= htmlspecialchars($h['to_version'], ENT_QUOTES) ?></strong></td>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'><span class='badge' style='background: <?= $statusColor ?>22; color: <?= $statusColor ?>;'><?= $statusLabel ?></span><?= $rolledBackDate ?></td>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color);'><?= $dateStr ?></td>
                        <td style='padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-family: monospace; font-size: 11px; color: var(--text-muted);'><?= $backupInfo ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    let _updateData = <?= $hasAvailableUpdate ? json_encode($ks_update_cache) : 'null' ?>;

    document.addEventListener('DOMContentLoaded', function() {
        if (_updateData && _updateData.available) {
            const status = document.getElementById('update-status');
            const available = document.getElementById('update-available');
            if (status) status.style.display = 'none';
            if (available) available.style.display = 'block';
            document.getElementById('update-version-label').textContent = '🎉 ' + (_updateData.tag || _updateData.new_version) + ' - ' + adminI18n.newVersionTpl;
            document.getElementById('update-date-label').textContent = adminI18n.publishedAt + ' ' + _updateData.published_at;
            document.getElementById('update-changelog-body').textContent = _updateData.changelog;
        } else {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('check') === '1' || urlParams.get('auto') === '1') {
                kpUpdateCheck();
            }
        }
    });
    const adminI18n = {
        checking: <?= json_encode(__('admin_update_checking')) ?>,
        consulting: <?= json_encode(__('admin_update_consulting')) ?>,
        checkBtn: <?= json_encode(__('admin_update_check_btn')) ?>,
        errorChecking: <?= json_encode(__('admin_update_error_checking')) ?>,
        upToDateTpl: <?= json_encode(__('admin_update_up_to_date', '', ['version' => ':version'])) ?>,
        newVersionTpl: <?= json_encode(__('admin_update_available_title')) ?>,
        publishedAt: <?= json_encode(__('admin_update_published_at')) ?>,
        confirmApply: <?= json_encode(__('admin_update_confirm_apply', '', ['version' => ':version'])) ?>,
        downloading: <?= json_encode(__('admin_update_downloading')) ?>,
        downloadingSub: <?= json_encode(__('admin_update_downloading_sub')) ?>,
        applying: <?= json_encode(__('admin_update_applying')) ?>,
        applyingSub: <?= json_encode(__('admin_update_applying_sub')) ?>,
        completed: <?= json_encode(__('admin_update_completed')) ?>,
        completedSub: <?= json_encode(__('admin_update_completed_sub', '', ['version' => ':version'])) ?>,
        failed: <?= json_encode(__('admin_update_failed')) ?>,
        rollbackConfirm: <?= json_encode(__('admin_update_rollback_confirm')) ?>,
        rollbackRestoring: <?= json_encode(__('admin_update_rollback_restoring')) ?>,
        rollbackProgress: <?= json_encode(__('admin_update_rollback_progress')) ?>,
        rollbackSuccess: <?= json_encode(__('admin_update_rollback_success')) ?>,
        rollbackFailed: <?= json_encode(__('admin_update_rollback_failed')) ?>,
        rollbackBtn: <?= json_encode(__('admin_update_rollback_btn')) ?>,
        selectZip: <?= json_encode(__('admin_update_select_zip')) ?>,
        zipExtError: <?= json_encode(__('admin_update_zip_ext_error')) ?>,
        manualConfirm: <?= json_encode(__('admin_update_manual_confirm', '', ['file' => ':file'])) ?>,
        installing: <?= json_encode(__('admin_update_installing')) ?>,
        uploadingSub: <?= json_encode(__('admin_update_uploading_sub')) ?>,
        reloadPanel: <?= json_encode(__('admin_update_reload_panel')) ?>,
        manualUploadBtn: <?= json_encode(__('admin_update_manual_upload_btn')) ?>
    };

    function kpUpdateCheck() {
        const btn = document.getElementById('btn-check');
        const status = document.getElementById('update-status');
        const available = document.getElementById('update-available');

        btn.disabled = true;
        btn.textContent = adminI18n.checking;
        status.style.display = 'block';
        status.style.color = 'var(--text-muted)';
        status.style.borderColor = 'var(--border-color)';
        status.innerHTML = adminI18n.consulting;
        available.style.display = 'none';

        fetch('/admin/update/action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=check'
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = adminI18n.checkBtn;
            
            if (data.error) {
                status.style.color = '#f87171';
                status.style.borderColor = 'var(--error)';
                status.innerHTML = '❌ ' + data.error;
                return;
            }
            if (data.up_to_date) {
                status.style.color = '#34d399';
                status.style.borderColor = 'var(--success)';
                status.innerHTML = '✅ ' + adminI18n.upToDateTpl.replace(':version', data.version);
                return;
            }
            if (data.available) {
                _updateData = data;
                status.style.display = 'none';
                available.style.display = 'block';
                document.getElementById('update-version-label').textContent = '🎉 ' + (data.tag || data.new_version) + ' - ' + adminI18n.newVersionTpl;
                document.getElementById('update-date-label').textContent = adminI18n.publishedAt + ' ' + data.published_at;
                document.getElementById('update-changelog-body').textContent = data.changelog;
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = adminI18n.checkBtn;
            status.style.color = '#f87171';
            status.style.borderColor = 'var(--error)';
            status.innerHTML = '❌ ' + adminI18n.errorChecking;
        });
    }

    function kpUpdateApply() {
        if (!_updateData) return;
        const confirmMsg = adminI18n.confirmApply.replace(':version', _updateData.new_version);
        if (!confirm(confirmMsg)) {
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
        progressLabel.textContent = adminI18n.downloading;
        progressBar.style.width = '25%';
        progressSub.textContent = adminI18n.downloadingSub;

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
            progressLabel.textContent = adminI18n.applying;
            progressBar.style.width = '75%';
            progressSub.textContent = adminI18n.applyingSub;

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
            progressLabel.textContent = adminI18n.completed;
            progressLabel.style.color = '#34d399';
            progressSub.innerHTML = adminI18n.completedSub.replace(':version', _updateData.new_version);

            setTimeout(() => {
                window.location.reload();
            }, 2000);
        })
        .catch(err => {
            progressLabel.textContent = adminI18n.failed;
            progressLabel.style.color = '#f87171';
            progressBar.style.backgroundColor = 'var(--error)';
            progressSub.textContent = '❌ ' + err.message;
        });
    }

    function kpUpdateRollback() {
        const select = document.getElementById('rollback-select');
        const backupFile = select.value;
        if (!backupFile) return;

        if (!confirm(adminI18n.rollbackConfirm)) {
            return;
        }

        const btn = document.getElementById('btn-rollback');
        const status = document.getElementById('rollback-status');

        btn.disabled = true;
        btn.textContent = adminI18n.rollbackRestoring;
        status.style.display = 'block';
        status.style.color = 'var(--text-muted)';
        status.style.background = 'rgba(255,255,255,0.02)';
        status.style.border = '1px solid var(--border-color)';
        status.innerHTML = adminI18n.rollbackProgress;

        fetch('/admin/update/action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=rollback&backup_file=' + encodeURIComponent(backupFile)
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = adminI18n.rollbackBtn;

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
            status.innerHTML = '✅ ' + adminI18n.rollbackSuccess;

            setTimeout(() => {
                window.location.reload();
            }, 2000);
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = adminI18n.rollbackBtn;
            status.style.color = '#f87171';
            status.style.background = 'rgba(239, 68, 68, 0.05)';
            status.style.border = '1px solid var(--error)';
            status.innerHTML = '❌ ' + adminI18n.rollbackFailed;
        });
    }

    function kpManualUpdateUpload(e) {
        e.preventDefault();
        const fileInput = document.getElementById('manual-zip-input');
        const btn = document.getElementById('btn-manual-upload');
        const status = document.getElementById('manual-upload-status');

        if (!fileInput.files || !fileInput.files[0]) {
            alert(adminI18n.selectZip);
            return;
        }

        const file = fileInput.files[0];
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert(adminI18n.zipExtError);
            return;
        }

        if (!confirm(adminI18n.manualConfirm.replace(':file', file.name))) {
            return;
        }

        btn.disabled = true;
        btn.textContent = adminI18n.installing;
        status.style.display = 'block';
        status.style.color = 'var(--text-color)';
        status.style.background = 'rgba(255,255,255,0.02)';
        status.style.border = '1px solid var(--border-color)';
        status.innerHTML = '<div style="font-weight: 600; margin-bottom: 4px;">' + adminI18n.installing + '</div>'
            + '<div style="font-size: 12px; color: var(--text-muted);">' + adminI18n.uploadingSub + '</div>';

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('zip_file', file);

        fetch('/admin/update/action', {
            method: 'POST',
            body: formData
        })
        .then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch(err) {
                throw new Error('HTTP ' + r.status + ': ' + text.substring(0, 150));
            }
        })
        .then(data => {
            if (data.error) {
                status.style.color = '#f87171';
                status.style.background = 'rgba(239, 68, 68, 0.05)';
                status.style.border = '1px solid var(--error)';
                status.innerHTML = '<strong>❌ ' + data.error + '</strong>';
                btn.disabled = false;
                btn.textContent = adminI18n.manualUploadBtn;
                return;
            }

            status.style.color = '#34d399';
            status.style.background = 'rgba(16, 185, 129, 0.05)';
            status.style.border = '1px solid var(--success)';
            status.innerHTML = '<div style="font-size: 15px; font-weight: 700;">🎉 ' + adminI18n.completed + '</div>'
                + '<div style="margin-top: 6px; font-size: 13px; color: var(--text-color);">v' + data.from + ' → <strong>v' + data.to + '</strong> (' + data.files_updated + ' files).</div>'
                + '<div style="margin-top: 12px;"><button class="btn-submit" onclick="window.location.reload()" style="background: var(--success); padding: 8px 16px;">' + adminI18n.reloadPanel + '</button></div>';
            fileInput.value = '';
            btn.disabled = false;
            btn.textContent = adminI18n.manualUploadBtn;
        })
        .catch(err => {
            status.style.color = '#f87171';
            status.style.background = 'rgba(239, 68, 68, 0.05)';
            status.style.border = '1px solid var(--error)';
            status.innerHTML = '<strong>❌ ' + err.message + '</strong>';
            btn.disabled = false;
            btn.textContent = adminI18n.manualUploadBtn;
        });
    }
</script>
