<div id="tab-catchup" style="display: none;" class="composer-card">
    <!-- Cabecera -->
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="color: var(--primary); font-size: 22px;">bolt</span> Ponerse al día
            <span style="font-size: 10px; background: rgba(99, 102, 241, 0.2); color: var(--primary); padding: 2px 8px; border-radius: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Beta</span>
        </h2>
        <a href="#" onclick="toggleCatchUpHelp(event)" id="catchup-help-toggle" style="font-size: 13.5px; color: var(--primary); text-decoration: none; font-weight: 500; transition: color 0.2s;" onmouseover="this.style.color='var(--primary-hover)'" onmouseout="this.style.color='var(--primary)'">¿De qué se trata?</a>
    </div>

    <!-- Panel de Ayuda explicativo -->
    <div id="catchup-help" style="display: none; background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.15); border-radius: 12px; padding: 16px; margin-bottom: 22px; font-size: 13.5px; line-height: 1.5; color: var(--text-color);">
        <p style="margin-bottom: 8px; font-weight: 600; color: #818cf8; display: flex; align-items: center; gap: 5px;">
            <span class="material-icons-outlined" style="font-size: 16px;">info</span> ¿Cómo funciona?
        </p>
        <p style="color: var(--text-muted); margin-bottom: 8px;">
            Esta función está inspirada en Phanpy. Te permite seleccionar un rango de tiempo específico y "ponerte al día" con las publicaciones de los usuarios que sigues de manera estructurada y resumida.
        </p>
        <p style="color: var(--text-muted);">
            Podrás ver un histograma de actividad, filtrar instantáneamente por posts originales, respuestas o impulsos (boosts), visualizar a los autores más activos e interactuar con el feed de forma cronológica.
        </p>
    </div>

    <!-- Pantalla de Configuración Inicial (Slider de Horas) -->
    <div id="catchup-setup-view" style="text-align: center; padding: 40px 10px;">
        <h3 style="font-size: 22px; font-weight: 600; margin-bottom: 12px; letter-spacing: -0.3px;">Pongámonos al día con las cuentas que sigues</h3>
        <p style="color: var(--text-muted); font-size: 14.5px; margin-bottom: 35px;">Selecciona el intervalo de tiempo que quieres revisar:</p>

        <!-- Contenedor del Deslizador -->
        <div style="background: var(--card-bg); backdrop-filter: blur(15px); border: 1px solid var(--border-color); border-radius: 20px; padding: 30px; max-width: 500px; margin: 0 auto 35px auto; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);">
            <div style="margin-bottom: 25px; position: relative;">
                <input type="range" id="catchup-range-slider" min="0" max="8" value="3" class="catchup-slider-input" style="width: 100%; height: 6px; border-radius: 3px; outline: none; cursor: pointer;" oninput="updateCatchUpSliderLabel()">
                <!-- Marcas de ticks -->
                <div style="display: flex; justify-content: space-between; padding: 0 8px; margin-top: 8px; font-size: 11px; color: var(--text-muted); font-weight: 500;">
                    <span>1h</span>
                    <span>2h</span>
                    <span>4h</span>
                    <span>6h</span>
                    <span>12h</span>
                    <span>18h</span>
                    <span>24h</span>
                    <span>48h</span>
                    <span>72h</span>
                </div>
            </div>
            
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255,255,255,0.03); border-radius: 12px; padding: 15px; margin-bottom: 30px;">
                <div id="catchup-slider-text" style="font-size: 18px; font-weight: 600; color: var(--text-color);">las últimas 6 horas</div>
                <div id="catchup-slider-date" style="font-size: 12.5px; color: var(--text-muted); margin-top: 5px; font-family: monospace;">-</div>
            </div>

            <button onclick="startCatchUp()" class="btn-catchup-primary" style="background: var(--primary); color: white; border: none; padding: 12px 30px; border-radius: 24px; font-weight: 600; cursor: pointer; font-size: 15px; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); display: inline-flex; align-items: center; gap: 8px; justify-content: center; width: 100%;">
                <span class="material-icons-outlined" style="font-size: 18px;">bolt</span> Ponerse al día
            </button>
        </div>

        <div style="font-size: 12.5px; color: var(--text-muted); max-width: 520px; margin: 0 auto; line-height: 1.5;">
            <p>
                Nota: Esta búsqueda procesa las publicaciones de tu feed de Inicio almacenadas localmente. El número máximo de publicaciones cargadas es de 1000.
            </p>
        </div>
    </div>

    <!-- Pantalla de Resultados Activa -->
    <div id="catchup-active-view" style="display: none;">
        <!-- Cabecera de Intervalo de Tiempo Activo -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="material-icons-outlined" style="color: var(--secondary); font-size: 18px;">date_range</span>
                <span id="catchup-active-time-range" style="font-size: 14.5px; font-weight: 600; color: var(--text-color); font-family: inherit;">-</span>
            </div>
            <button onclick="backToCatchUpSetup()" class="btn-catchup-secondary" style="background: rgba(255,255,255,0.03); color: var(--text-color); border: 1px solid var(--border-color); padding: 7px 15px; border-radius: 16px; font-size: 12.5px; cursor: pointer; display: flex; align-items: center; gap: 6px; font-weight: 500; transition: all 0.2s;">
                <span class="material-icons-outlined" style="font-size: 15px;">arrow_back</span> Cambiar intervalo
            </button>
        </div>

        <!-- Histograma de Actividad (Gráfico) -->
        <div id="catchup-chart-container" style="background: rgba(255,255,255,0.015); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px 20px; margin-bottom: 22px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 11.5px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.8px;">Distribución de actividad</span>
                <span id="catchup-chart-info" style="font-size: 11px; color: var(--text-muted); font-family: monospace;">-</span>
            </div>
            <div id="catchup-bars" style="display: flex; align-items: flex-end; justify-content: space-between; height: 60px; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.06); gap: 4px;">
                <!-- Columnas inyectadas dinámicamente -->
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 10px; color: var(--text-muted); margin-top: 5px; font-weight: 500;">
                <span id="catchup-chart-start-time">-</span>
                <span id="catchup-chart-end-time">-</span>
            </div>
        </div>

        <!-- Filtros por tipo de post -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
            <button onclick="setCatchUpFilter('all')" id="tab-cu-all" class="btn-cu-filter active">
                Todos <span class="badge" id="badge-cu-all">0</span>
            </button>
            <button onclick="setCatchUpFilter('original')" id="tab-cu-original" class="btn-cu-filter">
                Originales <span class="badge" id="badge-cu-original">0</span>
            </button>
            <button onclick="setCatchUpFilter('reply')" id="tab-cu-reply" class="btn-cu-filter">
                Respuestas <span class="badge" id="badge-cu-reply">0</span>
            </button>
            <button onclick="setCatchUpFilter('reblog')" id="tab-cu-reblog" class="btn-cu-filter">
                Impulsos <span class="badge" id="badge-cu-reblog">0</span>
            </button>
        </div>

        <!-- Fila de Autores más Activos -->
        <div style="background: rgba(255, 255, 255, 0.01); border: 1px solid var(--border-color); border-radius: 14px; padding: 15px 18px; margin-bottom: 22px;">
            <div style="font-size: 11.5px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.8px; margin-bottom: 12px;">Autores más activos</div>
            <div id="catchup-users-row" style="display: flex; gap: 14px; overflow-x: auto; padding-bottom: 8px; scrollbar-width: thin;">
                <!-- Burbujas de usuario con conteo de posts -->
            </div>
            
            <!-- Indicador de Filtro de Autor Activo -->
            <div id="catchup-author-filter-indicator" style="display: none; align-items: center; justify-content: space-between; background: rgba(99, 102, 241, 0.08); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 10px; padding: 10px 16px; margin-top: 12px; font-size: 13px;">
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span class="material-icons-outlined" style="font-size: 16px; color: var(--primary);">person</span>
                    Mostrando publicaciones de <strong id="catchup-filtered-author-name" style="color: var(--text-color);">@usuario</strong>
                </span>
                <button onclick="clearCatchUpAuthorFilter()" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: var(--error); cursor: pointer; display: flex; align-items: center; font-weight: 600; padding: 4px 10px; border-radius: 8px; font-size: 12px; transition: all 0.2s;">
                    Quitar filtro
                </button>
            </div>
        </div>

        <!-- Criterios de Ordenamiento -->
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 12.5px;">
                <span style="color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">ORDENAR:</span>
                <button onclick="setCatchUpSort('date_asc')" id="sort-cu-date_asc" class="btn-cu-sort active">Fecha ↑</button>
                <button onclick="setCatchUpSort('date_desc')" id="sort-cu-date_desc" class="btn-cu-sort">Fecha ↓</button>
                <button onclick="setCatchUpSort('replies')" id="sort-cu-replies" class="btn-cu-sort">Respuestas</button>
                <button onclick="setCatchUpSort('favs')" id="sort-cu-favs" class="btn-cu-sort">Me gustan</button>
                <button onclick="setCatchUpSort('reblogs')" id="sort-cu-reblogs" class="btn-cu-sort">Impulsos</button>
            </div>
        </div>

        <!-- Feed del CatchUp -->
        <div id="catchup-feed" style="display: flex; flex-direction: column; gap: 15px;">
            <!-- Publicaciones cargadas -->
        </div>
    </div>
</div>
