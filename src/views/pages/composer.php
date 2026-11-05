<!-- Composer para publicar / responder -->
<div class="composer-card">
    <!-- Contexto: Respuesta / Edición -->
    <div id="composer-context" class="composer-context-bar" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <span class="material-icons-outlined" id="composer-context-icon" style="font-size: 16px; color: var(--primary);">reply</span>
                <span id="composer-context-text" style="font-weight: 500;">Respondiendo a:</span>
            </div>
            <button class="composer-context-close" onclick="cancelComposerContext()" title="Cancelar">✕</button>
        </div>
        <!-- Participantes de la respuesta (chips interactivos) -->
        <div id="composer-reply-participants" class="reply-participants-wrapper" style="display: none;"></div>
    </div>

    <!-- Input de Advertencia de Contenido (Content Warning) -->
    <div id="composer-cw-container" class="cw-input-container" style="display: none;">
        <input type="text" id="composer-cw-text" class="cw-textarea" style="height: 36px; padding: 6px 12px; margin-bottom: 8px;" placeholder="<?= __('composer_cw_placeholder') ?>">
    </div>

    <div class="composer-header" style="position: relative;">
        <img id="composer-avatar" class="user-avatar" src="<?= htmlspecialchars($localUser['avatar'] ?: '/assets/default-avatar.png') ?>" alt="Avatar">
        <textarea id="composer-text" class="composer-textarea" placeholder="<?= __('composer_placeholder') ?>" maxlength="500" oninput="updateCharCount()"></textarea>
        <!-- Autocomplete dropdown suggestions -->
        <div id="composer-autocomplete" class="autocomplete-suggestions" style="display: none;"></div>
    </div>

    <!-- Previsualización de Imágenes Subidas -->
    <div id="composer-media-preview" style="display: flex; gap: 8px; flex-wrap: wrap; margin: 0 12px 8px 12px;"></div>
    <input type="file" id="composer-file-input" style="display: none;" accept="image/*,video/*,audio/*" multiple onchange="handleComposerFileUpload()">

    <!-- Creador de Encuestas -->
    <div id="composer-poll-container" style="display: none; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 15px; border-radius: 10px; margin: 0 12px 12px 12px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span style="font-size: 13.5px; font-weight: 600; color: var(--text-muted);"><?= __('composer_poll') ?></span>
            <span onclick="closeComposerPoll()" style="color:var(--text-muted); cursor:pointer; font-size:14px; user-select:none; padding: 2px 6px;">✕</span>
        </div>
        <div id="poll-options-inputs" style="display: flex; flex-direction: column; gap: 8px;">
            <input type="text" class="poll-option-field" placeholder="<?= __('composer_poll_option') ?> 1" maxlength="25" style="padding:8px;">
            <input type="text" class="poll-option-field" placeholder="<?= __('composer_poll_option') ?> 2" maxlength="25" style="padding:8px;">
        </div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
            <span onclick="addPollOptionField()" style="font-size: 12.5px; color: #818cf8; cursor:pointer; font-weight:600; user-select:none; padding: 4px 0;">➕ <?= __('composer_poll_add') ?></span>
            <select id="poll-expires-in" style="width: 120px; height: 32px; padding: 4px 8px; font-size: 12.5px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 6px; color: white; cursor: pointer; outline: none;">
                <option value="3600">1 <?= \KutSocial\I18n::getLang() === 'en' ? 'hour' : 'hora' ?></option>
                <option value="86400" selected>1 <?= \KutSocial\I18n::getLang() === 'en' ? 'day' : 'día' ?></option>
                <option value="604800">1 <?= \KutSocial\I18n::getLang() === 'en' ? 'week' : 'semana' ?></option>
            </select>
        </div>
    </div>

    <!-- Selector Flotante de Emojis -->
    <div id="emoji-picker-container" style="display: none; position: absolute; background: #161c26; border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; width: 230px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); z-index: 1000;">
        <div id="emoji-picker-grid" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; max-height: 120px; overflow-y: auto;">
            <!-- Emojis inyectados por JS -->
        </div>
    </div>

    <div class="composer-toolbar">
        <div class="composer-tools">
            <!-- Botón Subir Imagen / Multimedia -->
            <button class="composer-tool-btn" onclick="document.getElementById('composer-file-input').click()" title="<?= __('composer_attach_media') ?>">
                <span class="material-icons-outlined">image</span>
            </button>
            <!-- Botón Crear Encuesta -->
            <button class="composer-tool-btn" onclick="toggleComposerPoll()" title="<?= __('composer_poll') ?>">
                <span class="material-icons-outlined">poll</span>
            </button>
            <!-- Botón Content Warning (CW) -->
            <button id="composer-cw-btn" class="composer-tool-btn" onclick="toggleComposerCW()" title="<?= __('composer_cw') ?>">
                <span class="material-icons-outlined">warning_amber</span>
            </button>
            <!-- Botón Emojis -->
            <button id="emoji-picker-btn" class="composer-tool-btn" onclick="toggleEmojiPicker(event)" title="<?= __('composer_emoji') ?>">
                <span class="material-icons-outlined">sentiment_satisfied_alt</span>
            </button>
            <!-- Botón GIF -->
            <button id="gif-picker-btn" class="composer-tool-btn" onclick="openGifModal()" title="GIF">
                <span class="material-icons-outlined" style="font-size: 26px;">gif_box</span>
            </button>
            <!-- Selector de Visibilidad (Público) -->
            <div class="composer-select-wrapper" title="<?= __('composer_visibility_public') ?>">
                <select id="composer-visibility">
                    <option value="public">🌐 <?= __('composer_visibility_public') ?></option>
                    <option value="unlisted">🌙 <?= __('composer_visibility_unlisted') ?></option>
                    <option value="private">🔒 <?= __('composer_visibility_private') ?></option>
                    <option value="direct">✉️ <?= __('composer_visibility_direct') ?></option>
                </select>
                <span class="material-icons-outlined" id="composer-visibility-icon">public</span>
            </div>
            <!-- Selector de Idioma -->
            <div class="composer-select-wrapper" title="<?= __('language') ?>">
                <select id="composer-language">
                    <option value="es">🇪🇸 <?= __('spanish') ?></option>
                    <option value="en">🇺🇸 <?= __('english') ?></option>
                </select>
                <span class="material-icons-outlined">translate</span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <span id="char-count" class="char-counter">500</span>
            <button class="btn-publish" id="composer-submit-btn" onclick="publishToot()"><?= __('composer_publish') ?></button>
        </div>
    </div>
</div>
