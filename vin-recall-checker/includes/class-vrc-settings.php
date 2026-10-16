<?php
if (!defined('ABSPATH')) exit;

/**
 * Plugin settings, stored as one option. The credentials and URLs can also be
 * defined as constants in wp-config.php, which then take precedence and are
 * shown read-only in the admin.
 */
final class VRC_Settings {
    const OPTION = 'vrc_settings';

    /** Settings that a wp-config.php constant can override. */
    const CONSTANTS = [
        'token_url'    => 'VRC_TOKEN_URL',
        'api_base_url' => 'VRC_API_BASE_URL',
        'app_id'       => 'VRC_APP_ID',
        'app_secret'   => 'VRC_APP_SECRET',
    ];

    /** Path of 3.1 "getUnfinishedTechnologyActivityByVin", appended to the API base URL. */
    const DEFAULT_QUERY_PATH = '/v1/eu/dms/getUnfinishedTechnologyActivityByVin';

    /** AES key/IV from section 2.1.4 of the IAS documentation (16 bytes → AES-128-CBC). */
    const DEFAULT_AES_KEY = 'CheryOdin!202503';

    public static function defaults(): array {
        return [
            // Connection
            'token_url'           => '',
            'api_base_url'        => '',
            'query_path'          => self::DEFAULT_QUERY_PATH,
            'app_id'              => '',
            'app_secret'          => '',
            'secret_is_encrypted' => 0,
            'aes_key'             => self::DEFAULT_AES_KEY,
            'token_param_mode'    => 'both',
            'timeout'             => 10,
            // Behaviour
            'cache_minutes'       => 60,
            'rate_limit'          => 20,
            'show_labor'          => 1,
            'debug_log'           => 1,
            // Front-end texts
            'title'               => 'Έλεγχος ανάκλησης με αριθμό VIN',
            'intro'               => 'Πληκτρολογήστε τον 17ψήφιο αριθμό πλαισίου (VIN) του αυτοκινήτου σας για να δείτε αν υπάρχει ανοιχτή καμπάνια ανάκλησης ή τεχνικής αναβάθμισης. Θα τον βρείτε στην άδεια κυκλοφορίας (πεδίο Ε).',
            'button_label'        => 'Έλεγχος',
            'msg_campaign_one'    => 'Υπάρχει 1 ανοιχτή καμπάνια ανάκλησης / τεχνικής αναβάθμισης για το αυτοκίνητό σας.',
            'msg_campaigns'       => 'Υπάρχουν {count} ανοιχτές καμπάνιες ανάκλησης / τεχνικής αναβάθμισης για το αυτοκίνητό σας.',
            'msg_campaigns_cta'   => 'Η εργασία γίνεται δωρεάν στο εξουσιοδοτημένο service. Επικοινωνήστε μαζί μας για να κλείσετε ραντεβού.',
            'msg_clear'           => 'Δεν υπάρχει ανοιχτή καμπάνια ανάκλησης για αυτό το αυτοκίνητο.',
            'msg_not_found'       => 'Ο αριθμός VIN δεν βρέθηκε στο σύστημα του κατασκευαστή. Ελέγξτε ότι τον πληκτρολογήσατε σωστά ή επικοινωνήστε μαζί μας.',
            'msg_error'           => 'Η υπηρεσία ελέγχου δεν είναι διαθέσιμη αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο ή επικοινωνήστε μαζί μας.',
            'booking_url'         => '',
            'booking_label'       => 'Κλείστε ραντεβού στο service',
            'phone'               => '',
        ];
    }

    public static function all(): array {
        $saved = get_option(self::OPTION, []);
        $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        foreach (self::CONSTANTS as $key => $constant) {
            if (self::is_locked($key)) $settings[$key] = (string) constant($constant);
        }
        return $settings;
    }

    public static function get(string $key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** True when the value comes from a wp-config.php constant. */
    public static function is_locked(string $key): bool {
        return isset(self::CONSTANTS[$key]) && defined(self::CONSTANTS[$key]) && (string) constant(self::CONSTANTS[$key]) !== '';
    }

    public static function is_configured(?array $s = null): bool {
        $s = $s ?? self::all();
        return $s['token_url'] !== '' && $s['api_base_url'] !== '' && $s['app_id'] !== '' && $s['app_secret'] !== '';
    }

    /** Full URL of the VIN query: API base URL + query path. */
    public static function query_url(?array $s = null): string {
        $s = $s ?? self::all();
        if ($s['api_base_url'] === '') return '';
        return rtrim($s['api_base_url'], '/') . '/' . ltrim($s['query_path'], '/');
    }
}
