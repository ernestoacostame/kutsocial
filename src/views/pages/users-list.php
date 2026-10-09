<div id="tab-users-list" style="display: none;" class="composer-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 14px; margin-bottom: 20px;">
        <h2 id="users-list-title" style="font-size: 18px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary);">people</span> Usuarios
        </h2>
        <button type="button" onclick="goBackFromUsersList()" style="width: auto !important; padding: 6px 14px !important; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; margin: 0 !important; box-shadow: none !important; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); cursor: pointer;">
            ← Volver al Perfil
        </button>
    </div>
    <div id="users-list-container" style="display: flex; flex-direction: column; gap: 12px;">
        <!-- Se llena vía JS -->
    </div>
</div>
