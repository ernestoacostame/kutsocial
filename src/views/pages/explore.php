<div id="tab-explore" class="explore-page">
    <div class="explore-header">
        <h2 class="explore-title"><?= __('nav_explore', 'Explorar') ?></h2>
        <form class="explore-search-form" onsubmit="event.preventDefault(); submitExploreSearch();">
            <span class="material-icons-outlined">search</span>
            <input type="text" id="explore-search-input" placeholder="<?= __('explore_search_placeholder', 'Buscar o pegar enlace...') ?>" autocomplete="off">
        </form>
        <div class="explore-tabs" role="tablist">
            <button type="button" class="explore-tab-btn active" data-explore-tab="posts" onclick="switchExploreTab('posts')"><?= __('explore_posts', 'Publicaciones') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="hashtags" onclick="switchExploreTab('hashtags')"><?= __('explore_hashtags', 'Hashtags') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="people" onclick="switchExploreTab('people')"><?= __('explore_people', 'Personas') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="news" onclick="switchExploreTab('news')"><?= __('explore_news', 'Noticias') ?></button>
        </div>
    </div>
    <div id="explore-content-container" class="explore-content">
        <div style="text-align:center; padding: 30px; color: var(--text-muted);"><?= __('loading', 'Cargando...') ?></div>
    </div>
</div>
