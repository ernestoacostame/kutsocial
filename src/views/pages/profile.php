<div id="tab-profile" class="composer-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 14px; margin-bottom: 20px; gap: 15px; flex-wrap: wrap;">
        <h2 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary);">tune</span> Ajustes de Cuenta & Datos
        </h2>
        <div class="pill-toggle-group" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px; border-radius: 10px; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); flex-shrink: 0;">
            <button type="button" id="btn-subtab-profile-edit" onclick="switchProfileSettingsTab('edit')" class="profile-subtab-btn active">
                <span>👤</span> <span>Perfil</span>
            </button>
            <button type="button" id="btn-subtab-profile-privacy" onclick="switchProfileSettingsTab('privacy')" class="profile-subtab-btn">
                <span>🔒</span> <span>Privacidad</span>
            </button>
            <button type="button" id="btn-subtab-profile-import" onclick="switchProfileSettingsTab('import')" class="profile-subtab-btn">
                <span>📥</span> <span>Importar & Exportar</span>
            </button>
        </div>
    </div>
    
    <form id="profile-form" enctype="multipart/form-data">
        <!-- Subtab 1: Perfil -->
        <div id="subtab-content-profile-edit">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Imagen de Portada (Header)</label>
                <input type="file" id="profile-header-input" name="header" accept="image/*">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Imagen de Avatar</label>
                <input type="file" id="profile-avatar-input" name="avatar" accept="image/*">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="profile-display-name">Nombre a Mostrar</label>
                <input type="text" id="profile-display-name" name="display_name" placeholder="Tu nombre" value="<?= htmlspecialchars($localUser['display_name'] ?? '') ?>">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="profile-note">Biografía (Texto plano)</label>
                <textarea id="profile-note" name="note" class="composer-textarea" style="border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; height: 100px;" placeholder="Cuéntale algo al Fediverso..."><?= htmlspecialchars($localUser['note'] ?? '') ?></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label for="profile-also-known-as">Migración de Cuenta (alsoKnownAs)</label>
                <input type="text" id="profile-also-known-as" name="also_known_as" placeholder="https://mastodon.social/users/tu_antiguo_usuario" value="<?= htmlspecialchars($localUser['also_known_as'] ?? '') ?>">
                <small style="display: block; color: var(--text-muted); font-size: 11.5px; margin-top: 4px;">
                    URL del perfil de tu cuenta anterior en Mastodon para permitir la migración automática de tus seguidores hacia KutSocial.
                </small>
            </div>
            
            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Campos Personalizados (Metadatos de Verificación)</h3>
            <div id="metadata-fields-container">
                <?php 
                $fields = json_decode($localUser['fields'] ?? '[]', true) ?: [];
                for ($i = 0; $i < 4; $i++): 
                    $f = $fields[$i] ?? ['name' => '', 'value' => '', 'verified_at' => null];
                ?>
                <div style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                    <input type="text" id="field-name-<?= $i ?>" name="fields_attributes[<?= $i ?>][name]" placeholder="Etiqueta" style="flex: 1;" value="<?= htmlspecialchars($f['name']) ?>">
                    <input type="text" id="field-value-<?= $i ?>" name="fields_attributes[<?= $i ?>][value]" placeholder="Valor" style="flex: 2;" value="<?= htmlspecialchars($f['value']) ?>">
                    <span id="field-verified-<?= $i ?>" style="font-size: 11.5px; font-weight: 600; width: 90px; text-align: right; color: var(--success, #10b981);">
                        <?= !empty($f['verified_at']) ? '✓ Verificado' : '' ?>
                    </span>
                </div>
                <?php endfor; ?>
            </div>
            
            <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 12px; line-height: 1.4; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 8px;">
                💡 <strong>Verificación:</strong> Si añades un enlace aquí (ej. <code>https://tuweb.com</code>), asegúrate de que tu página web tenga un enlace de vuelta a tu perfil local (<code id="profile-verification-url-preview"><?= htmlspecialchars($localUser['url'] ?? '') ?></code>) con el atributo <code>rel="me"</code> (ej: <code>&lt;a href="..." rel="me"&gt;Mi Perfil&lt;/a&gt;</code>) para que aparezca verificado en verde.
            </p>

            <button type="submit" style="margin-top: 15px;">Guardar Cambios de Perfil</button>
            <div id="profile-save-status" style="margin-top: 12px; font-size: 13.5px; text-align: center; font-weight: 600;"></div>
        </div>

        <!-- Subtab 2: Privacidad -->
        <div id="subtab-content-profile-privacy" style="display: none;">
            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 5px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Ajustes de Privacidad</h3>
            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 15px; border-radius: 10px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px;">
                    <input type="checkbox" id="profile-discoverable" name="discoverable" style="width: auto;" <?= ($localUser['discoverable'] ?? 0) ? 'checked' : '' ?>>
                    <span>Descubrible (Aparecer en el directorio de perfiles y sugerencias)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px;">
                    <input type="checkbox" id="profile-auto-accept" name="auto_accept" style="width: auto;" <?= !($localUser['locked'] ?? 1) ? 'checked' : '' ?>>
                    <span>Aceptar seguidores automáticamente (Si no se marca, se requerirá aprobación manual)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px;">
                    <input type="checkbox" id="profile-searchable" name="searchable" style="width: auto;" <?= ($localUser['searchable'] ?? 0) ? 'checked' : '' ?>>
                    <span>Permitir búsquedas internas (Permitir que otros busquen tu usuario y contenido)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px;">
                    <input type="checkbox" id="profile-indexable" name="indexable" style="width: auto;" <?= ($localUser['indexable'] ?? 0) ? 'checked' : '' ?>>
                    <span>Indexable por buscadores (Permitir que Google y otros indexen tu perfil)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px;">
                    <input type="checkbox" id="profile-show-source" name="show_source" style="width: auto;" <?= ($localUser['show_source'] ?? 1) ? 'checked' : '' ?>>
                    <span>Mostrar aplicación de origen (Mostrar la app cliente con la que publicas tus toots)</span>
                </label>
            </div>
            
            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 25px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Preferencias de Publicación</h3>
            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 15px; border-radius: 10px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px;">
                    <input type="checkbox" id="pref-warn-missing-alt" style="width: auto;">
                    <span>Avisar cuando se vaya a publicar una imagen sin descripción (texto alternativo/AltText)</span>
                </label>
            </div>
            
            <button type="submit" style="margin-top: 15px;">Guardar Preferencias de Privacidad</button>
            <div id="profile-privacy-save-status" style="margin-top: 12px; font-size: 13.5px; text-align: center; font-weight: 600;"></div>
        </div>
    </form>

    <!-- Subtab 3: Importar & Exportar -->
    <div id="subtab-content-profile-import" style="display: none;">
        <!-- Exportar -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <h4 style="font-size: 14px; margin-bottom: 6px; color: var(--text-color);">📤 Exportar tus Datos (Formato Mastodon)</h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                Descarga copias de seguridad de tus publicaciones, seguidos, seguidores, listas y configuración en formatos CSV o JSON estándar.
            </p>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px;">
                <button onclick="downloadExport('/api/v1/export/posts', 'posts.json')" style="margin: 0; padding: 10px; font-size: 13px;">📝 Exportar Posts (JSON)</button>
                <button onclick="downloadExport('/api/v1/export/follows', 'following.csv')" style="margin: 0; padding: 10px; font-size: 13px;">👥 Exportar Follows (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/followers', 'followers.csv')" style="margin: 0; padding: 10px; font-size: 13px;">👤 Exportar Followers (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/lists', 'lists.csv')" style="margin: 0; padding: 10px; font-size: 13px;">📋 Exportar Listas (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/mutes', 'mutes.csv')" style="margin: 0; padding: 10px; font-size: 13px;">🔇 Exportar Muteados (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/blocks', 'blocks.csv')" style="margin: 0; padding: 10px; font-size: 13px;">🚫 Exportar Bloqueados (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/domain_blocks', 'domain_blocks.csv')" style="margin: 0; padding: 10px; font-size: 13px;">🌐 Dominios Bloqueados (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/bookmarks', 'bookmarks.csv')" style="margin: 0; padding: 10px; font-size: 13px;">🔖 Exportar Marcadores (CSV)</button>
                <button onclick="downloadExport('/api/v1/export/filters', 'filters.json')" style="margin: 0; padding: 10px; font-size: 13px;">📝 Exportar Filtros (JSON)</button>
            </div>
        </div>

        <!-- Importar -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <h4 style="font-size: 14px; margin-bottom: 6px; color: var(--text-color);">📥 Importar Datos de Mastodon / KutSocial</h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                Sube tus archivos CSV exportados desde Mastodon u otra instancia de KutSocial para restaurar tus seguidos, seguidores, listas y filtros.
            </p>
            <form id="import-form" style="display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <label for="import-type" style="margin-bottom: 6px;">Tipo de Dato</label>
                        <select id="import-type" name="type" style="width:100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; color: white; font-family: inherit;">
                            <option value="follows" style="background:#161c26;">👥 Seguidos (CSV)</option>
                            <option value="followers" style="background:#161c26;">👤 Seguidores (CSV)</option>
                            <option value="lists" style="background:#161c26;">📋 Listas (CSV)</option>
                            <option value="mutes" style="background:#161c26;">🔇 Silenciados (CSV)</option>
                            <option value="blocks" style="background:#161c26;">🚫 Bloqueados (CSV)</option>
                            <option value="domain_blocks" style="background:#161c26;">🌐 Dominios Bloqueados (CSV)</option>
                            <option value="bookmarks" style="background:#161c26;">🔖 Marcadores (CSV)</option>
                            <option value="filters" style="background:#161c26;">📝 Filtros de Palabra (JSON)</option>
                        </select>
                    </div>
                    <div style="flex: 2; min-width: 250px;">
                        <label for="import-file" style="margin-bottom: 6px;">Archivo (CSV o JSON)</label>
                        <input type="file" id="import-file" name="file" required style="padding: 9px 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: white; border-radius: 10px; font-family: inherit; width: 100%;">
                    </div>
                    <button type="submit" style="width: auto; margin: 0; padding: 12px 24px; height: 44px;">Subir e Importar</button>
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-color); cursor: pointer; margin-top: 4px;">
                        <input type="checkbox" id="import-mutual" name="mutual" value="1" style="width: auto;">
                        <span>Establecer seguimiento mutuo automáticamente (garantiza que ambos usuarios se sigan mutuamente al importar)</span>
                    </label>
                </div>
            </form>
            <div id="import-status-msg" style="margin-top: 12px; font-size: 13.5px; font-weight: 600; text-align: center;"></div>
        </div>

        <!-- Cola de Procesamiento -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <h4 style="font-size: 13.5px; margin-bottom: 8px; color: var(--text-color);">⚡ Cola de Procesamiento</h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                Las importaciones masivas se procesan en segundo plano. Si quieres forzar la ejecución inmediata de las tareas pendientes de la cola, pulsa el botón:
            </p>
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="process-queue-btn" onclick="processQueueNow()" style="margin: 0; padding: 12px 24px; font-size: 13px;">⚡ Procesar Tareas Pendientes Ahora</button>
                <span id="queue-status-msg" style="font-size: 13px; font-weight: 600;"></span>
            </div>
        </div>

        <!-- Re-enviar Follows Pendientes -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px;">
            <h4 style="font-size: 13.5px; margin-bottom: 8px; color: var(--text-color);">🔄 Re-enviar Follows Pendientes</h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                Si reinstalaste tu instancia y algunos follows quedaron en "pendiente", usa este botón para re-enviar las solicitudes a los servidores remotos.
            </p>
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="resend-pending-btn" onclick="resendPendingFollows()" style="margin: 0; padding: 12px 24px; font-size: 13px;">🔄 Re-enviar Follows Pendientes</button>
                <span id="resend-status-msg" style="font-size: 13px; font-weight: 600;"></span>
            </div>
        </div>
    </div>
</div>
