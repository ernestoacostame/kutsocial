<style>
#tab-explore.explore-page {
    width: 100%;
    max-width: 680px;
    margin: 0 auto;
    background: var(--card-bg, #151722);
    border: 1px solid var(--border-color, rgba(255,255,255,0.1));
    border-radius: 14px;
    overflow: hidden;
}
#tab-explore .explore-header {
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
    border-radius: 0 !important;
    background: transparent !important;
}
#tab-explore .explore-title {
    margin: 0 !important;
    padding: 16px 16px 14px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    text-align: center !important;
    color: var(--text-color, #fff) !important;
}
#tab-explore .explore-search-form {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    margin: 0 16px 10px !important;
    padding: 9px 14px !important;
    border-radius: 8px !important;
    border: 1px solid rgba(255,255,255,0.22) !important;
    background: rgba(0,0,0,0.2) !important;
}
#tab-explore .explore-search-form .material-icons-outlined {
    font-size: 20px !important;
    color: var(--text-muted, #9ca3af) !important;
    flex-shrink: 0 !important;
    margin: 0 !important;
}
#tab-explore .explore-search-form input {
    flex: 1 1 auto !important;
    width: 100% !important;
    min-width: 0 !important;
    background: transparent !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    color: var(--text-color, #fff) !important;
    font-size: 14px !important;
    padding: 0 !important;
    margin: 0 !important;
}
#tab-explore .explore-tabs {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 0 !important;
    border-bottom: 1px solid rgba(255,255,255,0.1) !important;
    padding: 0 8px !important;
}
#tab-explore .explore-tab-btn {
    position: relative !important;
    width: 100% !important;
    background: transparent !important;
    background-image: none !important;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
    color: var(--text-muted, #9ca3af) !important;
    padding: 12px 6px !important;
    margin: 0 !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-align: center !important;
    transition: color 0.15s ease !important;
}
#tab-explore .explore-tab-btn:hover {
    color: var(--text-color, #fff) !important;
    background: transparent !important;
}
#tab-explore .explore-tab-btn.active {
    color: var(--text-color, #fff) !important;
}
#tab-explore .explore-tab-btn.active::after {
    content: "";
    position: absolute;
    left: 25%;
    right: 25%;
    bottom: 0;
    height: 3px;
    border-radius: 3px 3px 0 0;
    background: #858afa;
}
#tab-explore .explore-content {
    padding: 0 !important;
}
#tab-explore .explore-content .toot-card {
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    border-top: none !important;
    margin-bottom: 0 !important;
}
</style>
<div id="tab-explore" class="explore-page">
    <div class="explore-header">
        <h2 class="explore-title"><?= __('nav_explore', 'Explore') ?></h2>
        <form class="explore-search-form" onsubmit="event.preventDefault(); submitExploreSearch();">
            <span class="material-icons-outlined">search</span>
            <input type="text" id="explore-search-input" placeholder="<?= __('explore_search_placeholder', 'Search or paste link...') ?>" autocomplete="off">
        </form>
        <div class="explore-tabs" role="tablist">
            <button type="button" class="explore-tab-btn active" data-explore-tab="posts" onclick="switchExploreTab('posts')"><?= __('explore_posts', 'Posts') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="hashtags" onclick="switchExploreTab('hashtags')"><?= __('explore_hashtags', 'Hashtags') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="people" onclick="switchExploreTab('people')"><?= __('explore_people', 'People') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="news" onclick="switchExploreTab('news')"><?= __('explore_news', 'News') ?></button>
        </div>
    </div>
    <div id="explore-content-container" class="explore-content">
        <div style="text-align:center; padding: 30px; color: var(--text-muted);"><?= __('loading', 'Loading...') ?></div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        if (typeof initExplorePage === 'function') {
            var c = document.getElementById('explore-content-container');
            if (c && c.children.length === 1 && !c.querySelector('.toot-card, .explore-tag-row, .explore-news-hero')) {
                initExplorePage();
            }
        }
    }, 120);
});
</script>
