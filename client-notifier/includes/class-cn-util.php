<?php
if (!defined('ABSPATH')) exit;

final class CN_Util {
    const SERVICE_TYPES = [
        'hosting'     => 'Web Hosting',
        'domain'      => 'Domain Name',
        'maintenance' => 'Maintenance',
        'ssl'         => 'SSL Certificate',
        'email'       => 'Email Hosting',
        'other'       => 'Άλλη υπηρεσία',
    ];
    const SERVICE_STATUSES = ['active' => 'Ενεργή', 'suspended' => 'Σε αναστολή', 'cancelled' => 'Ακυρωμένη'];
    const CLIENT_STATUSES = ['active' => 'Ενεργός', 'inactive' => 'Ανενεργός'];
    const MESSAGE_KINDS = [
        'renewal'  => 'Υπενθύμιση ανανέωσης',
        'report'   => 'Αναφορά maintenance',
        'campaign' => 'Ενημέρωση / Αποστολή',
        'test'     => 'Δοκιμαστικό',
    ];
    const MESSAGE_STATUSES = ['queued' => 'Σε αναμονή', 'sent' => 'Εστάλη', 'failed' => 'Απέτυχε', 'cancelled' => 'Ακυρώθηκε'];

    /** Current date in the site's timezone, Y-m-d. */
    public static function today() {
        return wp_date('Y-m-d');
    }

    /** MySQL datetime (site timezone) for created/sent columns. */
    public static function now() {
        return current_time('mysql');
    }

    /** Whole days from today until $ymd (negative once it has passed). */
    public static function days_until($ymd) {
        $tz = wp_timezone();
        $a = new DateTimeImmutable(self::today(), $tz);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $tz);
        if (!$b) return null;
        $diff = $a->diff($b);
        return $diff->invert ? -$diff->days : $diff->days;
    }

    public static function add_days($ymd, $days) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, wp_timezone());
        return $d ? $d->modify(($days >= 0 ? '+' : '') . (int) $days . ' days')->format('Y-m-d') : $ymd;
    }

    public static function add_months($ymd, $months) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, wp_timezone());
        return $d ? $d->modify('+' . (int) $months . ' months')->format('Y-m-d') : $ymd;
    }

    /** Accepts Y-m-d, d/m/Y, d-m-Y, d.m.Y; returns Y-m-d or ''. */
    public static function parse_date($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!j/n/Y'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat($fmt, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($d && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                return $d->format('Y-m-d');
            }
        }
        return '';
    }

    public static function format_date($ymd) {
        if (!$ymd || $ymd === '0000-00-00') return '—';
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        return $d ? $d->format('d/m/Y') : '—';
    }

    public static function format_datetime($mysql) {
        if (!$mysql) return '—';
        $ts = strtotime($mysql);
        return $ts ? date('d/m/Y H:i', $ts) : '—';
    }

    public static function money($amount) {
        if ($amount === null || $amount === '') return '';
        return number_format((float) $amount, 2, ',', '.') . ' €';
    }

    public static function type_label($type) {
        return self::SERVICE_TYPES[$type] ?? $type;
    }

    public static function cycle_label($months) {
        $months = (int) $months;
        if ($months === 12) return '1 έτος';
        if ($months > 12 && $months % 12 === 0) return ($months / 12) . ' έτη';
        return $months === 1 ? '1 μήνα' : $months . ' μήνες';
    }

    /** Human phrase for the reminder email: "λήγει σε 7 ημέρες", "έληξε πριν από 2 ημέρες"... */
    public static function expiry_phrase($days) {
        if ($days > 1) return sprintf('λήγει σε %d ημέρες', $days);
        if ($days === 1) return 'λήγει αύριο';
        if ($days === 0) return 'λήγει σήμερα';
        if ($days === -1) return 'έληξε χθες';
        return sprintf('έληξε πριν από %d ημέρες', abs($days));
    }

    /** Positive integer IDs from a request array. */
    public static function ids($value) {
        return array_values(array_unique(array_filter(array_map('absint', (array) $value))));
    }
}
