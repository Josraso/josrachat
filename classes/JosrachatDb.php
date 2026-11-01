<?php
/**
 * josrachat - Esquema de base de datos y autorreparación.
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosrachatDb
{
    /** Subir este número cuando cambie el esquema: getContent() y el front lo reparan solos. */
    const SCHEMA_VERSION = 1;

    public static function tables()
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;

        return array(
            'josrachat_conversation' => 'CREATE TABLE IF NOT EXISTS `' . $p . 'josrachat_conversation` (
                `id_conversation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `session_key` VARCHAR(64) NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_guest` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_lang` INT UNSIGNED NOT NULL DEFAULT 0,
                `ip` VARCHAR(45) NOT NULL DEFAULT "",
                `contact_email` VARCHAR(128) NOT NULL DEFAULT "",
                `messages` INT UNSIGNED NOT NULL DEFAULT 0,
                `need_human` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `no_answer` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `corrected` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `reviewed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `blocked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `tokens_in` INT UNSIGNED NOT NULL DEFAULT 0,
                `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
                `cost` DECIMAL(14,6) NOT NULL DEFAULT 0,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `order_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_conversation`),
                UNIQUE KEY `session_key` (`session_key`),
                KEY `id_shop_date` (`id_shop`, `date_upd`),
                KEY `id_cart` (`id_cart`),
                KEY `id_customer` (`id_customer`)
            ) ENGINE=' . $e . ' DEFAULT CHARSET=utf8mb4',

            'josrachat_message' => 'CREATE TABLE IF NOT EXISTS `' . $p . 'josrachat_message` (
                `id_message` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_conversation` INT UNSIGNED NOT NULL,
                `role` VARCHAR(16) NOT NULL DEFAULT "user",
                `content` MEDIUMTEXT NOT NULL,
                `tools` VARCHAR(255) NOT NULL DEFAULT "",
                `ip` VARCHAR(45) NOT NULL DEFAULT "",
                `cost` DECIMAL(14,6) NOT NULL DEFAULT 0,
                `error` VARCHAR(255) NOT NULL DEFAULT "",
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_message`),
                KEY `id_conversation` (`id_conversation`),
                KEY `ip_date` (`ip`, `date_add`)
            ) ENGINE=' . $e . ' DEFAULT CHARSET=utf8mb4',

            'josrachat_usage' => 'CREATE TABLE IF NOT EXISTS `' . $p . 'josrachat_usage` (
                `id_usage` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_conversation` INT UNSIGNED NOT NULL DEFAULT 0,
                `type` VARCHAR(16) NOT NULL DEFAULT "chat",
                `model` VARCHAR(64) NOT NULL DEFAULT "",
                `tokens_in` INT UNSIGNED NOT NULL DEFAULT 0,
                `tokens_cache_write` INT UNSIGNED NOT NULL DEFAULT 0,
                `tokens_cache_read` INT UNSIGNED NOT NULL DEFAULT 0,
                `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
                `cost` DECIMAL(14,6) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_usage`),
                KEY `id_shop_date` (`id_shop`, `date_add`)
            ) ENGINE=' . $e . ' DEFAULT CHARSET=utf8mb4',

            'josrachat_knowledge' => 'CREATE TABLE IF NOT EXISTS `' . $p . 'josrachat_knowledge` (
                `id_knowledge` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `question` TEXT NOT NULL,
                `answer` TEXT NOT NULL,
                `id_message` INT UNSIGNED NOT NULL DEFAULT 0,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_knowledge`),
                KEY `id_shop` (`id_shop`)
            ) ENGINE=' . $e . ' DEFAULT CHARSET=utf8mb4',

            'josrachat_document' => 'CREATE TABLE IF NOT EXISTS `' . $p . 'josrachat_document` (
                `id_document` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `title` VARCHAR(255) NOT NULL,
                `filename` VARCHAR(255) NOT NULL DEFAULT "",
                `content` LONGTEXT NOT NULL,
                `chars` INT UNSIGNED NOT NULL DEFAULT 0,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_document`),
                KEY `id_shop` (`id_shop`)
            ) ENGINE=' . $e . ' DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * Crea lo que falte. Devuelve un texto con el motivo si algo falla, o '' si todo va bien.
     */
    public static function ensure($force = false)
    {
        if (!$force && (int) Configuration::getGlobalValue('JOSRACHAT_SCHEMA') === self::SCHEMA_VERSION) {
            return '';
        }

        $db = Db::getInstance();
        foreach (self::tables() as $name => $sql) {
            if (!$db->execute($sql)) {
                return sprintf('No se pudo crear la tabla %s%s: %s', _DB_PREFIX_, $name, $db->getMsgError());
            }
        }
        Configuration::updateGlobalValue('JOSRACHAT_SCHEMA', self::SCHEMA_VERSION);

        return '';
    }

    public static function drop()
    {
        foreach (array_keys(self::tables()) as $name) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $name . '`');
        }

        return true;
    }
}
