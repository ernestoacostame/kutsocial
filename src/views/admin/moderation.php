<h3>Moderación y Bloqueos</h3>
<form method="POST" action="/admin/moderation/block" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: 200px 1fr 120px; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin: 0;">
            <label for="block_type">Tipo de Filtro</label>
            <select name="type" id="block_type" required>
                <option value="domain">Dominio completo</option>
                <option value="account">Cuenta / Usuario</option>
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
