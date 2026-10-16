<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode [vin_recall_check] and the public REST endpoint it calls.
 * The browser only ever talks to WordPress; credentials and tokens stay on the server.
 */
final class VRC_Frontend {
    const REST_NAMESPACE = 'vrc/v1';
    const RESULT_TRANSIENT = 'vrc_result_';
    const RATE_TRANSIENT = 'vrc_rate_';
    const RATE_WINDOW = HOUR_IN_SECONDS;
    /** 17 characters; I, O and Q are never used in a VIN. */
    const VIN_PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';

    public static function init() {
        add_shortcode('vin_recall_check', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_assets() {
        wp_register_style('vrc-frontend', VRC_URL . 'assets/css/vin-check.css', [], VRC_VERSION);
        wp_register_script('vrc-frontend', VRC_URL . 'assets/js/vin-check.js', [], VRC_VERSION, true);
    }

    public static function shortcode($atts): string {
        $s = VRC_Settings::all();
        $atts = shortcode_atts(['title' => $s['title'], 'intro' => $s['intro']], $atts, 'vin_recall_check');

        wp_enqueue_style('vrc-frontend');
        wp_enqueue_script('vrc-frontend');
        wp_localize_script('vrc-frontend', 'VRC', [
            'endpoint' => esc_url_raw(rest_url(self::REST_NAMESPACE . '/check')),
            'i18n'     => [
                'invalid' => 'Ο αριθμός VIN πρέπει να έχει 17 χαρακτήρες (γράμματα και αριθμοί, χωρίς I, O, Q).',
                'loading' => 'Έλεγχος στο σύστημα του κατασκευαστή…',
                'error'   => $s['msg_error'],
            ],
        ]);

        $id = wp_unique_id('vrc-');
        ob_start(); ?>
        <div class="vrc-checker" id="<?php echo esc_attr($id); ?>">
            <?php if ($atts['title'] !== '') : ?><h3 class="vrc-title"><?php echo esc_html($atts['title']); ?></h3><?php endif; ?>
            <?php if ($atts['intro'] !== '') : ?><p class="vrc-intro"><?php echo esc_html($atts['intro']); ?></p><?php endif; ?>
            <form class="vrc-form" novalidate>
                <label class="vrc-label" for="<?php echo esc_attr($id); ?>-vin">Αριθμός πλαισίου (VIN)</label>
                <div class="vrc-row">
                    <input type="text" id="<?php echo esc_attr($id); ?>-vin" name="vin" class="vrc-input"
                           maxlength="17" autocomplete="off" autocapitalize="characters" spellcheck="false"
                           placeholder="π.χ. LVVDB21B8JD123456" required>
                    <button type="submit" class="vrc-button"><?php echo esc_html($s['button_label']); ?></button>
                </div>
                <div class="vrc-counter" aria-hidden="true"><span>0</span>/17</div>
                <input type="text" name="website" class="vrc-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            </form>
            <div class="vrc-result" role="status" aria-live="polite" hidden></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/check', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_check'],
            'permission_callback' => '__return_true',
            'args'                => [
                'vin'     => ['type' => 'string', 'required' => true],
                'website' => ['type' => 'string', 'required' => false],
            ],
        ]);
    }

    public static function normalize_vin(string $vin): string {
        return strtoupper(preg_replace('/[\s\-\.]+/', '', $vin));
    }

    public static function rest_check(WP_REST_Request $request) {
        $s = VRC_Settings::all();

        // Honeypot field, invisible to people.
        if (trim((string) $request->get_param('website')) !== '') {
            return self::error_response('bad_request', $s['msg_error'], 400);
        }

        $vin = self::normalize_vin((string) $request->get_param('vin'));
        if (!preg_match(self::VIN_PATTERN, $vin)) {
            return self::error_response('invalid_vin', 'Ο αριθμός VIN πρέπει να έχει 17 χαρακτήρες (γράμματα και αριθμοί, χωρίς I, O, Q).', 400);
        }

        $cache_ttl = max(0, (int) $s['cache_minutes']) * MINUTE_IN_SECONDS;
        $cache_key = self::RESULT_TRANSIENT . md5($vin);
        $result = $cache_ttl ? get_transient($cache_key) : false;

        if (!is_array($result)) {
            if (!self::consume_rate_limit((int) $s['rate_limit'])) {
                return self::error_response('rate_limited', 'Κάνατε πολλούς ελέγχους σε σύντομο χρονικό διάστημα. Δοκιμάστε ξανά αργότερα.', 429);
            }
            $result = (new VRC_Api_Client($s))->check_vin($vin);
            if (is_wp_error($result)) {
                return self::error_response('service_error', $s['msg_error'], 502);
            }
            if ($cache_ttl) set_transient($cache_key, $result, $cache_ttl);
        }

        $response = rest_ensure_response(self::present($result, $s));
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /** Turns an API result into what the visitor sees. */
    public static function present(array $result, array $s): array {
        $out = ['vin' => $result['vin'], 'count' => $result['count'], 'labor' => '', 'cta' => null];

        if ($result['vin_exist'] === false) {
            $out['status'] = 'not_found';
            $out['message'] = $s['msg_not_found'];
        } elseif ($result['count'] > 0) {
            $out['status'] = 'campaigns';
            $out['message'] = $result['count'] === 1
                ? $s['msg_campaign_one']
                : str_replace('{count}', (string) $result['count'], $s['msg_campaigns']);
            $out['note'] = $s['msg_campaigns_cta'];
            if ($s['show_labor'] && $result['labor_minutes'] > 0) {
                $out['labor'] = 'Εκτιμώμενος χρόνος εργασίας: ' . self::format_minutes($result['labor_minutes']);
            }
            if ($s['booking_url'] !== '' || $s['phone'] !== '') {
                $out['cta'] = [
                    'url'   => $s['booking_url'] !== '' ? esc_url_raw($s['booking_url']) : '',
                    'label' => $s['booking_label'],
                    'phone' => $s['phone'],
                    'tel'   => preg_replace('/[^0-9+]/', '', $s['phone']),
                ];
            }
        } else {
            $out['status'] = 'clear';
            $out['message'] = $s['msg_clear'];
        }
        return $out;
    }

    public static function format_minutes(float $minutes): string {
        $minutes = (int) round($minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $parts = [];
        if ($h) $parts[] = $h . ($h === 1 ? ' ώρα' : ' ώρες');
        if ($m) $parts[] = $m . ' λεπτά';
        return $parts ? implode(' και ', $parts) : '0 λεπτά';
    }

    private static function error_response(string $code, string $message, int $status): WP_REST_Response {
        $response = new WP_REST_Response(['status' => 'error', 'code' => $code, 'message' => $message], $status);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /** Per-IP limit on lookups that reach the manufacturer (cached answers are free). */
    private static function consume_rate_limit(int $limit): bool {
        if ($limit <= 0) return true;
        $ip = (string) apply_filters('vrc_client_ip', $_SERVER['REMOTE_ADDR'] ?? '');
        $key = self::RATE_TRANSIENT . md5($ip . wp_salt('nonce'));
        $now = time();
        $bucket = get_transient($key);
        if (!is_array($bucket) || $bucket['start'] + self::RATE_WINDOW <= $now) {
            $bucket = ['start' => $now, 'n' => 0];
        }
        if ($bucket['n'] >= $limit) return false;
        $bucket['n']++;
        set_transient($key, $bucket, $bucket['start'] + self::RATE_WINDOW - $now);
        return true;
    }
}
