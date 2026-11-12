{* josrachat - filtros de conversaciones *}
<div class="btn-group" style="margin-bottom:15px;">
  {foreach from=$jrc_filters key=k item=f}
    <a class="btn {if $k == $jrc_filter}btn-primary{else}btn-default{/if}" href="{$jrc_filter_urls[$k]|escape:'html':'UTF-8'}">{$f.label|escape:'html':'UTF-8'}</a>
  {/foreach}
</div>
<p class="help-block">{l s='"Para revisar" muestra las conversaciones en las que el asistente no supo responder o el cliente pidió una persona y que todavía no has marcado como revisadas. Entra en una y pulsa "Corregir" en la respuesta que falló: lo que escribas se guarda en Conocimiento y el asistente lo usará con los siguientes clientes.' mod='josrachat'}</p>
<p class="help-block">{l s='"Spam" muestra los formularios de contacto que el filtro tomó por robots (campo trampa, enviados al instante o con letras al azar): no se mandó el correo. Si alguno era un cliente de verdad, contéstale directamente a su correo.' mod='josrachat'}</p>
