{* josrachat - consumo *}
<div class="row">
  <div class="col-lg-3 col-sm-6">
    <div class="panel text-center">
      <div class="panel-heading">{l s='Hoy' mod='josrachat'}</div>
      <h2 style="margin:5px 0;">{$jrc_today|escape:'html':'UTF-8'}</h2>
      {if $jrc_cap_day}
        <div class="progress" style="margin:8px 0 4px;"><div class="progress-bar {if $jrc_day_pct >= 100}progress-bar-danger{elseif $jrc_day_pct >= 80}progress-bar-warning{else}progress-bar-success{/if}" style="width:{$jrc_day_pct|intval}%;"></div></div>
        <small class="text-muted">{$jrc_day_pct|intval}% {l s='del tope diario de' mod='josrachat'} {$jrc_cap_day|escape:'html':'UTF-8'}</small>
      {else}<small class="text-muted">{l s='Sin tope diario' mod='josrachat'}</small>{/if}
    </div>
  </div>
  <div class="col-lg-3 col-sm-6">
    <div class="panel text-center">
      <div class="panel-heading">{l s='Este mes' mod='josrachat'}</div>
      <h2 style="margin:5px 0;">{$jrc_month|escape:'html':'UTF-8'}</h2>
      {if $jrc_cap_month}
        <div class="progress" style="margin:8px 0 4px;"><div class="progress-bar {if $jrc_month_pct >= 100}progress-bar-danger{elseif $jrc_month_pct >= 80}progress-bar-warning{else}progress-bar-success{/if}" style="width:{$jrc_month_pct|intval}%;"></div></div>
        <small class="text-muted">{$jrc_month_pct|intval}% {l s='del tope mensual de' mod='josrachat'} {$jrc_cap_month|escape:'html':'UTF-8'}</small>
      {else}<small class="text-muted">{l s='Sin tope mensual' mod='josrachat'}</small>{/if}
    </div>
  </div>
  <div class="col-lg-3 col-sm-6">
    <div class="panel text-center">
      <div class="panel-heading">{l s='Conversaciones este mes' mod='josrachat'}</div>
      <h2 style="margin:5px 0;">{$jrc_conv_month|intval}</h2>
      <small class="text-muted">{l s='Coste medio' mod='josrachat'}: {$jrc_avg|escape:'html':'UTF-8'} &middot; {l s='mes pasado' mod='josrachat'}: {$jrc_prev|escape:'html':'UTF-8'}</small>
    </div>
  </div>
  <div class="col-lg-3 col-sm-6">
    <div class="panel text-center">
      <div class="panel-heading">{l s='Pedidos tras chatear (mes)' mod='josrachat'}</div>
      <h2 style="margin:5px 0;">{$jrc_orders|intval}</h2>
      <small class="text-muted">{$jrc_sales|escape:'html':'UTF-8'} {l s='en ventas' mod='josrachat'}</small>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-heading"><i class="icon-calendar"></i> {l s='Últimos 30 días' mod='josrachat'}</div>
  {if $jrc_days}
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>{l s='Día' mod='josrachat'}</th>
        <th class="text-center">{l s='Conversaciones' mod='josrachat'}</th>
        <th class="text-center">{l s='Llamadas a la IA' mod='josrachat'}</th>
        <th class="text-right">{l s='Tokens entrada' mod='josrachat'}</th>
        <th class="text-right">{l s='Tokens de caché' mod='josrachat'}</th>
        <th class="text-right">{l s='Tokens salida' mod='josrachat'}</th>
        <th class="text-right">{l s='Coste' mod='josrachat'}</th>
        <th style="width:25%;"></th>
      </tr></thead>
      <tbody>
      {foreach from=$jrc_days item=d}
        <tr>
          <td>{$d.date|escape:'html':'UTF-8'}</td>
          <td class="text-center">{$d.convs|intval}</td>
          <td class="text-center">{$d.calls|intval}</td>
          <td class="text-right">{$d.tin_txt|escape:'html':'UTF-8'}</td>
          <td class="text-right">{$d.tcr_txt|escape:'html':'UTF-8'}</td>
          <td class="text-right">{$d.tout_txt|escape:'html':'UTF-8'}</td>
          <td class="text-right"><strong>{$d.eur_txt|escape:'html':'UTF-8'}</strong></td>
          <td><div class="progress" style="margin:0;"><div class="progress-bar" style="width:{$d.pct|intval}%;"></div></div></td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  </div>
  {else}
    <p class="text-muted">{l s='Todavía no hay consumo.' mod='josrachat'}</p>
  {/if}
  <p class="help-block">{l s='Los tokens de caché son la parte fija de las instrucciones (normas, categorías, páginas, respuestas validadas) que Anthropic reutiliza durante unos minutos y cobra a una fracción del precio normal.' mod='josrachat'}</p>
</div>

<div class="row">
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-heading"><i class="icon-bar-chart"></i> {l s='Por meses' mod='josrachat'}</div>
      {if $jrc_months}
      <table class="table">
        <thead><tr>
          <th>{l s='Mes' mod='josrachat'}</th>
          <th class="text-center">{l s='Conversaciones' mod='josrachat'}</th>
          <th class="text-right">{l s='Tokens entrada' mod='josrachat'}</th>
          <th class="text-right">{l s='Tokens salida' mod='josrachat'}</th>
          <th class="text-right">{l s='Coste' mod='josrachat'}</th>
          <th class="text-center">{l s='Pedidos' mod='josrachat'}</th>
          <th class="text-right">{l s='Ventas' mod='josrachat'}</th>
        </tr></thead>
        <tbody>
        {foreach from=$jrc_months item=m}
          <tr>
            <td>{$m.label|escape:'html':'UTF-8'}</td>
            <td class="text-center">{$m.convs|intval}</td>
            <td class="text-right">{$m.tin_txt|escape:'html':'UTF-8'}</td>
            <td class="text-right">{$m.tout_txt|escape:'html':'UTF-8'}</td>
            <td class="text-right"><strong>{$m.eur_txt|escape:'html':'UTF-8'}</strong></td>
            <td class="text-center">{$m.orders|intval}</td>
            <td class="text-right">{$m.sales_txt|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
      {else}
        <p class="text-muted">{l s='Todavía no hay consumo.' mod='josrachat'}</p>
      {/if}
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-heading"><i class="icon-tags"></i> {l s='Este mes por tipo' mod='josrachat'}</div>
      {if $jrc_types}
      <table class="table">
        {foreach from=$jrc_types item=t}
          <tr><td>{$t.label|escape:'html':'UTF-8'}</td><td class="text-center">{$t.n|intval}</td><td class="text-right">{$t.eur_txt|escape:'html':'UTF-8'}</td></tr>
        {/foreach}
      </table>
      {else}
        <p class="text-muted">-</p>
      {/if}
      <p class="help-block">{l s='Modelo actual' mod='josrachat'}: <strong>{$jrc_model|escape:'html':'UTF-8'}</strong>. {l s='Importes convertidos a euros con el cambio' mod='josrachat'} {$jrc_rate|escape:'html':'UTF-8'}. {l s='El cargo real lo ves en console.anthropic.com > Billing.' mod='josrachat'}</p>
      <p class="help-block">{l s='"Pedidos tras chatear" cuenta los pedidos del mismo carrito o del mismo cliente en los 7 días siguientes a usar el asistente.' mod='josrachat'}</p>
    </div>
  </div>
</div>
