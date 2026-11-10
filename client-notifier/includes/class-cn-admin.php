<?php
if (!defined('ABSPATH')) exit;

final class CN_Admin {
    const ACTIONS = [
        'save_client', 'delete_client', 'toggle_unsubscribe', 'import_csv',
        'save_service', 'delete_service', 'renew_service', 'remind_now',
        'save_report', 'delete_report',
        'save_campaign', 'delete_campaign',
        'retry_message', 'cancel_message', 'run_queue', 'run_reminders',
        'save_settings', 'send_test',
    ];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_head', [__CLASS__, 'styles']);
        foreach (self::ACTIONS as $a) {
            add_action('admin_post_cn_' . $a, [__CLASS__, 'handle_' . $a]);
        }
    }

    public static function menu() {
        add_menu_page('Πελάτες & Αποστολές', 'Πελάτες', CN_CAP, 'cn-dashboard', [__CLASS__, 'page_dashboard'], 'dashicons-email-alt', 58);
        add_submenu_page('cn-dashboard', 'Επισκόπηση', 'Επισκόπηση', CN_CAP, 'cn-dashboard', [__CLASS__, 'page_dashboard']);
        add_submenu_page('cn-dashboard', 'Πελάτες', 'Πελάτες', CN_CAP, 'cn-clients', [__CLASS__, 'page_clients']);
        add_submenu_page('cn-dashboard', 'Υπηρεσίες', 'Υπηρεσίες', CN_CAP, 'cn-services', [__CLASS__, 'page_services']);
        add_submenu_page('cn-dashboard', 'Αναφορές Maintenance', 'Αναφορές Maintenance', CN_CAP, 'cn-reports', [__CLASS__, 'page_reports']);
        add_submenu_page('cn-dashboard', 'Ενημερώσεις / Αποστολές', 'Αποστολές', CN_CAP, 'cn-campaigns', [__CLASS__, 'page_campaigns']);
        add_submenu_page('cn-dashboard', 'Ιστορικό αποστολών', 'Ιστορικό', CN_CAP, 'cn-log', [__CLASS__, 'page_log']);
        add_submenu_page('cn-dashboard', 'Ρυθμίσεις', 'Ρυθμίσεις', CN_CAP, 'cn-settings', [__CLASS__, 'page_settings']);
    }

    public static function styles() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'cn-') === false) return;
        echo '<style>
            .cn-wrap .cn-form th{width:200px}
            .cn-wrap .cn-inline{display:inline}
            .cn-wrap .cn-inline .button-link{color:#2271b1;cursor:pointer}
            .cn-wrap .cn-inline .button-link-delete{color:#b32d2e}
            .cn-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#f0f0f1;white-space:nowrap}
            .cn-badge.ok{background:#d1f0d9;color:#0a5a1f}.cn-badge.warn{background:#fcf0c3;color:#6b4e00}
            .cn-badge.bad{background:#fbd5d5;color:#8a1111}.cn-badge.muted{color:#777}
            .cn-cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
            .cn-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px 16px;min-width:150px}
            .cn-card strong{display:block;font-size:24px;line-height:1.3}
            .cn-filters{margin:12px 0}.cn-filters select,.cn-filters input{vertical-align:middle}
            .cn-preview{width:100%;max-width:760px;height:620px;border:1px solid #dcdcde;background:#fff}
            .cn-help code{font-size:12px}
            .cn-actions-col{white-space:nowrap}
        </style>';
    }

    /* ======================================================================
     * Helpers
     * ==================================================================== */

    private static function guard($action) {
        if (!current_user_can(CN_CAP)) wp_die('Δεν έχετε δικαίωμα πρόσβασης.', 403);
        check_admin_referer('cn_' . $action);
    }

    private static function url($page, array $args = []) {
        return add_query_arg($args, admin_url('admin.php?page=' . $page));
    }

    private static function notice($text, $type = 'success') {
        set_transient('cn_notice_' . get_current_user_id(), [$type, $text], 60);
    }

    private static function redirect($page, array $args = []) {
        wp_safe_redirect(self::url($page, $args));
        exit;
    }

    private static function back($fallback_page) {
        $ref = wp_get_referer();
        wp_safe_redirect($ref ?: self::url($fallback_page));
        exit;
    }

    private static function post($key, $default = '') {
        return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
    }

    private static function show_notice() {
        $key = 'cn_notice_' . get_current_user_id();
        $n = get_transient($key);
        if (!$n) return;
        delete_transient($key);
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($n[0]), wp_kses_post($n[1]));
    }

    /** A small POST form with a single button (all state changes go through POST + nonce). */
    private static function action_button($action, array $fields, $label, $confirm = '', $class = 'button-link') {
        $out = '<form class="cn-inline" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"'
            . ($confirm ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '') . '>';
        $out .= '<input type="hidden" name="action" value="cn_' . esc_attr($action) . '">';
        $out .= wp_nonce_field('cn_' . $action, '_wpnonce', true, false);
        foreach ($fields as $k => $v) {
            $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $out . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private static function form_open($action) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="cn_' . esc_attr($action) . '">';
        wp_nonce_field('cn_' . $action);
    }

    private static function select($name, array $options, $current, $placeholder = null, $attrs = '') {
        $out = '<select name="' . esc_attr($name) . '" ' . $attrs . '>';
        if ($placeholder !== null) $out .= '<option value="">' . esc_html($placeholder) . '</option>';
        foreach ($options as $v => $l) {
            $out .= '<option value="' . esc_attr($v) . '"' . selected((string) $current, (string) $v, false) . '>' . esc_html($l) . '</option>';
        }
        return $out . '</select>';
    }

    private static function expiry_badge($ymd, $status = 'active') {
        if (!$ymd) return '<span class="cn-badge muted">χωρίς λήξη</span>';
        $d = CN_Util::days_until($ymd);
        $date = esc_html(CN_Util::format_date($ymd));
        if ($status !== 'active') return '<span class="cn-badge muted">' . $date . '</span>';
        if ($d < 0) return '<span class="cn-badge bad">' . $date . ' · έληξε πριν ' . abs($d) . ' ημ.</span>';
        if ($d <= 30) return '<span class="cn-badge warn">' . $date . ' · σε ' . $d . ' ημ.</span>';
        return '<span class="cn-badge ok">' . $date . ' · σε ' . $d . ' ημ.</span>';
    }

    private static function status_badge($status, array $labels) {
        $class = ['active' => 'ok', 'sent' => 'ok', 'queued' => 'warn', 'failed' => 'bad', 'suspended' => 'warn',
            'cancelled' => 'muted', 'inactive' => 'muted', 'draft' => 'muted'][$status] ?? '';
        return '<span class="cn-badge ' . $class . '">' . esc_html($labels[$status] ?? $status) . '</span>';
    }

    private static function client_label($row, $prefix = '') {
        $name = $row->{$prefix . 'name'} ?? '';
        $company = $row->{$prefix . 'company'} ?? '';
        if ($name !== '' && $company !== '') return $name . ' (' . $company . ')';
        return $name !== '' ? $name : ($company !== '' ? $company : ($row->{$prefix . 'email'} ?? ''));
    }

    private static function client_options() {
        $out = [];
        foreach (CN_DB::clients() as $c) $out[$c->id] = self::client_label($c) . ' — ' . $c->email;
        return $out;
    }

    private static function preview_iframe($html) {
        // sandbox="" : the preview can run no scripts and reach nothing in the admin.
        echo '<iframe class="cn-preview" sandbox="" srcdoc="' . esc_attr($html) . '"></iframe>';
    }

    /* ======================================================================
     * Dashboard
     * ==================================================================== */

    public static function page_dashboard() {
        global $wpdb;
        $clients = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CN_DB::t('clients') . " WHERE status = 'active'");
        $services = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CN_DB::t('services') . " WHERE status = 'active'");
        $counts = CN_DB::message_counts();
        $expiring = CN_DB::services(['status' => 'active', 'expiring' => 60]);
        echo '<div class="wrap cn-wrap"><h1>Επισκόπηση</h1>';
        self::show_notice();
        echo '<div class="cn-cards">';
        printf('<div class="cn-card"><strong>%d</strong>Ενεργοί πελάτες</div>', $clients);
        printf('<div class="cn-card"><strong>%d</strong>Ενεργές υπηρεσίες</div>', $services);
        printf('<div class="cn-card"><strong>%d</strong>Λήγουν σε 60 ημέρες</div>', count($expiring));
        printf('<div class="cn-card"><strong>%d</strong>Σε αναμονή αποστολής</div>', $counts['queued']);
        printf('<div class="cn-card"><strong>%d</strong>Αποτυχημένα</div>', $counts['failed']);
        echo '</div>';

        $next_r = wp_next_scheduled(CN_Cron::REMINDERS_HOOK);
        $next_q = wp_next_scheduled(CN_Cron::QUEUE_HOOK);
        echo '<p>Επόμενος έλεγχος λήξεων: <strong>' . esc_html($next_r ? wp_date('d/m/Y H:i', $next_r) : '—') . '</strong> · '
            . 'Επόμενη αποστολή ουράς: <strong>' . esc_html($next_q ? wp_date('d/m/Y H:i', $next_q) : '—') . '</strong></p>';
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            echo '<p class="description">Το WP-Cron είναι απενεργοποιημένο: βεβαιωθείτε ότι υπάρχει cron job στον server που καλεί το wp-cron.php.</p>';
        }
        echo '<p>' . self::action_button('run_reminders', [], 'Έλεγχος λήξεων τώρα', '', 'button')
            . ' ' . self::action_button('run_queue', [], 'Αποστολή ουράς τώρα', '', 'button') . '</p>';

        echo '<h2>Υπηρεσίες που λήγουν τις επόμενες 60 ημέρες (ή έχουν λήξει)</h2>';
        self::services_table($expiring, true);
        echo '</div>';
    }

    /* ======================================================================
     * Clients
     * ==================================================================== */

    public static function page_clients() {
        $action = sanitize_key($_GET['action'] ?? '');
        if ($action === 'edit' || $action === 'new') {
            self::client_form(absint($_GET['id'] ?? 0));
            return;
        }
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $status = sanitize_key($_GET['status'] ?? '');
        $rows = CN_DB::clients($search, $status);
        echo '<div class="wrap cn-wrap"><h1 class="wp-heading-inline">Πελάτες</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(self::url('cn-clients', ['action' => 'new'])) . '">Νέος πελάτης</a><hr class="wp-header-end">';
        self::show_notice();
        echo '<form class="cn-filters" method="get"><input type="hidden" name="page" value="cn-clients">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Όνομα, εταιρεία ή email"> ';
        echo self::select('status', CN_Util::CLIENT_STATUSES, $status, 'Όλες οι καταστάσεις');
        echo ' <button class="button">Φιλτράρισμα</button></form>';

        echo '<table class="widefat striped"><thead><tr><th>Όνομα</th><th>Εταιρεία</th><th>Email</th><th>Τηλέφωνο</th><th>Ενεργές υπηρεσίες</th><th>Κατάσταση</th><th>Ενημερώσεις</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="8">Δεν υπάρχουν πελάτες.</td></tr>';
        foreach ($rows as $c) {
            $edit = esc_url(self::url('cn-clients', ['action' => 'edit', 'id' => $c->id]));
            echo '<tr><td><a href="' . $edit . '"><strong>' . esc_html($c->name ?: '—') . '</strong></a></td>';
            echo '<td>' . esc_html($c->company) . '</td><td>' . esc_html($c->email) . '</td><td>' . esc_html($c->phone) . '</td>';
            echo '<td>' . (int) $c->active_services . '</td><td>' . self::status_badge($c->status, CN_Util::CLIENT_STATUSES) . '</td>';
            echo '<td>' . ($c->unsubscribed_at ? '<span class="cn-badge muted">Διαγράφηκε</span>' : '<span class="cn-badge ok">Ναι</span>') . '</td>';
            echo '<td class="cn-actions-col"><a href="' . $edit . '">Επεξεργασία</a> | '
                . self::action_button('delete_client', ['id' => $c->id], 'Διαγραφή',
                    'Διαγραφή του πελάτη μαζί με όλες τις υπηρεσίες, αναφορές και το ιστορικό του;', 'button-link button-link-delete') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Εισαγωγή από CSV</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="cn_import_csv">';
        wp_nonce_field('cn_import_csv');
        echo '<p class="cn-help">Πρώτη γραμμή με επικεφαλίδες (διαχωριστικό <code>,</code> ή <code>;</code>, UTF-8). Υποχρεωτική στήλη: <code>email</code>. '
            . 'Προαιρετικές: <code>name</code>, <code>company</code>, <code>phone</code>, και για υπηρεσία: <code>service_type</code> '
            . '(' . esc_html(implode(', ', array_keys(CN_Util::SERVICE_TYPES))) . '), <code>service_label</code>, <code>expiry_date</code> (ΗΗ/ΜΜ/ΕΕΕΕ ή ΕΕΕΕ-ΜΜ-ΗΗ), '
            . '<code>price</code>, <code>cycle_months</code>. Υπάρχοντες πελάτες (ίδιο email) και υπηρεσίες (ίδιος τύπος και περιγραφή) ενημερώνονται.</p>';
        echo '<input type="file" name="csv" accept=".csv,text/csv" required> <button class="button">Εισαγωγή</button></form>';
        echo '</div>';
    }

    private static function client_form($id) {
        $c = $id ? CN_DB::client($id) : null;
        if ($id && !$c) wp_die('Ο πελάτης δεν βρέθηκε.');
        $v = function ($k, $d = '') use ($c) { return $c ? $c->$k : $d; };
        echo '<div class="wrap cn-wrap"><h1>' . ($c ? 'Επεξεργασία πελάτη' : 'Νέος πελάτης') . '</h1>';
        self::show_notice();
        self::form_open('save_client');
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<table class="form-table cn-form">';
        echo '<tr><th><label for="cn-name">Ονοματεπώνυμο</label></th><td><input id="cn-name" class="regular-text" name="name" value="' . esc_attr($v('name')) . '"></td></tr>';
        echo '<tr><th><label for="cn-company">Εταιρεία</label></th><td><input id="cn-company" class="regular-text" name="company" value="' . esc_attr($v('company')) . '"></td></tr>';
        echo '<tr><th><label for="cn-email">Email *</label></th><td><input id="cn-email" type="email" required class="regular-text" name="email" value="' . esc_attr($v('email')) . '"></td></tr>';
        echo '<tr><th><label for="cn-phone">Τηλέφωνο</label></th><td><input id="cn-phone" class="regular-text" name="phone" value="' . esc_attr($v('phone')) . '"></td></tr>';
        echo '<tr><th>Κατάσταση</th><td>' . self::select('status', CN_Util::CLIENT_STATUSES, $v('status', 'active'))
            . '<p class="description">Στους ανενεργούς πελάτες δεν αποστέλλεται κανένα μήνυμα.</p></td></tr>';
        echo '<tr><th><label for="cn-notes">Σημειώσεις</label></th><td><textarea id="cn-notes" class="large-text" rows="3" name="notes">' . esc_textarea($v('notes')) . '</textarea></td></tr>';
        echo '</table>';
        submit_button($c ? 'Αποθήκευση' : 'Δημιουργία πελάτη');
        echo '</form>';

        if ($c) {
            echo '<p>Ενημερωτικά μηνύματα: ' . ($c->unsubscribed_at
                ? '<span class="cn-badge muted">Διαγράφηκε στις ' . esc_html(CN_Util::format_datetime($c->unsubscribed_at)) . '</span> '
                    . self::action_button('toggle_unsubscribe', ['id' => $c->id, 'value' => 0], 'Επανεγγραφή (μόνο με συναίνεση του πελάτη)')
                : '<span class="cn-badge ok">Λαμβάνει</span> ' . self::action_button('toggle_unsubscribe', ['id' => $c->id, 'value' => 1], 'Διαγραφή από ενημερώσεις'))
                . '</p><p class="description">Οι υπενθυμίσεις ανανέωσης και οι αναφορές maintenance αφορούν υπηρεσίες του πελάτη και αποστέλλονται πάντα.</p>';

            echo '<h2>Υπηρεσίες <a class="page-title-action" href="' . esc_url(self::url('cn-services', ['action' => 'new', 'client_id' => $c->id])) . '">Νέα υπηρεσία</a></h2>';
            self::services_table(CN_DB::services(['client_id' => $c->id]), false);

            list($msgs) = CN_DB::messages(['client_id' => $c->id], 1, 20);
            echo '<h2>Τελευταία μηνύματα</h2>';
            self::messages_table($msgs);
        }
        echo '</div>';
    }

    public static function handle_save_client() {
        self::guard('save_client');
        $id = absint(self::post('id', 0));
        $result = CN_DB::save_client([
            'name' => self::post('name'), 'company' => self::post('company'), 'email' => self::post('email'),
            'phone' => self::post('phone'), 'status' => self::post('status'), 'notes' => self::post('notes'),
        ], $id);
        if (is_wp_error($result)) {
            self::notice(esc_html($result->get_error_message()), 'error');
            self::back('cn-clients');
        }
        self::notice('Ο πελάτης αποθηκεύτηκε.');
        self::redirect('cn-clients', ['action' => 'edit', 'id' => $result]);
    }

    public static function handle_delete_client() {
        self::guard('delete_client');
        CN_DB::delete_client(absint(self::post('id', 0)));
        self::notice('Ο πελάτης διαγράφηκε.');
        self::redirect('cn-clients');
    }

    public static function handle_toggle_unsubscribe() {
        self::guard('toggle_unsubscribe');
        CN_DB::set_unsubscribed(absint(self::post('id', 0)), (bool) absint(self::post('value', 0)));
        self::notice('Η προτίμηση ενημερώσεων ενημερώθηκε.');
        self::back('cn-clients');
    }

    public static function handle_import_csv() {
        self::guard('import_csv');
        $file = $_FILES['csv'] ?? null;
        if (!$file || !empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
            self::notice('Δεν ανέβηκε αρχείο.', 'error');
            self::redirect('cn-clients');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true) || $file['size'] > 2 * MB_IN_BYTES) {
            self::notice('Επιτρέπεται μόνο αρχείο .csv έως 2 MB.', 'error');
            self::redirect('cn-clients');
        }
        $result = self::import_csv_file($file['tmp_name']);
        $msg = sprintf('Εισαγωγή: %d νέοι πελάτες, %d ενημερώθηκαν, %d νέες υπηρεσίες, %d υπηρεσίες ενημερώθηκαν.',
            $result['clients_new'], $result['clients_updated'], $result['services_new'], $result['services_updated']);
        if ($result['errors']) {
            $msg .= '<br>Γραμμές με πρόβλημα:<br>' . implode('<br>', array_map('esc_html', array_slice($result['errors'], 0, 30)));
        }
        self::notice($msg, $result['errors'] ? 'warning' : 'success');
        self::redirect('cn-clients');
    }

    private static function import_csv_file($path) {
        $r = ['clients_new' => 0, 'clients_updated' => 0, 'services_new' => 0, 'services_updated' => 0, 'errors' => []];
        $fh = fopen($path, 'r');
        if (!$fh) { $r['errors'][] = 'Το αρχείο δεν διαβάζεται.'; return $r; }
        $first = (string) fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $headers = array_map(function ($h) { return sanitize_key(trim($h)); }, str_getcsv($first, $delim));
        if (!in_array('email', $headers, true)) {
            fclose($fh);
            $r['errors'][] = 'Λείπει η στήλη email.';
            return $r;
        }
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
            $line++;
            if ($cells === [null] || !array_filter($cells, 'strlen')) continue;
            $row = [];
            foreach ($headers as $i => $h) $row[$h] = isset($cells[$i]) ? trim($cells[$i]) : '';
            $email = sanitize_email($row['email'] ?? '');
            if (!is_email($email)) { $r['errors'][] = "Γραμμή $line: μη έγκυρο email."; continue; }

            $client = CN_DB::client_by_email($email);
            if ($client) {
                // Only fill fields given in the file; never blank out existing data.
                $data = (array) $client;
                foreach (['name', 'company', 'phone'] as $k) if (($row[$k] ?? '') !== '') $data[$k] = $row[$k];
                CN_DB::save_client($data, $client->id);
                $client_id = (int) $client->id;
                $r['clients_updated']++;
            } else {
                $res = CN_DB::save_client(['email' => $email, 'name' => $row['name'] ?? '', 'company' => $row['company'] ?? '', 'phone' => $row['phone'] ?? '']);
                if (is_wp_error($res)) { $r['errors'][] = "Γραμμή $line: " . $res->get_error_message(); continue; }
                $client_id = $res;
                $r['clients_new']++;
            }

            $type = sanitize_key($row['service_type'] ?? '');
            if ($type === '') continue;
            if (!isset(CN_Util::SERVICE_TYPES[$type])) { $r['errors'][] = "Γραμμή $line: άγνωστος τύπος υπηρεσίας «{$type}»."; continue; }
            $expiry = $row['expiry_date'] ?? '';
            if ($expiry !== '' && !CN_Util::parse_date($expiry)) { $r['errors'][] = "Γραμμή $line: μη έγκυρη ημερομηνία λήξης."; continue; }
            $label = sanitize_text_field($row['service_label'] ?? '');
            $existing = null;
            foreach (CN_DB::services(['client_id' => $client_id, 'type' => $type]) as $s) {
                if (strcasecmp($s->label, $label) === 0) { $existing = $s; break; }
            }
            $data = $existing ? (array) $existing : ['auto_remind' => 1, 'status' => 'active', 'cycle_months' => 12];
            $data = array_merge($data, ['client_id' => $client_id, 'type' => $type, 'label' => $label]);
            if ($expiry !== '') $data['expiry_date'] = $expiry;
            if (($row['price'] ?? '') !== '') $data['price'] = $row['price'];
            if (($row['cycle_months'] ?? '') !== '') $data['cycle_months'] = $row['cycle_months'];
            $res = CN_DB::save_service($data, $existing ? $existing->id : 0);
            if (is_wp_error($res)) { $r['errors'][] = "Γραμμή $line: " . $res->get_error_message(); continue; }
            $existing ? $r['services_updated']++ : $r['services_new']++;
        }
        fclose($fh);
        return $r;
    }

    /* ======================================================================
     * Services
     * ==================================================================== */

    public static function page_services() {
        $action = sanitize_key($_GET['action'] ?? '');
        if ($action === 'edit' || $action === 'new') {
            self::service_form(absint($_GET['id'] ?? 0));
            return;
        }
        $f = [
            'type'     => sanitize_key($_GET['type'] ?? ''),
            'status'   => sanitize_key($_GET['status'] ?? 'active'),
            'expiring' => isset($_GET['expiring']) && $_GET['expiring'] !== '' ? absint($_GET['expiring']) : '',
            'search'   => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
        ];
        echo '<div class="wrap cn-wrap"><h1 class="wp-heading-inline">Υπηρεσίες</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(self::url('cn-services', ['action' => 'new'])) . '">Νέα υπηρεσία</a><hr class="wp-header-end">';
        self::show_notice();
        echo '<form class="cn-filters" method="get"><input type="hidden" name="page" value="cn-services">';
        echo '<input type="search" name="s" value="' . esc_attr($f['search']) . '" placeholder="Domain, πελάτης..."> ';
        echo self::select('type', CN_Util::SERVICE_TYPES, $f['type'], 'Όλοι οι τύποι') . ' ';
        echo self::select('status', CN_Util::SERVICE_STATUSES, $f['status'], 'Όλες οι καταστάσεις') . ' ';
        echo self::select('expiring', ['30' => 'Λήγουν σε 30 ημέρες', '60' => 'Λήγουν σε 60 ημέρες', '90' => 'Λήγουν σε 90 ημέρες'], $f['expiring'], 'Οποιαδήποτε λήξη');
        echo ' <button class="button">Φιλτράρισμα</button></form>';
        self::services_table(CN_DB::services($f), true);
        echo '</div>';
    }

    private static function services_table(array $rows, $show_client) {
        echo '<table class="widefat striped"><thead><tr>' . ($show_client ? '<th>Πελάτης</th>' : '')
            . '<th>Τύπος</th><th>Περιγραφή</th><th>Λήξη</th><th>Τιμή</th><th>Κατάσταση</th><th>Αυτόματες υπενθυμίσεις</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="8">Δεν βρέθηκαν υπηρεσίες.</td></tr>';
        foreach ($rows as $s) {
            $edit = esc_url(self::url('cn-services', ['action' => 'edit', 'id' => $s->id]));
            echo '<tr>';
            if ($show_client) {
                echo '<td><a href="' . esc_url(self::url('cn-clients', ['action' => 'edit', 'id' => $s->client_id])) . '">'
                    . esc_html(self::client_label($s, 'client_')) . '</a></td>';
            }
            echo '<td>' . esc_html(CN_Util::type_label($s->type)) . '</td>';
            echo '<td><a href="' . $edit . '"><strong>' . esc_html($s->label ?: '—') . '</strong></a></td>';
            echo '<td>' . self::expiry_badge($s->expiry_date, $s->status) . '</td>';
            echo '<td>' . esc_html(CN_Util::money($s->price)) . ($s->price !== null ? ' / ' . esc_html(CN_Util::cycle_label($s->cycle_months)) : '') . '</td>';
            echo '<td>' . self::status_badge($s->status, CN_Util::SERVICE_STATUSES) . '</td>';
            echo '<td>' . ($s->auto_remind ? 'Ναι' : 'Όχι') . '</td>';
            echo '<td class="cn-actions-col"><a href="' . $edit . '">Επεξεργασία</a>';
            if ($s->expiry_date) {
                echo ' | ' . self::action_button('renew_service', ['id' => $s->id], 'Ανανεώθηκε',
                    sprintf('Μετάθεση της λήξης κατά %s (από %s);', CN_Util::cycle_label($s->cycle_months), CN_Util::format_date($s->expiry_date)));
                if ($s->status === 'active') {
                    echo ' | ' . self::action_button('remind_now', ['id' => $s->id], 'Υπενθύμιση τώρα', 'Αποστολή υπενθύμισης ανανέωσης στον πελάτη τώρα;');
                }
            }
            if ($s->type === 'maintenance') {
                echo ' | <a href="' . esc_url(self::url('cn-reports', ['action' => 'new', 'service_id' => $s->id])) . '">Νέα αναφορά</a>';
            }
            echo ' | ' . self::action_button('delete_service', ['id' => $s->id], 'Διαγραφή', 'Διαγραφή της υπηρεσίας και των αναφορών της;', 'button-link button-link-delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function service_form($id) {
        $s = $id ? CN_DB::service($id) : null;
        if ($id && !$s) wp_die('Η υπηρεσία δεν βρέθηκε.');
        $v = function ($k, $d = '') use ($s) { return $s ? $s->$k : $d; };
        $client_id = $s ? $s->client_id : absint($_GET['client_id'] ?? 0);
        $clients = self::client_options();
        echo '<div class="wrap cn-wrap"><h1>' . ($s ? 'Επεξεργασία υπηρεσίας' : 'Νέα υπηρεσία') . '</h1>';
        self::show_notice();
        if (!$clients) {
            echo '<p>Δημιουργήστε πρώτα έναν <a href="' . esc_url(self::url('cn-clients', ['action' => 'new'])) . '">πελάτη</a>.</p></div>';
            return;
        }
        self::form_open('save_service');
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<table class="form-table cn-form">';
        echo '<tr><th>Πελάτης *</th><td>' . self::select('client_id', $clients, $client_id, '— Επιλογή —', 'required') . '</td></tr>';
        echo '<tr><th>Τύπος *</th><td>' . self::select('type', CN_Util::SERVICE_TYPES, $v('type', 'hosting')) . '</td></tr>';
        echo '<tr><th><label for="cn-label">Περιγραφή</label></th><td><input id="cn-label" class="regular-text" name="label" value="' . esc_attr($v('label')) . '" placeholder="π.χ. example.gr ή Πακέτο Business">'
            . '<p class="description">Εμφανίζεται στα email (π.χ. το domain ή το όνομα του πακέτου).</p></td></tr>';
        echo '<tr><th><label for="cn-price">Τιμή ανανέωσης (€)</label></th><td><input id="cn-price" name="price" inputmode="decimal" value="' . esc_attr($v('price')) . '"></td></tr>';
        echo '<tr><th><label for="cn-cycle">Διάρκεια (μήνες)</label></th><td><input id="cn-cycle" type="number" min="1" max="120" name="cycle_months" value="' . esc_attr($v('cycle_months', 12)) . '"></td></tr>';
        echo '<tr><th><label for="cn-start">Έναρξη</label></th><td><input id="cn-start" type="date" name="start_date" value="' . esc_attr($v('start_date')) . '"></td></tr>';
        echo '<tr><th><label for="cn-expiry">Λήξη</label></th><td><input id="cn-expiry" type="date" name="expiry_date" value="' . esc_attr($v('expiry_date')) . '"></td></tr>';
        echo '<tr><th>Κατάσταση</th><td>' . self::select('status', CN_Util::SERVICE_STATUSES, $v('status', 'active')) . '</td></tr>';
        echo '<tr><th>Υπενθυμίσεις</th><td><label><input type="checkbox" name="auto_remind" value="1"' . checked($v('auto_remind', 1), 1, false)
            . '> Αυτόματη αποστολή υπενθυμίσεων ανανέωσης</label><p class="description">Ημέρες πριν τη λήξη: '
            . esc_html(CN_Settings::get('reminder_days')) . ' (αλλάζουν στις <a href="' . esc_url(self::url('cn-settings')) . '">Ρυθμίσεις</a>).</p></td></tr>';
        echo '<tr><th><label for="cn-snotes">Σημειώσεις</label></th><td><textarea id="cn-snotes" class="large-text" rows="3" name="notes">' . esc_textarea($v('notes')) . '</textarea></td></tr>';
        echo '</table>';
        submit_button($s ? 'Αποθήκευση' : 'Δημιουργία υπηρεσίας');
        echo '</form></div>';
    }

    public static function handle_save_service() {
        self::guard('save_service');
        $id = absint(self::post('id', 0));
        $fields = ['client_id', 'type', 'label', 'price', 'cycle_months', 'start_date', 'expiry_date', 'status', 'notes', 'auto_remind'];
        $data = [];
        foreach ($fields as $f) $data[$f] = self::post($f);
        $result = CN_DB::save_service($data, $id);
        if (is_wp_error($result)) {
            self::notice(esc_html($result->get_error_message()), 'error');
            self::back('cn-services');
        }
        self::notice('Η υπηρεσία αποθηκεύτηκε.');
        self::redirect('cn-clients', ['action' => 'edit', 'id' => absint($data['client_id'])]);
    }

    public static function handle_delete_service() {
        self::guard('delete_service');
        CN_DB::delete_service(absint(self::post('id', 0)));
        self::notice('Η υπηρεσία διαγράφηκε.');
        self::back('cn-services');
    }

    public static function handle_renew_service() {
        self::guard('renew_service');
        $new = CN_DB::renew_service(absint(self::post('id', 0)));
        self::notice($new ? 'Νέα ημερομηνία λήξης: ' . esc_html(CN_Util::format_date($new)) . '.' : 'Η υπηρεσία δεν βρέθηκε.', $new ? 'success' : 'error');
        self::back('cn-services');
    }

    public static function handle_remind_now() {
        self::guard('remind_now');
        $s = CN_DB::service(absint(self::post('id', 0)));
        $id = $s ? CN_Mailer::queue_renewal($s) : 0;
        $ok = $id && CN_Mailer::send_now($id);
        self::notice($ok ? 'Η υπενθύμιση εστάλη στο ' . esc_html($s->client_email) . '.' : 'Η αποστολή απέτυχε — δείτε το Ιστορικό.', $ok ? 'success' : 'error');
        self::back('cn-services');
    }

    /* ======================================================================
     * Maintenance reports
     * ==================================================================== */

    public static function page_reports() {
        $action = sanitize_key($_GET['action'] ?? '');
        if ($action === 'edit' || $action === 'new') {
            self::report_form(absint($_GET['id'] ?? 0));
            return;
        }
        echo '<div class="wrap cn-wrap"><h1 class="wp-heading-inline">Αναφορές Maintenance</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(self::url('cn-reports', ['action' => 'new'])) . '">Νέα αναφορά</a><hr class="wp-header-end">';
        self::show_notice();
        $rows = CN_DB::reports();
        echo '<table class="widefat striped"><thead><tr><th>Πελάτης</th><th>Υπηρεσία</th><th>Περίοδος</th><th>Κατάσταση</th><th>Αποστολή</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">Δεν υπάρχουν αναφορές.</td></tr>';
        foreach ($rows as $r) {
            $edit = esc_url(self::url('cn-reports', ['action' => 'edit', 'id' => $r->id]));
            echo '<tr><td>' . esc_html(self::client_label($r, 'client_')) . '</td>';
            echo '<td>' . esc_html(CN_Util::type_label($r->service_type) . ($r->service_label ? ' – ' . $r->service_label : '')) . '</td>';
            echo '<td><a href="' . $edit . '"><strong>' . esc_html($r->period ?: '—') . '</strong></a></td>';
            echo '<td>' . self::status_badge($r->status, ['draft' => 'Πρόχειρο', 'sent' => 'Εστάλη']) . '</td>';
            echo '<td>' . esc_html(CN_Util::format_datetime($r->sent_at)) . '</td>';
            echo '<td class="cn-actions-col"><a href="' . $edit . '">' . ($r->status === 'sent' ? 'Προβολή' : 'Επεξεργασία') . '</a> | '
                . self::action_button('delete_report', ['id' => $r->id], 'Διαγραφή', 'Διαγραφή της αναφοράς;', 'button-link button-link-delete') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function report_form($id) {
        $r = $id ? CN_DB::report($id) : null;
        if ($id && !$r) wp_die('Η αναφορά δεν βρέθηκε.');
        $service_id = $r ? $r->service_id : absint($_GET['service_id'] ?? 0);
        $options = [];
        foreach (CN_DB::services(['type' => 'maintenance']) as $s) {
            $options[$s->id] = self::client_label($s, 'client_') . ' — ' . ($s->label ?: 'Maintenance');
        }
        // A report can also be attached to another service type if it was preselected.
        if ($service_id && !isset($options[$service_id]) && ($s = CN_DB::service($service_id))) {
            $options[$s->id] = self::client_label($s, 'client_') . ' — ' . CN_Util::type_label($s->type) . ' ' . $s->label;
        }
        $sent = $r && $r->status === 'sent';
        echo '<div class="wrap cn-wrap"><h1>' . ($r ? 'Αναφορά maintenance' : 'Νέα αναφορά maintenance') . '</h1>';
        self::show_notice();
        if (!$options) {
            echo '<p>Δεν υπάρχουν υπηρεσίες τύπου Maintenance. <a href="' . esc_url(self::url('cn-services', ['action' => 'new'])) . '">Προσθέστε μία</a>.</p></div>';
            return;
        }
        $period = $r ? $r->period : wp_date('F Y');
        self::form_open('save_report');
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';
        $dis = $sent ? ' disabled' : '';
        echo '<table class="form-table cn-form">';
        echo '<tr><th>Υπηρεσία *</th><td>' . self::select('service_id', $options, $service_id, '— Επιλογή —', 'required' . $dis) . '</td></tr>';
        echo '<tr><th><label for="cn-period">Περίοδος</label></th><td><input id="cn-period" class="regular-text" name="period" value="' . esc_attr($period) . '"' . $dis . '></td></tr>';
        echo '<tr><th>Σύνοψη</th><td>';
        if ($sent) {
            echo '<div>' . wpautop(wp_kses_post($r->summary)) . '</div>';
        } else {
            wp_editor($r ? $r->summary : '', 'cn_summary', ['textarea_name' => 'summary', 'textarea_rows' => 6, 'media_buttons' => false]);
            echo '<p class="description">Ελεύθερο κείμενο: τι έγινε, backups, έλεγχοι ασφαλείας, παρατηρήσεις.</p>';
        }
        echo '</td></tr>';
        echo '<tr><th><label for="cn-items">Ενημερώσεις (updates)</label></th><td><textarea id="cn-items" class="large-text code" rows="10" name="items"' . $dis
            . ' placeholder="WordPress | Core | 6.8.2 | 6.8.3&#10;Plugin | WooCommerce | 10.2.1 | 10.3.0&#10;Theme | YOOtheme Pro | 4.5.3 | 4.5.6">' . esc_textarea($r ? $r->items : '') . '</textarea>'
            . '<p class="description">Μία ενημέρωση ανά γραμμή: <code>Τύπος | Όνομα | Προηγούμενη έκδοση | Νέα έκδοση</code>. Εμφανίζεται ως πίνακας στο email.</p></td></tr>';
        echo '</table>';
        if (!$sent) {
            echo '<p class="submit">';
            echo '<button class="button button-primary" name="do" value="save">Αποθήκευση πρόχειρου</button> ';
            echo '<button class="button" name="do" value="test">Αποθήκευση & δοκιμή στο email μου</button> ';
            echo '<button class="button" name="do" value="send" onclick="return confirm(\'Αποστολή της αναφοράς στον πελάτη;\');">Αποθήκευση & αποστολή στον πελάτη</button>';
            echo '</p>';
        } else {
            echo '<p>Εστάλη στις ' . esc_html(CN_Util::format_datetime($r->sent_at)) . '.</p>';
        }
        echo '</form>';

        if ($r && ($service = CN_DB::service($r->service_id)) && ($client = CN_DB::client($service->client_id))) {
            list($subject, $body) = CN_Mailer::build_report($r, $service, $client);
            echo '<h2>Προεπισκόπηση</h2><p><strong>Θέμα:</strong> ' . esc_html($subject) . '<br><strong>Προς:</strong> ' . esc_html($client->email) . '</p>';
            self::preview_iframe($body);
        }
        echo '</div>';
    }

    public static function handle_save_report() {
        global $wpdb;
        self::guard('save_report');
        $id = absint(self::post('id', 0));
        $existing = $id ? CN_DB::report($id) : null;
        if ($existing && $existing->status === 'sent') {
            self::notice('Η αναφορά έχει ήδη σταλεί.', 'error');
            self::redirect('cn-reports', ['action' => 'edit', 'id' => $id]);
        }
        $service = CN_DB::service(absint(self::post('service_id', 0)));
        if (!$service) {
            self::notice('Επιλέξτε υπηρεσία.', 'error');
            self::back('cn-reports');
        }
        $row = [
            'service_id' => $service->id,
            'period'     => sanitize_text_field(self::post('period')),
            'summary'    => wp_kses_post(self::post('summary')),
            'items'      => sanitize_textarea_field(self::post('items')),
        ];
        if ($existing) {
            $wpdb->update(CN_DB::t('reports'), $row, ['id' => $id]);
        } else {
            $row['created_at'] = CN_Util::now();
            $row['status'] = 'draft';
            $wpdb->insert(CN_DB::t('reports'), $row);
            $id = (int) $wpdb->insert_id;
        }
        $report = CN_DB::report($id);
        $client = CN_DB::client($service->client_id);
        list($subject, $body) = CN_Mailer::build_report($report, $service, $client);
        $do = sanitize_key(self::post('do'));

        if ($do === 'test') {
            $me = wp_get_current_user()->user_email;
            $ok = CN_Mailer::send_test($me, $subject, $body);
            self::notice($ok ? 'Δοκιμαστικό εστάλη στο ' . esc_html($me) . '.' : 'Η δοκιμαστική αποστολή απέτυχε — δείτε το Ιστορικό.', $ok ? 'success' : 'error');
        } elseif ($do === 'send') {
            $mid = CN_DB::queue_message([
                'client_id' => $client->id, 'service_id' => $service->id, 'kind' => 'report',
                'ref' => 'report:' . $id, 'to_email' => $client->email, 'subject' => $subject, 'body' => $body,
            ]);
            if (!$mid) {
                self::notice('Υπάρχει ήδη μήνυμα για αυτή την αναφορά στο Ιστορικό.', 'error');
            } else {
                // Locked from here on: the message is in the log and the queue retries it if needed.
                $wpdb->update(CN_DB::t('reports'), ['status' => 'sent', 'sent_at' => CN_Util::now()], ['id' => $id]);
                $ok = CN_Mailer::send_now($mid);
                self::notice($ok ? 'Η αναφορά εστάλη στο ' . esc_html($client->email) . '.'
                    : 'Η άμεση αποστολή απέτυχε· το μήνυμα θα ξαναδοκιμαστεί αυτόματα — δείτε το Ιστορικό.', $ok ? 'success' : 'warning');
            }
        } else {
            self::notice('Η αναφορά αποθηκεύτηκε.');
        }
        self::redirect('cn-reports', ['action' => 'edit', 'id' => $id]);
    }

    public static function handle_delete_report() {
        global $wpdb;
        self::guard('delete_report');
        $wpdb->delete(CN_DB::t('reports'), ['id' => absint(self::post('id', 0))]);
        self::notice('Η αναφορά διαγράφηκε.');
        self::redirect('cn-reports');
    }

    /* ======================================================================
     * Campaigns (updates / announcements to many clients)
     * ==================================================================== */

    public static function page_campaigns() {
        $action = sanitize_key($_GET['action'] ?? '');
        if ($action === 'edit' || $action === 'new') {
            self::campaign_form(absint($_GET['id'] ?? 0));
            return;
        }
        echo '<div class="wrap cn-wrap"><h1 class="wp-heading-inline">Ενημερώσεις / Αποστολές</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(self::url('cn-campaigns', ['action' => 'new'])) . '">Νέα αποστολή</a><hr class="wp-header-end">';
        self::show_notice();
        echo '<p class="description">Ελεύθερες αποστολές σε όλους τους πελάτες, σε όσους έχουν συγκεκριμένη υπηρεσία ή σε επιλεγμένους πελάτες '
            . '(π.χ. ανακοινώσεις, εργασίες συντήρησης server, νέες υπηρεσίες). Οι πελάτες που έχουν διαγραφεί από τις ενημερώσεις εξαιρούνται αυτόματα.</p>';
        $rows = CN_DB::campaigns();
        echo '<table class="widefat striped"><thead><tr><th>Θέμα</th><th>Κατάσταση</th><th>Παραλήπτες</th><th>Εστάλησαν</th><th>Απέτυχαν</th><th>Δημιουργία</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7">Δεν υπάρχουν αποστολές.</td></tr>';
        foreach ($rows as $k) {
            $edit = esc_url(self::url('cn-campaigns', ['action' => 'edit', 'id' => $k->id]));
            echo '<tr><td><a href="' . $edit . '"><strong>' . esc_html($k->subject ?: '(χωρίς θέμα)') . '</strong></a></td>';
            echo '<td>' . self::status_badge($k->status, ['draft' => 'Πρόχειρο', 'queued' => 'Σε αποστολή']) . '</td>';
            echo '<td>' . (int) $k->recipients . '</td><td>' . (int) $k->sent_count . '</td><td>' . (int) $k->failed_count . '</td>';
            echo '<td>' . esc_html(CN_Util::format_datetime($k->created_at)) . '</td><td class="cn-actions-col"><a href="' . $edit . '">'
                . ($k->status === 'draft' ? 'Επεξεργασία' : 'Προβολή') . '</a>';
            if ($k->status !== 'draft') {
                echo ' | <a href="' . esc_url(self::url('cn-log', ['campaign_id' => $k->id])) . '">Μηνύματα</a>';
            } else {
                echo ' | ' . self::action_button('delete_campaign', ['id' => $k->id], 'Διαγραφή', 'Διαγραφή του πρόχειρου;', 'button-link button-link-delete');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function campaign_form($id) {
        $k = $id ? CN_DB::campaign($id) : null;
        if ($id && !$k) wp_die('Η αποστολή δεν βρέθηκε.');
        $aud = $k && $k->audience ? (array) json_decode($k->audience, true) : ['mode' => 'all'];
        $mode = $aud['mode'] ?? 'all';
        $locked = $k && $k->status !== 'draft';
        $dis = $locked ? ' disabled' : '';
        echo '<div class="wrap cn-wrap"><h1>' . ($k ? 'Αποστολή' : 'Νέα αποστολή') . '</h1>';
        self::show_notice();
        self::form_open('save_campaign');
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<table class="form-table cn-form">';
        echo '<tr><th><label for="cn-subject">Θέμα *</label></th><td><input id="cn-subject" class="large-text" required name="subject" value="' . esc_attr($k ? $k->subject : '') . '"' . $dis . '></td></tr>';
        echo '<tr><th>Κείμενο</th><td>';
        if ($locked) {
            echo '<div>' . wpautop(wp_kses_post($k->body)) . '</div>';
        } else {
            wp_editor($k ? $k->body : '', 'cn_campaign_body', ['textarea_name' => 'body', 'textarea_rows' => 12]);
            echo '<p class="description cn-help">Μεταβλητές: <code>{client_name}</code> <code>{company}</code> <code>{client_email}</code> <code>{services_list}</code> (λίστα ενεργών υπηρεσιών του πελάτη) <code>{site_name}</code>. '
                . 'Ο σύνδεσμος διαγραφής από τις ενημερώσεις προστίθεται αυτόματα στο τέλος.</p>';
        }
        echo '</td></tr>';
        echo '<tr><th>Παραλήπτες</th><td><fieldset' . $dis . '>';
        echo '<label><input type="radio" name="aud_mode" value="all"' . checked($mode, 'all', false) . '> Όλοι οι ενεργοί πελάτες</label><br>';
        echo '<label><input type="radio" name="aud_mode" value="types"' . checked($mode, 'types', false) . '> Πελάτες με ενεργή υπηρεσία τύπου:</label><br><span style="margin-left:24px;display:inline-block">';
        foreach (CN_Util::SERVICE_TYPES as $t => $l) {
            echo '<label style="margin-right:12px"><input type="checkbox" name="aud_types[]" value="' . esc_attr($t) . '"'
                . checked(in_array($t, (array) ($aud['types'] ?? []), true), true, false) . '> ' . esc_html($l) . '</label>';
        }
        echo '</span><br><label><input type="radio" name="aud_mode" value="clients"' . checked($mode, 'clients', false) . '> Επιλεγμένοι πελάτες:</label><br>';
        echo '<select name="aud_clients[]" multiple size="8" style="margin-left:24px;min-width:420px">';
        $sel = CN_Util::ids($aud['clients'] ?? []);
        foreach (self::client_options() as $cid => $label) {
            echo '<option value="' . (int) $cid . '"' . selected(in_array((int) $cid, $sel, true), true, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description" style="margin-left:24px">Ctrl/Cmd + κλικ για πολλαπλή επιλογή.</p></fieldset>';
        if ($k) {
            $n = $locked ? (int) $k->recipients : count(CN_DB::audience_client_ids($aud));
            echo '<p><strong>' . ($locked ? 'Παραλήπτες: ' : 'Παραλήπτες με τις αποθηκευμένες επιλογές: ') . $n . '</strong></p>';
        }
        echo '</td></tr></table>';
        if (!$locked) {
            echo '<p class="submit">';
            echo '<button class="button button-primary" name="do" value="save">Αποθήκευση πρόχειρου</button> ';
            echo '<button class="button" name="do" value="test">Αποθήκευση & δοκιμή στο email μου</button> ';
            echo '<button class="button" name="do" value="send" onclick="return confirm(\'Έναρξη αποστολής σε όλους τους παραλήπτες; Δεν αναιρείται.\');">Αποθήκευση & αποστολή</button>';
            echo '</p><p class="description">Τα μηνύματα μπαίνουν σε ουρά και αποστέλλονται σταδιακά (' . (int) CN_Settings::get('batch_size')
                . ' ανά 5 λεπτά), για να μη χαρακτηριστούν spam και να μην ξεπεραστούν τα όρια του server.</p>';
        } else {
            echo '<p>Μπήκε σε ουρά στις ' . esc_html(CN_Util::format_datetime($k->queued_at)) . '. <a href="' . esc_url(self::url('cn-log', ['campaign_id' => $k->id])) . '">Δείτε τα μηνύματα</a>.</p>';
        }
        echo '</form>';
        if ($k) {
            $me = self::current_user_as_client();
            list($subject, $body) = CN_Mailer::build_campaign($k, $me);
            echo '<h2>Προεπισκόπηση</h2><p><strong>Θέμα:</strong> ' . esc_html($subject) . '</p>';
            self::preview_iframe($body);
        }
        echo '</div>';
    }

    /** Fake client object so previews/tests render with the admin's own details. */
    private static function current_user_as_client() {
        $u = wp_get_current_user();
        return (object) ['id' => 0, 'name' => $u->display_name, 'company' => '', 'email' => $u->user_email, 'unsubscribed_at' => null, 'status' => 'active'];
    }

    public static function handle_save_campaign() {
        global $wpdb;
        self::guard('save_campaign');
        $id = absint(self::post('id', 0));
        $existing = $id ? CN_DB::campaign($id) : null;
        if ($existing && $existing->status !== 'draft') {
            self::notice('Η αποστολή έχει ήδη ξεκινήσει.', 'error');
            self::redirect('cn-campaigns', ['action' => 'edit', 'id' => $id]);
        }
        $mode = sanitize_key(self::post('aud_mode', 'all'));
        $aud = ['mode' => in_array($mode, ['all', 'types', 'clients'], true) ? $mode : 'all'];
        if ($aud['mode'] === 'types') $aud['types'] = array_values(array_intersect(array_keys(CN_Util::SERVICE_TYPES), array_map('sanitize_key', (array) self::post('aud_types', []))));
        if ($aud['mode'] === 'clients') $aud['clients'] = CN_Util::ids(self::post('aud_clients', []));
        $row = [
            'subject'  => sanitize_text_field(self::post('subject')),
            'body'     => wp_kses_post(self::post('body')),
            'audience' => wp_json_encode($aud),
        ];
        if ($existing) {
            $wpdb->update(CN_DB::t('campaigns'), $row, ['id' => $id]);
        } else {
            $row['created_at'] = CN_Util::now();
            $row['status'] = 'draft';
            $wpdb->insert(CN_DB::t('campaigns'), $row);
            $id = (int) $wpdb->insert_id;
        }
        $k = CN_DB::campaign($id);
        $do = sanitize_key(self::post('do'));

        if ($do === 'test') {
            $me = self::current_user_as_client();
            list($subject, $body) = CN_Mailer::build_campaign($k, $me);
            $ok = CN_Mailer::send_test($me->email, $subject, $body);
            self::notice($ok ? 'Δοκιμαστικό εστάλη στο ' . esc_html($me->email) . '.' : 'Η δοκιμαστική αποστολή απέτυχε — δείτε το Ιστορικό.', $ok ? 'success' : 'error');
        } elseif ($do === 'send') {
            if ($k->subject === '' || trim(wp_strip_all_tags($k->body)) === '') {
                self::notice('Συμπληρώστε θέμα και κείμενο.', 'error');
                self::redirect('cn-campaigns', ['action' => 'edit', 'id' => $id]);
            }
            $ids = CN_DB::audience_client_ids($aud);
            if (!$ids) {
                self::notice('Δεν υπάρχουν παραλήπτες με αυτές τις επιλογές.', 'error');
                self::redirect('cn-campaigns', ['action' => 'edit', 'id' => $id]);
            }
            // Claim the campaign atomically so a double click cannot queue it twice.
            $claimed = $wpdb->query($wpdb->prepare('UPDATE ' . CN_DB::t('campaigns') . " SET status = 'queued', queued_at = %s, recipients = %d WHERE id = %d AND status = 'draft'",
                CN_Util::now(), count($ids), $id));
            if ($claimed) {
                $n = 0;
                foreach ($ids as $cid) {
                    $client = CN_DB::client($cid);
                    list($subject, $body) = CN_Mailer::build_campaign($k, $client);
                    $n += CN_DB::queue_message([
                        'client_id' => $cid, 'campaign_id' => $id, 'kind' => 'campaign', 'ref' => 'campaign:' . $id . ':' . $cid,
                        'to_email' => $client->email, 'subject' => $subject, 'body' => $body,
                    ]) ? 1 : 0;
                }
                self::notice(sprintf('%d μηνύματα μπήκαν σε ουρά και θα αποσταλούν σταδιακά.', $n));
            }
        } else {
            self::notice('Η αποστολή αποθηκεύτηκε ως πρόχειρο.');
        }
        self::redirect('cn-campaigns', ['action' => 'edit', 'id' => $id]);
    }

    public static function handle_delete_campaign() {
        global $wpdb;
        self::guard('delete_campaign');
        $wpdb->delete(CN_DB::t('campaigns'), ['id' => absint(self::post('id', 0)), 'status' => 'draft']);
        self::notice('Το πρόχειρο διαγράφηκε.');
        self::redirect('cn-campaigns');
    }

    /* ======================================================================
     * Log
     * ==================================================================== */

    public static function page_log() {
        if (!empty($_GET['view'])) {
            $m = CN_DB::message(absint($_GET['view']));
            if (!$m) wp_die('Το μήνυμα δεν βρέθηκε.');
            echo '<div class="wrap cn-wrap"><h1>Μήνυμα #' . (int) $m->id . '</h1>';
            echo '<p><a href="' . esc_url(self::url('cn-log')) . '">&larr; Ιστορικό</a></p>';
            echo '<table class="form-table"><tr><th>Προς</th><td>' . esc_html($m->to_email) . '</td></tr>';
            echo '<tr><th>Θέμα</th><td>' . esc_html($m->subject) . '</td></tr>';
            echo '<tr><th>Τύπος</th><td>' . esc_html(CN_Util::MESSAGE_KINDS[$m->kind] ?? $m->kind) . '</td></tr>';
            echo '<tr><th>Κατάσταση</th><td>' . self::status_badge($m->status, CN_Util::MESSAGE_STATUSES)
                . ($m->last_error ? ' <code>' . esc_html($m->last_error) . '</code>' : '') . '</td></tr>';
            echo '<tr><th>Δημιουργία / Αποστολή</th><td>' . esc_html(CN_Util::format_datetime($m->created_at)) . ' / ' . esc_html(CN_Util::format_datetime($m->sent_at)) . '</td></tr></table>';
            self::preview_iframe((string) $m->body);
            echo '</div>';
            return;
        }
        $f = [
            'kind'        => sanitize_key($_GET['kind'] ?? ''),
            'status'      => sanitize_key($_GET['status'] ?? ''),
            'campaign_id' => absint($_GET['campaign_id'] ?? 0),
        ];
        $page = max(1, absint($_GET['paged'] ?? 1));
        list($rows, $total) = CN_DB::messages($f, $page, 50);
        $counts = CN_DB::message_counts();
        echo '<div class="wrap cn-wrap"><h1>Ιστορικό αποστολών</h1>';
        self::show_notice();
        echo '<p>Σε αναμονή: <strong>' . (int) $counts['queued'] . '</strong> · Απέτυχαν: <strong>' . (int) $counts['failed'] . '</strong> '
            . self::action_button('run_queue', [], 'Αποστολή επόμενης παρτίδας τώρα', '', 'button') . '</p>';
        echo '<form class="cn-filters" method="get"><input type="hidden" name="page" value="cn-log">';
        if ($f['campaign_id']) echo '<input type="hidden" name="campaign_id" value="' . (int) $f['campaign_id'] . '">';
        echo self::select('kind', CN_Util::MESSAGE_KINDS, $f['kind'], 'Όλοι οι τύποι') . ' ';
        echo self::select('status', CN_Util::MESSAGE_STATUSES, $f['status'], 'Όλες οι καταστάσεις');
        echo ' <button class="button">Φιλτράρισμα</button>';
        if ($f['campaign_id']) echo ' <a href="' . esc_url(self::url('cn-log')) . '">Όλα τα μηνύματα</a>';
        echo '</form>';
        self::messages_table($rows);
        $pages = (int) ceil($total / 50);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
                'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages,
            ]) . '</div></div>';
        }
        echo '</div>';
    }

    private static function messages_table(array $rows) {
        echo '<table class="widefat striped"><thead><tr><th>Ημερομηνία</th><th>Προς</th><th>Τύπος</th><th>Θέμα</th><th>Κατάσταση</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">Δεν υπάρχουν μηνύματα.</td></tr>';
        foreach ($rows as $m) {
            $when = $m->sent_at ?: ($m->status === 'queued' ? $m->scheduled_at : $m->created_at);
            echo '<tr><td>' . esc_html(CN_Util::format_datetime($when)) . '</td>';
            echo '<td>' . esc_html($m->to_email) . (!empty($m->client_name) ? '<br><span class="description">' . esc_html($m->client_name) . '</span>' : '') . '</td>';
            echo '<td>' . esc_html(CN_Util::MESSAGE_KINDS[$m->kind] ?? $m->kind) . '</td>';
            echo '<td>' . esc_html($m->subject) . '</td>';
            echo '<td>' . self::status_badge($m->status, CN_Util::MESSAGE_STATUSES)
                . ($m->last_error ? '<br><span class="description">' . esc_html($m->last_error) . '</span>' : '') . '</td>';
            echo '<td class="cn-actions-col"><a href="' . esc_url(self::url('cn-log', ['view' => $m->id])) . '">Προβολή</a>';
            if (in_array($m->status, ['failed', 'cancelled'], true)) echo ' | ' . self::action_button('retry_message', ['id' => $m->id], 'Επανάληψη');
            if ($m->status === 'queued') echo ' | ' . self::action_button('cancel_message', ['id' => $m->id], 'Ακύρωση', '', 'button-link button-link-delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function handle_retry_message() {
        global $wpdb;
        self::guard('retry_message');
        $id = absint(self::post('id', 0));
        $wpdb->update(CN_DB::t('messages'), ['status' => 'queued', 'attempts' => 0, 'last_error' => null, 'scheduled_at' => CN_Util::now()], ['id' => $id]);
        $ok = CN_Mailer::send_now($id);
        $m = CN_DB::message($id);
        self::notice($ok ? 'Το μήνυμα εστάλη.' : 'Δεν εστάλη: ' . esc_html($m ? (string) $m->last_error : ''), $ok ? 'success' : 'error');
        self::back('cn-log');
    }

    public static function handle_cancel_message() {
        global $wpdb;
        self::guard('cancel_message');
        $wpdb->update(CN_DB::t('messages'), ['status' => 'cancelled', 'last_error' => 'Ακυρώθηκε από διαχειριστή.'], ['id' => absint(self::post('id', 0)), 'status' => 'queued']);
        self::notice('Το μήνυμα ακυρώθηκε.');
        self::back('cn-log');
    }

    public static function handle_run_queue() {
        self::guard('run_queue');
        list($sent, $failed) = CN_Mailer::process_queue();
        self::notice(sprintf('Εστάλησαν %d, απέτυχαν %d.', $sent, $failed), $failed ? 'warning' : 'success');
        self::back('cn-log');
    }

    public static function handle_run_reminders() {
        self::guard('run_reminders');
        $n = CN_Cron::queue_renewal_reminders();
        list($sent, $failed) = CN_Mailer::process_queue();
        self::notice(sprintf('Νέες υπενθυμίσεις: %d. Εστάλησαν %d, απέτυχαν %d.', $n, $sent, $failed));
        self::back('cn-dashboard');
    }

    /* ======================================================================
     * Settings
     * ==================================================================== */

    public static function page_settings() {
        $s = CN_Settings::all();
        $text = function ($key, $label, $type = 'text', $desc = '') use ($s) {
            echo '<tr><th><label for="cn-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input id="cn-' . esc_attr($key) . '" type="' . esc_attr($type)
                . '" class="regular-text" name="cn[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '">'
                . ($desc ? '<p class="description">' . $desc . '</p>' : '') . '</td></tr>';
        };
        echo '<div class="wrap cn-wrap"><h1>Ρυθμίσεις</h1>';
        self::show_notice();
        self::form_open('save_settings');
        echo '<h2>Αποστολέας</h2><table class="form-table cn-form">';
        $text('from_name', 'Όνομα αποστολέα');
        $text('from_email', 'Email αποστολέα', 'email', 'Πρέπει να ανήκει στο domain του site (π.χ. info@domain.gr), με σωστά SPF/DKIM/DMARC. Κενό = προεπιλογή WordPress/SMTP plugin.');
        $text('reply_to', 'Reply-To', 'email', 'Εκεί πηγαίνουν οι απαντήσεις των πελατών.');
        $text('bcc_renewals', 'Κρυφή κοινοποίηση υπενθυμίσεων', 'email', 'Προαιρετικά: αντίγραφο κάθε υπενθύμισης ανανέωσης σε εσάς.');
        echo '</table><h2>Υπενθυμίσεις & ουρά</h2><table class="form-table cn-form">';
        $text('reminder_days', 'Ημέρες πριν τη λήξη', 'text', 'Χωρισμένες με κόμμα. <code>0</code> = την ημέρα λήξης, αρνητικό = μετά τη λήξη (π.χ. <code>-3</code>).');
        $text('batch_size', 'Μηνύματα ανά 5 λεπτά', 'number', 'Όριο αποστολής ανά εκτέλεση της ουράς (1–200). Ελέγξτε τα όρια του hosting/SMTP.');
        echo '</table><h2>Εμφάνιση email</h2><table class="form-table cn-form">';
        $text('brand_color', 'Χρώμα', 'color');
        $text('logo_url', 'URL λογότυπου', 'url', 'Προτιμήστε https και PNG. Κενό = εμφανίζεται το όνομα αποστολέα.');
        echo '<tr><th><label for="cn-footer">Υποσέλιδο</label></th><td><textarea id="cn-footer" class="large-text" rows="3" name="cn[footer_text]">' . esc_textarea($s['footer_text']) . '</textarea>'
            . '<p class="description">Π.χ. επωνυμία, διεύθυνση, τηλέφωνο, ΑΦΜ.</p></td></tr>';
        echo '</table>';

        $vars_common = '<code>{client_name}</code> <code>{company}</code> <code>{site_name}</code> <code>{service_type}</code> <code>{service_label}</code> <code>{expiry_date}</code> <code>{days_left}</code> <code>{price}</code> <code>{cycle}</code>';
        echo '<h2>Πρότυπο: Υπενθύμιση ανανέωσης</h2><table class="form-table cn-form">';
        $text('renewal_subject', 'Θέμα');
        echo '<tr><th>Κείμενο</th><td>';
        wp_editor($s['renewal_body'], 'cn_renewal_body', ['textarea_name' => 'cn[renewal_body]', 'textarea_rows' => 10, 'media_buttons' => false]);
        echo '<p class="description cn-help">Μεταβλητές: ' . $vars_common . ' <code>{expiry_phrase}</code> («λήγει σε 7 ημέρες», «έληξε χθες»…) <code>{price_line}</code> («Κόστος ανανέωσης: …» ή κενό).</p></td></tr></table>';
        echo '<h2>Πρότυπο: Αναφορά maintenance</h2><table class="form-table cn-form">';
        $text('report_subject', 'Θέμα');
        echo '<tr><th>Κείμενο</th><td>';
        wp_editor($s['report_body'], 'cn_report_body', ['textarea_name' => 'cn[report_body]', 'textarea_rows' => 10, 'media_buttons' => false]);
        echo '<p class="description cn-help">Μεταβλητές: ' . $vars_common . ' <code>{report_period}</code> <code>{report_summary}</code> <code>{report_items}</code> (πίνακας ενημερώσεων).</p></td></tr></table>';

        echo '<h2>Δεδομένα</h2><table class="form-table cn-form"><tr><th>Απεγκατάσταση</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1"'
            . checked((bool) get_option('cn_delete_on_uninstall'), true, false) . '> Διαγραφή όλων των δεδομένων όταν διαγραφεί το plugin</label></td></tr></table>';
        submit_button('Αποθήκευση ρυθμίσεων');
        echo '</form>';

        echo '<h2>Δοκιμή αποστολής</h2>';
        self::form_open('send_test');
        echo '<p><input type="email" name="to" class="regular-text" value="' . esc_attr(wp_get_current_user()->user_email) . '" required> ';
        echo self::select('template', ['renewal' => 'Υπενθύμιση ανανέωσης', 'report' => 'Αναφορά maintenance'], 'renewal');
        echo ' <button class="button">Αποστολή δοκιμαστικού</button></p>';
        echo '<p class="description">Στέλνει το πρότυπο με ενδεικτικά δεδομένα. Αν δεν φτάσει, εγκαταστήστε ένα SMTP plugin (π.χ. WP Mail SMTP, FluentSMTP).</p></form>';
        echo '</div>';
    }

    public static function handle_save_settings() {
        self::guard('save_settings');
        $in = isset($_POST['cn']) && is_array($_POST['cn']) ? $_POST['cn'] : [];
        // Bodies are unslashed inside sanitize(); the other fields here.
        foreach ($in as $k => $v) if (!in_array($k, ['renewal_body', 'report_body'], true)) $in[$k] = wp_unslash($v);
        CN_Settings::save($in);
        update_option('cn_delete_on_uninstall', self::post('delete_on_uninstall') ? 1 : 0, false);
        CN_Cron::schedule();
        self::notice('Οι ρυθμίσεις αποθηκεύτηκαν.');
        self::redirect('cn-settings');
    }

    public static function handle_send_test() {
        self::guard('send_test');
        $to = sanitize_email(self::post('to'));
        if (!is_email($to)) {
            self::notice('Μη έγκυρο email.', 'error');
            self::redirect('cn-settings');
        }
        $client = self::current_user_as_client();
        $client->email = $to;
        $service = (object) ['id' => 0, 'client_id' => 0, 'type' => 'domain', 'label' => 'example.gr', 'price' => 25,
            'cycle_months' => 12, 'expiry_date' => CN_Util::add_days(CN_Util::today(), 14), 'status' => 'active'];
        $s = CN_Settings::all();
        if (self::post('template') === 'report') {
            $service->type = 'maintenance';
            $report = (object) ['period' => wp_date('F Y'), 'summary' => 'Έγινε πλήρες backup, έλεγχος ασφαλείας και ενημέρωση όλων των στοιχείων.',
                'items' => "WordPress | Core | 6.8.2 | 6.8.3\nPlugin | WooCommerce | 10.2.1 | 10.3.0\nTheme | YOOtheme Pro | 4.5.3 | 4.5.6"];
            list($subject, $body) = CN_Mailer::build_report($report, $service, $client);
        } else {
            $vars = array_merge(CN_Mailer::client_vars($client), CN_Mailer::service_vars($service));
            $subject = CN_Mailer::render_subject($s['renewal_subject'], $vars);
            $body = CN_Mailer::wrap(CN_Mailer::render($s['renewal_body'], $vars));
        }
        $ok = CN_Mailer::send_test($to, $subject, $body);
        self::notice($ok ? 'Το δοκιμαστικό εστάλη στο ' . esc_html($to) . '.' : 'Η αποστολή απέτυχε — δείτε το Ιστορικό για το σφάλμα.', $ok ? 'success' : 'error');
        self::redirect('cn-settings');
    }
}
