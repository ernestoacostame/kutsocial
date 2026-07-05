<div id="tab-feed">
    <!-- Composer para publicar -->
    <div class="composer-card">
        <!-- Contexto: Respuesta / Edición -->
        <div id="composer-context" class="composer-context-bar" style="display: none;">
            <span id="composer-context-text">Respondiendo a ...</span>
            <button class="composer-context-close" onclick="cancelComposerContext()">✕</button>
        </div>

        <!-- Cabecera de selectores (Visibilidad, Idioma) -->
        <div class="composer-meta-selects">
            <select id="composer-visibility" class="composer-select-pill">
                <option value="public">🌐 Público</option>
                <option value="unlisted">🌙 Público silencioso</option>
                <option value="private">🔒 Solo seguidores</option>
                <option value="direct">✉️ Mensaje privado</option>
            </select>
            <select id="composer-language" class="composer-select-pill">
                <option value="es">🗣️ Español</option>
                <option value="en">🗣️ English</option>
            </select>
        </div>

        <!-- Input de Advertencia de Contenido (Content Warning) -->
        <div id="composer-cw-container" class="cw-input-container" style="display: none;">
            <input type="text" id="composer-cw-text" class="cw-textarea" style="height: 36px; padding: 6px 12px; margin-bottom: 8px;" placeholder="Escribe aquí la advertencia de contenido (CW)...">
        </div>

        <div class="composer-header" style="position: relative;">
            <img id="composer-avatar" class="user-avatar" src="<?= htmlspecialchars($localUser['avatar'] ?: '/assets/default-avatar.png') ?>" alt="Avatar">
            <textarea id="composer-text" class="composer-textarea" placeholder="¿Qué está pasando en el Fediverso?" maxlength="500" oninput="updateCharCount()"></textarea>
            <!-- Autocomplete dropdown suggestions -->
            <div id="composer-autocomplete" class="autocomplete-suggestions" style="display: none;"></div>
        </div>

        <!-- Previsualización de Imágenes Subidas -->
        <div id="composer-media-preview" style="display: flex; gap: 8px; flex-wrap: wrap; margin: 0 12px 8px 12px;"></div>
        <input type="file" id="composer-file-input" style="display: none;" accept="image/*" multiple onchange="handleComposerFileUpload()">

        <!-- Creador de Encuestas -->
        <div id="composer-poll-container" style="display: none; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 15px; border-radius: 10px; margin: 0 12px 12px 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 13.5px; font-weight: 600; color: var(--text-muted);">Encuesta</span>
                <button onclick="closeComposerPoll()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:14px;">✕</button>
            </div>
            <div id="poll-options-inputs" style="display: flex; flex-direction: column; gap: 8px;">
                <input type="text" class="poll-option-field" placeholder="Opción 1" maxlength="25" style="padding:8px;">
                <input type="text" class="poll-option-field" placeholder="Opción 2" maxlength="25" style="padding:8px;">
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                <button onclick="addPollOptionField()" style="font-size: 12.5px; border:none; background:none; color: #818cf8; cursor:pointer; font-weight:600;">➕ Añadir opción</button>
                <select id="poll-expires-in" style="width: 120px; padding: 4px; font-size: 12.5px; background:rgba(0,0,0,0.3); border:1px solid var(--border-color); color:white;">
                    <option value="3600">1 hora</option>
                    <option value="86400" selected>1 día</option>
                    <option value="604800">1 semana</option>
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
                <button class="composer-tool-btn" onclick="document.getElementById('composer-file-input').click()" title="Adjuntar multimedia">
                    <span class="material-icons-outlined">image</span>
                </button>
                <!-- Botón Crear Encuesta -->
                <button class="composer-tool-btn" onclick="toggleComposerPoll()" title="Crear encuesta">
                    <span class="material-icons-outlined">poll</span>
                </button>
                <!-- Botón Content Warning (CW) -->
                <button id="composer-cw-btn" class="composer-tool-btn" onclick="toggleComposerCW()" title="Advertencia de Contenido">
                    <span class="material-icons-outlined">warning_amber</span>
                </button>
                <!-- Botón Emojis -->
                <button id="emoji-picker-btn" class="composer-tool-btn" onclick="toggleEmojiPicker(event)" title="Insertar emoji">
                    <span class="material-icons-outlined">sentiment_satisfied_alt</span>
                </button>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <span id="char-count" class="char-counter">500</span>
                <button class="btn-publish" id="composer-submit-btn" onclick="publishToot()">Publicar</button>
            </div>
        </div>
    </div>

    <!-- Listado de toots -->
    <div class="feed-container" id="feed">
        <!-- Se inyectan dinámicamente -->
    </div>
</div>
