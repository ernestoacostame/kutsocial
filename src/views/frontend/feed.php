<div id="tab-feed">
    <style>
    #home-timeline-header.home-filter-bar {
        display: flex;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 12px 16px !important;
        margin: 0 !important;
        border: none !important;
        border-bottom: 1px solid rgba(255,255,255,0.08) !important;
        border-radius: 0 !important;
        background: transparent !important;
    }
    #home-timeline-header .home-filter-title {
        font-size: 16px !important;
        font-weight: 700 !important;
        color: var(--text-color, #fff) !important;
        margin: 0 !important;
    }
    #home-timeline-header .home-filter-btn {
        width: 34px !important;
        min-width: 34px !important;
        max-width: 34px !important;
        height: 34px !important;
        padding: 0 !important;
        margin: 0 !important;
        border-radius: 50% !important;
        border: 1px solid rgba(255,255,255,0.16) !important;
        background: rgba(255,255,255,0.06) !important;
        background-image: none !important;
        box-shadow: none !important;
        color: var(--text-color, #fff) !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
    }
    #home-timeline-header .home-filter-btn:hover {
        background: rgba(255,255,255,0.12) !important;
    }
    #home-timeline-header .home-filter-btn .material-icons-outlined {
        font-size: 18px !important;
        margin: 0 !important;
    }
    #home-timeline-header .home-filter-popover {
        position: absolute !important;
        right: 0 !important;
        top: 40px !important;
        width: 220px !important;
        background: #181b24 !important;
        border: 1px solid rgba(255,255,255,0.15) !important;
        border-radius: 12px !important;
        padding: 8px 12px !important;
        box-shadow: 0 12px 32px rgba(0,0,0,0.45) !important;
        z-index: 200 !important;
    }
    #home-timeline-header .home-filter-row {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 8px 0 !important;
        font-size: 13.5px !important;
        color: var(--text-color, #fff) !important;
        cursor: pointer !important;
        gap: 10px !important;
        margin: 0 !important;
    }
    #home-timeline-header .home-filter-row input[type="checkbox"] {
        width: 17px !important;
        height: 17px !important;
        accent-color: #858afa !important;
        cursor: pointer !important;
        margin: 0 !important;
    }
    </style>
    <!-- Cabecera Home con filtros rápidos -->
    <div id="home-timeline-header" class="home-filter-bar">
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
