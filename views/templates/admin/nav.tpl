{* josrachat - pestañas del módulo *}
{foreach from=$jrc_warnings item=w}
  <div class="alert alert-warning">{$w|escape:'html':'UTF-8'}</div>
{/foreach}
<ul class="nav nav-tabs" style="margin-bottom:15px;">
  {foreach from=$jrc_tabs key=k item=t}
    <li{if $k == $jrc_active} class="active"{/if}>
      <a href="{$t.url|escape:'html':'UTF-8'}"><i class="{$t.icon|escape:'html':'UTF-8'}"></i> {$t.label|escape:'html':'UTF-8'}{if $t.badge > 0} <span class="badge" style="background:#e74c3c;">{$t.badge|intval}</span>{/if}</a>
    </li>
  {/foreach}
</ul>
