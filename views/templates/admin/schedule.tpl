{* josrachat - horario de apertura *}
<form method="post" action="{$jrc_action|escape:'html':'UTF-8'}" class="defaultForm form-horizontal">
  <div class="panel">
    <div class="panel-heading"><i class="icon-time"></i> {l s='Horario del asistente' mod='josrachat'}</div>

    <div class="alert {if !$jrc_sched_enabled}alert-info{elseif $jrc_open_now}alert-success{else}alert-warning{/if}">
      {if !$jrc_sched_enabled}
        {l s='Programación desactivada: el asistente se muestra a todas horas.' mod='josrachat'}
      {elseif $jrc_open_now}
        <strong>{l s='Ahora mismo: abierto.' mod='josrachat'}</strong> {l s='El asistente se está mostrando en la tienda.' mod='josrachat'}
      {else}
        <strong>{l s='Ahora mismo: cerrado.' mod='josrachat'}</strong> {l s='El asistente está oculto en la tienda.' mod='josrachat'}
        {if $jrc_next_open} {l s='Vuelve a aparecer' mod='josrachat'} {$jrc_next_open|escape:'html':'UTF-8'}.{/if}
      {/if}
      <br><small>{l s='Hora de la tienda' mod='josrachat'}: {$jrc_now|escape:'html':'UTF-8'} ({$jrc_tz|escape:'html':'UTF-8'})</small>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Activar programación' mod='josrachat'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="JOSRACHAT_SCHEDULE_ENABLED" id="JOSRACHAT_SCHEDULE_ENABLED_on" value="1"{if $jrc_sched_enabled} checked="checked"{/if}>
          <label for="JOSRACHAT_SCHEDULE_ENABLED_on">{l s='Sí' mod='josrachat'}</label>
          <input type="radio" name="JOSRACHAT_SCHEDULE_ENABLED" id="JOSRACHAT_SCHEDULE_ENABLED_off" value="0"{if !$jrc_sched_enabled} checked="checked"{/if}>
          <label for="JOSRACHAT_SCHEDULE_ENABLED_off">{l s='No' mod='josrachat'}</label>
          <a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Si está activo, el asistente solo aparece en la tienda dentro de este horario. Fuera de horario se oculta del todo; si alguien tiene el chat abierto en ese momento, se cierra al enviar el siguiente mensaje.' mod='josrachat'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Programación semanal' mod='josrachat'}</label>
      <div class="col-lg-9">
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>{l s='Día' mod='josrachat'}</th>
                <th>{l s='Abierto' mod='josrachat'}</th>
                <th>{l s='Turno 1' mod='josrachat'}</th>
                <th>{l s='Turno partido' mod='josrachat'}</th>
                <th>{l s='Turno 2' mod='josrachat'}</th>
              </tr>
            </thead>
            <tbody>
            {foreach from=$jrc_days item=d}
              {assign var='ds' value=$jrc_schedule[$d]}
              <tr>
                <td><strong>{$jrc_day_labels[$d]|escape:'html':'UTF-8'}</strong></td>
                <td>
                  <span class="switch prestashop-switch fixed-width-md">
                    <input type="radio" name="jrc_day_{$d|escape:'html':'UTF-8'}" id="jrc_day_{$d|escape:'html':'UTF-8'}_on" value="1"{if $ds.enabled} checked="checked"{/if}>
                    <label for="jrc_day_{$d|escape:'html':'UTF-8'}_on">{l s='Sí' mod='josrachat'}</label>
                    <input type="radio" name="jrc_day_{$d|escape:'html':'UTF-8'}" id="jrc_day_{$d|escape:'html':'UTF-8'}_off" value="0"{if !$ds.enabled} checked="checked"{/if}>
                    <label for="jrc_day_{$d|escape:'html':'UTF-8'}_off">{l s='No' mod='josrachat'}</label>
                    <a class="slide-button btn"></a>
                  </span>
                </td>
                <td style="white-space:nowrap;">
                  <input type="time" class="form-control" style="display:inline-block;width:auto;" name="jrc_t1_from_{$d|escape:'html':'UTF-8'}" value="{$ds.t1_from|escape:'html':'UTF-8'}">
                  &ndash;
                  <input type="time" class="form-control" style="display:inline-block;width:auto;" name="jrc_t1_to_{$d|escape:'html':'UTF-8'}" value="{$ds.t1_to|escape:'html':'UTF-8'}">
                </td>
                <td>
                  <span class="switch prestashop-switch fixed-width-md">
                    <input type="radio" name="jrc_t2_{$d|escape:'html':'UTF-8'}" id="jrc_t2_{$d|escape:'html':'UTF-8'}_on" value="1"{if $ds.t2_enabled} checked="checked"{/if}>
                    <label for="jrc_t2_{$d|escape:'html':'UTF-8'}_on">{l s='Sí' mod='josrachat'}</label>
                    <input type="radio" name="jrc_t2_{$d|escape:'html':'UTF-8'}" id="jrc_t2_{$d|escape:'html':'UTF-8'}_off" value="0"{if !$ds.t2_enabled} checked="checked"{/if}>
                    <label for="jrc_t2_{$d|escape:'html':'UTF-8'}_off">{l s='No' mod='josrachat'}</label>
                    <a class="slide-button btn"></a>
                  </span>
                </td>
                <td style="white-space:nowrap;">
                  <input type="time" class="form-control" style="display:inline-block;width:auto;" name="jrc_t2_from_{$d|escape:'html':'UTF-8'}" value="{$ds.t2_from|escape:'html':'UTF-8'}">
                  &ndash;
                  <input type="time" class="form-control" style="display:inline-block;width:auto;" name="jrc_t2_to_{$d|escape:'html':'UTF-8'}" value="{$ds.t2_to|escape:'html':'UTF-8'}">
                </td>
              </tr>
            {/foreach}
            </tbody>
          </table>
        </div>
        <p class="help-block">{l s='La hora de cierre no se incluye: con cierre a las 14:00, a las 14:00 en punto ya está cerrado. Las horas del turno 2 solo cuentan si "Turno partido" está en Sí.' mod='josrachat'}</p>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitJosrachatSchedule" value="1" class="btn btn-primary pull-right">
        <i class="icon-save"></i> {l s='Guardar horario' mod='josrachat'}
      </button>
    </div>
  </div>
</form>
