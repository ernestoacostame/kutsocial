<div id="tab-feed">
    <!-- Composer para publicar -->
    <?php include __DIR__ . '/composer.php'; ?>

    <!-- Píldora / Botón flotante para nuevos toots -->
    <div id="new-toots-pill-container" class="new-toots-pill-container" style="display: none;">
        <button id="btn-new-toots-pill" class="new-toots-pill" onclick="scrollToTopAndDismissPill()" type="button">
            <span class="material-icons-outlined" style="font-size: 18px;">arrow_upward</span>
            <span id="new-toots-pill-text"><?= __('timeline_new_toots_single', '1 nuevo toot ↑') ?></span>
        </button>
    </div>

    <!-- Listado de toots -->
    <div id="feed-container-wrapper" style="width: 100%;">
    </div>

    <!-- Botón flotante para volver arriba -->
    <button id="btn-scroll-to-top" class="scroll-to-top-btn" onclick="scrollToTopTimeline()" type="button" title="<?= __('timeline_scroll_to_top', 'Volver al principio') ?>">
        <span class="material-icons-outlined" style="font-size: 22px;">keyboard_arrow_up</span>
    </button>
</div>
