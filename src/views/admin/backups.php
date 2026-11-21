<?php
$engineLabel = match ($dbDriver ?? 'sqlite') {
    'pgsql' => 'PostgreSQL',
    'mysql' => 'MariaDB / MySQL',
    default => 'SQLite',
};
$csrfToken = htmlspecialchars(\KutSocial\Controllers\AdminController::getCsrfToken(), ENT_QUOTES, 'UTF-8');

$formatBackupSize = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
};
?>
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
    <h3 style="margin: 0;"><?= __('admin_backups_title') ?></h3>
    <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted);">
        <span><?= __('admin_backups_engine_label') ?></span>
        <span class="badge badge-success" style="font-weight: 600;"><?= htmlspecialchars($engineLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</div>
<p class="help-text" style="margin-bottom: 25px;"><?= __('admin_backups_desc') ?></p>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <!-- Backup Base de Datos -->
    <div class="update-card" style="display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <span class="material-icons-outlined" style="color: var(--primary);">storage</span>
                <h4 style="margin: 0;"><?= __('admin_backups_db_title') ?></h4>
            </div>
            <p class="help-text" style="margin: 0; line-height: 1.5;"><?= __('admin_backups_db_desc') ?></p>
        </div>
        <form method="POST" action="/admin/backups/create-db" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button type="submit" class="btn-submit" style="width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <span class="material-icons-outlined" style="font-size: 18px;">save</span>
                <?= __('admin_backups_db_btn') ?>
            </button>
        </form>
    </div>

    <!-- Backup Completo del Directorio -->
    <div class="update-card" style="display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <span class="material-icons-outlined" style="color: var(--primary);">folder_zip</span>
                <h4 style="margin: 0;"><?= __('admin_backups_full_title') ?></h4>
            </div>
            <p class="help-text" style="margin: 0; line-height: 1.5;"><?= __('admin_backups_full_desc') ?></p>
        </div>
        <form method="POST" action="/admin/backups/create-full" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button type="submit" class="btn-submit" style="width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <span class="material-icons-outlined" style="font-size: 18px;">archive</span>
                <?= __('admin_backups_full_btn') ?>
            </button>
        </form>
    </div>
</div>

<!-- Tabla de Backups existentes -->
<div class="update-card" style="padding: 0; overflow-x: auto;">
    <h4 style="padding: 20px 25px 10px 25px; margin: 0;"><?= __('admin_backups_list_title') ?></h4>
    <table class="list-table" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="padding: 12px 15px;"><?= __('admin_backups_col_file') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_backups_col_type') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_backups_col_size') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_backups_col_date') ?></th>
                <th style="padding: 12px 15px;"><?= __('admin_backups_col_actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($backups)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 20px;">
                        <?= __('admin_backups_empty') ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($backups as $b): ?>
                    <?php
                    $isFull = ($b['type'] === 'Full');
                    $badgeClass = $isFull ? 'badge-pending' : 'badge-success';
                    $typeLabel = $isFull ? __('admin_backups_type_full') : __('admin_backups_type_db');
                    $safeFile = htmlspecialchars($b['filename'], ENT_QUOTES, 'UTF-8');
                    $urlFile = urlencode($b['filename']);
                    ?>
                    <tr>
                        <td style="padding: 12px 15px; font-family: monospace; font-size: 13px;"><?= $safeFile ?></td>
                        <td style="padding: 12px 15px;"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td style="padding: 12px 15px;"><?= $formatBackupSize((int)$b['size']) ?></td>
                        <td style="padding: 12px 15px;"><?= date('d/m/Y H:i', (int)$b['mtime']) ?></td>
                        <td style="padding: 12px 15px; white-space: nowrap;">
                            <a href="/admin/backups/download?file=<?= $urlFile ?>" class="btn-submit" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; font-size: 13px; text-decoration: none; margin-right: 6px;">
                                <span class="material-icons-outlined" style="font-size: 16px;">download</span>
                                <?= __('admin_backups_download') ?>
                            </a>
                            <form method="POST" action="/admin/backups/delete" style="margin: 0; display: inline-block;" onsubmit="return confirm('<?= htmlspecialchars(__('admin_backups_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>');">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="file" value="<?= $safeFile ?>">
                                <button type="submit" class="btn-danger" style="display: inline-flex; align-items: center; gap: 4px;">
                                    <span class="material-icons-outlined" style="font-size: 16px;">delete</span>
                                    <?= __('admin_backups_delete') ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
