<?php
/**
 * josrachat 2.0.8: conexión con el servidor de actualizaciones de josra.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_8($module)
{
    return $module->getUpdater()->registerHooks();
}
