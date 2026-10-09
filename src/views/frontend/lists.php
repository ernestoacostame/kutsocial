<div id="tab-lists" style="display: none;" class="lists-view-container">
    <div class="lists-header-bar">
        <h2 style="font-size: 18px; margin: 0; display: flex; align-items: center; gap: 10px; font-weight: 700;">
            <span class="material-icons-outlined" style="color: var(--primary); font-size: 24px;">format_list_bulleted</span> Mis Listas
        </h2>
        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" onclick="showImportListsModal()" class="btn-publish" style="width: auto; padding: 7px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; margin: 0; box-shadow: none; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color); cursor: pointer; border-radius: 10px;">
                <span class="material-icons-outlined" style="font-size: 16px;">upload_file</span> Importar
            </button>
            <button type="button" onclick="showCreateListModal()" class="btn-publish" style="width: auto; padding: 7px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; margin: 0; box-shadow: none; border-radius: 10px;">
                <span class="material-icons-outlined" style="font-size: 16px;">add</span> Nueva Lista
            </button>
        </div>
    </div>

    <div class="lists-layout-grid">
        <aside class="lists-sidebar-panel" id="lists-sidebar">
            <?php if (empty($userLists)): ?>
                <div style="font-size: 13px; color: var(--text-muted); padding: 25px 10px; text-align: center;">No tienes listas creadas ni importadas.</div>
            <?php else: ?>
                <?php foreach ($userLists as $lst): ?>
                    <button type="button" class="list-nav-btn" data-list-id="<?= $lst['id'] ?>" onclick="selectList(<?= $lst['id'] ?>)">
                        <span style="display: flex; align-items: center; gap: 10px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <span class="material-icons-outlined" style="font-size: 18px; color: var(--primary); flex-shrink: 0;">list</span>
                            <span style="overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($lst['title']) ?></span>
                        </span>
                        <span class="material-icons-outlined" style="font-size: 16px; opacity: 0.4; flex-shrink: 0;">chevron_right</span>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <div id="list-content-area" style="min-width: 0;">
            <div id="list-no-selection" style="text-align: center; padding: 60px 20px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-muted); backdrop-filter: blur(10px);">
                <span class="material-icons-outlined" style="font-size: 48px; opacity: 0.3; margin-bottom: 12px; display: block;">view_timeline</span>
                <div style="font-size: 15px; font-weight: 500;">Selecciona una lista para ver sus publicaciones y miembros</div>
            </div>

            <div id="list-detail-view" style="display: none;">
                <div class="list-detail-header-card">
                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                        <span class="material-icons-outlined" style="color: var(--primary); font-size: 22px; flex-shrink: 0;">format_list_bulleted</span>
                        <h3 id="selected-list-title" style="font-size: 18px; margin: 0; color: var(--text-color); font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></h3>
                    </div>
                    
                    <div style="display: flex; gap: 10px; align-items: center; flex-shrink: 0;">
                        <div class="pill-toggle-group">
                            <button type="button" id="btn-list-timeline" class="pill-toggle-btn active" onclick="loadListTimelineView()">Feed</button>
                            <button type="button" id="btn-list-members" class="pill-toggle-btn" onclick="loadListMembersView()">Miembros</button>
                        </div>
                        <button type="button" id="btn-delete-list" class="btn-delete-item" title="Eliminar Lista" onclick="deleteSelectedList()" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; margin: 0; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; color: var(--error); cursor: pointer;">
                            <span class="material-icons-outlined" style="font-size: 18px;">delete</span>
                        </button>
                    </div>
                </div>

                <div id="list-members-container" style="display: none; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                    <!-- Miembros de la lista -->
                </div>

                <div id="list-feed-container" class="feed-container">
                    <!-- Feed de la lista -->
                </div>
            </div>
        </div>
    </div>
</div>
