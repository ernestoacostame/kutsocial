<div id="tab-thread-view" style="display: none;">
    <!-- Back Header -->
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px; cursor: pointer; color: var(--text-muted); font-weight: 500;" onclick="goBackToFeed()">
        <span style="font-size: 18px;">←</span> <span>Volver a la línea de tiempo</span>
    </div>

    <div class="composer-card" style="padding: 20px; display: flex; flex-direction: column; gap: 14px;">
        <!-- Antecesores -->
        <div id="thread-ancestors" style="display: flex; flex-direction: column; gap: 14px;"></div>

        <!-- Status Principal (Destacado) -->
        <div id="thread-main" style="display: flex; flex-direction: column;"></div>

        <!-- Respuestas (Descendientes) -->
        <div id="thread-descendants" style="display: flex; flex-direction: column; gap: 14px;"></div>
    </div>
</div>
