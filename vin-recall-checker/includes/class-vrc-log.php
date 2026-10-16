<?php
if (!defined('ABSPATH')) exit;

/**
 * Small error log kept in a non-autoloaded option, shown on the settings page.
 * Never receives tokens or secrets; VINs are masked before they are stored.
 */
final class VRC_Log {
    const OPTION = 'vrc_log';
    const MAX_ENTRIES = 30;

    public static function add(string $context, string $message): void {
        if (!VRC_Settings::get('debug_log')) return;
        $log = self::entries();
        array_unshift($log, [
            'time'    => current_time('mysql'),
            'context' => $context,
            'message' => mb_substr($message, 0, 500),
        ]);
        update_option(self::OPTION, array_slice($log, 0, self::MAX_ENTRIES), false);
    }

    public static function entries(): array {
        $log = get_option(self::OPTION, []);
        return is_array($log) ? $log : [];
    }

    public static function clear(): void {
        delete_option(self::OPTION);
    }

    /** Keeps the manufacturer code and the last 6 characters: LVV*******123456. */
    public static function mask_vin(string $vin): string {
        if (strlen($vin) < 10) return $vin;
        return substr($vin, 0, 3) . str_repeat('*', strlen($vin) - 9) . substr($vin, -6);
    }
}
