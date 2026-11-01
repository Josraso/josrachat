{* josrachat - paginación sencilla *}
<div class="text-center" style="margin:10px 0 20px;">
  {if $jrc_prev}<a class="btn btn-default" href="{$jrc_prev|escape:'html':'UTF-8'}"><i class="icon-chevron-left"></i> {l s='Anteriores' mod='josrachat'}</a>{/if}
  <span style="margin:0 12px;">{l s='Página' mod='josrachat'} {$jrc_page|intval} / {$jrc_pages|intval}</span>
  {if $jrc_next}<a class="btn btn-default" href="{$jrc_next|escape:'html':'UTF-8'}">{l s='Siguientes' mod='josrachat'} <i class="icon-chevron-right"></i></a>{/if}
</div>
