<div id="tab-collections" style="display: none;" class="composer-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary);">folder</span> Mis Colecciones Públicas
        </h2>
        <button onclick="showCreateCollectionModal()" class="btn-publish" style="width: auto; padding: 6px 12px; font-size: 13px; display: inline-flex; align-items: center; margin: 0; box-shadow: none;">+ Nueva Colección</button>
    </div>
    <div id="collections-layout" style="display: grid; grid-template-columns: 200px 1fr; gap: 20px;">
        <div id="collections-sidebar" style="border-right: 1px solid var(--border-color); padding-right: 15px; display: flex; flex-direction: column; gap: 8px; min-height: 200px;">
            <?php if (empty($userCollections)): ?>
                <div style="font-size: 13px; color: var(--text-muted); padding: 10px 0;">No tienes colecciones.</div>
            <?php else: ?>
                <?php foreach ($userCollections as $col): ?>
                    <button class="collection-nav-btn" data-collection-id="<?= $col['id'] ?>" onclick="selectCollection(<?= $col['id'] ?>)" style="background: none; border: none; text-align: left; padding: 8px 12px; border-radius: 8px; color: var(--text-color); cursor: pointer; transition: all 0.2s; font-size: 13.5px; width: 100%; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-transform: none; font-weight: normal;">
                        <span>📁 <?= htmlspecialchars($col['title']) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div id="collection-content-area">
            <div id="collection-no-selection" style="text-align: center; padding: 40px; color: var(--text-muted);">
                Selecciona o crea una colección para ver sus cuentas asociadas.
            </div>
            <div id="collection-detail-view" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 id="selected-collection-title" style="font-size: 18px; margin: 0; color: var(--primary);"></h3>
                    <button id="btn-delete-collection" class="btn-publish" style="padding: 4px 10px; font-size: 12px; margin: 0; background: var(--error); border: none;" onclick="deleteSelectedCollection()">Eliminar</button>
                </div>
                <p id="selected-collection-desc" style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;"></p>
                <h4 style="font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 10px; font-weight: bold; letter-spacing: 0.5px;">Cuentas Curadas</h4>
                <div id="collection-accounts-container" style="display: flex; flex-direction: column; gap: 10px;">
                    <!-- Cuentas de la colección -->
                </div>
            </div>
        </div>
    </div>
</div>
