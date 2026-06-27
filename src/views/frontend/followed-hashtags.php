<div id="tab-followed-hashtags" style="display: none;" class="composer-card">
    <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 18px; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary);">tag</span> Hashtags Seguidos
        </h2>
        <div style="display: flex; gap: 8px;">
            <input type="text" id="hashtag-follow-input" placeholder="Ej: php" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 4px 12px; background: rgba(0,0,0,0.2); color: var(--text-color); font-size: 14px; width: 140px;">
            <button onclick="followHashtagFromInput()" class="btn-publish" style="width: auto; padding: 4px 12px; font-size: 13px; margin: 0;">Seguir</button>
        </div>
    </div>
    <div id="followed-hashtags-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px;">
        <!-- Se llena vía JS -->
    </div>
</div>
