<div id="tab-collections" style="display: none;" class="lists-view-container">
    <div class="lists-header-bar">
        <h2 style="font-size: 18px; margin: 0; display: flex; align-items: center; gap: 10px; font-weight: 700;">
            <span class="material-icons-outlined" style="color: var(--primary); font-size: 24px;">folder</span> Mis Colecciones Públicas
        </h2>
        <button type="button" onclick="showCreateCollectionModal()" class="btn-publish" style="width: auto; padding: 7px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; margin: 0; box-shadow: none; border-radius: 10px;">
            <span class="material-icons-outlined" style="font-size: 16px;">add</span> Nueva Colección
        </button>
    </div>

    <div class="lists-layout-grid">
        <aside class="lists-sidebar-panel" id="collections-sidebar">
            <?php if (empty($userCollections)): ?>
                <div style="font-size: 13px; color: var(--text-muted); padding: 25px 10px; text-align: center;">No tienes colecciones.</div>
            <?php else: ?>
                <?php foreach ($userCollections as $col): ?>
                    <button type="button" class="list-nav-btn" data-collection-id="<?= $col['id'] ?>" onclick="selectCollection(<?= $col['id'] ?>)">
                        <span style="display: flex; align-items: center; gap: 10px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <span class="material-icons-outlined" style="font-size: 18px; color: var(--primary); flex-shrink: 0;">folder</span>
                            <span style="overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($col['title']) ?></span>
                        </span>
                        <span class="material-icons-outlined" style="font-size: 16px; opacity: 0.4; flex-shrink: 0;">chevron_right</span>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <div id="collection-content-area" style="min-width: 0;">
            <div id="collection-no-selection" style="text-align: center; padding: 60px 20px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-muted); backdrop-filter: blur(10px);">
                <span class="material-icons-outlined" style="font-size: 48px; opacity: 0.3; margin-bottom: 12px; display: block;">folder_open</span>
                <div style="font-size: 15px; font-weight: 500;">Selecciona o crea una colección para ver sus cuentas asociadas</div>
            </div>

            <div id="collection-detail-view" style="display: none;">
                <div class="list-detail-header-card">
                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="material-icons-outlined" style="color: var(--primary); font-size: 22px; flex-shrink: 0;">folder</span>
                            <h3 id="selected-collection-title" style="font-size: 18px; margin: 0; color: var(--text-color); font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></h3>
                        </div>
                        <p id="selected-collection-desc" style="font-size: 13px; color: var(--text-muted); margin: 0; line-height: 1.4;"></p>
                    </div>
                    <button type="button" id="btn-delete-collection" class="btn-delete-item" title="Eliminar Colección" onclick="deleteSelectedCollection()" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; margin: 0; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; color: var(--error); cursor: pointer; flex-shrink: 0;">
                        <span class="material-icons-outlined" style="font-size: 18px;">delete</span>
                    </button>
                </div>

                <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; backdrop-filter: blur(10px);">
                    <h4 style="font-size: 13px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 14px; font-weight: 700; letter-spacing: 0.5px;">Cuentas Curadas</h4>
                    <div id="collection-accounts-container" style="display: flex; flex-direction: column; gap: 10px;">
                        <!-- Cuentas de la colección -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
