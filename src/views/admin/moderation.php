<h3><?= __('admin_mod_title') ?></h3>

<!-- Sección Reportes de Usuarios -->
<h4 style="margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
    <span class="material-icons-outlined" style="color: var(--error, #ef4444); font-size: 22px;">flag</span> 
    <?= __('admin_mod_reports_title', '', ['count' => count($reports ?? [])]) ?>
</h4>

<?php if (!empty($reports) && count($reports) > 0): ?>
    <table class="list-table" style="margin-bottom: 35px;">
        <thead>
            <tr>
                <th><?= __('admin_mod_col_status') ?></th>
                <th><?= __('admin_mod_col_reported') ?></th>
                <th><?= __('admin_mod_col_reporter') ?></th>
                <th><?= __('admin_mod_col_category') ?></th>
                <th><?= __('admin_mod_col_date') ?></th>
                <th><?= __('admin_mod_col_actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reports as $rep): ?>
                <?php 
                $targetHandle = ($rep['target_domain'] ? $rep['target_username'] . '@' . $rep['target_domain'] : $rep['target_username']) ?: 'ID #' . $rep['target_account_id'];
                $reporterHandle = ($rep['reporter_domain'] ? $rep['reporter_username'] . '@' . $rep['reporter_domain'] : $rep['reporter_username']) ?: 'ID #' . $rep['account_id'];
                $isResolved = !empty($rep['action_taken']);
                $categoryBadge = match($rep['category'] ?? '') {
                    'spam' => '<span style="background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">SPAM</span>',
                    'violation' => '<span style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">INFRACCIÓN</span>',
                    'legal' => '<span style="background: rgba(168, 85, 247, 0.2); color: #a855f7; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">LEGAL</span>',
                    default => '<span style="background: rgba(255, 255, 255, 0.1); color: var(--text-muted); padding: 2px 8px; border-radius: 4px; font-size: 11.5px;">OTRO</span>'
                };
                ?>
                <tr style="<?= $isResolved ? 'opacity: 0.6;' : '' ?>">
                    <td>
                        <?php if ($isResolved): ?>
                            <span style="color: #10b981; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="material-icons-outlined" style="font-size: 16px;">check_circle</span> <?= __('admin_mod_status_resolved') ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #f59e0b; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="material-icons-outlined" style="font-size: 16px;">pending</span> <?= __('admin_mod_status_pending') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="color: var(--text-color);">@<?= htmlspecialchars($targetHandle) ?></strong>
                        <?php if (!empty($rep['forward'])): ?>
                            <span style="display: block; font-size: 11px; color: var(--text-muted);"><?= __('admin_mod_forwarded') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span style="color: var(--text-muted);">@<?= htmlspecialchars($reporterHandle) ?></span></td>
                    <td style="max-width: 280px;">
                        <?= $categoryBadge ?>
                        <div style="font-size: 13px; color: var(--text-color); margin-top: 4px; line-height: 1.4; word-break: break-word;">
                            <?= htmlspecialchars($rep['comment']) ?>
                        </div>
                    </td>
                    <td><span style="font-size: 12px; color: var(--text-muted);"><?= date('d/m/Y H:i', strtotime($rep['created_at'])) ?></span></td>
                    <td>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <?php if (!$isResolved): ?>
                                <form method="POST" action="/admin/moderation/report/resolve" style="margin:0;">
                                    <input type="hidden" name="id" value="<?= $rep['id'] ?>">
                                    <button type="submit" class="btn-submit" style="height: 32px; padding: 0 10px; font-size: 12px;"><?= __('admin_mod_action_resolve') ?></button>
                                </form>
                                <form method="POST" action="/admin/moderation/block" style="margin:0;">
                                    <input type="hidden" name="type" value="account">
                                    <input type="hidden" name="target" value="<?= htmlspecialchars($targetHandle) ?>">
                                    <button type="submit" class="btn-danger" style="height: 32px; padding: 0 10px; font-size: 12px;" onclick="return confirm('<?= __('admin_mod_block_confirm', '', ['handle' => htmlspecialchars($targetHandle)]) ?>')"><?= __('admin_mod_action_block') ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="/admin/moderation/report/delete" style="margin:0;">
                                <input type="hidden" name="id" value="<?= $rep['id'] ?>">
                                <button type="submit" class="btn-danger" style="height: 32px; padding: 0 8px; font-size: 12px; opacity: 0.7;" onclick="return confirm('<?= __('admin_mod_delete_confirm') ?>')">✕</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 15px 20px; border-radius: 10px; margin-bottom: 30px;">
        <p style="color: var(--text-muted); font-size: 14px; margin: 0;"><?= __('admin_mod_no_reports') ?></p>
    </div>
<?php endif; ?>

<h4 style="margin: 30px 0 12px 0;"><?= __('admin_mod_blocks_title') ?></h4>
<form method="POST" action="/admin/moderation/block" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: 200px 1fr 120px; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin: 0;">
            <label for="block_type"><?= __('admin_mod_filter_type') ?></label>
            <select name="type" id="block_type" required>
                <option value="domain"><?= __('admin_mod_type_domain') ?></option>
                <option value="account"><?= __('admin_mod_type_account') ?></option>
                <option value="mute"><?= __('admin_mod_type_mute') ?></option>
                <option value="word"><?= __('admin_mod_type_word') ?></option>
                <option value="hashtag"><?= __('admin_mod_type_hashtag') ?></option>
            </select>
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="block_target"><?= __('admin_mod_filter_val') ?></label>
            <input type="text" name="target" id="block_target" placeholder="ej: spamdomain.com o spambot" required>
        </div>
        <button type="submit" class="btn-submit" style="height: 43px;"><?= __('admin_mod_btn_block') ?></button>
    </div>
    <p class="help-text"><?= __('admin_mod_blocks_desc') ?></p>
</form>

<h4 style="margin: 30px 0 10px 0;"><?= __('admin_mod_active_blocks', '', ['count' => count($blocks)]) ?></h4>
<?php if (count($blocks) > 0): ?>
    <table class="list-table">
        <thead>
            <tr>
                <th><?= __('admin_mod_col_type') ?></th>
                <th><?= __('admin_mod_col_target') ?></th>
                <th><?= __('admin_mod_col_date') ?></th>
                <th><?= __('admin_mod_col_actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($blocks as $row): ?>
                <?php 
                $typeLabel = match($row['type']) {
                    'domain' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">public</span> ' . __('admin_mod_type_domain'),
                    'account' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">person</span> ' . __('admin_mod_type_account'),
                    'mute' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">volume_off</span> ' . __('admin_mod_type_mute'),
                    'word' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">description</span> ' . __('admin_mod_type_word'),
                    'hashtag' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">tag</span> ' . __('admin_mod_type_hashtag'),
                    default => htmlspecialchars($row['type'])
                };
                ?>
                <tr>
                    <td><strong><?= $typeLabel ?></strong></td>
                    <td><code><?= htmlspecialchars($row['target']) ?></code></td>
                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                    <td>
                        <form method="POST" action="/admin/moderation/unblock" style="margin:0;">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" class="btn-danger"><?= __('admin_mod_btn_unblock') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p style="color: var(--text-muted); font-size: 14.5px;"><?= __('admin_mod_no_blocks') ?></p>
<?php endif; ?>
