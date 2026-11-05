<h3><?= __('admin_maint_title') ?></h3>
<p class="help-text" style="margin-bottom: 25px;"><?= __('admin_maint_desc') ?></p>

<form method="POST" action="/admin/maintenance/clean" onsubmit="return confirm('<?= __('admin_maint_confirm') ?>');">
    
    <!-- Opción 1: Multimedia Huérfanos -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="clean_orphans" id="clean_orphans" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="clean_orphans" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;"><?= __('admin_maint_opt_orphans_title') ?></label>
            <span class="help-text"><?= __('admin_maint_opt_orphans_desc') ?></span>
        </div>
    </div>
    
    <!-- Opción 2: Cuentas Remotas Inactivas -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="prune_accounts" id="prune_accounts" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="prune_accounts" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;"><?= __('admin_maint_opt_accounts_title') ?></label>
            <span class="help-text"><?= __('admin_maint_opt_accounts_desc') ?></span>
        </div>
    </div>
    
    <!-- Opción 3: Estados Remotos Antiguos -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="remove_statuses" id="remove_statuses" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="remove_statuses" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;"><?= __('admin_maint_opt_statuses_title') ?></label>
            <span class="help-text" style="display: block; margin-bottom: 10px;"><?= __('admin_maint_opt_statuses_desc') ?></span>
            <div class="form-group" style="margin-bottom: 0; display: inline-flex; align-items: center; gap: 10px;">
                <label for="statuses_days" style="font-size: 13px; font-weight: normal; margin-bottom: 0;"><?= __('admin_maint_opt_statuses_age') ?></label>
                <input type="number" name="statuses_days" id="statuses_days" value="4" min="1" max="365" style="width: 80px; padding: 6px 10px; border-radius: 6px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: white;">
                <span style="font-size: 13px; color: var(--text-muted);"><?= __('admin_maint_opt_statuses_days') ?></span>
            </div>
        </div>
    </div>
    
    <!-- Opción 4: Limpiar Cola de Tareas (Jobs) -->
    <div class="update-card" style="margin-bottom: 20px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="clear_jobs" id="clear_jobs" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="clear_jobs" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;"><?= __('admin_maint_opt_jobs_title') ?></label>
            <span class="help-text"><?= __('admin_maint_opt_jobs_desc') ?></span>
        </div>
    </div>
    
    <!-- Opción 5: Optimizar Base de Datos (VACUUM) -->
    <div class="update-card" style="margin-bottom: 25px; display: flex; align-items: flex-start; gap: 15px;">
        <input type="checkbox" name="vacuum_db" id="vacuum_db" value="1" checked style="width: auto; margin-top: 5px; cursor: pointer;">
        <div>
            <label for="vacuum_db" style="font-weight: 600; display: block; cursor: pointer; font-size: 15px; margin-bottom: 4px;"><?= __('admin_maint_opt_vacuum_title') ?></label>
            <span class="help-text"><?= __('admin_maint_opt_vacuum_desc') ?></span>
        </div>
    </div>
    
    <button type="submit" class="btn-submit" style="width: 100%; font-size: 15px;"><?= __('admin_maint_run_btn') ?></button>
</form>
