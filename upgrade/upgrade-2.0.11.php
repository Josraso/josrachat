<?php
/**
 * josrachat 2.0.11: filtro de spam en el formulario de contacto del asistente.
 * - Los formularios de contacto pasan a contar como mensaje, para que las conversaciones que solo
 *   tienen formulario salgan en el back-office (antes quedaban ocultas).
 * - Los formularios ya guardados con letras al azar se pasan a «Spam».
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_11($module)
{
    require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatSpam.php';
    JosrachatDb::ensure();
    $p = _DB_PREFIX_;
    Db::getInstance()->execute(
        'UPDATE `' . $p . 'josrachat_conversation` c
         SET c.`messages` = c.`messages` + (SELECT COUNT(*) FROM `' . $p . 'josrachat_message` m
                                            WHERE m.`id_conversation` = c.`id_conversation` AND m.`role` = "contact")'
    );
    JosrachatSpam::reclassifyOld();

    return $module->getUpdater()->registerHooks();
}
