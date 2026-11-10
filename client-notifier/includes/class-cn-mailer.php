<?php
if (!defined('ABSPATH')) exit;

final class CN_Mailer {
    const MAX_ATTEMPTS = 3;
    const LOCK = 'cn_queue_lock';
    /** Placeholders that the plugin builds as HTML itself (already escaped inside). */
    const RAW_VARS = ['report_summary', 'report_items', 'services_list'];

    /* ---------- Unsubscribe tokens ---------- */

    public static function unsubscribe_token($client) {
        $key = wp_salt('auth') . '|cn-unsubscribe';
        return substr(hash_hmac('sha256', (int) $client->id . '|' . strtolower($client->email), $key), 0, 40);
    }

    public static function verify_unsubscribe($client, $token) {
        return $client && is_string($token) && hash_equals(self::unsubscribe_token($client), $token);
    }

    public static function unsubscribe_url($client) {
        return add_query_arg(['cn_unsub' => (int) $client->id, 't' => self::unsubscribe_token($client)], home_url('/'));
    }

    /* ---------- Rendering ---------- */

    /** Replaces {placeholders}; values are escaped unless the plugin built them as HTML. */
    public static function render($template, array $vars) {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars) {
            if (!array_key_exists($m[1], $vars)) return $m[0];
            $v = (string) $vars[$m[1]];
            if (in_array($m[1], self::RAW_VARS, true)) return $v;
            if ($m[1] === 'unsubscribe_url') return esc_url($v);
            return esc_html($v);
        }, (string) $template);
    }

    /** Subject lines are plain text: no escaping, but no line breaks either (header injection). */
    public static function render_subject($template, array $vars) {
        $s = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) && !in_array($m[1], self::RAW_VARS, true) ? (string) $vars[$m[1]] : $m[0];
        }, (string) $template);
        return trim(preg_replace('/[\r\n\t]+/', ' ', wp_strip_all_tags($s)));
    }

    public static function client_vars($client) {
        return [
            'client_name'     => $client->name !== '' ? $client->name : ($client->company !== '' ? $client->company : $client->email),
            'company'         => $client->company,
            'client_email'    => $client->email,
            'site_name'       => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'unsubscribe_url' => self::unsubscribe_url($client),
            'services_list'   => self::services_list_html($client->id),
        ];
    }

    public static function service_vars($service) {
        $days = $service->expiry_date ? CN_Util::days_until($service->expiry_date) : null;
        $price = CN_Util::money($service->price);
        return [
            'service_type'  => CN_Util::type_label($service->type),
            'service_label' => $service->label,
            'expiry_date'   => CN_Util::format_date($service->expiry_date),
            'days_left'     => $days === null ? '' : (string) $days,
            'expiry_phrase' => $days === null ? '' : CN_Util::expiry_phrase($days),
            'price'         => $price,
            'cycle'         => CN_Util::cycle_label($service->cycle_months),
            'price_line'    => $price !== '' ? sprintf('Κόστος ανανέωσης: %s / %s.', $price, CN_Util::cycle_label($service->cycle_months)) : '',
        ];
    }

    public static function services_list_html($client_id) {
        if (!$client_id) return '';
        $rows = CN_DB::services(['client_id' => $client_id, 'status' => 'active']);
        if (!$rows) return '';
        $html = '<ul>';
        foreach ($rows as $s) {
            $html .= '<li>' . esc_html(CN_Util::type_label($s->type)) . ($s->label !== '' ? ': <strong>' . esc_html($s->label) . '</strong>' : '')
                . ($s->expiry_date ? ' (λήξη ' . esc_html(CN_Util::format_date($s->expiry_date)) . ')' : '') . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * Report items: one per line "Τύπος | Όνομα | Από έκδοση | Σε έκδοση".
     * Returns [ [type, name, from, to], ... ].
     */
    public static function parse_report_items($text) {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) === 1) $parts = ['', $parts[0]];
            $items[] = array_map('sanitize_text_field', array_pad(array_slice($parts, 0, 4), 4, ''));
        }
        return $items;
    }

    public static function report_items_html(array $items) {
        if (!$items) return '';
        $color = esc_attr(self::brand_color());
        $th = 'style="text-align:left;padding:8px;border-bottom:2px solid ' . $color . ';font-size:13px;"';
        $td = 'style="padding:8px;border-bottom:1px solid #e5e5e5;font-size:13px;"';
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:16px 0;">'
            . "<tr><th $th>Τύπος</th><th $th>Στοιχείο</th><th $th>Προηγούμενη έκδοση</th><th $th>Νέα έκδοση</th></tr>";
        foreach ($items as $i) {
            $html .= "<tr><td $td>" . esc_html($i[0]) . "</td><td $td><strong>" . esc_html($i[1]) . "</strong></td><td $td>"
                . esc_html($i[2]) . "</td><td $td>" . esc_html($i[3]) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private static function brand_color() {
        return sanitize_hex_color(CN_Settings::get('brand_color')) ?: '#1e73be';
    }

    /** Wraps the message body in the branded e-mail layout. */
    public static function wrap($inner, $unsubscribe_url = '') {
        $s = CN_Settings::all();
        $color = esc_attr(self::brand_color());
        $logo = $s['logo_url'] ? '<img src="' . esc_url($s['logo_url']) . '" alt="' . esc_attr($s['from_name']) . '" style="max-height:60px;max-width:240px;border:0;">'
            : '<span style="font-size:20px;font-weight:bold;color:#ffffff;">' . esc_html($s['from_name']) . '</span>';
        $footer = nl2br(esc_html($s['footer_text']));
        if ($unsubscribe_url) {
            $footer .= '<br><br><a href="' . esc_url($unsubscribe_url) . '" style="color:#888888;">Διαγραφή από τις ενημερώσεις</a>';
        }
        return '<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:6px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#222222;">'
            . '<tr><td style="background:' . $color . ';padding:20px 28px;">' . $logo . '</td></tr>'
            . '<tr><td style="padding:28px;font-size:15px;line-height:1.6;">' . $inner . '</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#fafafa;border-top:1px solid #eeeeee;font-size:12px;color:#888888;line-height:1.5;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function to_text($html) {
        $html = preg_replace('#<(br|/p|/tr|/li|/h[1-6])\s*/?>#i', "$0\n", $html);
        $html = preg_replace('#</t[dh]>#i', ' | ', $html);
        $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $text = wp_strip_all_tags(preg_replace('#<(head|style)[^>]*>.*?</\1>#is', '', $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }

    /* ---------- Building messages ---------- */

    /** Renewal reminder for a service row (from CN_DB::service). Returns queued message id or 0. */
    public static function queue_renewal($service, $ref = null) {
        $client = CN_DB::client($service->client_id);
        if (!$client) return 0;
        $vars = array_merge(self::client_vars($client), self::service_vars($service));
        $s = CN_Settings::all();
        return CN_DB::queue_message([
            'client_id'  => $client->id,
            'service_id' => $service->id,
            'kind'       => 'renewal',
            'ref'        => $ref,
            'to_email'   => $client->email,
            'subject'    => self::render_subject($s['renewal_subject'], $vars),
            'body'       => self::wrap(self::render($s['renewal_body'], $vars)),
        ]);
    }

    public static function build_report($report, $service, $client) {
        $s = CN_Settings::all();
        $vars = array_merge(self::client_vars($client), self::service_vars($service), [
            'report_period'  => $report->period,
            'report_summary' => $report->summary ? wpautop(wp_kses_post($report->summary)) : '',
            'report_items'   => self::report_items_html(self::parse_report_items($report->items)),
        ]);
        return [self::render_subject($s['report_subject'], $vars), self::wrap(self::render($s['report_body'], $vars))];
    }

    public static function build_campaign($campaign, $client) {
        $vars = self::client_vars($client);
        $body = self::render(wpautop(wp_kses_post($campaign->body)), $vars);
        return [self::render_subject($campaign->subject, $vars), self::wrap($body, $vars['unsubscribe_url'])];
    }

    /* ---------- Sending ---------- */

    /** Sends one message row now and records the result. Returns true on success. */
    public static function send_message($m) {
        global $wpdb;
        $table = CN_DB::t('messages');
        $client = $m->client_id ? CN_DB::client($m->client_id) : null;

        $skip = self::skip_reason($m, $client);
        if ($skip) {
            $wpdb->update($table, ['status' => 'cancelled', 'last_error' => $skip], ['id' => $m->id]);
            return false;
        }

        $s = CN_Settings::all();
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from_name = trim(preg_replace('/[\r\n"<>]+/', '', $s['from_name']));
        if ($s['from_email'] && is_email($s['from_email'])) {
            $headers[] = 'From: ' . ($from_name !== '' ? '"' . $from_name . '" ' : '') . '<' . $s['from_email'] . '>';
        }
        if ($s['reply_to'] && is_email($s['reply_to'])) $headers[] = 'Reply-To: ' . $s['reply_to'];
        if ($m->kind === 'renewal' && $s['bcc_renewals'] && is_email($s['bcc_renewals'])) $headers[] = 'Bcc: ' . $s['bcc_renewals'];
        if ($m->kind === 'campaign' && $client) {
            $headers[] = 'List-Unsubscribe: <' . esc_url_raw(self::unsubscribe_url($client)) . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        $alt = self::to_text($m->body);
        $error = '';
        $set_alt = function ($phpmailer) use ($alt) { $phpmailer->AltBody = $alt; };
        $on_fail = function ($wp_error) use (&$error) { $error = $wp_error->get_error_message(); };
        add_action('phpmailer_init', $set_alt);
        add_action('wp_mail_failed', $on_fail);
        $subject = preg_replace('/[\r\n]+/', ' ', $m->subject);
        $ok = wp_mail($m->to_email, $subject, $m->body, $headers);
        remove_action('phpmailer_init', $set_alt);
        remove_action('wp_mail_failed', $on_fail);

        $attempts = (int) $m->attempts + 1;
        if ($ok) {
            $wpdb->update($table, ['status' => 'sent', 'attempts' => $attempts, 'sent_at' => CN_Util::now(), 'last_error' => null], ['id' => $m->id]);
            return true;
        }
        $retry = $attempts < self::MAX_ATTEMPTS && $m->kind !== 'test';
        $wpdb->update($table, [
            'status'       => $retry ? 'queued' : 'failed',
            'attempts'     => $attempts,
            'last_error'   => $error ?: 'Το wp_mail απέτυχε.',
            'scheduled_at' => wp_date('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS * $attempts),
        ], ['id' => $m->id]);
        return false;
    }

    /** Last-moment checks: a queued message may no longer be appropriate. */
    private static function skip_reason($m, $client) {
        if ($m->kind === 'test') return '';
        if (!$client) return 'Ο πελάτης δεν υπάρχει πλέον.';
        if ($client->status !== 'active') return 'Ο πελάτης είναι ανενεργός.';
        if ($m->kind === 'campaign' && $client->unsubscribed_at) return 'Ο πελάτης έχει διαγραφεί από τις ενημερώσεις.';
        if (strcasecmp($client->email, $m->to_email) !== 0) return 'Το email του πελάτη άλλαξε μετά τη δημιουργία του μηνύματος.';
        if ($m->kind === 'renewal' && $m->service_id) {
            $service = CN_DB::service($m->service_id);
            if (!$service || $service->status !== 'active') return 'Η υπηρεσία δεν είναι ενεργή.';
            // Automatic reminders carry the expiry date they were made for; a renewal makes them stale.
            if ($m->ref && preg_match('/^renewal:\d+:(\d{4}-\d{2}-\d{2}):/', $m->ref, $mm) && $mm[1] !== $service->expiry_date) {
                return 'Η ημερομηνία λήξης άλλαξε (η υπηρεσία ανανεώθηκε).';
            }
        }
        return '';
    }

    /** Sends a queued message immediately (manual actions). */
    public static function send_now($id) {
        $m = CN_DB::message($id);
        return $m && $m->status === 'queued' ? self::send_message($m) : false;
    }

    /** Sends the next batch of due messages. Returns [sent, failed]. */
    public static function process_queue($limit = null) {
        global $wpdb;
        // Simple lock so overlapping cron runs never send the same message twice.
        if (get_transient(self::LOCK)) return [0, 0];
        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);
        $limit = $limit ?: (int) CN_Settings::get('batch_size');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . CN_DB::t('messages') . " WHERE status = 'queued' AND scheduled_at <= %s ORDER BY scheduled_at ASC, id ASC LIMIT %d",
            CN_Util::now(), $limit));
        $sent = $failed = 0;
        foreach ($rows as $m) {
            self::send_message($m) ? $sent++ : $failed++;
        }
        delete_transient(self::LOCK);
        return [$sent, $failed];
    }

    /** Direct test e-mail to an address (not tied to a client). */
    public static function send_test($to, $subject, $body) {
        $id = CN_DB::queue_message(['kind' => 'test', 'to_email' => $to, 'subject' => '[ΔΟΚΙΜΗ] ' . $subject, 'body' => $body]);
        return $id ? self::send_now($id) : false;
    }
}
