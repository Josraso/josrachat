<?php
/**
 * josrachat - Horario de apertura: programación semanal con turno partido.
 * Mismo formato que josrawhatsapp (días lunes…domingo, turno 1 y turno 2 opcional).
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosrachatSchedule
{
    public static $days = array('lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo');

    public static function labels()
    {
        return array(
            'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles', 'jueves' => 'Jueves',
            'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
        );
    }

    /** Horario por defecto: lunes a viernes 09:00-14:00 y 16:00-20:00, fin de semana cerrado. */
    public static function defaults()
    {
        $schedule = array();
        foreach (self::$days as $d) {
            $schedule[$d] = array(
                'enabled' => in_array($d, array('sabado', 'domingo'), true) ? 0 : 1,
                't1_from' => '09:00',
                't1_to' => '14:00',
                't2_enabled' => in_array($d, array('sabado', 'domingo'), true) ? 0 : 1,
                't2_from' => '16:00',
                't2_to' => '20:00',
            );
        }

        return $schedule;
    }

    /** Horario guardado, completado con los valores por defecto para que nunca falte una clave. */
    public static function get()
    {
        $saved = json_decode((string) Configuration::get('JOSRACHAT_SCHEDULE'), true);
        $defaults = self::defaults();
        if (!is_array($saved)) {
            return $defaults;
        }
        foreach ($defaults as $d => $def) {
            $saved[$d] = isset($saved[$d]) && is_array($saved[$d]) ? array_merge($def, $saved[$d]) : $def;
        }

        return $saved;
    }

    public static function sanitizeTime($value)
    {
        $value = trim((string) $value);
        if (preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $value, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return '';
    }

    public static function now()
    {
        $tz = (string) Configuration::get('PS_TIMEZONE');
        try {
            return new DateTime('now', new DateTimeZone($tz !== '' ? $tz : 'Europe/Madrid'));
        } catch (Exception $e) {
            return new DateTime('now');
        }
    }

    /** true si el asistente debe mostrarse ahora (programación desactivada = siempre). */
    public static function isOpen(DateTime $when = null)
    {
        if (!Configuration::get('JOSRACHAT_SCHEDULE_ENABLED')) {
            return true;
        }
        $when = $when ? $when : self::now();
        $map = array(0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado');
        $schedule = self::get();
        $day = $schedule[$map[(int) $when->format('w')]];
        if (empty($day['enabled'])) {
            return false;
        }
        $current = $when->format('H:i');
        // Desde la hora de apertura (incluida) hasta la de cierre (excluida).
        if ($day['t1_from'] !== '' && $day['t1_to'] !== '' && $current >= $day['t1_from'] && $current < $day['t1_to']) {
            return true;
        }
        if (!empty($day['t2_enabled']) && $day['t2_from'] !== '' && $day['t2_to'] !== '' && $current >= $day['t2_from'] && $current < $day['t2_to']) {
            return true;
        }

        return false;
    }

    /** Próxima apertura en texto ("hoy a las 16:00", "el lunes a las 09:00"), o '' si nunca abre. */
    public static function nextOpening()
    {
        $now = self::now();
        $schedule = self::get();
        $map = array(0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado');
        $labels = self::labels();
        for ($i = 0; $i < 8; ++$i) {
            $date = clone $now;
            if ($i > 0) {
                $date->modify('+' . $i . ' day');
            }
            $key = $map[(int) $date->format('w')];
            $day = $schedule[$key];
            if (empty($day['enabled'])) {
                continue;
            }
            $starts = array($day['t1_from']);
            if (!empty($day['t2_enabled'])) {
                $starts[] = $day['t2_from'];
            }
            sort($starts);
            foreach ($starts as $start) {
                if ($start === '' || ($i === 0 && $start <= $now->format('H:i'))) {
                    continue;
                }
                $when = $i === 0 ? 'hoy' : ($i === 1 ? 'mañana' : 'el ' . Tools::strtolower($labels[$key]));

                return $when . ' a las ' . $start;
            }
        }

        return '';
    }

    /** Lee el formulario, valida y guarda. Devuelve array de errores (vacío si todo bien). */
    public static function saveFromRequest()
    {
        $labels = self::labels();
        $errors = array();
        $schedule = array();
        foreach (self::$days as $d) {
            $row = array(
                'enabled' => (int) Tools::getValue('jrc_day_' . $d),
                't1_from' => self::sanitizeTime(Tools::getValue('jrc_t1_from_' . $d)),
                't1_to' => self::sanitizeTime(Tools::getValue('jrc_t1_to_' . $d)),
                't2_enabled' => (int) Tools::getValue('jrc_t2_' . $d),
                't2_from' => self::sanitizeTime(Tools::getValue('jrc_t2_from_' . $d)),
                't2_to' => self::sanitizeTime(Tools::getValue('jrc_t2_to_' . $d)),
            );
            if ($row['enabled']) {
                if ($row['t1_from'] === '' || $row['t1_to'] === '' || $row['t1_to'] <= $row['t1_from']) {
                    $errors[] = $labels[$d] . ': en el turno 1 la hora de cierre tiene que ser posterior a la de apertura.';
                }
                if ($row['t2_enabled']) {
                    if ($row['t2_from'] === '' || $row['t2_to'] === '' || $row['t2_to'] <= $row['t2_from']) {
                        $errors[] = $labels[$d] . ': en el turno 2 la hora de cierre tiene que ser posterior a la de apertura.';
                    } elseif ($row['t2_from'] < $row['t1_to']) {
                        $errors[] = $labels[$d] . ': el turno 2 tiene que empezar cuando termina el turno 1 o después.';
                    }
                }
            }
            $schedule[$d] = $row;
        }
        if ($errors) {
            return $errors;
        }
        Configuration::updateValue('JOSRACHAT_SCHEDULE_ENABLED', (int) Tools::getValue('JOSRACHAT_SCHEDULE_ENABLED'));
        Configuration::updateValue('JOSRACHAT_SCHEDULE', json_encode($schedule));

        return array();
    }
}
