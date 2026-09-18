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
        <?php if (empty($userHashtags)): ?>
            <div style="grid-column: 1/-1; text-align:center; padding: 20px; color: var(--text-muted);">No sigues ningún hashtag todavía.</div>
        <?php else: ?>
            <?php foreach ($userHashtags as $tag): ?>
                <div class="tag-follow-card" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 15px;">
                    <div style="cursor:pointer; display:flex; flex-direction:column; min-width:0; flex:1; margin-right:8px;" onclick="viewHashtagTimeline('<?= htmlspecialchars($tag) ?>')">
                        <span style="font-weight:700; color:var(--text-color); font-size:15px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">#<?= htmlspecialchars($tag) ?></span>
                        <span style="font-size:11px; color:var(--text-muted); margin-top:2px;">Ver publicaciones</span>
                    </div>
                    <button class="btn-publish" style="width:auto; white-space:nowrap; flex-shrink:0; padding: 4px 10px; font-size: 11px; margin: 0; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color);" onclick="unfollowHashtag('<?= htmlspecialchars($tag) ?>', this.parentElement)">Dejar de seguir</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
