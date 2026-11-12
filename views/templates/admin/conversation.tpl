{* josrachat - detalle de una conversación *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-comments"></i> {l s='Conversación' mod='josrachat'} #{$jrc_conv.id_conversation|intval}
    <span class="panel-heading-action">{$jrc_status nofilter}</span>
  </div>
  <div class="row">
    <div class="col-lg-6">
      <dl class="dl-horizontal">
        <dt>{l s='Cliente' mod='josrachat'}</dt><dd>{$jrc_who|escape:'html':'UTF-8'}{if $jrc_email} &middot; {$jrc_email|escape:'html':'UTF-8'}{/if}</dd>
        <dt>{l s='Empezó' mod='josrachat'}</dt><dd>{$jrc_date_add|escape:'html':'UTF-8'}</dd>
        <dt>{l s='Mensajes' mod='josrachat'}</dt><dd>{$jrc_conv.messages|intval}</dd>
        <dt>IP</dt><dd>{$jrc_conv.ip|escape:'html':'UTF-8'}</dd>
      </dl>
    </div>
    <div class="col-lg-6">
      <dl class="dl-horizontal">
        <dt>{l s='Coste' mod='josrachat'}</dt><dd>{$jrc_cost|escape:'html':'UTF-8'}</dd>
        <dt>{l s='Tokens entrada / salida' mod='josrachat'}</dt><dd>{$jrc_tokens|escape:'html':'UTF-8'}</dd>
        <dt>{l s='Pedido después' mod='josrachat'}</dt><dd>{if $jrc_order}{$jrc_order|escape:'html':'UTF-8'}{else}-{/if}</dd>
      </dl>
    </div>
  </div>
  <div class="panel-footer">
    <a class="btn btn-default" href="{$jrc_back|escape:'html':'UTF-8'}"><i class="process-icon-back"></i> {l s='Volver' mod='josrachat'}</a>
    {if $jrc_mailto}<a class="btn btn-default" href="{$jrc_mailto|escape:'html':'UTF-8'}"><i class="process-icon-envelope"></i> {l s='Escribir al cliente' mod='josrachat'}</a>{/if}
    <a class="btn btn-default pull-right" href="{$jrc_url_block|escape:'html':'UTF-8'}"><i class="process-icon-{if $jrc_conv.blocked}ok{else}cancel{/if}"></i> {if $jrc_conv.blocked}{l s='Desbloquear' mod='josrachat'}{else}{l s='Bloquear visitante' mod='josrachat'}{/if}</a>
    <a class="btn btn-default pull-right" href="{$jrc_url_reviewed|escape:'html':'UTF-8'}"><i class="process-icon-{if $jrc_conv.reviewed}refresh{else}save{/if}"></i> {if $jrc_conv.reviewed}{l s='Marcar sin revisar' mod='josrachat'}{else}{l s='Marcar revisada' mod='josrachat'}{/if}</a>
  </div>
</div>

<div class="panel">
  <div class="panel-heading"><i class="icon-list"></i> {l s='Mensajes' mod='josrachat'}</div>
  {foreach from=$jrc_messages item=m}
    {if $m.role == 'user'}
      <div class="well well-sm" style="margin-left:0;margin-right:20%;">
        <p class="text-muted" style="margin:0 0 4px;"><i class="icon-user"></i> <strong>{l s='Cliente' mod='josrachat'}</strong> &middot; {$m.date|escape:'html':'UTF-8'}</p>
        <div>{$m.content|escape:'html':'UTF-8'|nl2br}</div>
      </div>
    {elseif $m.role == 'contact'}
      <div class="alert alert-warning">
        <p style="margin:0 0 4px;"><i class="icon-envelope"></i> <strong>{l s='Formulario de contacto enviado' mod='josrachat'}</strong> &middot; {$m.date|escape:'html':'UTF-8'}</p>
        <div>{$m.content|escape:'html':'UTF-8'|nl2br}</div>
        {if $m.error}<p class="text-danger" style="margin:4px 0 0;">{$m.error|escape:'html':'UTF-8'}</p>{/if}
      </div>
    {elseif $m.role == 'spam'}
      <div class="alert alert-danger">
        <p style="margin:0 0 4px;"><i class="icon-ban"></i> <strong>{l s='Formulario de contacto tomado por spam: no se mandó el correo' mod='josrachat'}</strong> &middot; {$m.date|escape:'html':'UTF-8'} &middot; IP {$m.ip|escape:'html':'UTF-8'}</p>
        <div>{$m.content|escape:'html':'UTF-8'|nl2br}</div>
        {if $m.error}<p style="margin:4px 0 0;"><em>{$m.error|escape:'html':'UTF-8'}</em></p>{/if}
        <p class="help-block" style="margin:4px 0 0;">{l s='Si era un cliente de verdad, contéstale directamente a su correo.' mod='josrachat'}</p>
      </div>
    {else}
      <div class="panel" style="margin-left:20%;margin-bottom:10px;">
        <p class="text-muted" style="margin:0 0 4px;">
          <i class="icon-comments"></i> <strong>{l s='Asistente' mod='josrachat'}</strong> &middot; {$m.date|escape:'html':'UTF-8'}
          {if $m.cost_eur} &middot; {$m.cost_eur|escape:'html':'UTF-8'}{/if}
          {if $m.tools_text} &middot; <em>{$m.tools_text|escape:'html':'UTF-8'}</em>{/if}
          <a class="btn btn-default btn-xs pull-right" href="{$m.correct_url|escape:'html':'UTF-8'}"><i class="icon-pencil"></i> {l s='Corregir' mod='josrachat'}</a>
        </p>
        <div>{$m.content|escape:'html':'UTF-8'|nl2br}</div>
        {if $m.error}<p class="text-danger" style="margin:4px 0 0;"><i class="icon-warning"></i> {$m.error|escape:'html':'UTF-8'}</p>{/if}
      </div>
    {/if}
  {foreachelse}
    <p class="text-muted">{l s='Sin mensajes.' mod='josrachat'}</p>
  {/foreach}
</div>
