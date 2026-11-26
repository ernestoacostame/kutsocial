<div id="tab-profile" class="composer-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 14px; margin-bottom: 22px; gap: 15px; flex-wrap: wrap;">
        <h2 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary);">tune</span> <?= __('profile_settings_and_data') ?>
        </h2>
        <div class="pill-toggle-group" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px; border-radius: 10px; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); flex-shrink: 0;">
            <button type="button" id="btn-subtab-profile-edit" onclick="switchProfileSettingsTab('edit')" class="profile-subtab-btn active">
                <span>👤</span> <span><?= __('profile_tab_profile') ?></span>
            </button>
            <button type="button" id="btn-subtab-profile-privacy" onclick="switchProfileSettingsTab('privacy')" class="profile-subtab-btn">
                <span>🔒</span> <span><?= __('profile_tab_privacy') ?></span>
            </button>
            <button type="button" id="btn-subtab-profile-appearance" onclick="switchProfileSettingsTab('appearance')" class="profile-subtab-btn">
                <span>🎨</span> <span><?= __('profile_tab_appearance', 'Apariencia') ?></span>
            </button>
            <button type="button" id="btn-subtab-profile-import" onclick="switchProfileSettingsTab('import')" class="profile-subtab-btn">
                <span>📥</span> <span><?= __('profile_import_export') ?></span>
            </button>
        </div>
    </div>
    
    <form id="profile-form" enctype="multipart/form-data">
        <!-- Subtab 1: Perfil -->
        <div id="subtab-content-profile-edit">
            <div class="form-group" style="margin-bottom: 22px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_header') ?></label>
                <input type="file" id="profile-header-input" name="header" accept="image/*" style="padding: 10px 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 22px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_avatar') ?></label>
                <input type="file" id="profile-avatar-input" name="avatar" accept="image/*" style="padding: 10px 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 22px;">
                <label for="profile-display-name" style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_display_name') ?></label>
                <input type="text" id="profile-display-name" name="display_name" placeholder="<?= __('profile_display_name_placeholder') ?>" value="<?= htmlspecialchars($localUser['display_name'] ?? '') ?>" style="padding: 11px 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 22px;">
                <label for="profile-note" style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_note') ?></label>
                <textarea id="profile-note" name="note" class="composer-textarea" style="border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 14px; height: 100px;" placeholder="<?= __('profile_note_placeholder') ?>"><?= htmlspecialchars($localUser['note'] ?? '') ?></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 22px;">
                <label for="profile-also-known-as" style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_also_known_as') ?></label>
                <input type="text" id="profile-also-known-as" name="also_known_as" placeholder="https://mastodon.social/users/tu_antiguo_usuario" value="<?= htmlspecialchars($localUser['also_known_as'] ?? '') ?>" style="padding: 11px 14px;">
                <small style="display: block; color: var(--text-muted); font-size: 12px; margin-top: 8px; line-height: 1.5;">
                    <?= __('profile_also_known_as_desc') ?>
                </small>
            </div>
            
            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_fields') ?></h3>
            <div id="metadata-fields-container">
                <?php 
                $fields = json_decode($localUser['fields'] ?? '[]', true) ?: [];
                for ($i = 0; $i < 4; $i++): 
                    $f = $fields[$i] ?? ['name' => '', 'value' => '', 'verified_at' => null];
                ?>
                <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
                    <input type="text" id="field-name-<?= $i ?>" name="fields_attributes[<?= $i ?>][name]" placeholder="<?= __('profile_field_label') ?>" style="flex: 1; padding: 11px 14px;" value="<?= htmlspecialchars($f['name']) ?>">
                    <input type="text" id="field-value-<?= $i ?>" name="fields_attributes[<?= $i ?>][value]" placeholder="<?= __('profile_field_value') ?>" style="flex: 2; padding: 11px 14px;" value="<?= htmlspecialchars($f['value']) ?>">
                    <span id="field-verified-<?= $i ?>" style="font-size: 11.5px; font-weight: 600; width: 95px; text-align: right; color: var(--success, #10b981);">
                        <?= !empty($f['verified_at']) ? __('profile_verified_badge') : '' ?>
                    </span>
                </div>
                <?php endfor; ?>
            </div>
            
            <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 20px; margin-bottom: 24px; line-height: 1.5; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 14px 16px; border-radius: 8px;">
                <?= str_replace(':url', '<code id="profile-verification-url-preview">' . htmlspecialchars($localUser['url'] ?? '') . '</code>', __('profile_verification_tip')) ?>
            </p>

            <button type="submit" style="margin-top: 22px; padding: 12px 24px; font-size: 14px;"><?= __('profile_save_changes') ?></button>
            <div id="profile-save-status" style="margin-top: 14px; font-size: 13.5px; text-align: center; font-weight: 600;"></div>
        </div>

        <!-- Subtab 2: Privacidad -->
        <div id="subtab-content-profile-privacy" style="display: none;">
            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 5px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_privacy_settings') ?></h3>
            <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="profile-discoverable" name="discoverable" style="width: auto;" <?= ($localUser['discoverable'] ?? 0) ? 'checked' : '' ?>>
                    <span><?= __('profile_privacy_discoverable') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="profile-auto-accept" name="auto_accept" style="width: auto;" <?= !($localUser['locked'] ?? 1) ? 'checked' : '' ?>>
                    <span><?= __('profile_privacy_auto_accept') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="profile-searchable" name="searchable" style="width: auto;" <?= ($localUser['searchable'] ?? 0) ? 'checked' : '' ?>>
                    <span><?= __('profile_privacy_searchable') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="profile-indexable" name="indexable" style="width: auto;" <?= ($localUser['indexable'] ?? 0) ? 'checked' : '' ?>>
                    <span><?= __('profile_privacy_indexable') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="profile-show-source" name="show_source" style="width: auto;" <?= ($localUser['show_source'] ?? 1) ? 'checked' : '' ?>>
                    <span><?= __('profile_privacy_show_source') ?></span>
                </label>
            </div>
            
            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_publication_preferences') ?></h3>
            <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                <div>
                    <label for="pref-default-visibility" style="display: block; margin-bottom: 8px; font-size: 13.5px; font-weight: 600; color: var(--text-color); text-transform: none;"><?= __('pref_default_visibility', 'Visibilidad predeterminada al publicar') ?></label>
                    <select id="pref-default-visibility" style="max-width: 320px; width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: var(--text-color);">
                        <option value="public"><?= __('vis_public', 'Público') ?></option>
                        <option value="unlisted"><?= __('vis_unlisted', 'Sin listar') ?></option>
                        <option value="private"><?= __('vis_private', 'Solo seguidores') ?></option>
                        <option value="direct"><?= __('vis_direct', 'Directo (mencionados)') ?></option>
                    </select>
                </div>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="pref-warn-missing-alt" style="width: auto;">
                    <span><?= __('pref_warn_missing_alt_desc') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="pref-warn-delete" style="width: auto;">
                    <span><?= __('pref_warn_delete_desc', 'Advertir antes de eliminar una publicación') ?></span>
                </label>
            </div>

            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_timeline_preferences') ?></h3>
            <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="pref-auto-scroll-new" style="width: auto;">
                    <span><?= __('pref_auto_scroll_new_desc') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="pref-quick-boost" style="width: auto;">
                    <span><?= __('pref_quick_boost_desc', 'Habilitar impulso rápido (un clic en Re-toot impulsa al instante; Shift+Click siempre impulsa)') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="pref-expand-cw" style="width: auto;">
                    <span><?= __('pref_expand_cw_desc', 'Expandir siempre las publicaciones con advertencia de contenido (CW)') ?></span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; line-height: 1.4; margin: 0;">
                    <input type="checkbox" id="pref-show-stats" style="width: auto;">
                    <span><?= __('pref_show_stats_desc', 'Mostrar estadísticas de interacción (contadores) en los feeds') ?></span>
                </label>
                <div style="margin-top: 4px;">
                    <label for="pref-media-display" style="display: block; margin-bottom: 8px; font-size: 13.5px; font-weight: 600; color: var(--text-color); text-transform: none;"><?= __('pref_media_display', 'Visualización de medios sensibles') ?></label>
                    <select id="pref-media-display" style="max-width: 340px; width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: var(--text-color);">
                        <option value="warn_sensitive"><?= __('pref_media_warn_sensitive', 'Advertir antes de mostrar medios marcados como sensibles') ?></option>
                        <option value="warn_all"><?= __('pref_media_warn_all', 'Advertir antes de mostrar todos los medios') ?></option>
                        <option value="show_all"><?= __('pref_media_show_all', 'Mostrar todos los medios sin advertencia') ?></option>
                    </select>
                </div>
            </div>

            <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 28px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('profile_interface_language') ?></h3>
            <div style="margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
                <label for="select-profile-language-spa" style="display: block; margin-bottom: 10px; font-size: 14px; color: var(--text-color); text-transform: none;"><?= __('profile_interface_language_desc') ?></label>
                <select id="select-profile-language-spa" onchange="window.location.href='/lang/' + this.value" style="max-width: 280px; width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: white;">
                    <option value="es" <?= \KutSocial\I18n::getLang() === 'es' ? 'selected' : '' ?>><?= __('spanish') ?> (Spanish)</option>
                    <option value="en" <?= \KutSocial\I18n::getLang() === 'en' ? 'selected' : '' ?>><?= __('english') ?> (English)</option>
                </select>
            </div>
            
            <button type="submit" style="margin-top: 22px; padding: 12px 24px; font-size: 14px;"><?= __('profile_save_privacy') ?></button>
            <div id="profile-privacy-save-status" style="margin-top: 14px; font-size: 13.5px; text-align: center; font-weight: 600;"></div>
        </div>
    </form>

    <!-- Subtab 3: Apariencia -->
    <div id="subtab-content-profile-appearance" style="display: none;">
        <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 5px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('appearance_theme_and_colors', 'Tema y colores') ?></h3>
        <div style="display: flex; flex-direction: column; gap: 18px; margin-bottom: 24px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
            <div>
                <label style="display: block; font-size: 13.5px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;"><?= __('appearance_mode', 'Modo') ?></label>
                <div style="display: flex; gap: 18px; flex-wrap: wrap;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_mode" value="auto" style="width: auto;"> <?= __('appearance_auto', 'Auto') ?></label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_mode" value="light" style="width: auto;"> <?= __('appearance_light', 'Claro') ?></label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_mode" value="dark" style="width: auto;"> <?= __('appearance_dark', 'Oscuro') ?></label>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 13.5px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;"><?= __('appearance_accent_color', 'Color de acento') ?></label>
                <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_accent" value="indigo" style="width: auto;"><span style="width: 12px; height: 12px; border-radius: 50%; background: #6366f1; display: inline-block;"></span> Indigo</label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_accent" value="lime" style="width: auto;"><span style="width: 12px; height: 12px; border-radius: 50%; background: #84cc16; display: inline-block;"></span> Lime</label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_accent" value="pink" style="width: auto;"><span style="width: 12px; height: 12px; border-radius: 50%; background: #ec4899; display: inline-block;"></span> Pink</label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_accent" value="orange" style="width: auto;"><span style="width: 12px; height: 12px; border-radius: 50%; background: #f97316; display: inline-block;"></span> Orange</label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_accent" value="cyan" style="width: auto;"><span style="width: 12px; height: 12px; border-radius: 50%; background: #06b6d4; display: inline-block;"></span> Cyan</label>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 13.5px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;"><?= __('appearance_contrast', 'Contraste') ?></label>
                <div style="display: flex; gap: 18px; flex-wrap: wrap;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_contrast" value="auto" style="width: auto;"> <?= __('appearance_auto', 'Auto') ?></label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_contrast" value="high" style="width: auto;"> <?= __('appearance_high_contrast', 'Alto contraste') ?></label>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 13.5px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;"><?= __('appearance_emoji_style', 'Estilo de emojis') ?></label>
                <div style="display: flex; gap: 18px; flex-wrap: wrap;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_emoji_style" value="auto" style="width: auto;"> <?= __('appearance_auto', 'Auto') ?></label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_emoji_style" value="native" style="width: auto;"> <?= __('appearance_emoji_native', 'Nativo') ?></label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; margin: 0;"><input type="radio" name="appearance_emoji_style" value="twemoji" style="width: auto;"> Twemoji</label>
                </div>
            </div>

            <div>
                <label for="appearance-timezone-select" style="display: block; font-size: 13.5px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;"><?= __('appearance_timezone', 'Zona horaria') ?></label>
                <select id="appearance-timezone-select" style="max-width: 340px; width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: var(--text-color);">
                    <option value="auto"><?= __('appearance_timezone_auto', 'Automática (navegador)') ?></option>
                </select>
            </div>
        </div>

        <h3 style="font-size: 13.5px; color: var(--text-muted); margin-top: 22px; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.5px;"><?= __('appearance_animations_a11y', 'Animaciones y accesibilidad') ?></h3>
        <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: 10px;">
            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; margin: 0;">
                <input type="checkbox" id="pref-reduce-motion" style="width: auto;">
                <span><?= __('pref_reduce_motion', 'Reducir movimiento en animaciones') ?></span>
            </label>
            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; margin: 0;">
                <input type="checkbox" id="pref-system-font" style="width: auto;">
                <span><?= __('pref_system_font', 'Usar fuente predeterminada del sistema') ?></span>
            </label>
            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; text-transform: none; font-weight: normal; color: var(--text-color); font-size: 14px; margin: 0;">
                <input type="checkbox" id="pref-system-scrollbar" style="width: auto;">
                <span><?= __('pref_system_scrollbar', 'Usar barra de desplazamiento predeterminada del sistema') ?></span>
            </label>
        </div>
        <p style="font-size: 12.5px; color: var(--text-muted);"><?= __('appearance_saved_auto', 'Los cambios de apariencia se aplican y guardan automáticamente.') ?></p>
    </div>

    <!-- Subtab 4: Importar & Exportar -->
    <div id="subtab-content-profile-import" style="display: none;">
        <!-- Exportar -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <h4 style="font-size: 14px; margin-bottom: 6px; color: var(--text-color);">📤 <?= __('profile_export_title') ?></h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                <?= __('profile_export_desc') ?>
            </p>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px;">
                <button onclick="downloadExport('/api/v1/export/posts', 'posts.json')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_posts') ?></button>
                <button onclick="downloadExport('/api/v1/export/follows', 'following.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_follows') ?></button>
                <button onclick="downloadExport('/api/v1/export/followers', 'followers.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_followers') ?></button>
                <button onclick="downloadExport('/api/v1/export/lists', 'lists.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_lists') ?></button>
                <button onclick="downloadExport('/api/v1/export/mutes', 'mutes.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_mutes') ?></button>
                <button onclick="downloadExport('/api/v1/export/blocks', 'blocks.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_blocks') ?></button>
                <button onclick="downloadExport('/api/v1/export/domain_blocks', 'domain_blocks.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_domain_blocks') ?></button>
                <button onclick="downloadExport('/api/v1/export/bookmarks', 'bookmarks.csv')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_bookmarks') ?></button>
                <button onclick="downloadExport('/api/v1/export/filters', 'filters.json')" style="margin: 0; padding: 10px; font-size: 13px;"><?= __('profile_export_filters') ?></button>
            </div>
        </div>

        <!-- Importar -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <h4 style="font-size: 14px; margin-bottom: 6px; color: var(--text-color);">📥 <?= __('profile_import_title') ?></h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                <?= __('profile_import_desc') ?>
            </p>
            <form id="import-form" style="display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <label for="import-type" style="margin-bottom: 6px;"><?= __('profile_import_data_type') ?></label>
                        <select id="import-type" name="type" style="width:100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; color: white; font-family: inherit;">
                            <option value="follows" style="background:#161c26;"><?= __('profile_import_opt_follows') ?></option>
                            <option value="lists" style="background:#161c26;"><?= __('profile_import_opt_lists') ?></option>
                            <option value="mutes" style="background:#161c26;"><?= __('profile_import_opt_mutes') ?></option>
                            <option value="blocks" style="background:#161c26;"><?= __('profile_import_opt_blocks') ?></option>
                            <option value="domain_blocks" style="background:#161c26;"><?= __('profile_import_opt_domain_blocks') ?></option>
                            <option value="bookmarks" style="background:#161c26;"><?= __('profile_import_opt_bookmarks') ?></option>
                            <option value="filters" style="background:#161c26;"><?= __('profile_import_opt_filters') ?></option>
                        </select>
                    </div>
                    <div style="flex: 2; min-width: 250px;">
                        <label for="import-file" style="margin-bottom: 6px;"><?= __('profile_import_file_label_full') ?></label>
                        <input type="file" id="import-file" name="file" required style="padding: 9px 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: white; border-radius: 10px; font-family: inherit; width: 100%;">
                    </div>
                    <button type="submit" style="width: auto; margin: 0; padding: 12px 24px; height: 44px;"><?= __('profile_import_submit_btn') ?></button>
                </div>
            </form>
            <div id="import-status-msg" style="margin-top: 12px; font-size: 13.5px; font-weight: 600; text-align: center;"></div>
        </div>

        <!-- Cola de Procesamiento -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <h4 style="font-size: 13.5px; margin-bottom: 8px; color: var(--text-color);">⚡ <?= __('profile_queue_title') ?></h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                <?= __('profile_queue_desc') ?>
            </p>
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="process-queue-btn" onclick="processQueueNow()" style="margin: 0; padding: 12px 24px; font-size: 13px;"><?= __('profile_queue_btn') ?></button>
                <span id="queue-status-msg" style="font-size: 13px; font-weight: 600;"></span>
            </div>
        </div>

        <!-- Re-enviar Follows Pendientes -->
        <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px;">
            <h4 style="font-size: 13.5px; margin-bottom: 8px; color: var(--text-color);">🔄 <?= __('profile_resend_title') ?></h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 15px;">
                <?= __('profile_resend_desc') ?>
            </p>
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="resend-pending-btn" onclick="resendPendingFollows()" style="margin: 0; padding: 12px 24px; font-size: 13px;"><?= __('profile_resend_btn') ?></button>
                <span id="resend-status-msg" style="font-size: 13px; font-weight: 600;"></span>
            </div>
        </div>
    </div>
</div>
