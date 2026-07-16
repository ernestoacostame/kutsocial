<h3>Gestión de Usuarios (<?= count($localUsers) ?>)</h3>
<form method="POST" action="/admin/users/create" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 20px; border-radius: 10px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 120px 120px; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin: 0;">
            <label for="new_username">Usuario</label>
            <input type="text" name="username" id="new_username" placeholder="ej: maria" required pattern="^[a-zA-Z0-9_]{1,30}$">
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="new_email">Correo</label>
            <input type="email" name="email" id="new_email" placeholder="maria@ejemplo.com" required>
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="new_pwd">Contraseña</label>
            <input type="password" name="password" id="new_pwd" placeholder="Min. 8 car." required minlength="8">
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="new_role">Rol</label>
            <select name="role" id="new_role" required>
                <option value="user">Usuario</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <button type="submit" class="btn-submit" style="height: 43px;">Crear</button>
    </div>
</form>

<table class="list-table">
    <thead>
        <tr>
            <th>Usuario</th>
            <th>Correo</th>
            <th>Rol</th>
            <th>Creado el</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($localUsers as $u): ?>
            <?php 
            $isSelf = ((int)$u['id'] === (int)$admin['id']);
            $isOwner = ($u['role'] === 'owner');
            
            $selectDisabled = $isSelf ? 'disabled' : '';
            if ($isOwner && $admin['role'] !== 'owner') {
                $selectDisabled = 'disabled';
            }
            ?>
            <tr>
                <td><strong>@<?= htmlspecialchars($u['username']) ?></strong></td>
                <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td>
                    <form method="POST" action="/admin/users/role" style="margin:0; display:inline;">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <select name="role" onchange="this.form.submit()" <?= $selectDisabled ?> style="padding: 6px 10px; font-size: 13px; width: auto; background: rgba(255,255,255,0.05); border-radius: 6px; border: 1px solid var(--border-color); color: white;">
                            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Usuario</option>
                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                            <option value="owner" <?= $u['role'] === 'owner' ? 'selected' : '' ?>>Propietario</option>
                        </select>
                    </form>
                </td>
                <td><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></td>
                <td>
                    <?php if ($isSelf): ?>
                        <span style="color: var(--text-muted); font-size:12px;">Tú</span>
                    <?php else: ?>
                        <?php if (!$isOwner || $admin['role'] === 'owner'): ?>
                            <form method="POST" action="/admin/users/delete" style="margin:0; display:inline-block;" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este usuario?');">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-danger">Eliminar</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
