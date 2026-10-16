<?php
if (!defined('ABSPATH')) exit;

/** Settings page (Ρυθμίσεις → Ανακλήσεις VIN) with connection tests and the error log. */
final class VRC_Admin {
    const PAGE = 'vrc-settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('wp_ajax_vrc_test_token', [__CLASS__, 'ajax_test_token']);
        add_action('wp_ajax_vrc_test_vin', [__CLASS__, 'ajax_test_vin']);
        add_action('admin_post_vrc_clear_log', [__CLASS__, 'clear_log']);
        add_filter('plugin_action_links_' . plugin_basename(VRC_FILE), [__CLASS__, 'action_links']);
    }

    public static function menu() {
        add_options_page('Έλεγχος ανακλήσεων VIN', 'Ανακλήσεις VIN', 'manage_options', self::PAGE, [__CLASS__, 'render']);
    }

    public static function action_links(array $links): array {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE)) . '">Ρυθμίσεις</a>');
        return $links;
    }

    public static function register() {
        register_setting('vrc_settings_group', VRC_Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => VRC_Settings::defaults(),
        ]);
    }

    public static function sanitize($input): array {
        $input = is_array($input) ? wp_unslash($input) : [];
        $defaults = VRC_Settings::defaults();
        $old = get_option(VRC_Settings::OPTION, []);
        $old = is_array($old) ? $old : [];
        $out = [];

        foreach (['token_url', 'api_base_url', 'booking_url'] as $k) {
            $out[$k] = esc_url_raw(trim((string) ($input[$k] ?? '')));
        }
        foreach (['query_path', 'app_id', 'aes_key', 'title', 'button_label', 'booking_label', 'phone',
                  'msg_campaign_one', 'msg_campaigns', 'msg_clear'] as $k) {
            $out[$k] = sanitize_text_field((string) ($input[$k] ?? ''));
        }
        foreach (['intro', 'msg_campaigns_cta', 'msg_not_found', 'msg_error'] as $k) {
            $out[$k] = sanitize_textarea_field((string) ($input[$k] ?? ''));
        }
        // Empty password field = keep the stored secret.
        $secret = trim((string) ($input['app_secret'] ?? ''));
        $out['app_secret'] = $secret !== '' ? $secret : (string) ($old['app_secret'] ?? '');

        foreach (['secret_is_encrypted', 'show_labor', 'debug_log'] as $k) {
            $out[$k] = empty($input[$k]) ? 0 : 1;
        }
        $out['token_param_mode'] = in_array($input['token_param_mode'] ?? '', ['headers', 'query', 'both'], true) ? $input['token_param_mode'] : 'both';
        $out['timeout'] = min(60, max(3, (int) ($input['timeout'] ?? $defaults['timeout'])));
        $out['cache_minutes'] = min(10080, max(0, (int) ($input['cache_minutes'] ?? $defaults['cache_minutes'])));
        $out['rate_limit'] = min(1000, max(0, (int) ($input['rate_limit'] ?? $defaults['rate_limit'])));

        // Required texts fall back to the defaults instead of rendering empty.
        foreach (['query_path', 'aes_key', 'button_label', 'msg_campaign_one', 'msg_campaigns', 'msg_clear', 'msg_not_found', 'msg_error'] as $k) {
            if ($out[$k] === '') $out[$k] = $defaults[$k];
        }

        if (strlen($out['aes_key']) !== 16 && strlen($out['aes_key']) !== 24 && strlen($out['aes_key']) !== 32) {
            add_settings_error(VRC_Settings::OPTION, 'vrc_aes', 'Το κλειδί AES πρέπει να έχει 16, 24 ή 32 χαρακτήρες. Επανήλθε η προεπιλογή.');
            $out['aes_key'] = $defaults['aes_key'];
        }
        foreach (['token_url', 'api_base_url'] as $k) {
            if ($out[$k] !== '' && stripos($out[$k], 'https://') !== 0) {
                add_settings_error(VRC_Settings::OPTION, 'vrc_' . $k, 'Οι διευθύνσεις του κατασκευαστή πρέπει να ξεκινούν με https://.');
            }
        }

        // New credentials or URLs: drop cached token and cached answers.
        foreach (['token_url', 'api_base_url', 'query_path', 'app_id', 'app_secret', 'secret_is_encrypted', 'aes_key', 'token_param_mode'] as $k) {
            if ((string) ($old[$k] ?? '') !== (string) $out[$k]) {
                self::flush_cache();
                break;
            }
        }
        return $out;
    }

    /** Deletes cached tokens and VIN answers (they live in transients with known prefixes). */
    public static function flush_cache(): void {
        global $wpdb;
        foreach ([VRC_Api_Client::TOKEN_TRANSIENT, VRC_Frontend::RESULT_TRANSIENT] as $prefix) {
            $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
            $like_timeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $like_timeout));
        }
        if (wp_using_ext_object_cache()) wp_cache_flush();
    }

    private static function check_ajax(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Δεν έχετε δικαίωμα.'], 403);
        check_ajax_referer('vrc_admin_test');
    }

    public static function ajax_test_token() {
        self::check_ajax();
        $client = new VRC_Api_Client();
        $token = $client->get_token(true);
        if (is_wp_error($token)) {
            $data = $token->get_error_data();
            wp_send_json_error(['message' => $token->get_error_message(), 'raw' => is_array($data) ? ($data['raw'] ?? '') : '']);
        }
        wp_send_json_success(['message' => 'Επιτυχής λήψη token (' . substr($token, 0, 6) . '…). Η σύνδεση με το IAS λειτουργεί.']);
    }

    public static function ajax_test_vin() {
        self::check_ajax();
        $vin = VRC_Frontend::normalize_vin((string) wp_unslash($_POST['vin'] ?? ''));
        if (!preg_match(VRC_Frontend::VIN_PATTERN, $vin)) {
            wp_send_json_error(['message' => 'Μη έγκυρο VIN (17 χαρακτήρες, χωρίς I, O, Q).']);
        }
        $result = (new VRC_Api_Client())->check_vin($vin, true);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            wp_send_json_error(['message' => $result->get_error_message(), 'raw' => is_array($data) ? ($data['raw'] ?? '') : '']);
        }
        $shown = VRC_Frontend::present($result, VRC_Settings::all());
        wp_send_json_success(['message' => 'Ο επισκέπτης θα δει: ' . $shown['message'] . ($shown['labor'] ? ' ' . $shown['labor'] : ''), 'raw' => $result['raw']]);
    }

    public static function clear_log() {
        if (!current_user_can('manage_options')) wp_die('Δεν έχετε δικαίωμα.');
        check_admin_referer('vrc_clear_log');
        VRC_Log::clear();
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&vrc_log_cleared=1#vrc-log'));
        exit;
    }

    // ---------------------------------------------------------------- rendering

    private static function name(string $key): string {
        return VRC_Settings::OPTION . '[' . $key . ']';
    }

    private static function text_field(array $s, string $key, string $label, string $help = '', string $type = 'text', string $class = 'regular-text') {
        $locked = VRC_Settings::is_locked($key);
        $value = $type === 'password' ? '' : $s[$key];
        echo '<tr><th scope="row"><label for="vrc-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        if ($locked) {
            echo '<code>' . esc_html($type === 'password' ? '••••••••' : $s[$key]) . '</code>';
            echo '<p class="description">Ορίζεται από τη σταθερά <code>' . esc_html(VRC_Settings::CONSTANTS[$key]) . '</code> στο wp-config.php.</p>';
            echo '<input type="hidden" name="' . esc_attr(self::name($key)) . '" value="">';
        } else {
            $placeholder = ($type === 'password' && $s[$key] !== '') ? 'Αποθηκευμένο — αφήστε κενό για να μην αλλάξει' : '';
            printf('<input type="%s" id="vrc-%s" name="%s" value="%s" class="%s" placeholder="%s" autocomplete="off">',
                esc_attr($type), esc_attr($key), esc_attr(self::name($key)), esc_attr($value), esc_attr($class), esc_attr($placeholder));
            if ($help !== '') echo '<p class="description">' . wp_kses($help, ['code' => [], 'br' => [], 'strong' => []]) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function textarea_field(array $s, string $key, string $label, string $help = '') {
        echo '<tr><th scope="row"><label for="vrc-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        printf('<textarea id="vrc-%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr($key), esc_attr(self::name($key)), esc_textarea($s[$key]));
        if ($help !== '') echo '<p class="description">' . wp_kses($help, ['code' => []]) . '</p>';
        echo '</td></tr>';
    }

    private static function checkbox_field(array $s, string $key, string $label, string $text) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label>';
        printf('<input type="checkbox" name="%s" value="1" %s> %s', esc_attr(self::name($key)), checked(!empty($s[$key]), true, false), esc_html($text));
        echo '</label></td></tr>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $s = VRC_Settings::all();
        $query_url = VRC_Settings::query_url($s);
        ?>
        <div class="wrap">
            <h1>Έλεγχος ανακλήσεων VIN</h1>
            <p>Εμφανίστε τη φόρμα ελέγχου σε οποιαδήποτε σελίδα με το shortcode <code>[vin_recall_check]</code>
               (προαιρετικά: <code>[vin_recall_check title="…" intro="…"]</code>).</p>
            <?php if (!empty($_GET['vrc_log_cleared'])) : ?><div class="notice notice-success is-dismissible"><p>Το αρχείο σφαλμάτων καθαρίστηκε.</p></div><?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('vrc_settings_group'); ?>

                <h2>Σύνδεση με τον κατασκευαστή (IAS)</h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::text_field($s, 'token_url', 'Auth / Token endpoint', 'Πλήρης διεύθυνση, π.χ. <code>https://…/api/auth/getToken</code>', 'url', 'large-text');
                    self::text_field($s, 'api_base_url', 'API base URL', 'Η βάση πάνω στην οποία προστίθεται η διαδρομή του ερωτήματος, π.χ. <code>https://ipaas-om-sit.omodaglobal.com/api/externalIntegration</code>', 'url', 'large-text');
                    self::text_field($s, 'query_path', 'Διαδρομή ερωτήματος VIN', 'Προεπιλογή: <code>' . VRC_Settings::DEFAULT_QUERY_PATH . '</code>', 'text', 'large-text');
                    ?>
                    <tr><th scope="row">Τελική διεύθυνση ερωτήματος</th>
                        <td><code><?php echo esc_html($query_url !== '' ? $query_url : '—'); ?></code>
                        <p class="description">Πρέπει να μοιάζει με <code>https://…/api/externalIntegration/v1/eu/dms/getUnfinishedTechnologyActivityByVin</code>. Αν λείπει το <code>/api/externalIntegration</code>, προσθέστε το στο API base URL.</p></td></tr>
                    <?php
                    self::text_field($s, 'app_id', 'App ID');
                    self::text_field($s, 'app_secret', 'App Secret', 'Αποθηκεύεται στη βάση. Για μεγαλύτερη ασφάλεια ορίστε το στο wp-config.php: <code>define(\'VRC_APP_SECRET\', \'…\');</code>', 'password');
                    self::checkbox_field($s, 'secret_is_encrypted', 'Κρυπτογράφηση secret', 'Το App Secret που μου δόθηκε είναι ήδη κρυπτογραφημένο (hex) — να σταλεί όπως είναι');
                    self::text_field($s, 'aes_key', 'Κλειδί AES', 'Κλειδί και IV για AES/CBC/PKCS5Padding (ενότητα 2.1.4). Προεπιλογή: <code>' . VRC_Settings::DEFAULT_AES_KEY . '</code>');
                    ?>
                    <tr><th scope="row"><label for="vrc-token_param_mode">Αποστολή app-id / app-secret</label></th><td>
                        <select id="vrc-token_param_mode" name="<?php echo esc_attr(self::name('token_param_mode')); ?>">
                            <option value="both" <?php selected($s['token_param_mode'], 'both'); ?>>Headers και query string (προεπιλογή)</option>
                            <option value="headers" <?php selected($s['token_param_mode'], 'headers'); ?>>Μόνο ως HTTP headers</option>
                            <option value="query" <?php selected($s['token_param_mode'], 'query'); ?>>Μόνο ως query string</option>
                        </select>
                        <p class="description">Πώς στέλνονται τα στοιχεία στο GET getToken. Αν η δοκιμή αποτύχει, δοκιμάστε τις άλλες επιλογές.</p>
                    </td></tr>
                    <?php self::text_field($s, 'timeout', 'Χρονικό όριο (δευτ.)', 'Ανά προσπάθεια· σε σφάλμα δικτύου γίνονται έως 2 επαναλήψεις.', 'number', 'small-text'); ?>
                </table>

                <h2>Συμπεριφορά</h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::text_field($s, 'cache_minutes', 'Προσωρινή αποθήκευση απαντήσεων (λεπτά)', 'Ίδιο VIN μέσα σε αυτό το διάστημα δεν ξαναρωτά τον κατασκευαστή. 0 = χωρίς cache.', 'number', 'small-text');
                    self::text_field($s, 'rate_limit', 'Όριο ελέγχων ανά IP / ώρα', 'Προστασία από κατάχρηση. 0 = χωρίς όριο.', 'number', 'small-text');
                    self::checkbox_field($s, 'show_labor', 'Χρόνος εργασίας', 'Εμφάνιση του εκτιμώμενου χρόνου εργασίας στον επισκέπτη');
                    self::checkbox_field($s, 'debug_log', 'Αρχείο σφαλμάτων', 'Καταγραφή σφαλμάτων σύνδεσης (χωρίς token/secret, με μερικώς κρυμμένο VIN)');
                    ?>
                </table>

                <h2>Κείμενα φόρμας</h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::text_field($s, 'title', 'Τίτλος', '', 'text', 'large-text');
                    self::textarea_field($s, 'intro', 'Εισαγωγικό κείμενο');
                    self::text_field($s, 'button_label', 'Κουμπί');
                    self::text_field($s, 'msg_campaign_one', 'Μήνυμα: 1 καμπάνια', '', 'text', 'large-text');
                    self::text_field($s, 'msg_campaigns', 'Μήνυμα: πολλές καμπάνιες', 'Το <code>{count}</code> αντικαθίσταται με τον αριθμό.', 'text', 'large-text');
                    self::textarea_field($s, 'msg_campaigns_cta', 'Οδηγία όταν υπάρχει καμπάνια');
                    self::text_field($s, 'msg_clear', 'Μήνυμα: καμία καμπάνια', '', 'text', 'large-text');
                    self::textarea_field($s, 'msg_not_found', 'Μήνυμα: VIN δεν βρέθηκε');
                    self::textarea_field($s, 'msg_error', 'Μήνυμα: σφάλμα υπηρεσίας');
                    self::text_field($s, 'booking_url', 'Σύνδεσμος ραντεβού service', 'Προαιρετικό. Εμφανίζεται ως κουμπί όταν υπάρχει καμπάνια.', 'url', 'large-text');
                    self::text_field($s, 'booking_label', 'Κείμενο κουμπιού ραντεβού');
                    self::text_field($s, 'phone', 'Τηλέφωνο service', 'Προαιρετικό. Εμφανίζεται ως σύνδεσμος κλήσης όταν υπάρχει καμπάνια.');
                    ?>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Δοκιμή σύνδεσης</h2>
            <p class="description">Χρησιμοποιεί τις <strong>αποθηκευμένες</strong> ρυθμίσεις — πατήστε πρώτα «Αποθήκευση αλλαγών».</p>
            <p>
                <button type="button" class="button" id="vrc-test-token">Δοκιμή λήψης token</button>
                <input type="text" id="vrc-test-vin" class="regular-text" maxlength="17" placeholder="VIN για δοκιμή" style="text-transform:uppercase">
                <button type="button" class="button" id="vrc-test-vin-btn">Δοκιμή VIN</button>
            </p>
            <div id="vrc-test-result"></div>

            <hr>
            <h2 id="vrc-log">Αρχείο σφαλμάτων</h2>
            <?php $log = VRC_Log::entries(); ?>
            <?php if (!$log) : ?>
                <p>Δεν υπάρχουν καταγεγραμμένα σφάλματα.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1100px">
                    <thead><tr><th style="width:160px">Ώρα</th><th style="width:80px">Τύπος</th><th>Μήνυμα</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $row) : ?>
                        <tr><td><?php echo esc_html($row['time']); ?></td><td><?php echo esc_html($row['context']); ?></td><td><?php echo esc_html($row['message']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px">
                    <input type="hidden" name="action" value="vrc_clear_log">
                    <?php wp_nonce_field('vrc_clear_log'); ?>
                    <?php submit_button('Καθαρισμός αρχείου', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var out = document.getElementById('vrc-test-result');
            function show(ok, msg, raw) {
                out.innerHTML = '';
                var box = document.createElement('div');
                box.className = 'notice inline ' + (ok ? 'notice-success' : 'notice-error');
                var p = document.createElement('p');
                p.textContent = msg;
                box.appendChild(p);
                if (raw) {
                    var pre = document.createElement('pre');
                    pre.style.cssText = 'white-space:pre-wrap;max-height:300px;overflow:auto;background:#f6f7f7;padding:8px';
                    try { pre.textContent = JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { pre.textContent = raw; }
                    box.appendChild(pre);
                }
                out.appendChild(box);
            }
            function run(action, extra, btn) {
                var body = new URLSearchParams(Object.assign({ action: action, _ajax_nonce: <?php echo wp_json_encode(wp_create_nonce('vrc_admin_test')); ?> }, extra));
                btn.disabled = true;
                show(true, 'Γίνεται έλεγχος…');
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (r) { show(!!r.success, (r.data && r.data.message) || 'Άγνωστο σφάλμα', r.data && r.data.raw); })
                    .catch(function (e) { show(false, 'Σφάλμα: ' + e.message); })
                    .finally(function () { btn.disabled = false; });
            }
            document.getElementById('vrc-test-token').addEventListener('click', function () { run('vrc_test_token', {}, this); });
            document.getElementById('vrc-test-vin-btn').addEventListener('click', function () {
                run('vrc_test_vin', { vin: document.getElementById('vrc-test-vin').value }, this);
            });
        })();
        </script>
        <?php
    }
}
