<?php
/**
 * josrachat - Punto de entrada AJAX del widget.
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatDb.php';
require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatClaude.php';
require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatTools.php';
require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatBot.php';
require_once _PS_MODULE_DIR_ . 'josrachat/classes/JosrachatSchedule.php';

class JosrachatAjaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    const MAX_LENGTH = 800;

    public function postProcess()
    {
        @set_time_limit(120);
        JosrachatDb::ensure();

        if (!Configuration::get('JOSRACHAT_ACTIVE')) {
            $this->out(array('ok' => false, 'error' => 'disabled'));
        }
        // Fuera de horario el asistente no responde; el formulario de contacto sigue funcionando.
        if (Tools::getValue('action') !== 'contact' && !JosrachatSchedule::isOpen()) {
            $this->out(array('ok' => false, 'error' => 'closed'));
        }

        $action = Tools::getValue('action');
        $cookie = $this->context->cookie;

        if ($action === 'init') {
            if (empty($cookie->josrachat_sid) || !preg_match('/^[a-f0-9]{32}$/', $cookie->josrachat_sid)) {
                $cookie->josrachat_sid = Josrachat::randomToken();
            }
            if (empty($cookie->josrachat_tok)) {
                $cookie->josrachat_tok = Josrachat::randomToken();
            }
            $bot = new JosrachatBot($this->context, $this->module);
            $conv = $bot->getConversation($cookie->josrachat_sid);
            $history = array();
            if ($conv) {
                foreach ($bot->history((int) $conv['id_conversation'], 40) as $m) {
                    $history[] = array('role' => $m['role'], 'text' => $m['content']);
                }
            }
            $this->out(array(
                'ok' => true,
                'token' => $cookie->josrachat_tok,
                'history' => $history,
                'contact' => $conv ? (bool) $conv['need_human'] : false,
            ));
        }

        if (empty($cookie->josrachat_tok) || !hash_equals((string) $cookie->josrachat_tok, (string) Tools::getValue('token'))) {
            $this->out(array('ok' => false, 'error' => 'token'));
        }

        if ($action === 'send') {
            $this->send();
        }
        if ($action === 'contact') {
            $this->contact();
        }
        if ($action === 'reset') {
            // Nueva conversación: la anterior se queda guardada en el back-office.
            $this->context->cookie->josrachat_sid = Josrachat::randomToken();
            $this->out(array('ok' => true));
        }

        $this->out(array('ok' => false, 'error' => 'action'));
    }

    protected function send()
    {
        $text = trim((string) Tools::getValue('message'));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
        if ($text === '') {
            $this->out(array('ok' => false, 'error' => 'empty'));
        }
        if (Tools::strlen($text) > self::MAX_LENGTH) {
            $text = Tools::substr($text, 0, self::MAX_LENGTH);
        }

        $bot = new JosrachatBot($this->context, $this->module);
        $sid = (string) $this->context->cookie->josrachat_sid;
        $conv = $bot->getConversation($sid);
        if (!$conv) {
            $conv = $bot->createConversation($sid);
        }
        if (!$conv) {
            $this->out(array('ok' => false, 'error' => 'db'));
        }

        $limit = $bot->rateLimit($conv);
        if ($limit !== '') {
            $es = in_array($this->context->language->iso_code, array('es', 'gl', 'ca'), true);
            $this->out(array(
                'ok' => true,
                'reply' => $es ? 'Has enviado muchos mensajes seguidos. Si lo necesitas, contacta con nosotros por aquí:' : 'You have sent too many messages. If you need help, contact us here:',
                'contact' => true,
            ));
        }

        $result = $bot->reply($conv, $text, (int) Tools::getValue('viewing'));
        $this->out(array(
            'ok' => true,
            'reply' => $result['reply'],
            'contact' => !empty($result['contact']),
            'orderForm' => !empty($result['orderForm']),
        ));
    }

    protected function contact()
    {
        $name = trim((string) Tools::getValue('name'));
        $email = trim((string) Tools::getValue('email'));
        $message = trim((string) Tools::getValue('message'));
        $es = in_array($this->context->language->iso_code, array('es', 'gl', 'ca'), true);

        if ($name === '' || !Validate::isName($name) || !Validate::isEmail($email) || $message === '' || !Validate::isMessage($message)) {
            $this->out(array('ok' => false, 'error' => $es ? 'Revisa el nombre, el email y el mensaje.' : 'Please check your name, email and message.'));
        }
        // Como mucho 3 formularios por conversación.
        $bot = new JosrachatBot($this->context, $this->module);
        $sid = (string) $this->context->cookie->josrachat_sid;
        $conv = $bot->getConversation($sid);
        if (!$conv) {
            $conv = $bot->createConversation($sid);
        }
        $sent = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'josrachat_message` WHERE `id_conversation` = ' . (int) $conv['id_conversation'] . ' AND `role` = "contact"'
        );
        if ($sent >= 3 || (int) $conv['blocked']) {
            $this->out(array('ok' => false, 'error' => $es ? 'Ya hemos recibido tus mensajes, te responderemos lo antes posible.' : 'We already have your messages and will reply soon.'));
        }

        $to = (string) Configuration::get('JOSRACHAT_CONTACT_EMAIL');
        if (!Validate::isEmail($to)) {
            $to = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        $message = Tools::substr($message, 0, 3000);

        // Últimos mensajes del chat para que quien conteste tenga contexto.
        $lines = array();
        foreach ($bot->history((int) $conv['id_conversation'], 12) as $m) {
            $lines[] = ($m['role'] === 'user' ? 'Cliente: ' : 'Asistente: ') . $m['content'];
        }
        $transcript = $lines ? implode("\n\n", $lines) : '(sin mensajes en el chat)';

        $ok = Mail::Send(
            Josrachat::mailLang(),
            'josrachat_contact',
            'Mensaje desde el asistente de la tienda: ' . $name,
            array(
                '{name}' => $name,
                '{email}' => $email,
                '{message}' => nl2br(Tools::safeOutput($message)),
                '{message_txt}' => $message,
                '{transcript}' => nl2br(Tools::safeOutput($transcript)),
                '{transcript_txt}' => $transcript,
                '{conversation}' => (int) $conv['id_conversation'],
                '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
            ),
            $to,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'josrachat/mails/',
            false,
            (int) $this->context->shop->id,
            null,
            $email,
            $name
        );

        Db::getInstance()->insert('josrachat_message', array(
            'id_conversation' => (int) $conv['id_conversation'],
            'role' => 'contact',
            'content' => pSQL($name . ' <' . $email . ">\n" . $message),
            'ip' => pSQL(Tools::getRemoteAddr()),
            'error' => $ok ? '' : 'No se pudo enviar el correo (revisa la configuración de correo de la tienda)',
            'date_add' => date('Y-m-d H:i:s'),
        ));
        Db::getInstance()->update('josrachat_conversation', array(
            'contact_email' => pSQL($email),
            'need_human' => 1,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_conversation` = ' . (int) $conv['id_conversation']);

        if (!$ok) {
            $this->out(array('ok' => false, 'error' => $es ? 'No se ha podido enviar ahora mismo. Inténtalo por WhatsApp o teléfono.' : 'It could not be sent right now. Please try WhatsApp or phone.'));
        }
        $this->out(array('ok' => true, 'reply' => $es ? '¡Recibido! Te responderemos a ' . $email . ' lo antes posible.' : 'Received! We will reply to ' . $email . ' as soon as possible.'));
    }

    protected function out(array $data)
    {
        $this->context->cookie->write();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        die(Josrachat::jsonEncode($data));
    }
}
