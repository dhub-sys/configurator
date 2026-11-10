<?php
if (!defined('ABSPATH')) exit;

final class CN_Settings {
    const OPTION = 'cn_settings';

    public static function defaults() {
        return [
            'from_name'      => get_bloginfo('name'),
            'from_email'     => '',
            'reply_to'       => get_option('admin_email'),
            'bcc_renewals'   => '',
            'reminder_days'  => '30,14,7,1,0,-3',
            'batch_size'     => 20,
            'brand_color'    => '#1e73be',
            'logo_url'       => '',
            'footer_text'    => get_bloginfo('name'),
            'renewal_subject' => 'Υπενθύμιση ανανέωσης: {service_type} {service_label}',
            'renewal_body'   => "<p>Αγαπητέ/ή {client_name},</p>\n"
                . "<p>Σας ενημερώνουμε ότι η υπηρεσία <strong>{service_type} – {service_label}</strong> {expiry_phrase} (<strong>{expiry_date}</strong>).</p>\n"
                . "<p>{price_line}</p>\n"
                . "<p>Για να μη διακοπεί η λειτουργία της, παρακαλούμε απαντήστε σε αυτό το email ή επικοινωνήστε μαζί μας ώστε να προχωρήσουμε στην ανανέωση.</p>\n"
                . "<p>Με εκτίμηση,<br>{site_name}</p>",
            'report_subject' => 'Αναφορά συντήρησης {service_label} – {report_period}',
            'report_body'    => "<p>Αγαπητέ/ή {client_name},</p>\n"
                . "<p>Σας αποστέλλουμε την αναφορά συντήρησης για το <strong>{service_label}</strong> για την περίοδο <strong>{report_period}</strong>.</p>\n"
                . "{report_summary}\n{report_items}\n"
                . "<p>Για οποιαδήποτε απορία είμαστε στη διάθεσή σας.</p>\n"
                . "<p>Με εκτίμηση,<br>{site_name}</p>",
        ];
    }

    public static function all() {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function get($key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** Reminder offsets in days before expiry (negative = after), unique, descending. */
    public static function reminder_days($raw = null) {
        $raw = $raw === null ? (string) self::get('reminder_days') : (string) $raw;
        $days = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $part) {
            if ($part === '' || !preg_match('/^-?\d+$/', $part)) continue;
            $n = (int) $part;
            if ($n <= 365 && $n >= -90) $days[] = $n;
        }
        $days = array_values(array_unique($days));
        rsort($days);
        return $days;
    }

    public static function sanitize(array $in) {
        $d = self::defaults();
        $out = [];
        $out['from_name'] = sanitize_text_field($in['from_name'] ?? $d['from_name']);
        $out['from_email'] = sanitize_email($in['from_email'] ?? '');
        $out['reply_to'] = sanitize_email($in['reply_to'] ?? '');
        $out['bcc_renewals'] = sanitize_email($in['bcc_renewals'] ?? '');
        $out['reminder_days'] = implode(',', self::reminder_days($in['reminder_days'] ?? ''));
        $out['batch_size'] = max(1, min(200, absint($in['batch_size'] ?? 20)));
        $out['brand_color'] = sanitize_hex_color($in['brand_color'] ?? '') ?: $d['brand_color'];
        $out['logo_url'] = esc_url_raw($in['logo_url'] ?? '', ['https', 'http']);
        $out['footer_text'] = sanitize_textarea_field($in['footer_text'] ?? '');
        foreach (['renewal', 'report'] as $t) {
            $out[$t . '_subject'] = sanitize_text_field($in[$t . '_subject'] ?? '') ?: $d[$t . '_subject'];
            $body = wp_kses_post(wp_unslash($in[$t . '_body'] ?? ''));
            $out[$t . '_body'] = trim($body) !== '' ? $body : $d[$t . '_body'];
        }
        return $out;
    }

    public static function save(array $in) {
        update_option(self::OPTION, self::sanitize($in), false);
    }
}
