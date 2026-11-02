<?php
/**
 * josrachat - Llamadas a la API de Anthropic, tarifas y registro de consumo.
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosrachatClaude
{
    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    /**
     * Modelos ofrecidos y tarifas por defecto en USD por millón de tokens
     * (entrada, escritura de caché 5 min, lectura de caché, salida).
     * Son editables en la configuración porque Anthropic las cambia.
     */
    public static function defaultModels()
    {
        return array(
            'claude-haiku-5-5' => array('label' => 'Claude Haiku 5.5 (el más barato, recomendado)', 'in' => 0.10, 'cw' => 0.125, 'cr' => 0.01, 'out' => 0.50),
            'claude-sonnet-5-5' => array('label' => 'Claude Sonnet 5.5 (más listo, unas 20 veces más caro)', 'in' => 2.00, 'cw' => 2.50, 'cr' => 0.10, 'out' => 10.00),
            'claude-haiku-4-5' => array('label' => 'Claude Haiku 4.5 (generación anterior)', 'in' => 1.00, 'cw' => 1.25, 'cr' => 0.10, 'out' => 5.00),
        );
    }

    public static function currentModel()
    {
        $model = (string) Configuration::get('JOSRACHAT_MODEL');
        $models = self::defaultModels();

        return isset($models[$model]) ? $model : 'claude-haiku-5-5';
    }

    /** Tarifas guardadas para un modelo, mezcladas con las de fábrica. */
    public static function prices($model)
    {
        $defaults = self::defaultModels();
        $base = isset($defaults[$model]) ? $defaults[$model] : $defaults['claude-haiku-5-5'];
        $saved = json_decode((string) Configuration::get('JOSRACHAT_PRICES'), true);
        if (is_array($saved) && isset($saved[$model]) && is_array($saved[$model])) {
            foreach (array('in', 'cw', 'cr', 'out') as $k) {
                if (isset($saved[$model][$k]) && is_numeric($saved[$model][$k])) {
                    $base[$k] = (float) $saved[$model][$k];
                }
            }
        }

        return $base;
    }

    public static function savePrices($model, array $prices)
    {
        $saved = json_decode((string) Configuration::get('JOSRACHAT_PRICES'), true);
        if (!is_array($saved)) {
            $saved = array();
        }
        $saved[$model] = array();
        foreach (array('in', 'cw', 'cr', 'out') as $k) {
            $saved[$model][$k] = isset($prices[$k]) ? max(0, (float) str_replace(',', '.', $prices[$k])) : 0;
        }
        Configuration::updateValue('JOSRACHAT_PRICES', json_encode($saved));
    }

    /** Coste en USD de una respuesta según su bloque usage. */
    public static function cost($model, array $usage)
    {
        $p = self::prices($model);

        return (self::u($usage, 'input_tokens') * $p['in']
            + self::u($usage, 'cache_creation_input_tokens') * $p['cw']
            + self::u($usage, 'cache_read_input_tokens') * $p['cr']
            + self::u($usage, 'output_tokens') * $p['out']) / 1000000;
    }

    public static function u(array $usage, $key)
    {
        return isset($usage[$key]) ? (int) $usage[$key] : 0;
    }

    /** USD -> EUR con el cambio configurado. */
    public static function toEur($usd)
    {
        $rate = (float) str_replace(',', '.', (string) Configuration::get('JOSRACHAT_USD_EUR'));
        if ($rate <= 0) {
            $rate = 0.92;
        }

        return $usd * $rate;
    }

    /**
     * Llama a la API. Devuelve array('ok' => bool, 'data' => respuesta, 'error' => motivo legible, 'usage' => ...).
     * Reintenta una vez si Anthropic está saturado o hay un corte de red.
     */
    public static function call(array $payload, $timeout = 45)
    {
        $apiKey = trim((string) Configuration::get('JOSRACHAT_API_KEY'));
        if ($apiKey === '') {
            return array('ok' => false, 'error' => 'No hay API Key de Anthropic configurada.', 'fatal' => true);
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'El servidor no tiene la extensión cURL de PHP.', 'fatal' => true);
        }

        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::API_VERSION,
        );
        $workspace = trim((string) Configuration::get('JOSRACHAT_WORKSPACE'));
        if ($workspace !== '') {
            $headers[] = 'anthropic-workspace-id: ' . $workspace;
        }

        $attempt = 0;
        do {
            ++$attempt;
            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => Josrachat::jsonEncode($payload),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
            ));
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $retry = ($raw === false || in_array($http, array(500, 502, 503, 529), true)) && $attempt < 2;
            if ($retry) {
                sleep(2);
            }
        } while ($retry);

        if ($raw === false) {
            return array('ok' => false, 'error' => 'No se pudo conectar con Anthropic: ' . $curlError);
        }

        $data = json_decode($raw, true);
        if ($http === 200 && is_array($data)) {
            return array('ok' => true, 'data' => $data, 'usage' => isset($data['usage']) ? $data['usage'] : array());
        }

        $msg = (is_array($data) && isset($data['error']['message'])) ? $data['error']['message'] : Tools::substr((string) $raw, 0, 300);
        $fatal = false;
        switch ($http) {
            case 401:
                $reason = 'La API Key no es válida o se ha revocado.';
                $fatal = true;
                break;
            case 403:
                $reason = 'La API Key no tiene permiso para este modelo.';
                $fatal = true;
                break;
            case 404:
                $reason = 'El modelo configurado no existe en Anthropic.';
                $fatal = true;
                break;
            case 429:
                $reason = 'Anthropic ha limitado las peticiones (demasiadas a la vez).';
                break;
            case 529:
            case 503:
                $reason = 'Anthropic está saturado en este momento.';
                break;
            default:
                $reason = 'Anthropic ha devuelto un error HTTP ' . $http . '.';
        }
        if (stripos($msg, 'anthropic-workspace-id') !== false || stripos($msg, 'not scoped to a workspace') !== false) {
            $reason = 'Esta API Key no está limitada a un espacio de trabajo (workspace): pon el ID del workspace en la configuración o crea la clave dentro de un workspace.';
            $fatal = true;
        } elseif (stripos($msg, 'Workspace') !== false && $http === 404) {
            $reason = 'El ID del espacio de trabajo (workspace) configurado no existe o la clave no tiene acceso a él.';
            $fatal = true;
        }
        if (stripos($msg, 'credit balance') !== false) {
            $reason = 'No queda saldo en la cuenta de Anthropic.';
            $fatal = true;
        }

        return array('ok' => false, 'error' => $reason . ' Detalle: ' . $msg, 'fatal' => $fatal, 'http' => $http);
    }

    /** Guarda una fila de consumo y devuelve el coste en USD. */
    public static function logUsage($idShop, $idConversation, $type, $model, array $usage)
    {
        $cost = self::cost($model, $usage);
        Db::getInstance()->insert('josrachat_usage', array(
            'id_shop' => (int) $idShop,
            'id_conversation' => (int) $idConversation,
            'type' => pSQL($type),
            'model' => pSQL($model),
            'tokens_in' => self::u($usage, 'input_tokens'),
            'tokens_cache_write' => self::u($usage, 'cache_creation_input_tokens'),
            'tokens_cache_read' => self::u($usage, 'cache_read_input_tokens'),
            'tokens_out' => self::u($usage, 'output_tokens'),
            'cost' => (float) $cost,
            'date_add' => date('Y-m-d H:i:s'),
        ));

        return $cost;
    }

    /** Gasto en USD desde una fecha (Y-m-d H:i:s) para la tienda. */
    public static function spentSince($idShop, $since)
    {
        return (float) Db::getInstance()->getValue(
            'SELECT IFNULL(SUM(`cost`), 0) FROM `' . _DB_PREFIX_ . 'josrachat_usage`
             WHERE `id_shop` = ' . (int) $idShop . ' AND `date_add` >= "' . pSQL($since) . '"'
        );
    }

    /**
     * Comprueba los topes. Devuelve '' si se puede seguir o el motivo si no.
     * Además manda el aviso del 80 % una vez al mes.
     */
    public static function budgetBlock($idShop, Module $module)
    {
        $month = self::toEur(self::spentSince($idShop, date('Y-m-01 00:00:00')));
        $day = self::toEur(self::spentSince($idShop, date('Y-m-d 00:00:00')));
        $capMonth = (float) str_replace(',', '.', (string) Configuration::get('JOSRACHAT_CAP_MONTH'));
        $capDay = (float) str_replace(',', '.', (string) Configuration::get('JOSRACHAT_CAP_DAY'));

        if ($capMonth > 0 && $month >= $capMonth * 0.8 && Configuration::get('JOSRACHAT_ALERT_SENT') !== date('Y-m')) {
            Configuration::updateValue('JOSRACHAT_ALERT_SENT', date('Y-m'));
            self::sendAlert($module, sprintf(
                'El asistente lleva gastados %s € este mes, el %d %% del tope de %s €. Al llegar al tope dejará de responder y mostrará las opciones de contacto.',
                number_format($month, 2, ',', '.'),
                (int) round($month / $capMonth * 100),
                number_format($capMonth, 2, ',', '.')
            ));
        }
        if ($capMonth > 0 && $month >= $capMonth) {
            return 'Tope mensual alcanzado (' . number_format($capMonth, 2, ',', '.') . ' €).';
        }
        if ($capDay > 0 && $day >= $capDay) {
            return 'Tope diario alcanzado (' . number_format($capDay, 2, ',', '.') . ' €).';
        }

        return '';
    }

    /** Aviso por correo al administrador. Como mucho uno por motivo y día para errores. */
    public static function sendAlert(Module $module, $text, $dedupeKey = null)
    {
        if ($dedupeKey !== null) {
            $key = 'JOSRACHAT_ALERT_' . Tools::strtoupper(Tools::substr(md5($dedupeKey), 0, 10));
            if (Configuration::get($key) === date('Y-m-d')) {
                return;
            }
            Configuration::updateValue($key, date('Y-m-d'));
        }
        $to = (string) Configuration::get('JOSRACHAT_ALERT_EMAIL');
        if (!Validate::isEmail($to)) {
            $to = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        if (!Validate::isEmail($to)) {
            return;
        }
        PrestaShopLogger::addLog('josrachat: ' . $text, 2, null, 'josrachat');
        $idLang = Josrachat::mailLang();
        Mail::Send(
            $idLang,
            'josrachat_alert',
            'Asistente de la tienda: aviso',
            array('{message}' => $text, '{shop_name}' => Configuration::get('PS_SHOP_NAME')),
            $to,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'josrachat/mails/'
        );
    }
}
