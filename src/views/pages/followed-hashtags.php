<div id="tab-followed-hashtags" style="display: none;" class="composer-card">
    <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
        <h2 style="font-size: 18px; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700;">
            <span class="material-icons-outlined" style="color: var(--primary);">tag</span> Hashtags Seguidos
        </h2>
        <div style="display: flex; gap: 8px; align-items: center;">
            <input type="text" id="hashtag-follow-input" placeholder="Ej: php" style="border: 1px solid var(--border-color); border-radius: 10px; padding: 7px 14px; background: rgba(0,0,0,0.25); color: var(--text-color); font-size: 14px; width: 160px; outline: none;">
            <button type="button" onclick="followHashtagFromInput()" class="btn-publish" style="width: auto; padding: 7px 16px; font-size: 13px; font-weight: 600; margin: 0; border-radius: 10px;">Seguir</button>
        </div>
    </div>
    <div id="followed-hashtags-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px;">
        <?php if (empty($userHashtags)): ?>
            <div style="grid-column: 1/-1; text-align:center; padding: 30px 20px; color: var(--text-muted);">No sigues ningún hashtag todavía.</div>
        <?php else: ?>
            <?php foreach ($userHashtags as $tag): ?>
                <div class="tag-follow-card">
                    <div class="tag-follow-info" onclick="viewHashtagTimeline('<?= htmlspecialchars($tag) ?>')">
                        <span class="tag-follow-name">#<?= htmlspecialchars($tag) ?></span>
                        <span class="tag-follow-sub">Ver publicaciones</span>
                    </div>
                    <button type="button" class="btn-unfollow-tag" onclick="unfollowHashtag('<?= htmlspecialchars($tag) ?>', this.parentElement)" title="Dejar de seguir hashtag">✕</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
