<div id="tab-lists" style="display: none;" class="composer-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary);">list</span> Mis Listas
        </h2>
        <button onclick="showCreateListModal()" class="btn-publish" style="width: auto; padding: 6px 12px; font-size: 13px; display: inline-flex; align-items: center; margin: 0; box-shadow: none;">+ Nueva Lista</button>
    </div>
    <div id="lists-layout" style="display: grid; grid-template-columns: 200px 1fr; gap: 20px;">
        <div id="lists-sidebar" style="border-right: 1px solid var(--border-color); padding-right: 15px; display: flex; flex-direction: column; gap: 8px; min-height: 200px;">
            <!-- Se llena vía JS -->
        </div>
        <div id="list-content-area">
            <div id="list-no-selection" style="text-align: center; padding: 40px; color: var(--text-muted);">
                Selecciona o crea una lista para ver sus miembros y publicaciones.
            </div>
            <div id="list-detail-view" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed var(--border-color); padding-bottom: 10px;">
                    <h3 id="selected-list-title" style="font-size: 18px; margin: 0; color: var(--primary);"></h3>
                    <div style="display: flex; gap: 8px;">
                        <button id="btn-list-timeline" class="btn-publish" style="padding: 4px 10px; font-size: 12px; margin: 0; background: var(--primary);" onclick="loadListTimelineView()">Feed</button>
                        <button id="btn-list-members" class="btn-publish" style="padding: 4px 10px; font-size: 12px; margin: 0; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color);" onclick="loadListMembersView()">Miembros</button>
                        <button id="btn-delete-list" class="btn-publish" style="padding: 4px 10px; font-size: 12px; margin: 0; background: var(--error); border: none;" onclick="deleteSelectedList()">Eliminar</button>
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
