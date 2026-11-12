<?php
/**
 * josrachat 2.0.10: aviso de licencia: el cartel con el botón sale desde la primera vez.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_10($module)
{
    return $module->getUpdater()->registerHooks();
}
