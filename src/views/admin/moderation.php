<h3>Moderación y Reportes</h3>

<!-- Sección Reportes de Usuarios -->
<h4 style="margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
    <span class="material-icons-outlined" style="color: var(--error, #ef4444); font-size: 22px;">flag</span> 
    Reportes de Usuarios (<?= count($reports ?? []) ?>)
</h4>

<?php if (!empty($reports) && count($reports) > 0): ?>
    <table class="list-table" style="margin-bottom: 35px;">
        <thead>
            <tr>
                <th>Estado</th>
                <th>Cuenta Reportada</th>
                <th>Denunciante</th>
                <th>Categoría / Motivo</th>
                <th>Fecha</th>
                <th>Acciones</th>
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
                                <span class="material-icons-outlined" style="font-size: 16px;">check_circle</span> Resuelto
                            </span>
                        <?php else: ?>
                            <span style="color: #f59e0b; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="material-icons-outlined" style="font-size: 16px;">pending</span> Pendiente
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="color: var(--text-color);">@<?= htmlspecialchars($targetHandle) ?></strong>
                        <?php if (!empty($rep['forward'])): ?>
                            <span style="display: block; font-size: 11px; color: var(--text-muted);">Reenviado al origen</span>
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
                                    <button type="submit" class="btn-submit" style="height: 32px; padding: 0 10px; font-size: 12px;">Resolver</button>
                                </form>
                                <form method="POST" action="/admin/moderation/block" style="margin:0;">
                                    <input type="hidden" name="type" value="account">
                                    <input type="hidden" name="target" value="<?= htmlspecialchars($targetHandle) ?>">
                                    <button type="submit" class="btn-danger" style="height: 32px; padding: 0 10px; font-size: 12px;" onclick="return confirm('¿Bloquear a @<?= htmlspecialchars($targetHandle) ?>?')">Bloquear</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="/admin/moderation/report/delete" style="margin:0;">
                                <input type="hidden" name="id" value="<?= $rep['id'] ?>">
                                <button type="submit" class="btn-danger" style="height: 32px; padding: 0 8px; font-size: 12px; opacity: 0.7;" onclick="return confirm('¿Eliminar este reporte?')">✕</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 15px 20px; border-radius: 10px; margin-bottom: 30px;">
        <p style="color: var(--text-muted); font-size: 14px; margin: 0;">No hay reportes de usuarios registrados.</p>
    </div>
<?php endif; ?>

<h4 style="margin: 30px 0 12px 0;">Reglas y Filtros de Bloqueo</h4>
<form method="POST" action="/admin/moderation/block" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: 200px 1fr 120px; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin: 0;">
            <label for="block_type">Tipo de Filtro</label>
            <select name="type" id="block_type" required>
                <option value="domain">Dominio completo</option>
                <option value="account">Cuenta / Usuario (Bloqueo)</option>
                <option value="mute">Cuenta / Usuario (Silencio)</option>
                <option value="word">Palabra clave</option>
                <option value="hashtag">Hashtag</option>
            </select>
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="block_target">Valor a Bloquear</label>
            <input type="text" name="target" id="block_target" placeholder="ej: spamdomain.com o spambot" required>
        </div>
        <button type="submit" class="btn-submit" style="height: 43px;">Bloquear</button>
    </div>
    <p class="help-text">Los bloqueos ocultarán automáticamente de las timelines locales y federadas cualquier toot que coincida con el filtro.</p>
</form>

<h4 style="margin: 30px 0 10px 0;">Bloqueos Activos (<?= count($blocks) ?>)</h4>
<?php if (count($blocks) > 0): ?>
    <table class="list-table">
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Destino / Valor</th>
                <th>Creado el</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($blocks as $row): ?>
                <?php 
                $typeLabel = match($row['type']) {
                    'domain' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">public</span> Dominio',
                    'account' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">person</span> Cuenta',
                    'mute' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">volume_off</span> Silenciado',
                    'word' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">description</span> Palabra',
                    'hashtag' => '<span class="material-icons-outlined" style="vertical-align: middle; margin-right: 6px; font-size: 18px;">tag</span> Hashtag',
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
                            <button type="submit" class="btn-danger">Desbloquear</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p style="color: var(--text-muted); font-size: 14.5px;">No hay ningún bloqueo activo.</p>
<?php endif; ?>
