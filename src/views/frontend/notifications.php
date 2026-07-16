<div id="tab-notifications" style="display: none;" class="composer-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 18px;">🔔 Notificaciones</h2>
        <button id="btn-clear-notifications" onclick="clearAllNotifications()" style="display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--secondary); border-radius: 50%; cursor: pointer; transition: all 0.2s;" title="Marcar todas como leídas">
            <span class="material-icons-outlined" style="font-size: 18px;">done_all</span>
        </button>
    </div>
    <div id="notifications-list" style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px;">
        <!-- Inyectadas dinámicamente -->
    </div>
</div>
