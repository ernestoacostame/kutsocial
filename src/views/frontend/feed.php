<div id="tab-feed">
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
