<div id="tab-feed">
    <!-- Cabecera Home con filtros rápidos -->
    <div id="home-timeline-header" class="home-filter-bar" style="display: none;">
        <span class="home-filter-title"><?= __('nav_home', 'Inicio') ?></span>
        <div style="position: relative;">
            <button type="button" id="home-filter-btn" class="home-filter-btn" onclick="toggleHomeFilterPopover(event)" title="<?= __('timeline_filters', 'Filtros de inicio') ?>">
                <span class="material-icons-outlined">tune</span>
            </button>
            <div id="home-filter-popover" class="home-filter-popover" style="display: none;" onclick="event.stopPropagation()">
                <label class="home-filter-row">
                    <span><?= __('filter_show_boosts', 'Mostrar impulsos') ?></span>
                    <input type="checkbox" id="filter-show-boosts" checked onchange="onHomeFilterChange()">
                </label>
                <label class="home-filter-row">
                    <span><?= __('filter_show_quotes', 'Mostrar citas') ?></span>
                    <input type="checkbox" id="filter-show-quotes" checked onchange="onHomeFilterChange()">
                </label>
                <label class="home-filter-row">
                    <span><?= __('filter_show_replies', 'Mostrar respuestas') ?></span>
                    <input type="checkbox" id="filter-show-replies" checked onchange="onHomeFilterChange()">
                </label>
            </div>
        </div>
    </div>

    <!-- Composer para publicar -->
    <?php include __DIR__ . '/composer.php'; ?>

    <!-- Listado de toots -->
    <div id="feed-container-wrapper" style="width: 100%;">
    </div>

    <!-- Botón flotante para volver arriba (Móvil) -->
    <button id="btn-scroll-to-top" class="scroll-to-top-btn" onclick="scrollToTopTimeline()" type="button" title="<?= __('timeline_scroll_to_top', 'Volver al principio') ?>">
        <span class="material-icons-outlined" style="font-size: 22px;">keyboard_arrow_up</span>
    </button>
</div>
