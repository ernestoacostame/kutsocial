<h3>Relays de ActivityPub</h3>
<p class="help-text" style="margin-bottom: 25px;">Los relays envían y reciben contenidos públicos compartidos en el Fediverso, permitiendo que tu instancia descubra más usuarios y toots.</p>

<form method="POST" action="/admin/relays" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: 1fr 140px; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin: 0;">
            <label for="relay_inbox">Inbox URL del Relay</label>
            <input type="url" name="inbox_url" id="relay_inbox" placeholder="https://relay.ejemplo.org/inbox" required>
        </div>
        <button type="submit" class="btn-submit" style="height: 43px;">Suscribirse</button>
    </div>
</form>

<h4 style="margin: 30px 0 10px 0;">Relays Suscritos (<?= count($relays) ?>)</h4>
<?php if (count($relays) > 0): ?>
    <table class="list-table">
        <thead>
            <tr>
                <th>Inbox URL</th>
                <th>Estado</th>
                <th>Suscrito el</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($relays as $row): ?>
                <?php 
                $statusBadge = ($row['status'] === 'accepted') 
                    ? "<span class='badge badge-success'>Aceptado</span>" 
                    : "<span class='badge badge-pending'>Pendiente</span>";
                ?>
                <tr>
                    <td><code><?= htmlspecialchars($row['inbox_url']) ?></code></td>
                    <td><?= $statusBadge ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                    <td>
                        <form method="POST" action="/admin/relays/delete" style="margin:0;">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" class="btn-danger">Desconectar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p style="color: var(--text-muted); font-size: 14.5px;">No estás conectado a ningún relay en este momento.</p>
<?php endif; ?>
