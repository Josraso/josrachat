<?php
/**
 * josrachat - Asistente con IA (Claude) para la tienda.
 * Responde a los clientes consultando el catálogo, envíos, páginas, documentos y pedidos.
 * Configuración, conocimiento, conversaciones y consumo dentro de Módulos > Configurar.
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/JosrachatDb.php';
require_once __DIR__ . '/classes/JosrachatClaude.php';
require_once __DIR__ . '/classes/JosrachatTools.php';
require_once __DIR__ . '/classes/JosrachatBot.php';
require_once __DIR__ . '/classes/JosrachatSchedule.php';
require_once __DIR__ . '/classes/JosrachatSpam.php';
require_once dirname(__FILE__) . '/classes/JosrachatUpdater.php';

class Josrachat extends Module
{
    const PAGE_SIZE = 50;

    protected static $headerDone = false;

    /** Hooks del módulo: install() los registra y getContent() repara los que falten. */
    protected $moduleHooks = array('displayHeader', 'actionValidateOrder');

    public function __construct()
    {
        $this->name = 'josrachat';
        $this->tab = 'front_office_features';
        $this->version = '2.0.11';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.6.1.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Asistente IA de la tienda');
        $this->description = $this->l('Asistente que responde a los clientes con inteligencia artificial (Claude) usando el catálogo, envíos, páginas, documentos y pedidos de la tienda. Con control de gasto y registro de conversaciones.');
        $this->confirmUninstall = $this->l('Se borrarán las conversaciones, el conocimiento y el registro de consumo. ¿Seguro?');
    }

    // ================================================================ instalación

    public function getUpdater()
    {
        return new JosrachatUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }


    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        $error = JosrachatDb::ensure(true);
        if ($error !== '') {
            $this->_errors[] = $error;

            return false;
        }
        foreach (array_merge($this->moduleHooks, $this->getUpdater()->getHooks()) as $hook) {
            if (!$this->registerHook($hook)) {
                $this->_errors[] = 'No se pudo registrar el hook ' . $hook . '.';

                return false;
            }
        }
        $welcome = array();
        foreach (Language::getLanguages(false) as $lang) {
            $welcome[(int) $lang['id_lang']] = in_array($lang['iso_code'], array('es', 'gl', 'ca'), true)
                ? '¡Hola! Soy el asistente de la tienda. Pregúntame por productos, envíos o tu pedido.'
                : 'Hi! I am the shop assistant. Ask me about products, shipping or your order.';
        }
        $defaults = array(
            'JOSRACHAT_ACTIVE' => 0,
            'JOSRACHAT_MODEL' => 'claude-haiku-5-5',
            'JOSRACHAT_USD_EUR' => '0.92',
            'JOSRACHAT_CAP_MONTH' => '20',
            'JOSRACHAT_CAP_DAY' => '3',
            'JOSRACHAT_LIMIT_CONV_HOUR' => 20,
            'JOSRACHAT_LIMIT_IP_DAY' => 60,
            'JOSRACHAT_BOT_NAME' => 'Asistente',
            'JOSRACHAT_COLOR' => '#2563eb',
            'JOSRACHAT_POSITION' => 'right',
            'JOSRACHAT_MOBILE' => 1,
        );
        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        Configuration::updateValue('JOSRACHAT_WELCOME', $welcome);
        Configuration::updateValue('JOSRACHAT_ORDER_INSTRUCTIONS', JosrachatBot::defaultOrderInstructions());
        if (Configuration::get('JOSRACHAT_SCHEDULE') === false) {
            Configuration::updateValue('JOSRACHAT_SCHEDULE_ENABLED', 0);
            Configuration::updateValue('JOSRACHAT_SCHEDULE', json_encode(JosrachatSchedule::defaults()));
        }

        return true;
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();
        Configuration::deleteByName('JOSRACHAT_UPD_TS');
        Configuration::deleteByName('JOSRACHAT_UPD_INFO');
        Configuration::deleteByName('JOSRACHAT_UPD_SU');
        Configuration::deleteByName('JOSRACHAT_UPD_LT');
        JosrachatDb::drop();
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration_lang` WHERE `id_configuration` IN (SELECT `id_configuration` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "JOSRACHAT\_%")');
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "JOSRACHAT\_%"');

        return parent::uninstall();
    }

    /** Autorreparación para cuando se actualiza por FTP sin reinstalar. */
    protected function selfRepair()
    {
        $error = JosrachatDb::ensure();
        if (Configuration::get('JOSRACHAT_ORDER_INSTRUCTIONS') === false) {
            Configuration::updateValue('JOSRACHAT_ORDER_INSTRUCTIONS', JosrachatBot::defaultOrderInstructions());
        }
        if (Configuration::get('JOSRACHAT_SCHEDULE') === false) {
            Configuration::updateValue('JOSRACHAT_SCHEDULE_ENABLED', 0);
            Configuration::updateValue('JOSRACHAT_SCHEDULE', json_encode(JosrachatSchedule::defaults()));
        }
        foreach ($this->moduleHooks as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
            }
        }

        return $error;
    }

    // ================================================================ front

    public function hookDisplayHeader($params)
    {
        if (self::$headerDone || !Configuration::get('JOSRACHAT_ACTIVE') || trim((string) Configuration::get('JOSRACHAT_API_KEY')) === '') {
            return '';
        }
        if (!isset($this->context->controller) || !($this->context->controller instanceof FrontController)) {
            return '';
        }
        self::$headerDone = true;
        if (!JosrachatSchedule::isOpen()) {
            return '';
        }

        $idLang = (int) $this->context->language->id;
        $iso = $this->context->language->iso_code;
        $es = in_array($iso, array('es', 'gl', 'ca'), true);
        $welcome = (string) Configuration::get('JOSRACHAT_WELCOME', $idLang);
        if ($welcome === '') {
            $welcome = $es ? '¡Hola! ¿En qué te puedo ayudar?' : 'Hi! How can I help you?';
        }
        $customer = $this->context->customer;
        $logged = $customer && $customer->isLogged();
        $color = (string) Configuration::get('JOSRACHAT_COLOR');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2563eb';
        }

        Media::addJsDef(array('josrachatCfg' => array(
            'url' => $this->context->link->getModuleLink($this->name, 'ajax', array(), true),
            'name' => (string) Configuration::get('JOSRACHAT_BOT_NAME') ?: 'Asistente',
            'welcome' => $welcome,
            'color' => $color,
            'position' => Configuration::get('JOSRACHAT_POSITION') === 'left' ? 'left' : 'right',
            'mobile' => (bool) Configuration::get('JOSRACHAT_MOBILE'),
            'whatsapp' => preg_replace('/[^0-9]/', '', (string) Configuration::get('JOSRACHAT_WHATSAPP')),
            'phone' => (string) Configuration::get('JOSRACHAT_PHONE'),
            'customerName' => $logged ? trim($customer->firstname . ' ' . $customer->lastname) : '',
            'customerEmail' => $logged ? $customer->email : '',
            'texts' => $es ? array(
                'open' => 'Abrir el asistente',
                'subtitle' => 'Asistente automático',
                'person' => 'Hablar con una persona',
                'menu' => 'Opciones',
                'reset' => 'Nueva conversación',
                'orderEmail' => 'Email de la compra',
                'orderRef' => 'Número o referencia del pedido',
                'orderCheck' => 'Consultar pedido',
                'orderError' => 'Pon un email válido y el número de pedido.',
                'orderMessage' => 'Quiero saber cómo va mi pedido %ref%. El email de la compra es %email%.',
                'close' => 'Cerrar',
                'contactTitle' => '¿Prefieres hablar con una persona?',
                'call' => 'Llamar',
                'write' => 'Escribirnos',
                'waText' => 'Hola, vengo del chat de la web.',
                'name' => 'Tu nombre',
                'email' => 'Tu email',
                'message' => '¿En qué podemos ayudarte?',
                'send' => 'Enviar',
                'back' => 'Volver al chat',
                'placeholder' => 'Escribe tu pregunta…',
                'note' => 'Respuestas automáticas generadas con IA. Pueden contener errores.',
                'error' => 'No he podido responder ahora mismo. Inténtalo de nuevo o contacta con nosotros.',
                'formError' => 'Rellena el nombre, un email válido y el mensaje.',
                'closed' => 'El asistente está fuera de horario. Si lo necesitas, puedes escribirnos por aquí:',
            ) : array(
                'open' => 'Open the assistant',
                'subtitle' => 'Automated assistant',
                'person' => 'Talk to a person',
                'menu' => 'Options',
                'reset' => 'New conversation',
                'orderEmail' => 'Email used for the purchase',
                'orderRef' => 'Order number or reference',
                'orderCheck' => 'Check order',
                'orderError' => 'Enter a valid email and the order number.',
                'orderMessage' => 'I would like to know the status of my order %ref%. The purchase email is %email%.',
                'close' => 'Close',
                'contactTitle' => 'Would you rather talk to a person?',
                'call' => 'Call',
                'write' => 'Write to us',
                'waText' => 'Hi, I come from the website chat.',
                'name' => 'Your name',
                'email' => 'Your email',
                'message' => 'How can we help you?',
                'send' => 'Send',
                'back' => 'Back to chat',
                'placeholder' => 'Type your question…',
                'note' => 'Automated AI answers. They may contain mistakes.',
                'error' => 'I could not answer right now. Try again or contact us.',
                'formError' => 'Please fill in your name, a valid email and the message.',
                'closed' => 'The assistant is outside opening hours. If you need help, you can write to us here:',
            ),
        )));

        $css = 'modules/' . $this->name . '/views/css/front.css';
        $js = 'modules/' . $this->name . '/views/js/front.js';
        if (method_exists($this->context->controller, 'registerStylesheet')) {
            $this->context->controller->registerStylesheet('josrachat-front', $css, array('media' => 'all', 'priority' => 900));
            $this->context->controller->registerJavascript('josrachat-front', $js, array('position' => 'bottom', 'priority' => 900));
        } else {
            $this->context->controller->addCSS($this->_path . 'views/css/front.css');
            $this->context->controller->addJS($this->_path . 'views/js/front.js');
        }

        return '';
    }

    /** Ventas atribuidas: pedidos del mismo carrito o del mismo cliente en los 7 días siguientes a chatear. */
    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }
        $order = $params['order'];
        try {
            $conv = Db::getInstance()->getRow(
                'SELECT `id_conversation` FROM `' . _DB_PREFIX_ . 'josrachat_conversation`
                 WHERE `id_shop` = ' . (int) $order->id_shop . ' AND `id_order` = 0 AND `messages` > 0
                   AND (`id_cart` = ' . (int) $order->id_cart . '
                        OR (`id_customer` = ' . (int) $order->id_customer . ' AND `id_customer` > 0 AND `date_upd` >= "' . pSQL(date('Y-m-d H:i:s', time() - 7 * 86400)) . '"))
                 ORDER BY (`id_cart` = ' . (int) $order->id_cart . ') DESC, `date_upd` DESC'
            );
            if ($conv) {
                $rate = (float) $order->conversion_rate > 0 ? (float) $order->conversion_rate : 1;
                Db::getInstance()->update('josrachat_conversation', array(
                    'id_order' => (int) $order->id,
                    'order_total' => (float) $order->total_paid_tax_incl / $rate,
                ), '`id_conversation` = ' . (int) $conv['id_conversation']);
            }
        } catch (Exception $e) {
            // Nunca romper un pedido por la estadística del chat.
            PrestaShopLogger::addLog('josrachat: no se pudo atribuir el pedido ' . (int) $order->id . ': ' . $e->getMessage(), 2, null, 'josrachat');
        }
    }

    // ================================================================ utilidades públicas

    /** Idioma para los correos: el del contexto si hay plantilla, si no español, si no inglés. */
    public static function mailLang()
    {
        $context = Context::getContext();
        $candidates = array($context->language->iso_code, 'es', 'en');
        foreach ($candidates as $iso) {
            $id = (int) Language::getIdByIso($iso);
            if ($id && is_file(_PS_MODULE_DIR_ . 'josrachat/mails/' . $iso . '/josrachat_contact.txt')) {
                return $id;
            }
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    public static function formatMoney($amount, $iso = null)
    {
        $context = Context::getContext();
        if (!$iso) {
            $iso = $context->currency ? $context->currency->iso_code : 'EUR';
        }
        try {
            if (method_exists($context, 'getCurrentLocale') && $context->getCurrentLocale()) {
                return $context->getCurrentLocale()->formatPrice((float) $amount, $iso);
            }
            // PrestaShop 1.6: formato de precios de la propia tienda.
            if (version_compare(_PS_VERSION_, '1.7.0.0', '<')) {
                $idCurrency = (int) Currency::getIdByIsoCode($iso);
                if ($idCurrency) {
                    return Tools::displayPrice((float) $amount, new Currency($idCurrency));
                }
            }
        } catch (Exception $e) {
            // sigue con el formato simple
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . ($iso === 'EUR' ? '€' : $iso);
    }

    /**
     * json_encode que nunca falla por texto mal codificado (descripciones antiguas en latin1, cortes raros…).
     * En PHP 7.2+ sustituye los caracteres inválidos; en versiones anteriores los limpia a mano.
     */
    public static function jsonEncode($data)
    {
        $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? constant('JSON_INVALID_UTF8_SUBSTITUTE') : 0;
        $json = json_encode($data, $flags);
        if ($json !== false) {
            return $json;
        }
        $clean = function ($value) use (&$clean) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $clean($v);
                }

                return $value;
            }
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                return mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1252');
            }

            return $value;
        };
        $json = json_encode($clean($data));

        return $json !== false ? $json : '{}';
    }

    /** Token aleatorio (PHP 5.6 no tiene random_bytes). */
    public static function randomToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(16));
        }

        return md5(uniqid(mt_rand(), true) . _COOKIE_KEY_);
    }

    /** Aviso informativo: Module::displayInformation() no existe en 1.6. */
    protected function infoBox($text)
    {
        if (method_exists($this, 'displayInformation')) {
            return $this->displayInformation($text);
        }

        return '<div class="alert alert-info">' . Tools::safeOutput($text) . '</div>';
    }

    /** Importe en euros para el back-office: más decimales cuando es menos de 1 €. */
    public static function eur($amount)
    {
        $amount = (float) $amount;

        return number_format($amount, abs($amount) < 1 && $amount != 0 ? 4 : 2, ',', '.') . ' €';
    }

    // ================================================================ back-office

    /** URL completa del back-office (evita el 404 de la ruta Symfony en PS 8/9). */
    protected function adminUrl(array $params = array(), $withToken = true)
    {
        $url = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . basename(_PS_ADMIN_DIR_)
            . '/index.php?controller=AdminModules&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        if ($withToken) {
            $url .= '&token=' . Tools::getAdminTokenLite('AdminModules');
        }
        foreach ($params as $k => $v) {
            $url .= '&' . urlencode($k) . '=' . urlencode((string) $v);
        }

        return $url;
    }

    public function getContent()
    {
        $updater = $this->getUpdater();
        $out = $updater->processRequest();
        $repair = $this->selfRepair();
        if ($repair !== '') {
            $out .= $this->displayError($repair);
        }

        $tab = Tools::getValue('josrachat_tab', 'config');
        if (!in_array($tab, array('config', 'schedule', 'knowledge', 'conversations', 'usage'), true)) {
            $tab = 'config';
        }

        $out .= $this->processActions($tab);
        // Una acción puede llevarnos a otra pestaña (por ejemplo, corregir una respuesta).
        $tab = Tools::getValue('josrachat_tab', $tab);

        $forms = $this->renderNav($tab);
        switch ($tab) {
            case 'knowledge':
                $forms .= $this->renderKnowledgeTab();
                break;
            case 'conversations':
                $forms .= $this->renderConversationsTab();
                break;
            case 'schedule':
                $forms .= $this->renderScheduleTab();
                break;
            case 'usage':
                $forms .= $this->renderUsageTab();
                break;
            default:
                $forms .= $this->renderConfigTab();
        }

        // Panel de actualizaciones: arriba si hay versión nueva, abajo si no
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $out . $panel . $forms
            : $out . $forms . $panel;
    }

    protected function renderNav($active)
    {
        $attention = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'josrachat_conversation`
             WHERE `id_shop` = ' . (int) $this->context->shop->id . ' AND (`need_human` = 1 OR `no_answer` = 1) AND `reviewed` = 0'
        );
        $tabs = array(
            'config' => array('label' => $this->l('Configuración'), 'icon' => 'icon-cogs', 'badge' => 0),
            'schedule' => array('label' => $this->l('Horario'), 'icon' => 'icon-time', 'badge' => 0),
            'knowledge' => array('label' => $this->l('Conocimiento'), 'icon' => 'icon-book', 'badge' => 0),
            'conversations' => array('label' => $this->l('Conversaciones'), 'icon' => 'icon-comments', 'badge' => $attention),
            'usage' => array('label' => $this->l('Consumo'), 'icon' => 'icon-bar-chart', 'badge' => 0),
        );
        foreach ($tabs as $k => &$t) {
            $t['url'] = $this->adminUrl(array('josrachat_tab' => $k));
        }
        unset($t);

        $warnings = array();
        if (!Configuration::get('JOSRACHAT_ACTIVE')) {
            $warnings[] = $this->l('El asistente está desactivado: no se muestra en la tienda.');
        } elseif (trim((string) Configuration::get('JOSRACHAT_API_KEY')) === '') {
            $warnings[] = $this->l('Falta la API Key de Anthropic: el asistente no se muestra hasta que la pongas.');
        }
        if (Configuration::get('JOSRACHAT_ACTIVE') && !JosrachatSchedule::isOpen()) {
            $next = JosrachatSchedule::nextOpening();
            $warnings[] = $this->l('Fuera de horario: el asistente está oculto en la tienda ahora mismo.') . ($next !== '' ? ' ' . sprintf($this->l('Vuelve a aparecer %s.'), $next) : '');
        }
        $block = JosrachatClaude::budgetBlock((int) $this->context->shop->id, $this);
        if ($block !== '') {
            $warnings[] = $this->l('El asistente no está respondiendo:') . ' ' . $block . ' ' . $this->l('Los clientes ven las opciones de contacto.');
        }

        $this->context->smarty->assign(array(
            'jrc_tabs' => $tabs,
            'jrc_active' => $active,
            'jrc_warnings' => $warnings,
        ));

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/nav.tpl');
    }

    // ---------------------------------------------------------------- acciones

    protected function processActions($tab)
    {
        $out = '';
        $db = Db::getInstance();
        $idShop = (int) $this->context->shop->id;

        // Configuración
        if (Tools::isSubmit('submitJosrachatConfig')) {
            $out .= $this->saveConfig();
        }
        if (Tools::isSubmit('submitJosrachatSchedule')) {
            $errors = JosrachatSchedule::saveFromRequest();
            if ($errors) {
                $out .= $this->displayError($this->l('No se ha guardado el horario:') . '<br>' . implode('<br>', array_map('Tools::safeOutput', $errors)));
            } else {
                $out .= $this->displayConfirmation($this->l('Horario guardado.'));
            }
        }
        if (Tools::isSubmit('submitJosrachatTest')) {
            $out .= $this->testConnection();
        }

        // Conocimiento: respuestas validadas
        if (Tools::getValue('jrc_export_faq')) {
            $this->exportFaq();
        }
        if (Tools::isSubmit('submitJosrachatImportFaq')) {
            $out .= $this->importFaq();
        }
        if (Tools::isSubmit('submitJosrachatKnowledge')) {
            $q = trim((string) Tools::getValue('jrc_question'));
            $a = trim((string) Tools::getValue('jrc_answer'));
            $id = (int) Tools::getValue('id_knowledge');
            $idMessage = (int) Tools::getValue('jrc_id_message');
            if ($q === '' || $a === '') {
                $out .= $this->displayError($this->l('Rellena la pregunta y la respuesta.'));
                $_GET['josrachat_tab'] = 'knowledge';
            } else {
                $row = array(
                    'question' => pSQL($q, true),
                    'answer' => pSQL($a, true),
                    'active' => (int) Tools::getValue('jrc_active', 1),
                    'date_upd' => date('Y-m-d H:i:s'),
                );
                if ($id) {
                    $db->update('josrachat_knowledge', $row, '`id_knowledge` = ' . $id . ' AND `id_shop` = ' . $idShop);
                } else {
                    $row['id_shop'] = $idShop;
                    $row['id_message'] = $idMessage;
                    $row['date_add'] = date('Y-m-d H:i:s');
                    $db->insert('josrachat_knowledge', $row);
                }
                if ($idMessage) {
                    $idConv = (int) $db->getValue('SELECT `id_conversation` FROM `' . _DB_PREFIX_ . 'josrachat_message` WHERE `id_message` = ' . $idMessage);
                    if ($idConv) {
                        $db->update('josrachat_conversation', array('corrected' => 1, 'reviewed' => 1), '`id_conversation` = ' . $idConv);
                    }
                }
                $out .= $this->displayConfirmation($this->l('Respuesta guardada. El asistente la usará a partir del próximo mensaje.'));
            }
        }
        if (Tools::isSubmit('deletejosrachat_knowledge')) {
            $db->delete('josrachat_knowledge', '`id_knowledge` = ' . (int) Tools::getValue('id_knowledge') . ' AND `id_shop` = ' . $idShop);
            $out .= $this->displayConfirmation($this->l('Respuesta eliminada.'));
        }
        if (Tools::isSubmit('statusjosrachat_knowledge')) {
            $db->execute('UPDATE `' . _DB_PREFIX_ . 'josrachat_knowledge` SET `active` = 1 - `active` WHERE `id_knowledge` = ' . (int) Tools::getValue('id_knowledge') . ' AND `id_shop` = ' . $idShop);
        }

        // Conocimiento: documentos
        if (Tools::isSubmit('submitJosrachatDocument')) {
            $out .= $this->saveDocument();
        }
        if (Tools::isSubmit('deletejosrachat_document')) {
            $db->delete('josrachat_document', '`id_document` = ' . (int) Tools::getValue('id_document') . ' AND `id_shop` = ' . $idShop);
            $out .= $this->displayConfirmation($this->l('Documento eliminado.'));
        }
        if (Tools::isSubmit('statusjosrachat_document')) {
            $db->execute('UPDATE `' . _DB_PREFIX_ . 'josrachat_document` SET `active` = 1 - `active` WHERE `id_document` = ' . (int) Tools::getValue('id_document') . ' AND `id_shop` = ' . $idShop);
        }

        // Conversaciones
        $idConv = (int) Tools::getValue('id_conversation');
        if ($idConv && Tools::getValue('jrc_do')) {
            $do = Tools::getValue('jrc_do');
            $sets = array(
                'reviewed' => array('reviewed' => 1),
                'unreviewed' => array('reviewed' => 0),
                'block' => array('blocked' => 1),
                'unblock' => array('blocked' => 0),
            );
            if (isset($sets[$do])) {
                $db->update('josrachat_conversation', $sets[$do], '`id_conversation` = ' . $idConv . ' AND `id_shop` = ' . $idShop);
                $out .= $this->displayConfirmation($this->l('Conversación actualizada.'));
            }
        }
        if (Tools::isSubmit('deletejosrachat_conversation') && $idConv) {
            if ($db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'josrachat_conversation` WHERE `id_conversation` = ' . $idConv . ' AND `id_shop` = ' . $idShop)) {
                $db->delete('josrachat_message', '`id_conversation` = ' . $idConv);
                $db->delete('josrachat_conversation', '`id_conversation` = ' . $idConv);
                $out .= $this->displayConfirmation($this->l('Conversación eliminada. Su consumo se mantiene en las estadísticas.'));
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- pestaña configuración

    protected function configFields()
    {
        return array(
            'JOSRACHAT_ACTIVE', 'JOSRACHAT_WORKSPACE', 'JOSRACHAT_MODEL', 'JOSRACHAT_BOT_NAME', 'JOSRACHAT_INSTRUCTIONS', 'JOSRACHAT_ORDER_INSTRUCTIONS', 'JOSRACHAT_COLOR',
            'JOSRACHAT_POSITION', 'JOSRACHAT_MOBILE', 'JOSRACHAT_WHATSAPP', 'JOSRACHAT_PHONE', 'JOSRACHAT_CONTACT_EMAIL',
            'JOSRACHAT_USD_EUR', 'JOSRACHAT_CAP_MONTH', 'JOSRACHAT_CAP_DAY', 'JOSRACHAT_ALERT_EMAIL',
            'JOSRACHAT_LIMIT_CONV_HOUR', 'JOSRACHAT_LIMIT_IP_DAY',
        );
    }

    protected function saveConfig()
    {
        $errors = array();
        $num = function ($v) {
            return (float) str_replace(',', '.', trim((string) $v));
        };

        foreach (array('JOSRACHAT_CONTACT_EMAIL', 'JOSRACHAT_ALERT_EMAIL') as $k) {
            $v = trim((string) Tools::getValue($k));
            if ($v !== '' && !Validate::isEmail($v)) {
                $errors[] = sprintf($this->l('El email "%s" no es válido.'), $v);
            }
        }
        $color = (string) Tools::getValue('JOSRACHAT_COLOR');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = $this->l('El color debe tener el formato #RRGGBB.');
        }
        if ($num(Tools::getValue('JOSRACHAT_USD_EUR')) <= 0) {
            $errors[] = $this->l('El cambio de dólar a euro debe ser mayor que 0.');
        }
        if ($errors) {
            return $this->displayError(implode('<br>', array_map('Tools::safeOutput', $errors)));
        }

        // Tarifas: van al modelo que se estaba viendo al abrir el formulario.
        $pricesModel = (string) Tools::getValue('JOSRACHAT_PRICES_MODEL');
        $models = JosrachatClaude::defaultModels();
        if (isset($models[$pricesModel])) {
            JosrachatClaude::savePrices($pricesModel, array(
                'in' => Tools::getValue('JOSRACHAT_PRICE_IN'),
                'cw' => Tools::getValue('JOSRACHAT_PRICE_CW'),
                'cr' => Tools::getValue('JOSRACHAT_PRICE_CR'),
                'out' => Tools::getValue('JOSRACHAT_PRICE_OUT'),
            ));
        }

        $newKey = trim((string) Tools::getValue('JOSRACHAT_API_KEY_NEW'));
        if ($newKey !== '') {
            if (strpos($newKey, 'sk-ant-') !== 0) {
                return $this->displayError($this->l('Esa no parece una API Key de Claude: tiene que empezar por sk-ant-. No se ha guardado nada.'));
            }
            Configuration::updateValue('JOSRACHAT_API_KEY', $newKey);
        }
        if (Tools::getValue('JOSRACHAT_API_KEY_DELETE')) {
            Configuration::updateValue('JOSRACHAT_API_KEY', '');
        }

        foreach ($this->configFields() as $k) {
            $v = Tools::getValue($k);
            if (in_array($k, array('JOSRACHAT_USD_EUR', 'JOSRACHAT_CAP_MONTH', 'JOSRACHAT_CAP_DAY'), true)) {
                $v = (string) max(0, $num($v));
            } elseif (in_array($k, array('JOSRACHAT_LIMIT_CONV_HOUR', 'JOSRACHAT_LIMIT_IP_DAY', 'JOSRACHAT_ACTIVE', 'JOSRACHAT_MOBILE'), true)) {
                $v = max(0, (int) $v);
            } elseif ($k === 'JOSRACHAT_MODEL' && !isset($models[$v])) {
                $v = 'claude-haiku-5-5';
            } elseif ($k === 'JOSRACHAT_POSITION') {
                $v = $v === 'left' ? 'left' : 'right';
            } else {
                $v = trim((string) $v);
            }
            Configuration::updateValue($k, $v);
        }

        $welcome = array();
        foreach (Language::getLanguages(false) as $lang) {
            $welcome[(int) $lang['id_lang']] = trim((string) Tools::getValue('JOSRACHAT_WELCOME_' . (int) $lang['id_lang']));
        }
        Configuration::updateValue('JOSRACHAT_WELCOME', $welcome);

        $msg = $this->l('Configuración guardada.');
        if (Configuration::get('JOSRACHAT_ACTIVE') && trim((string) Configuration::get('JOSRACHAT_API_KEY')) === '') {
            return $this->displayConfirmation($msg) . $this->displayWarning($this->l('Has activado el asistente pero falta la API Key: no saldrá en la tienda hasta que la pongas.'));
        }

        return $this->displayConfirmation($msg);
    }

    protected function testConnection()
    {
        $model = JosrachatClaude::currentModel();
        $res = JosrachatClaude::call(array(
            'model' => $model,
            'max_tokens' => 10,
            'messages' => array(array('role' => 'user', 'content' => 'Responde solo: OK')),
        ), 20);
        if (!$res['ok']) {
            return $this->displayError($this->l('La conexión con Anthropic ha fallado.') . ' ' . Tools::safeOutput($res['error']));
        }
        JosrachatClaude::logUsage((int) $this->context->shop->id, 0, 'prueba', $model, $res['usage']);

        return $this->displayConfirmation(sprintf($this->l('Conexión correcta con Anthropic usando %s.'), $model));
    }

    protected function renderConfigTab()
    {
        $models = JosrachatClaude::defaultModels();
        $model = JosrachatClaude::currentModel();
        $prices = JosrachatClaude::prices($model);
        $modelOptions = array();
        foreach ($models as $id => $m) {
            $modelOptions[] = array('id' => $id, 'name' => $m['label']);
        }
        $key = trim((string) Configuration::get('JOSRACHAT_API_KEY'));
        $keyDesc = $key !== ''
            ? sprintf($this->l('Guardada: %s. Déjalo vacío para mantenerla.'), Tools::substr($key, 0, 10) . '…' . Tools::substr($key, -4))
            : sprintf($this->l('Créala en %s y pégala aquí. Empieza por sk-ant-.'), '<a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">platform.claude.com/settings/keys</a>');
        $switch = function ($name) {
            return array(
                array('id' => $name . '_on', 'value' => 1, 'label' => $this->l('Sí')),
                array('id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')),
            );
        };

        $help = '<div class="alert alert-info" style="margin-bottom:0;">'
            . '<p><strong>' . $this->l('Cómo conseguir la API Key de Claude') . '</strong></p>'
            . '<ol style="margin:6px 0 6px 18px;padding:0;">'
            . '<li>' . sprintf($this->l('Entra en la consola de Claude: %s. Puedes usar el mismo email que en claude.ai, pero la API se paga aparte: la suscripción de claude.ai no sirve aquí.'), '<a href="https://platform.claude.com/" target="_blank" rel="noopener"><strong>platform.claude.com</strong></a>') . '</li>'
            . '<li>' . $this->l('Añade saldo en Settings > Billing. Es prepago: cuando se acaba, el asistente deja de responder y te llega un email.') . '</li>'
            . '<li>' . sprintf($this->l('Recomendado: crea un espacio de trabajo (workspace) por tienda en %s. Así ves el gasto de cada tienda por separado en la consola.'), '<a href="https://platform.claude.com/settings/workspaces" target="_blank" rel="noopener">Settings > Workspaces</a>') . '</li>'
            . '<li>' . sprintf($this->l('Crea la clave en %s con "Create key", elige ese workspace y cópiala: solo se ve una vez.'), '<a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">Settings > API Keys</a>') . '</li>'
            . '<li>' . $this->l('Pégala abajo, guarda y pulsa "Probar conexión". Si sale un error, el mensaje dice el motivo.') . '</li>'
            . '</ol>'
            . '<p style="margin:0;">' . sprintf($this->l('Guía oficial: %s'), '<a href="https://platform.claude.com/docs/es/get-api-key" target="_blank" rel="noopener">platform.claude.com/docs/es/get-api-key</a>') . '</p>'
            . '</div>';

        $forms = array();
        $forms[] = array('form' => array(
            'legend' => array('title' => $this->l('Asistente'), 'icon' => 'icon-comments'),
            'input' => array(
                array('type' => 'html', 'name' => 'jrc_api_help', 'html_content' => $help),
                array('type' => 'switch', 'label' => $this->l('Activar en la tienda'), 'name' => 'JOSRACHAT_ACTIVE', 'is_bool' => true, 'values' => $switch('JOSRACHAT_ACTIVE')),
                array('type' => 'text', 'label' => $this->l('API Key de Claude'), 'name' => 'JOSRACHAT_API_KEY_NEW', 'placeholder' => 'sk-ant-...', 'autocomplete' => false, 'desc' => $keyDesc, 'col' => 6),
                array('type' => 'text', 'label' => $this->l('ID del workspace'), 'name' => 'JOSRACHAT_WORKSPACE', 'placeholder' => 'wrkspc_...', 'col' => 4, 'desc' => sprintf($this->l('Solo si al probar la conexión te dice que la clave no está limitada a un workspace. Lo ves en %s. Si creaste la clave dentro de un workspace, déjalo vacío.'), '<a href="https://platform.claude.com/settings/workspaces" target="_blank" rel="noopener">Settings > Workspaces</a>')),
                array('type' => 'select', 'label' => $this->l('Modelo'), 'name' => 'JOSRACHAT_MODEL', 'options' => array('query' => $modelOptions, 'id' => 'id', 'name' => 'name'), 'desc' => $this->l('Haiku es el más barato y basta para atender una tienda. Sonnet razona mejor pero cuesta mucho más.')),
                array('type' => 'text', 'label' => $this->l('Nombre del asistente'), 'name' => 'JOSRACHAT_BOT_NAME', 'col' => 4),
                array('type' => 'textarea', 'label' => $this->l('Mensaje de bienvenida'), 'name' => 'JOSRACHAT_WELCOME', 'lang' => true, 'rows' => 2, 'col' => 9),
                array('type' => 'textarea', 'label' => $this->l('Instrucciones de la tienda'), 'name' => 'JOSRACHAT_INSTRUCTIONS', 'rows' => 7, 'col' => 9, 'desc' => $this->l('Lo que el asistente debe saber o cómo debe comportarse: tono, horarios, zonas de reparto, qué recomendar primero, qué no decir nunca… Ejemplo: "Tuteamos al cliente. Recogida en tienda de lunes a viernes de 9 a 14 h. Para guisos recomienda primero la ternera de nuestra categoría Guisos."')),
                array('type' => 'textarea', 'label' => $this->l('Respuesta sobre pedidos'), 'name' => 'JOSRACHAT_ORDER_INSTRUCTIONS', 'rows' => 12, 'col' => 9, 'desc' => $this->l('Formato y normas cuando un cliente pregunta por su pedido (estado, pago, envío, seguimiento e historial). Déjalo vacío para volver al formato por defecto.')),
                array('type' => 'color', 'label' => $this->l('Color'), 'name' => 'JOSRACHAT_COLOR'),
                array('type' => 'select', 'label' => $this->l('Posición'), 'name' => 'JOSRACHAT_POSITION', 'options' => array('query' => array(array('id' => 'right', 'name' => $this->l('Abajo a la derecha')), array('id' => 'left', 'name' => $this->l('Abajo a la izquierda'))), 'id' => 'id', 'name' => 'name')),
                array('type' => 'switch', 'label' => $this->l('Mostrar en móvil'), 'name' => 'JOSRACHAT_MOBILE', 'is_bool' => true, 'values' => $switch('JOSRACHAT_MOBILE')),
            ),
            'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosrachatConfig'),
            'buttons' => array(array('type' => 'submit', 'name' => 'submitJosrachatTest', 'title' => $this->l('Probar conexión'), 'icon' => 'process-icon-refresh', 'class' => 'pull-right')),
        ));
        $forms[] = array('form' => array(
            'legend' => array('title' => $this->l('Contacto con una persona'), 'icon' => 'icon-user'),
            'description' => $this->l('El asistente muestra estas opciones cuando el cliente lo pide, cuando no sabe responder o si hay una queja. El formulario siempre está disponible.'),
            'input' => array(
                array('type' => 'text', 'label' => $this->l('WhatsApp'), 'name' => 'JOSRACHAT_WHATSAPP', 'col' => 4, 'desc' => $this->l('Con prefijo de país y sin espacios, por ejemplo 34600111222. Vacío para no mostrarlo.')),
                array('type' => 'text', 'label' => $this->l('Teléfono'), 'name' => 'JOSRACHAT_PHONE', 'col' => 4, 'desc' => $this->l('Vacío para no mostrarlo.')),
                array('type' => 'text', 'label' => $this->l('Email que recibe los formularios'), 'name' => 'JOSRACHAT_CONTACT_EMAIL', 'col' => 4, 'desc' => $this->l('Vacío para usar el email de la tienda. Llega con la conversación del chat y responder va directo al cliente.')),
            ),
            'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosrachatConfig'),
        ));
        $forms[] = array('form' => array(
            'legend' => array('title' => $this->l('Gasto y límites'), 'icon' => 'icon-money'),
            'input' => array(
                array('type' => 'text', 'label' => $this->l('Tope mensual'), 'name' => 'JOSRACHAT_CAP_MONTH', 'suffix' => '€', 'col' => 2, 'desc' => $this->l('Al llegar, el asistente deja de llamar a la IA y muestra las opciones de contacto. Aviso por email al 80 %. 0 = sin tope.')),
                array('type' => 'text', 'label' => $this->l('Tope diario'), 'name' => 'JOSRACHAT_CAP_DAY', 'suffix' => '€', 'col' => 2, 'desc' => $this->l('Protege de un pico de abuso en un solo día. 0 = sin tope.')),
                array('type' => 'text', 'label' => $this->l('Email de avisos'), 'name' => 'JOSRACHAT_ALERT_EMAIL', 'col' => 4, 'desc' => $this->l('Recibe el aviso del 80 % y los errores graves (sin saldo, clave no válida). Vacío para usar el de la tienda.')),
                array('type' => 'text', 'label' => $this->l('Mensajes por conversación y hora'), 'name' => 'JOSRACHAT_LIMIT_CONV_HOUR', 'col' => 2, 'desc' => $this->l('0 = sin límite.')),
                array('type' => 'text', 'label' => $this->l('Mensajes por IP y día'), 'name' => 'JOSRACHAT_LIMIT_IP_DAY', 'col' => 2, 'desc' => $this->l('Evita que alguien gaste tu saldo a propósito. 0 = sin límite.')),
                array('type' => 'html', 'name' => 'jrc_prices_title', 'html_content' => '<p class="help-block">' . sprintf($this->l('Tarifas de Anthropic para %s en dólares por millón de tokens. Vienen puestas las publicadas; cámbialas solo si Anthropic las cambia (anthropic.com/pricing).'), '<strong>' . Tools::safeOutput($model) . '</strong>') . '</p>'),
                array('type' => 'text', 'label' => $this->l('Entrada'), 'name' => 'JOSRACHAT_PRICE_IN', 'prefix' => '$', 'col' => 2),
                array('type' => 'text', 'label' => $this->l('Escritura en caché'), 'name' => 'JOSRACHAT_PRICE_CW', 'prefix' => '$', 'col' => 2),
                array('type' => 'text', 'label' => $this->l('Lectura de caché'), 'name' => 'JOSRACHAT_PRICE_CR', 'prefix' => '$', 'col' => 2),
                array('type' => 'text', 'label' => $this->l('Salida'), 'name' => 'JOSRACHAT_PRICE_OUT', 'prefix' => '$', 'col' => 2),
                array('type' => 'text', 'label' => $this->l('Cambio dólar a euro'), 'name' => 'JOSRACHAT_USD_EUR', 'col' => 2, 'desc' => $this->l('Anthropic cobra en dólares. 1 $ = este número de €.')),
                array('type' => 'hidden', 'name' => 'JOSRACHAT_PRICES_MODEL'),
            ),
            'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosrachatConfig'),
        ));

        $values = array();
        foreach ($this->configFields() as $k) {
            $values[$k] = Tools::getValue($k, Configuration::get($k));
        }
        $values['JOSRACHAT_MODEL'] = $model;
        $values['JOSRACHAT_API_KEY_NEW'] = '';
        $values['JOSRACHAT_PRICES_MODEL'] = $model;
        $values['JOSRACHAT_PRICE_IN'] = $prices['in'];
        $values['JOSRACHAT_PRICE_CW'] = $prices['cw'];
        $values['JOSRACHAT_PRICE_CR'] = $prices['cr'];
        $values['JOSRACHAT_PRICE_OUT'] = $prices['out'];
        foreach (Language::getLanguages(false) as $lang) {
            $values['JOSRACHAT_WELCOME'][(int) $lang['id_lang']] = Configuration::get('JOSRACHAT_WELCOME', (int) $lang['id_lang']);
        }

        $helper = $this->formHelper('config', 'submitJosrachatConfig');
        $helper->tpl_vars['fields_value'] = $values;

        return $helper->generateForm($forms);
    }

    protected function formHelper($tab, $submit)
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->name;
        $helper->identifier = 'id_' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->adminUrl(array('josrachat_tab' => $tab), false);
        $helper->submit_action = $submit;
        $helper->show_toolbar = false;
        $default = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->default_form_language = $default;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $languages = Language::getLanguages(false);
        foreach ($languages as &$l) {
            $l['is_default'] = (int) ((int) $l['id_lang'] === $default);
        }
        unset($l);
        $helper->languages = $languages;
        $helper->tpl_vars = array('languages' => $languages, 'id_language' => $this->context->language->id);

        return $helper;
    }

    protected function listHelper($tab, $table, $identifier, $title, array $actions, $total, array $extra = array())
    {
        $helper = new HelperList();
        $helper->module = $this;
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->show_toolbar = false;
        $helper->no_link = true;
        $helper->identifier = $identifier;
        $helper->table = $table;
        $helper->list_id = $table;
        $helper->actions = $actions;
        $helper->title = $title;
        $helper->listTotal = $total;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->adminUrl(array_merge(array('josrachat_tab' => $tab), $extra), false);

        return $helper;
    }

    // ---------------------------------------------------------------- pestaña conocimiento

    protected function renderKnowledgeTab()
    {
        $out = '';
        $db = Db::getInstance();
        $idShop = (int) $this->context->shop->id;

        // Formulario de respuesta validada (nuevo, edición o corrección desde una conversación)
        $editId = Tools::isSubmit('updatejosrachat_knowledge') ? (int) Tools::getValue('id_knowledge') : 0;
        $fromMessage = (int) Tools::getValue('correct_message');
        $values = array('id_knowledge' => 0, 'jrc_question' => '', 'jrc_answer' => '', 'jrc_active' => 1, 'jrc_id_message' => 0);
        $legend = $this->l('Nueva respuesta validada');
        if ($editId) {
            $row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'josrachat_knowledge` WHERE `id_knowledge` = ' . $editId . ' AND `id_shop` = ' . $idShop);
            if ($row) {
                $values = array('id_knowledge' => $editId, 'jrc_question' => $row['question'], 'jrc_answer' => $row['answer'], 'jrc_active' => (int) $row['active'], 'jrc_id_message' => (int) $row['id_message']);
                $legend = $this->l('Editar respuesta validada');
            }
        } elseif ($fromMessage) {
            $msg = $db->getRow(
                'SELECT m.`id_message`, m.`id_conversation`, m.`content` FROM `' . _DB_PREFIX_ . 'josrachat_message` m
                 INNER JOIN `' . _DB_PREFIX_ . 'josrachat_conversation` c ON c.`id_conversation` = m.`id_conversation` AND c.`id_shop` = ' . $idShop . '
                 WHERE m.`id_message` = ' . $fromMessage . ' AND m.`role` = "assistant"'
            );
            if ($msg) {
                $question = (string) $db->getValue(
                    'SELECT `content` FROM `' . _DB_PREFIX_ . 'josrachat_message`
                     WHERE `id_conversation` = ' . (int) $msg['id_conversation'] . ' AND `role` = "user" AND `id_message` < ' . (int) $msg['id_message'] . '
                     ORDER BY `id_message` DESC'
                );
                $values = array('id_knowledge' => 0, 'jrc_question' => $question, 'jrc_answer' => $msg['content'], 'jrc_active' => 1, 'jrc_id_message' => (int) $msg['id_message']);
                $legend = $this->l('Corregir respuesta del asistente');
                $out .= $this->infoBox($this->l('Escribe cómo debería haber respondido. Puedes generalizar la pregunta para que sirva a otros clientes (por ejemplo "¿Hacéis envíos a Canarias?" en vez de la frase exacta).'));
            }
        }
        if (Tools::isSubmit('submitJosrachatKnowledge') && (trim((string) Tools::getValue('jrc_question')) === '' || trim((string) Tools::getValue('jrc_answer')) === '')) {
            foreach (array_keys($values) as $k) {
                $values[$k] = Tools::getValue($k, $values[$k]);
            }
        }

        $form = array('form' => array(
            'legend' => array('title' => $legend, 'icon' => 'icon-check'),
            'description' => $this->l('Preguntas con la respuesta exacta que quieres que dé el asistente. Tienen prioridad sobre todo lo demás.'),
            'input' => array(
                array('type' => 'hidden', 'name' => 'id_knowledge'),
                array('type' => 'hidden', 'name' => 'jrc_id_message'),
                array('type' => 'textarea', 'label' => $this->l('Pregunta'), 'name' => 'jrc_question', 'rows' => 2, 'col' => 9, 'required' => true),
                array('type' => 'textarea', 'label' => $this->l('Respuesta correcta'), 'name' => 'jrc_answer', 'rows' => 5, 'col' => 9, 'required' => true),
                array('type' => 'switch', 'label' => $this->l('Activa'), 'name' => 'jrc_active', 'is_bool' => true, 'values' => array(
                    array('id' => 'jrc_active_on', 'value' => 1, 'label' => $this->l('Sí')),
                    array('id' => 'jrc_active_off', 'value' => 0, 'label' => $this->l('No')),
                )),
            ),
            'submit' => array('title' => $this->l('Guardar respuesta'), 'name' => 'submitJosrachatKnowledge'),
        ));
        if ($editId || $fromMessage) {
            $form['form']['buttons'] = array(array('href' => $this->adminUrl(array('josrachat_tab' => 'knowledge')), 'title' => $this->l('Cancelar'), 'icon' => 'process-icon-cancel'));
        }
        $helper = $this->formHelper('knowledge', 'submitJosrachatKnowledge');
        $helper->tpl_vars['fields_value'] = $values;
        $out .= $helper->generateForm(array($form));

        // Lista de respuestas validadas
        $rows = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'josrachat_knowledge` WHERE `id_shop` = ' . $idShop . ' ORDER BY `id_knowledge` DESC');
        foreach ($rows as &$r) {
            $r['question'] = Tools::substr($r['question'], 0, 140);
            $r['answer'] = Tools::substr($r['answer'], 0, 180) . (Tools::strlen($r['answer']) > 180 ? '…' : '');
            $r['date_upd'] = date('d/m/Y H:i', strtotime($r['date_upd']));
        }
        unset($r);
        $list = $this->listHelper('knowledge', 'josrachat_knowledge', 'id_knowledge', $this->l('Respuestas validadas'), array('edit', 'delete'), count($rows));
        $out .= $list->generateList($rows, array(
            'id_knowledge' => array('title' => 'ID', 'class' => 'fixed-width-xs', 'search' => false, 'orderby' => false),
            'question' => array('title' => $this->l('Pregunta'), 'search' => false, 'orderby' => false),
            'answer' => array('title' => $this->l('Respuesta'), 'search' => false, 'orderby' => false),
            'active' => array('title' => $this->l('Activa'), 'type' => 'bool', 'active' => 'status', 'align' => 'center', 'class' => 'fixed-width-sm', 'search' => false, 'orderby' => false),
            'date_upd' => array('title' => $this->l('Actualizada'), 'class' => 'fixed-width-lg', 'search' => false, 'orderby' => false),
        ));

        // Exportar e importar respuestas validadas
        $importForm = array('form' => array(
            'legend' => array('title' => $this->l('Exportar e importar respuestas validadas'), 'icon' => 'icon-exchange'),
            'description' => $this->l('Sirve para copiarlas de una tienda a otra o para editarlas en Excel. El archivo es un CSV con las columnas pregunta, respuesta y activa (1 o 0), separado por punto y coma y en UTF-8. Al importar también se aceptan archivos separados por comas o tabuladores.'),
            'input' => array(
                array('type' => 'html', 'name' => 'jrc_export_btn', 'html_content' => '<a class="btn btn-default" href="' . Tools::safeOutput($this->adminUrl(array('josrachat_tab' => 'knowledge', 'jrc_export_faq' => 1))) . '"><i class="icon-download"></i> ' . $this->l('Exportar respuestas validadas (CSV)') . '</a> <span class="help-block" style="display:inline;margin-left:8px;">' . sprintf($this->l('%d respuestas en esta tienda.'), count($rows)) . '</span>'),
                array('type' => 'file', 'label' => $this->l('Archivo CSV para importar'), 'name' => 'jrc_faq_file'),
                array('type' => 'select', 'label' => $this->l('Modo'), 'name' => 'jrc_faq_mode', 'options' => array('query' => array(
                    array('id' => 'add', 'name' => $this->l('Añadir: las preguntas que ya existen se saltan')),
                    array('id' => 'replace', 'name' => $this->l('Sustituir: borra todas las respuestas validadas y carga las del archivo')),
                ), 'id' => 'id', 'name' => 'name')),
            ),
            'submit' => array('title' => $this->l('Importar'), 'name' => 'submitJosrachatImportFaq', 'icon' => 'process-icon-upload'),
        ));
        $helper = $this->formHelper('knowledge', 'submitJosrachatImportFaq');
        $helper->tpl_vars['fields_value'] = array('jrc_faq_mode' => 'add');
        $out .= $helper->generateForm(array($importForm));

        // Documentos
        $docForm = array('form' => array(
            'legend' => array('title' => $this->l('Añadir documento'), 'icon' => 'icon-file-text'),
            'description' => $this->l('Catálogos, fichas técnicas, tarifas, preguntas frecuentes… El asistente los lee cuando le hacen falta. Sube un archivo PDF, TXT, CSV o MD, o pega el texto directamente.'),
            'input' => array(
                array('type' => 'text', 'label' => $this->l('Título'), 'name' => 'jrc_doc_title', 'col' => 6, 'desc' => $this->l('Descriptivo: el asistente decide si leerlo por el título. Ejemplo: "Guía de cortes de ternera y cómo cocinarlos".')),
                array('type' => 'file', 'label' => $this->l('Archivo'), 'name' => 'jrc_doc_file', 'desc' => $this->l('Máximo 10 MB. Si el servidor no puede leer el PDF, el texto se extrae con la IA (cuesta unos céntimos y queda en Consumo).')),
                array('type' => 'textarea', 'label' => $this->l('O pega el texto'), 'name' => 'jrc_doc_text', 'rows' => 6, 'col' => 9),
            ),
            'submit' => array('title' => $this->l('Añadir documento'), 'name' => 'submitJosrachatDocument'),
        ));
        $helper = $this->formHelper('knowledge', 'submitJosrachatDocument');
        $helper->tpl_vars['fields_value'] = array('jrc_doc_title' => '', 'jrc_doc_text' => '');
        $out .= $helper->generateForm(array($docForm));

        $docs = $db->executeS('SELECT `id_document`, `title`, `filename`, `chars`, `active`, `date_add` FROM `' . _DB_PREFIX_ . 'josrachat_document` WHERE `id_shop` = ' . $idShop . ' ORDER BY `id_document` DESC');
        foreach ($docs as &$d) {
            $d['chars'] = number_format((int) $d['chars'], 0, ',', '.');
            $d['date_add'] = date('d/m/Y H:i', strtotime($d['date_add']));
        }
        unset($d);
        $list = $this->listHelper('knowledge', 'josrachat_document', 'id_document', $this->l('Documentos'), array('delete'), count($docs));
        $out .= $list->generateList($docs, array(
            'id_document' => array('title' => 'ID', 'class' => 'fixed-width-xs', 'search' => false, 'orderby' => false),
            'title' => array('title' => $this->l('Título'), 'search' => false, 'orderby' => false),
            'filename' => array('title' => $this->l('Archivo'), 'search' => false, 'orderby' => false),
            'chars' => array('title' => $this->l('Caracteres'), 'align' => 'right', 'class' => 'fixed-width-md', 'search' => false, 'orderby' => false),
            'active' => array('title' => $this->l('Activo'), 'type' => 'bool', 'active' => 'status', 'align' => 'center', 'class' => 'fixed-width-sm', 'search' => false, 'orderby' => false),
            'date_add' => array('title' => $this->l('Subido'), 'class' => 'fixed-width-lg', 'search' => false, 'orderby' => false),
        ));

        $out .= '<div class="alert alert-info">' . $this->l('Además de esto, el asistente ya consulta solo los productos, categorías, precios, ofertas, stock, combinaciones, características, transportistas, páginas de información (CMS) y pedidos de la tienda. No hace falta copiarlos aquí.') . '</div>';

        return $out;
    }

    /** Descarga las respuestas validadas de la tienda en CSV (UTF-8 con BOM, punto y coma). */
    protected function exportFaq()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `question`, `answer`, `active` FROM `' . _DB_PREFIX_ . 'josrachat_knowledge`
             WHERE `id_shop` = ' . (int) $this->context->shop->id . ' ORDER BY `id_knowledge` ASC'
        );
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array('pregunta', 'respuesta', 'activa'), ';');
        foreach ($rows ? $rows : array() as $r) {
            fputcsv($fh, array($r['question'], $r['answer'], (int) $r['active']), ';');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $shop = Tools::str2url((string) Configuration::get('PS_SHOP_NAME'));
        $filename = 'respuestas-validadas' . ($shop !== '' ? '-' . $shop : '') . '-' . date('Y-m-d') . '.csv';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    /** Importa un CSV de respuestas validadas. Devuelve el mensaje con el resultado. */
    protected function importFaq()
    {
        $file = isset($_FILES['jrc_faq_file']) ? $_FILES['jrc_faq_file'] : null;
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $this->displayError($this->l('Elige el archivo CSV que quieres importar.'));
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->displayError(sprintf($this->l('El archivo no ha llegado bien (código %d). Puede que supere el tamaño máximo de subida del servidor (%s).'), (int) $file['error'], ini_get('upload_max_filesize')));
        }
        $raw = (string) Tools::file_get_contents($file['tmp_name']);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (trim($raw) === '') {
            return $this->displayError($this->l('El archivo está vacío.'));
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            // Guardado desde Excel en Windows: viene en Windows-1252.
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        // Separador: el que más aparece en la primera línea.
        $firstLine = strtok($raw, "\n");
        $delimiter = ';';
        $best = -1;
        foreach (array(';', ',', "\t") as $d) {
            $n = substr_count($firstLine, $d);
            if ($n > $best) {
                $best = $n;
                $delimiter = $d;
            }
        }

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        $colQ = 0;
        $colA = 1;
        $colActive = 2;
        $line = 0;
        $records = array();
        $errors = array();
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            ++$line;
            if ($row === array(null) || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            if ($line === 1) {
                $head = array_map(function ($h) {
                    return Tools::strtolower(trim((string) $h));
                }, $row);
                $isHeader = false;
                foreach ($head as $i => $h) {
                    if (in_array($h, array('pregunta', 'question'), true)) {
                        $colQ = $i;
                        $isHeader = true;
                    } elseif (in_array($h, array('respuesta', 'answer'), true)) {
                        $colA = $i;
                        $isHeader = true;
                    } elseif (in_array($h, array('activa', 'activo', 'active'), true)) {
                        $colActive = $i;
                        $isHeader = true;
                    }
                }
                if ($isHeader) {
                    continue;
                }
            }
            $q = isset($row[$colQ]) ? trim((string) $row[$colQ]) : '';
            $a = isset($row[$colA]) ? trim((string) $row[$colA]) : '';
            if ($q === '' || $a === '') {
                $errors[] = sprintf($this->l('Fila %d: falta la pregunta o la respuesta.'), $line);
                continue;
            }
            $activeVal = isset($row[$colActive]) ? Tools::strtolower(trim((string) $row[$colActive])) : '1';
            $records[] = array(
                'question' => $q,
                'answer' => $a,
                'active' => in_array($activeVal, array('0', 'no', 'false', 'n'), true) ? 0 : 1,
            );
        }
        fclose($fh);

        if (!$records) {
            $msg = $this->l('No se ha importado nada: el archivo no tiene filas válidas. Comprueba que tiene las columnas pregunta y respuesta.');
            if ($errors) {
                $msg .= '<br>' . implode('<br>', array_map('Tools::safeOutput', array_slice($errors, 0, 10)));
            }

            return $this->displayError($msg);
        }

        $db = Db::getInstance();
        $idShop = (int) $this->context->shop->id;
        $mode = Tools::getValue('jrc_faq_mode') === 'replace' ? 'replace' : 'add';
        $normalize = function ($text) {
            return trim(preg_replace('/\s+/u', ' ', Tools::strtolower((string) $text)));
        };
        $existing = array();
        if ($mode === 'replace') {
            $db->delete('josrachat_knowledge', '`id_shop` = ' . $idShop);
        } else {
            foreach ($db->executeS('SELECT `question` FROM `' . _DB_PREFIX_ . 'josrachat_knowledge` WHERE `id_shop` = ' . $idShop) as $r) {
                $existing[$normalize($r['question'])] = true;
            }
        }

        $added = 0;
        $skipped = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($records as $r) {
            $key = $normalize($r['question']);
            if (isset($existing[$key])) {
                ++$skipped;
                continue;
            }
            $existing[$key] = true;
            $ok = $db->insert('josrachat_knowledge', array(
                'id_shop' => $idShop,
                'question' => pSQL($r['question'], true),
                'answer' => pSQL($r['answer'], true),
                'id_message' => 0,
                'active' => (int) $r['active'],
                'date_add' => $now,
                'date_upd' => $now,
            ));
            if ($ok) {
                ++$added;
            } else {
                $errors[] = sprintf($this->l('No se pudo guardar "%s": %s'), Tools::substr($r['question'], 0, 60), $db->getMsgError());
            }
        }

        $msg = ($mode === 'replace'
            ? sprintf($this->l('Importación terminada (modo sustituir): %d respuestas cargadas.'), $added)
            : sprintf($this->l('Importación terminada: %d añadidas, %d saltadas porque la pregunta ya existía.'), $added, $skipped));
        $out = $this->displayConfirmation($msg);
        if ($errors) {
            $out .= $this->displayWarning(sprintf($this->l('%d filas con problemas:'), count($errors)) . '<br>' . implode('<br>', array_map('Tools::safeOutput', array_slice($errors, 0, 15))) . (count($errors) > 15 ? '<br>…' : ''));
        }

        return $out;
    }

    protected function saveDocument()
    {
        @set_time_limit(300);
        $title = trim((string) Tools::getValue('jrc_doc_title'));
        $text = trim((string) Tools::getValue('jrc_doc_text'));
        $filename = '';
        $file = isset($_FILES['jrc_doc_file']) ? $_FILES['jrc_doc_file'] : null;
        $hasFile = $file && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return $this->displayError(sprintf($this->l('El archivo no ha llegado bien (código %d). Puede que supere el tamaño máximo de subida del servidor (%s).'), (int) $file['error'], ini_get('upload_max_filesize')));
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                return $this->displayError($this->l('El archivo pasa de 10 MB.'));
            }
            $filename = basename($file['name']);
            $ext = Tools::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, array('txt', 'csv', 'md'), true)) {
                $text = (string) Tools::file_get_contents($file['tmp_name']);
                if (!mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
                }
            } elseif ($ext === 'pdf') {
                $result = $this->pdfToText($file['tmp_name']);
                if ($result['error'] !== '') {
                    return $this->displayError($result['error']);
                }
                $text = $result['text'];
            } else {
                return $this->displayError($this->l('Formato no admitido. Usa PDF, TXT, CSV o MD. Para Word o Excel, guárdalo antes como PDF.'));
            }
            if ($title === '') {
                $title = pathinfo($filename, PATHINFO_FILENAME);
            }
        }

        $text = trim($text);
        if ($text === '') {
            return $this->displayError($this->l('No hay texto que guardar: sube un archivo o pega el contenido.'));
        }
        if ($title === '') {
            return $this->displayError($this->l('Ponle un título al documento.'));
        }

        Db::getInstance()->insert('josrachat_document', array(
            'id_shop' => (int) $this->context->shop->id,
            'title' => pSQL(Tools::substr($title, 0, 255)),
            'filename' => pSQL(Tools::substr($filename, 0, 255)),
            'content' => pSQL($text, true),
            'chars' => (int) Tools::strlen($text),
            'active' => 1,
            'date_add' => date('Y-m-d H:i:s'),
        ));

        return $this->displayConfirmation(sprintf($this->l('Documento "%s" añadido (%s caracteres).'), Tools::safeOutput($title), number_format(Tools::strlen($text), 0, ',', '.')));
    }

    /** PDF a texto: primero pdftotext del servidor; si no está, la IA lo extrae. */
    protected function pdfToText($path)
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
            $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
            if ($bin !== '') {
                $text = (string) @shell_exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
                if (Tools::strlen(trim($text)) > 50) {
                    return array('text' => $text, 'error' => '');
                }
            }
        }

        $model = JosrachatClaude::currentModel();
        $res = JosrachatClaude::call(array(
            'model' => $model,
            'max_tokens' => 16000,
            'messages' => array(array('role' => 'user', 'content' => array(
                array('type' => 'document', 'source' => array('type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) Tools::file_get_contents($path)))),
                array('type' => 'text', 'text' => 'Transcribe todo el texto útil de este documento tal cual, en su idioma original, conservando títulos, listas y tablas en texto plano. No añadas comentarios.'),
            ))),
        ), 180);
        if (!$res['ok']) {
            return array('text' => '', 'error' => $this->l('No se pudo leer el PDF.') . ' ' . Tools::safeOutput($res['error']));
        }
        JosrachatClaude::logUsage((int) $this->context->shop->id, 0, 'documento', $model, $res['usage']);
        $text = '';
        foreach ($res['data']['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') {
                $text .= $block['text'];
            }
        }
        if (Tools::strlen(trim($text)) < 20) {
            return array('text' => '', 'error' => $this->l('El PDF no tiene texto legible (puede ser una imagen escaneada de mala calidad).'));
        }

        return array('text' => $text, 'error' => '');
    }

    // ---------------------------------------------------------------- pestaña conversaciones

    protected function renderConversationsTab()
    {
        $idConv = (int) Tools::getValue('id_conversation');
        if ($idConv && Tools::isSubmit('viewjosrachat_conversation')) {
            $view = $this->renderConversation($idConv);
            if ($view !== '') {
                return $view;
            }
        }

        $db = Db::getInstance();
        $idShop = (int) $this->context->shop->id;
        $filter = Tools::getValue('jrc_filter', 'attention');
        $filters = array(
            'attention' => array('label' => $this->l('Para revisar'), 'where' => '(c.`need_human` = 1 OR c.`no_answer` = 1) AND c.`reviewed` = 0'),
            'all' => array('label' => $this->l('Todas'), 'where' => 'NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'josrachat_message` sm WHERE sm.`id_conversation` = c.`id_conversation` AND sm.`role` = "spam")'),
            'no_answer' => array('label' => $this->l('Sin respuesta'), 'where' => 'c.`no_answer` = 1'),
            'human' => array('label' => $this->l('Pidieron persona'), 'where' => 'c.`need_human` = 1'),
            'order' => array('label' => $this->l('Con pedido'), 'where' => 'c.`id_order` > 0'),
            'blocked' => array('label' => $this->l('Bloqueadas'), 'where' => 'c.`blocked` = 1'),
            // formularios de contacto que el filtro tomó por robots (no se mandó el correo)
            'spam' => array('label' => $this->l('Spam'), 'where' => 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'josrachat_message` sm WHERE sm.`id_conversation` = c.`id_conversation` AND sm.`role` = "spam")'),
        );
        if (!isset($filters[$filter])) {
            $filter = 'attention';
        }
        $where = 'c.`id_shop` = ' . $idShop . ' AND c.`messages` > 0 AND ' . $filters[$filter]['where'];
        $total = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'josrachat_conversation` c WHERE ' . $where);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) Tools::getValue('jrc_page', 1)));

        $rows = $db->executeS(
            'SELECT c.*, cu.`firstname`, cu.`lastname`, cu.`email` AS customer_email,
                (SELECT m.`content` FROM `' . _DB_PREFIX_ . 'josrachat_message` m WHERE m.`id_conversation` = c.`id_conversation` AND m.`role` IN ("user", "contact", "spam") ORDER BY m.`id_message` ASC LIMIT 1) AS first_question,
                EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'josrachat_message` sm2 WHERE sm2.`id_conversation` = c.`id_conversation` AND sm2.`role` = "spam") AS is_spam
             FROM `' . _DB_PREFIX_ . 'josrachat_conversation` c
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = c.`id_customer`
             WHERE ' . $where . '
             ORDER BY c.`date_upd` DESC
             LIMIT ' . (($page - 1) * self::PAGE_SIZE) . ', ' . self::PAGE_SIZE
        );
        $list = array();
        foreach ($rows as $r) {
            $who = trim($r['firstname'] . ' ' . $r['lastname']);
            if ($who === '') {
                $who = $r['contact_email'] !== '' ? $r['contact_email'] : $this->l('Visitante');
            }
            $list[] = array(
                'id_conversation' => (int) $r['id_conversation'],
                'date_upd' => date('d/m/Y H:i', strtotime($r['date_upd'])),
                'who' => $who,
                'first_question' => Tools::substr((string) $r['first_question'], 0, 90) . (Tools::strlen((string) $r['first_question']) > 90 ? '…' : ''),
                'messages' => (int) $r['messages'],
                'cost' => self::eur(JosrachatClaude::toEur((float) $r['cost'])),
                'status' => $r,
                'order' => (int) $r['id_order'] ? '#' . (int) $r['id_order'] . ' · ' . self::formatMoney((float) $r['order_total']) : '',
            );
        }

        $this->context->smarty->assign(array(
            'jrc_filters' => $filters,
            'jrc_filter' => $filter,
            'jrc_filter_urls' => array_combine(array_keys($filters), array_map(function ($k) {
                return $this->adminUrl(array('josrachat_tab' => 'conversations', 'jrc_filter' => $k));
            }, array_keys($filters))),
            'jrc_total' => $total,
        ));
        $out = $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/conversations_filter.tpl');

        $helper = $this->listHelper('conversations', 'josrachat_conversation', 'id_conversation', sprintf($this->l('Conversaciones: %s'), $filters[$filter]['label']), array('view', 'delete'), count($list), array('jrc_filter' => $filter));
        $out .= $helper->generateList($list, array(
            'id_conversation' => array('title' => 'ID', 'class' => 'fixed-width-xs', 'search' => false, 'orderby' => false),
            'date_upd' => array('title' => $this->l('Última actividad'), 'class' => 'fixed-width-lg', 'search' => false, 'orderby' => false),
            'who' => array('title' => $this->l('Cliente'), 'search' => false, 'orderby' => false),
            'first_question' => array('title' => $this->l('Primera pregunta'), 'search' => false, 'orderby' => false),
            'messages' => array('title' => $this->l('Mensajes'), 'align' => 'center', 'class' => 'fixed-width-sm', 'search' => false, 'orderby' => false),
            'cost' => array('title' => $this->l('Coste'), 'align' => 'right', 'class' => 'fixed-width-md', 'search' => false, 'orderby' => false),
            'status' => array('title' => $this->l('Estado'), 'callback' => 'listStatusBadges', 'callback_object' => $this, 'search' => false, 'orderby' => false),
            'order' => array('title' => $this->l('Pedido'), 'class' => 'fixed-width-lg', 'search' => false, 'orderby' => false),
        ));

        if ($pages > 1) {
            $this->context->smarty->assign(array(
                'jrc_page' => $page,
                'jrc_pages' => $pages,
                'jrc_prev' => $page > 1 ? $this->adminUrl(array('josrachat_tab' => 'conversations', 'jrc_filter' => $filter, 'jrc_page' => $page - 1)) : '',
                'jrc_next' => $page < $pages ? $this->adminUrl(array('josrachat_tab' => 'conversations', 'jrc_filter' => $filter, 'jrc_page' => $page + 1)) : '',
            ));
            $out .= $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/pagination.tpl');
        }

        return $out;
    }

    /** Callback de HelperList: etiquetas de estado. */
    public function listStatusBadges($value, $row)
    {
        $r = is_array($value) ? $value : (isset($row['status']) && is_array($row['status']) ? $row['status'] : array());
        $badges = array();
        if (!empty($r['no_answer'])) {
            $badges[] = '<span class="label label-danger">' . $this->l('Sin respuesta') . '</span>';
        }
        if (!empty($r['need_human'])) {
            $badges[] = '<span class="label label-warning">' . $this->l('Pidió persona') . '</span>';
        }
        if (!empty($r['corrected'])) {
            $badges[] = '<span class="label label-info">' . $this->l('Corregida') . '</span>';
        }
        if (!empty($r['reviewed'])) {
            $badges[] = '<span class="label label-success">' . $this->l('Revisada') . '</span>';
        }
        if (!empty($r['blocked'])) {
            $badges[] = '<span class="label label-default">' . $this->l('Bloqueada') . '</span>';
        }
        if (!empty($r['is_spam'])) {
            $badges[] = '<span class="label label-danger">' . $this->l('Spam') . '</span>';
        }

        return implode(' ', $badges);
    }

    protected function renderConversation($idConv)
    {
        $db = Db::getInstance();
        $conv = $db->getRow(
            'SELECT c.*, cu.`firstname`, cu.`lastname`, cu.`email` AS customer_email FROM `' . _DB_PREFIX_ . 'josrachat_conversation` c
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = c.`id_customer`
             WHERE c.`id_conversation` = ' . (int) $idConv . ' AND c.`id_shop` = ' . (int) $this->context->shop->id
        );
        if (!$conv) {
            return '';
        }
        $messages = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'josrachat_message` WHERE `id_conversation` = ' . (int) $idConv . ' ORDER BY `id_message` ASC');
        $toolNames = array(
            'buscar_productos' => $this->l('buscó productos'),
            'ver_producto' => $this->l('miró una ficha'),
            'productos_mas_vendidos' => $this->l('miró los más vendidos'),
            'gastos_envio' => $this->l('consultó envíos'),
            'leer_pagina' => $this->l('leyó una página o documento'),
            'estado_pedido' => $this->l('consultó un pedido'),
            'contactar_persona' => $this->l('ofreció contacto con una persona'),
        );
        foreach ($messages as &$m) {
            $m['date'] = date('d/m/Y H:i', strtotime($m['date_add']));
            $m['cost_eur'] = (float) $m['cost'] > 0 ? self::eur(JosrachatClaude::toEur((float) $m['cost'])) : '';
            $tools = array();
            foreach (array_filter(explode(',', (string) $m['tools'])) as $t) {
                $tools[] = isset($toolNames[$t]) ? $toolNames[$t] : $t;
            }
            $m['tools_text'] = implode(', ', $tools);
            $m['correct_url'] = $m['role'] === 'assistant' ? $this->adminUrl(array('josrachat_tab' => 'knowledge', 'correct_message' => (int) $m['id_message'])) : '';
        }
        unset($m);

        $who = trim($conv['firstname'] . ' ' . $conv['lastname']);
        $email = $conv['contact_email'] !== '' ? $conv['contact_email'] : (string) $conv['customer_email'];
        $base = array('josrachat_tab' => 'conversations', 'id_conversation' => (int) $idConv, 'viewjosrachat_conversation' => 1);

        $this->context->smarty->assign(array(
            'jrc_conv' => $conv,
            'jrc_who' => $who !== '' ? $who : $this->l('Visitante sin sesión'),
            'jrc_email' => $email,
            'jrc_mailto' => $email !== '' ? 'mailto:' . $email . '?subject=' . rawurlencode(sprintf($this->l('Tu consulta en %s'), Configuration::get('PS_SHOP_NAME'))) : '',
            'jrc_messages' => $messages,
            'jrc_date_add' => date('d/m/Y H:i', strtotime($conv['date_add'])),
            'jrc_cost' => self::eur(JosrachatClaude::toEur((float) $conv['cost'])),
            'jrc_tokens' => number_format((int) $conv['tokens_in'], 0, ',', '.') . ' / ' . number_format((int) $conv['tokens_out'], 0, ',', '.'),
            'jrc_order' => (int) $conv['id_order'] ? '#' . (int) $conv['id_order'] . ' · ' . self::formatMoney((float) $conv['order_total']) : '',
            'jrc_back' => $this->adminUrl(array('josrachat_tab' => 'conversations')),
            'jrc_url_reviewed' => $this->adminUrl(array_merge($base, array('jrc_do' => (int) $conv['reviewed'] ? 'unreviewed' : 'reviewed'))),
            'jrc_url_block' => $this->adminUrl(array_merge($base, array('jrc_do' => (int) $conv['blocked'] ? 'unblock' : 'block'))),
            'jrc_status' => $this->listStatusBadges($conv, array()),
        ));

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/conversation.tpl');
    }

    // ---------------------------------------------------------------- pestaña horario

    protected function renderScheduleTab()
    {
        $schedule = JosrachatSchedule::get();
        // Si el guardado ha fallado, se vuelve a pintar lo que el usuario había puesto.
        if (Tools::isSubmit('submitJosrachatSchedule')) {
            foreach (JosrachatSchedule::$days as $d) {
                $schedule[$d] = array(
                    'enabled' => (int) Tools::getValue('jrc_day_' . $d),
                    't1_from' => JosrachatSchedule::sanitizeTime(Tools::getValue('jrc_t1_from_' . $d)),
                    't1_to' => JosrachatSchedule::sanitizeTime(Tools::getValue('jrc_t1_to_' . $d)),
                    't2_enabled' => (int) Tools::getValue('jrc_t2_' . $d),
                    't2_from' => JosrachatSchedule::sanitizeTime(Tools::getValue('jrc_t2_from_' . $d)),
                    't2_to' => JosrachatSchedule::sanitizeTime(Tools::getValue('jrc_t2_to_' . $d)),
                );
            }
        }
        $now = JosrachatSchedule::now();
        $this->context->smarty->assign(array(
            'jrc_action' => $this->adminUrl(array('josrachat_tab' => 'schedule')),
            'jrc_sched_enabled' => (int) Tools::getValue('JOSRACHAT_SCHEDULE_ENABLED', Configuration::get('JOSRACHAT_SCHEDULE_ENABLED')),
            'jrc_schedule' => $schedule,
            'jrc_days' => JosrachatSchedule::$days,
            'jrc_day_labels' => JosrachatSchedule::labels(),
            'jrc_open_now' => JosrachatSchedule::isOpen(),
            'jrc_next_open' => JosrachatSchedule::nextOpening(),
            'jrc_now' => $now->format('d/m/Y H:i'),
            'jrc_tz' => $now->getTimezone()->getName(),
        ));

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/schedule.tpl');
    }

    // ---------------------------------------------------------------- pestaña consumo

    protected function renderUsageTab()
    {
        $db = Db::getInstance();
        $idShop = (int) $this->context->shop->id;
        $u = _DB_PREFIX_ . 'josrachat_usage';
        $c = _DB_PREFIX_ . 'josrachat_conversation';

        $today = JosrachatClaude::toEur(JosrachatClaude::spentSince($idShop, date('Y-m-d 00:00:00')));
        $month = JosrachatClaude::toEur(JosrachatClaude::spentSince($idShop, date('Y-m-01 00:00:00')));
        $prevStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $prev = JosrachatClaude::toEur((float) $db->getValue('SELECT IFNULL(SUM(`cost`), 0) FROM `' . $u . '` WHERE `id_shop` = ' . $idShop . ' AND `date_add` >= "' . pSQL($prevStart) . '" AND `date_add` < "' . pSQL(date('Y-m-01 00:00:00')) . '"'));
        $convMonth = (int) $db->getValue('SELECT COUNT(*) FROM `' . $c . '` WHERE `id_shop` = ' . $idShop . ' AND `messages` > 0 AND `date_add` >= "' . pSQL(date('Y-m-01 00:00:00')) . '"');
        $orders = $db->getRow('SELECT COUNT(*) AS n, IFNULL(SUM(`order_total`), 0) AS total FROM `' . $c . '` WHERE `id_shop` = ' . $idShop . ' AND `id_order` > 0 AND `date_upd` >= "' . pSQL(date('Y-m-01 00:00:00')) . '"');
        $capMonth = (float) str_replace(',', '.', (string) Configuration::get('JOSRACHAT_CAP_MONTH'));
        $capDay = (float) str_replace(',', '.', (string) Configuration::get('JOSRACHAT_CAP_DAY'));

        $days = $db->executeS(
            'SELECT DATE(`date_add`) AS d, COUNT(*) AS calls, COUNT(DISTINCT IF(`id_conversation` > 0, `id_conversation`, NULL)) AS convs,
                SUM(`tokens_in`) AS tin, SUM(`tokens_cache_write`) AS tcw, SUM(`tokens_cache_read`) AS tcr, SUM(`tokens_out`) AS tout, SUM(`cost`) AS cost
             FROM `' . $u . '` WHERE `id_shop` = ' . $idShop . ' AND `date_add` >= "' . pSQL(date('Y-m-d 00:00:00', strtotime('-29 days'))) . '"
             GROUP BY DATE(`date_add`) ORDER BY d DESC'
        );
        $max = 0;
        foreach ($days as &$d) {
            $d['eur'] = JosrachatClaude::toEur((float) $d['cost']);
            $max = max($max, $d['eur']);
        }
        unset($d);
        foreach ($days as &$d) {
            $d['pct'] = $max > 0 ? (int) round($d['eur'] / $max * 100) : 0;
            $d['date'] = date('d/m/Y', strtotime($d['d']));
            $d['eur_txt'] = self::eur($d['eur']);
            $d['tin_txt'] = number_format((int) $d['tin'] + (int) $d['tcw'], 0, ',', '.');
            $d['tcr_txt'] = number_format((int) $d['tcr'], 0, ',', '.');
            $d['tout_txt'] = number_format((int) $d['tout'], 0, ',', '.');
        }
        unset($d);

        $months = $db->executeS(
            'SELECT DATE_FORMAT(`date_add`, "%Y-%m") AS m, SUM(`cost`) AS cost, SUM(`tokens_in` + `tokens_cache_write` + `tokens_cache_read`) AS tin, SUM(`tokens_out`) AS tout
             FROM `' . $u . '` WHERE `id_shop` = ' . $idShop . ' AND `date_add` >= "' . pSQL(date('Y-m-01 00:00:00', strtotime('-11 months'))) . '"
             GROUP BY m ORDER BY m DESC'
        );
        $convByMonth = array();
        foreach ($db->executeS(
            'SELECT DATE_FORMAT(`date_add`, "%Y-%m") AS m, COUNT(*) AS n, SUM(IF(`id_order` > 0, 1, 0)) AS orders, SUM(`order_total`) AS sales
             FROM `' . $c . '` WHERE `id_shop` = ' . $idShop . ' AND `messages` > 0 AND `date_add` >= "' . pSQL(date('Y-m-01 00:00:00', strtotime('-11 months'))) . '"
             GROUP BY m'
        ) as $r) {
            $convByMonth[$r['m']] = $r;
        }
        foreach ($months as &$m) {
            $m['label'] = Tools::substr($m['m'], 5, 2) . '/' . Tools::substr($m['m'], 0, 4);
            $m['eur_txt'] = self::eur(JosrachatClaude::toEur((float) $m['cost']));
            $m['tin_txt'] = number_format((int) $m['tin'], 0, ',', '.');
            $m['tout_txt'] = number_format((int) $m['tout'], 0, ',', '.');
            $cm = isset($convByMonth[$m['m']]) ? $convByMonth[$m['m']] : array('n' => 0, 'orders' => 0, 'sales' => 0);
            $m['convs'] = (int) $cm['n'];
            $m['orders'] = (int) $cm['orders'];
            $m['sales_txt'] = self::formatMoney((float) $cm['sales']);
        }
        unset($m);

        $byType = $db->executeS('SELECT `type`, COUNT(*) AS n, SUM(`cost`) AS cost FROM `' . $u . '` WHERE `id_shop` = ' . $idShop . ' AND `date_add` >= "' . pSQL(date('Y-m-01 00:00:00')) . '" GROUP BY `type`');
        $typeNames = array('chat' => $this->l('Respuestas a clientes'), 'documento' => $this->l('Lectura de PDF'), 'prueba' => $this->l('Pruebas de conexión'));
        foreach ($byType as &$t) {
            $t['label'] = isset($typeNames[$t['type']]) ? $typeNames[$t['type']] : $t['type'];
            $t['eur_txt'] = self::eur(JosrachatClaude::toEur((float) $t['cost']));
        }
        unset($t);

        $this->context->smarty->assign(array(
            'jrc_today' => self::eur($today),
            'jrc_month' => self::eur($month),
            'jrc_prev' => self::eur($prev),
            'jrc_cap_month' => $capMonth > 0 ? self::eur($capMonth) : '',
            'jrc_cap_day' => $capDay > 0 ? self::eur($capDay) : '',
            'jrc_month_pct' => $capMonth > 0 ? min(100, (int) round($month / $capMonth * 100)) : 0,
            'jrc_day_pct' => $capDay > 0 ? min(100, (int) round($today / $capDay * 100)) : 0,
            'jrc_conv_month' => $convMonth,
            'jrc_avg' => $convMonth > 0 ? self::eur($month / $convMonth) : '-',
            'jrc_orders' => (int) $orders['n'],
            'jrc_sales' => self::formatMoney((float) $orders['total']),
            'jrc_days' => $days,
            'jrc_months' => $months,
            'jrc_types' => $byType,
            'jrc_model' => JosrachatClaude::currentModel(),
            'jrc_rate' => (string) Configuration::get('JOSRACHAT_USD_EUR'),
        ));

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/usage.tpl');
    }
}
