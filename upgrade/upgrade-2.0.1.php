<?php
/**
 * josrachat 2.0.1: formato de respuesta sobre pedidos configurable.
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_1($module)
{
    require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatBot.php';
    if (Configuration::get('JOSRACHAT_ORDER_INSTRUCTIONS') === false || trim((string) Configuration::get('JOSRACHAT_ORDER_INSTRUCTIONS')) === '') {
        Configuration::updateValue('JOSRACHAT_ORDER_INSTRUCTIONS', JosrachatBot::defaultOrderInstructions());
    }

    return true;
}
