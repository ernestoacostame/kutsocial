<div id="tab-thread-view" style="display: none;">
    <!-- Back Header -->
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px; cursor: pointer; color: var(--text-muted); font-weight: 500;" onclick="goBackToFeed()">
        <span style="font-size: 18px;">←</span> <span>Volver a la línea de tiempo</span>
    </div>

    <div class="composer-card" style="padding: 10px 0;">
        <!-- Antecesores -->
        <div id="thread-ancestors"></div>

        <!-- Status Principal (Destacado) -->
        <div id="thread-main" style="border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); background: rgba(99, 102, 241, 0.03); margin: 10px 0;"></div>

        <!-- Respuestas (Descendientes) -->
        <div id="thread-descendants"></div>
    </div>
</div>
