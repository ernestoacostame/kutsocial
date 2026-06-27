<h3>Mantenimiento del Sistema</h3>
<p class="help-text" style="margin-bottom: 25px;">Ejecuta tareas de limpieza y optimización para mantener el servidor funcionando de manera eficiente y liberar espacio en disco.</p>

<form method="POST" action="/admin/maintenance/clean" onsubmit="return confirm('¿Estás seguro de que deseas iniciar las tareas de mantenimiento seleccionadas?');">
    
    <!-- Opción 1: Multimedia Huérfanos -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="clean_orphans" id="clean_orphans" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="clean_orphans" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">🧹 Limpieza de Archivos Multimedia Huérfanos</label>
            <span class="help-text">Escanea la carpeta de subidas (<code>data/uploads/</code>) y elimina de forma segura cualquier imagen, avatar o archivo adjunto que ya no esté referenciado en perfiles de usuarios ni en publicaciones de la base de datos.</span>
        </div>
    </div>
    
    <!-- Opción 2: Cuentas Remotas Inactivas -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="prune_accounts" id="prune_accounts" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="prune_accounts" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">👤 Eliminar Cuentas Remotas Inactivas</label>
            <span class="help-text">Elimina cuentas de otras instancias federadas con las que ningún usuario local ha interactuado (no nos siguen, no los seguimos y no tienen estados guardados o marcados como favoritos).</span>
        </div>
    </div>
    
    <!-- Opción 3: Estados Remotos Antiguos -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="remove_statuses" id="remove_statuses" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="remove_statuses" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">📝 Eliminar Estados Remotos Antiguos</label>
            <span class="help-text" style="display: block; margin-bottom: 10px;">Elimina publicaciones remotas federadas con las que no ha habido interacción (no están marcadas como favorito o marcador, ni tienen respuestas locales).</span>
            <div class="form-group" style="margin-bottom: 0; display: inline-flex; align-items: center; gap: 10px;">
                <label for="statuses_days" style="font-size: 13px; font-weight: normal; margin-bottom: 0;">Antigüedad de estados a eliminar:</label>
                <input type="number" name="statuses_days" id="statuses_days" value="4" min="1" max="365" style="width: 80px; padding: 6px 10px; border-radius: 6px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: white;">
                <span style="font-size: 13px; color: var(--text-muted);">días o más</span>
            </div>
        </div>
    </div>
    
    <!-- Opción 4: Limpiar Cola de Tareas (Jobs) -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="clear_jobs" id="clear_jobs" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="clear_jobs" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">⚙️ Limpiar Historial de Tareas en Cola</label>
            <span class="help-text">Elimina permanentemente de la base de datos el registro de tareas en cola (jobs) ya finalizadas o fallidas.</span>
        </div>
    </div>
    
    <!-- Opción 5: Optimizar Base de Datos (VACUUM) -->
    <div class="update-card" style="margin-bottom: 25px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="vacuum_db" id="vacuum_db" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="vacuum_db" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;">🗄️ Optimizar Base de Datos (VACUUM)</label>
            <span class="help-text">Ejecuta el comando <code>VACUUM</code> en la base de datos SQLite para compactar el archivo, reconstruir índices y recuperar todo el espacio libre en el disco tras las eliminaciones.</span>
        </div>
    </div>
    
    <button type="submit" class="btn-submit" style="width: 100%; font-size: 15px;">Ejecutar Tareas Seleccionadas</button>
</form>
