<?php
/**
 * josrachat - Filtro de spam del formulario de contacto del asistente.
 *
 * Los robots abren el widget, cogen el código y envían el formulario con letras al azar
 * («eMtQwEmSupjClFcGnyAB»). Lo que se considera spam no manda correo: se guarda en la
 * conversación (filtro «Spam» del back-office) por si alguna vez cae un cliente de verdad.
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosrachatSpam
{
    /** segundos mínimos entre abrir el asistente y enviar el formulario */
    const MIN_SECONDS = 4;

    /** formularios por IP en 24 horas (contando también los bloqueados) */
    const MAX_PER_IP_DAY = 3;

    /**
     * Motivo por el que el formulario parece de un robot, o '' si parece de una persona.
     *
     * @param string   $name
     * @param string   $email
     * @param string   $message
     * @param int|null $elapsed  segundos desde que se abrió el asistente (null si no se sabe)
     * @param string   $trap     campo trampa (invisible: una persona lo deja vacío)
     * @param int      $typed    veces que se escribió o pegó en el mensaje (-1 si el widget es antiguo)
     */
    public static function reason($name, $email, $message, $elapsed, $trap, $typed)
    {
        if (trim((string) $trap) !== '') {
            return 'campo trampa relleno';
        }
        if ($elapsed !== null && $elapsed < self::MIN_SECONDS) {
            return 'enviado ' . (int) $elapsed . ' s después de abrir el asistente';
        }
        if ((int) $typed === 0) {
            return 'nadie escribió en el mensaje';
        }
        if (self::looksRandom($name)) {
            return 'nombre con letras al azar';
        }
        if (self::looksRandom($message)) {
            return 'mensaje con letras al azar';
        }

        return '';
    }

    /**
     * Texto de letras al azar: una sola «palabra» larga, solo letras y cambiando de mayúsculas a
     * minúsculas muchas veces (eMtQwEmSupjClFcGnyAB). Un nombre como «McDonald» o un mensaje
     * normal (con espacios) no entra.
     */
    public static function looksRandom($text)
    {
        $t = trim((string) $text);
        if (Tools::strlen($t) < 10 || preg_match('/\s/u', $t) || !preg_match('/^[A-Za-z]+$/', $t)) {
            return false;
        }
        $changes = 0;
        $prev = null;
        foreach (str_split($t) as $ch) {
            $upper = ctype_upper($ch);
            if ($prev !== null && $upper !== $prev) {
                ++$changes;
            }
            $prev = $upper;
        }

        return $changes >= 5;
    }

    /** ¿Esta IP ya ha enviado el máximo de formularios en las últimas 24 horas? */
    public static function ipOverLimit($ip)
    {
        $n = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'josrachat_message`
             WHERE `ip` = "' . pSQL((string) $ip) . '" AND `role` IN ("contact", "spam")
               AND `date_add` >= "' . pSQL(date('Y-m-d H:i:s', time() - 86400)) . '"'
        );

        return $n >= self::MAX_PER_IP_DAY;
    }

    /**
     * Repasa los formularios ya guardados y pasa a spam los de letras al azar (al actualizar a 2.0.11).
     *
     * @return int formularios pasados a spam
     */
    public static function reclassifyOld()
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $rows = $db->executeS('SELECT `id_message`, `id_conversation`, `content` FROM `' . $p . 'josrachat_message` WHERE `role` = "contact"');
        $n = 0;
        foreach ($rows ? $rows : array() as $r) {
            // el contenido es «nombre <email>\nmensaje»
            $parts = explode("\n", (string) $r['content'], 2);
            $name = trim(preg_replace('/<[^>]*>\s*$/', '', $parts[0]));
            $msg = isset($parts[1]) ? $parts[1] : '';
            $why = self::looksRandom($name) ? 'nombre con letras al azar' : (self::looksRandom($msg) ? 'mensaje con letras al azar' : '');
            if ($why === '') {
                continue;
            }
            $db->update('josrachat_message', array('role' => 'spam', 'error' => pSQL('Spam: ' . $why)), '`id_message` = ' . (int) $r['id_message']);
            // si la conversación no tiene nada más que esto, deja de estar «para revisar»
            $other = (int) $db->getValue('SELECT COUNT(*) FROM `' . $p . 'josrachat_message` WHERE `id_conversation` = ' . (int) $r['id_conversation'] . ' AND `role` <> "spam"');
            if (!$other) {
                $db->update('josrachat_conversation', array('need_human' => 0, 'reviewed' => 1), '`id_conversation` = ' . (int) $r['id_conversation']);
            }
            ++$n;
        }

        return $n;
    }
}
