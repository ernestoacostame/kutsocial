<div id="tab-profile-view" style="display: none;">
    <!-- Back Header -->
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px; cursor: pointer; color: var(--text-muted); font-weight: 500;" onclick="goBackToFeed()">
        <span style="font-size: 18px;">←</span> <span>Atrás</span>
    </div>

    <!-- Tarjeta de Perfil -->
    <div class="composer-card" style="padding: 0; overflow: hidden; position: relative;">
        <!-- Banner -->
        <div id="profile-view-header-bg" class="profile-header-banner" style="background-image: url('/assets/default-header.png');">
            <!-- Avatar overlapping -->
            <img id="profile-view-avatar" class="profile-view-avatar-img" src="/assets/default-avatar.png" alt="Avatar">
        </div>

        <!-- Datos del Perfil -->
        <div class="profile-info-container" style="padding: 20px;">
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: -10px; margin-bottom: 15px; position: relative;">
                <button id="profile-view-manage-lists-btn" class="btn-publish" style="background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color); font-size: 14px; padding: 6px 16px; border-radius: 20px; cursor: pointer; display: none; transition: all 0.2s;" onclick="openManageListsModal()">Organizar</button>
                <button id="profile-view-remove-follower-btn" class="btn-publish" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); color: var(--error); font-size: 14px; padding: 6px 16px; border-radius: 20px; cursor: pointer; display: none; transition: all 0.2s;">Eliminar seguidor</button>
                <button id="profile-view-action-btn" class="btn-publish" style="background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color); font-size: 14px; padding: 6px 16px; border-radius: 20px; cursor: pointer; transition: all 0.2s;">Editar perfil</button>
                
                <button id="profile-view-options-btn" class="btn-publish" style="background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color); font-size: 14px; padding: 6px 12px; border-radius: 20px; cursor: pointer; display: none; transition: all 0.2s; align-items: center; justify-content: center;" onclick="toggleProfileOptionsMenu(event)">
                    <span class="material-icons-outlined" style="font-size: 18px; vertical-align: middle;">more_horiz</span>
                </button>
                
                <div id="profile-options-dropdown" class="dropdown-menu" style="display: none; position: absolute; top: 38px; right: 0; background: #1e1f22; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); z-index: 100; min-width: 220px; padding: 5px 0;">
                    <button class="dropdown-item" onclick="handleProfileMute()" style="width: 100%; text-align: left; background: none; border: none; color: #fff; padding: 10px 15px; font-size: 13.5px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s;">
                        <span class="material-icons-outlined" style="font-size: 18px;" id="profile-mute-icon">volume_off</span>
                        <span id="profile-mute-text">Silenciar usuario</span>
                    </button>
                    <button class="dropdown-item danger" onclick="handleProfileBlock()" style="width: 100%; text-align: left; background: none; border: none; color: var(--error); padding: 10px 15px; font-size: 13.5px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s;">
                        <span class="material-icons-outlined" style="font-size: 18px;" id="profile-block-icon">block</span>
                        <span id="profile-block-text">Bloquear usuario</span>
                    </button>
                    <button class="dropdown-item danger" onclick="handleProfileDomainBlock()" style="width: 100%; text-align: left; background: none; border: none; color: var(--error); padding: 10px 15px; font-size: 13.5px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s; border-top: 1px solid rgba(255,255,255,0.05);" id="profile-domain-block-btn">
                        <span class="material-icons-outlined" style="font-size: 18px;">public_off</span>
                        <span id="profile-domain-block-text">Bloquear dominio</span>
                    </button>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <h2 style="font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                    <span id="profile-view-display-name">Cargando...</span>
                    <span id="profile-view-badge" style="color: var(--text-color); font-size: 16px;"></span>
                </h2>
                <div style="display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                    <span id="profile-view-handle" style="color: var(--text-muted); font-size: 14px;">@cargando</span>
                    <span id="profile-view-follows-you-badge" style="display: none; background: rgba(255,255,255,0.06); color: var(--text-muted); font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600;">Te sigue</span>
                    <span id="profile-view-mutual-badge" style="display: none; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--secondary); font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600; align-items: center; gap: 4px;">
                        <span class="material-icons-outlined" style="font-size: 12px; vertical-align: middle;">compare_arrows</span> Mutuo
                    </span>
                    <span style="color: var(--text-muted); cursor: pointer; font-size: 13px;" title="Información del perfil" onclick="showProfileTechnicalInfo(event)">ⓘ</span>
                </div>
            </div>

            <div id="profile-view-role-container" style="margin-bottom: 15px; display: none;">
                <span class="role-badge" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #f59e0b; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                    🛡️ <span id="profile-view-role-text">Owner</span>
                </span>
            </div>

            <div style="display: flex; gap: 24px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                <div onclick="loadUsersList('followers', activeProfileViewId)" style="cursor: pointer;">
                    <div id="profile-view-followers-count" class="stat-num">0</div>
                    <div class="stat-label" style="text-decoration: underline; text-decoration-style: dotted;">Seguidores</div>
                </div>
                <div onclick="loadUsersList('following', activeProfileViewId)" style="cursor: pointer;">
                    <div id="profile-view-following-count" class="stat-num">0</div>
                    <div class="stat-label" style="text-decoration: underline; text-decoration-style: dotted;">Siguiendo</div>
                </div>
                <div>
                    <div id="profile-view-posts-count" class="stat-num">0</div>
                    <div class="stat-label">Toots</div>
                </div>
                <div>
                    <div id="profile-view-joined" class="stat-num">-</div>
                    <div class="stat-label">Unido en</div>
                </div>
            </div>

            <div id="profile-view-bio" style="font-size: 15px; line-height: 1.6; color: var(--text-color); margin-bottom: 20px; word-break: break-word;"></div>

            <div id="profile-view-fields" class="profile-fields-grid" style="margin-top: 15px; margin-bottom: 20px;"></div>
        </div>

        <!-- Subpestañas: Actividad / Multimedia -->
        <div style="display: flex; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); background: rgba(0,0,0,0.1);">
            <button id="profile-subtab-activity" class="subtab-btn active" onclick="switchProfileSubtab('activity')">Actividad</button>
            <button id="profile-subtab-media" class="subtab-btn" onclick="switchProfileSubtab('media')">Multimedia</button>
            <button id="profile-subtab-favourites" class="subtab-btn" onclick="switchProfileSubtab('favourites')" style="display: none;">Favoritos</button>
        </div>

        <div id="profile-feed" class="feed-container" style="padding: 15px 0;"></div>
    </div>
</div>
