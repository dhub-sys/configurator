<?php
if (!defined('ABSPATH')) exit;

final class CN_DB {
    const VERSION = '1';
    const VERSION_OPTION = 'cn_db_version';

    public static function t($name) {
        global $wpdb;
        return $wpdb->prefix . 'cn_' . $name;
    }

    public static function maybe_upgrade() {
        if (get_option(self::VERSION_OPTION) !== self::VERSION) self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $clients = self::t('clients');
        $services = self::t('services');
        $reports = self::t('reports');
        $campaigns = self::t('campaigns');
        $messages = self::t('messages');

        dbDelta("CREATE TABLE $clients (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL,
            phone varchar(50) NOT NULL DEFAULT '',
            notes text NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            unsubscribed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY status (status)
        ) $c;");

        dbDelta("CREATE TABLE $services (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id bigint(20) unsigned NOT NULL,
            type varchar(30) NOT NULL,
            label varchar(190) NOT NULL DEFAULT '',
            price decimal(10,2) NULL,
            cycle_months smallint(5) unsigned NOT NULL DEFAULT 12,
            start_date date NULL,
            expiry_date date NULL,
            auto_remind tinyint(1) NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'active',
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY client_id (client_id),
            KEY expiry (status,expiry_date)
        ) $c;");

        dbDelta("CREATE TABLE $reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            service_id bigint(20) unsigned NOT NULL,
            period varchar(100) NOT NULL DEFAULT '',
            summary longtext NULL,
            items longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_at datetime NOT NULL,
            sent_at datetime NULL,
            PRIMARY KEY  (id),
            KEY service_id (service_id)
        ) $c;");

        dbDelta("CREATE TABLE $campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subject varchar(255) NOT NULL DEFAULT '',
            body longtext NULL,
            audience longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            recipients int(10) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            queued_at datetime NULL,
            PRIMARY KEY  (id)
        ) $c;");

        dbDelta("CREATE TABLE $messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id bigint(20) unsigned NOT NULL DEFAULT 0,
            service_id bigint(20) unsigned NULL,
            campaign_id bigint(20) unsigned NULL,
            kind varchar(30) NOT NULL,
            ref varchar(190) NULL,
            to_email varchar(190) NOT NULL,
            subject varchar(255) NOT NULL DEFAULT '',
            body longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL,
            scheduled_at datetime NOT NULL,
            sent_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ref (ref),
            KEY queue (status,scheduled_at),
            KEY client_id (client_id),
            KEY campaign_id (campaign_id)
        ) $c;");

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /* ---------- Clients ---------- */

    public static function client($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('clients') . ' WHERE id = %d', $id));
    }

    public static function client_by_email($email) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('clients') . ' WHERE email = %s', $email));
    }

    public static function clients($search = '', $status = '') {
        global $wpdb;
        $t = self::t('clients');
        $s = self::t('services');
        $where = ['1=1'];
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(c.name LIKE %s OR c.company LIKE %s OR c.email LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'c.status = %s';
            $args[] = $status;
        }
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM $s s WHERE s.client_id = c.id AND s.status = 'active') AS active_services
                FROM $t c WHERE " . implode(' AND ', $where) . ' ORDER BY c.name ASC, c.company ASC';
        return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
    }

    /** Insert or update; returns the id, or WP_Error. */
    public static function save_client(array $data, $id = 0) {
        global $wpdb;
        $email = sanitize_email($data['email'] ?? '');
        if (!is_email($email)) return new WP_Error('email', 'Μη έγκυρη διεύθυνση email.');
        $existing = self::client_by_email($email);
        if ($existing && (int) $existing->id !== (int) $id) {
            return new WP_Error('email', 'Υπάρχει ήδη πελάτης με αυτό το email.');
        }
        $row = [
            'name'       => sanitize_text_field($data['name'] ?? ''),
            'company'    => sanitize_text_field($data['company'] ?? ''),
            'email'      => $email,
            'phone'      => sanitize_text_field($data['phone'] ?? ''),
            'notes'      => sanitize_textarea_field($data['notes'] ?? ''),
            'status'     => isset(CN_Util::CLIENT_STATUSES[$data['status'] ?? '']) ? $data['status'] : 'active',
            'updated_at' => CN_Util::now(),
        ];
        if ($id) {
            $wpdb->update(self::t('clients'), $row, ['id' => $id]);
            return (int) $id;
        }
        $row['created_at'] = $row['updated_at'];
        $wpdb->insert(self::t('clients'), $row);
        return (int) $wpdb->insert_id;
    }

    public static function set_unsubscribed($id, $unsubscribed) {
        global $wpdb;
        $wpdb->update(self::t('clients'), ['unsubscribed_at' => $unsubscribed ? CN_Util::now() : null], ['id' => $id]);
    }

    /** Deletes the client with all services, reports and messages. */
    public static function delete_client($id) {
        global $wpdb;
        $id = (int) $id;
        $service_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::t('services') . ' WHERE client_id = %d', $id));
        foreach ($service_ids as $sid) {
            $wpdb->delete(self::t('reports'), ['service_id' => (int) $sid]);
        }
        $wpdb->delete(self::t('services'), ['client_id' => $id]);
        $wpdb->delete(self::t('messages'), ['client_id' => $id]);
        $wpdb->delete(self::t('clients'), ['id' => $id]);
    }

    /* ---------- Services ---------- */

    public static function service($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT s.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.status AS client_status
             FROM ' . self::t('services') . ' s JOIN ' . self::t('clients') . ' c ON c.id = s.client_id WHERE s.id = %d', $id));
    }

    public static function services(array $f = []) {
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        if (!empty($f['client_id'])) { $where[] = 's.client_id = %d'; $args[] = (int) $f['client_id']; }
        if (!empty($f['type'])) { $where[] = 's.type = %s'; $args[] = $f['type']; }
        if (!empty($f['status'])) { $where[] = 's.status = %s'; $args[] = $f['status']; }
        if (isset($f['expiring']) && $f['expiring'] !== '') {
            $where[] = 's.expiry_date IS NOT NULL AND s.expiry_date <= %s';
            $args[] = CN_Util::add_days(CN_Util::today(), (int) $f['expiring']);
        }
        if (!empty($f['search'])) {
            $like = '%' . $wpdb->esc_like($f['search']) . '%';
            $where[] = '(s.label LIKE %s OR c.name LIKE %s OR c.company LIKE %s OR c.email LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        $sql = 'SELECT s.*, c.name AS client_name, c.company AS client_company, c.email AS client_email
                FROM ' . self::t('services') . ' s JOIN ' . self::t('clients') . ' c ON c.id = s.client_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY s.expiry_date IS NULL, s.expiry_date ASC, c.name ASC';
        return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
    }

    public static function save_service(array $data, $id = 0) {
        global $wpdb;
        $client_id = absint($data['client_id'] ?? 0);
        if (!$client_id || !self::client($client_id)) return new WP_Error('client', 'Επιλέξτε πελάτη.');
        $type = sanitize_key($data['type'] ?? '');
        if (!isset(CN_Util::SERVICE_TYPES[$type])) return new WP_Error('type', 'Μη έγκυρος τύπος υπηρεσίας.');
        $price = trim(str_replace(['€', ' '], '', (string) ($data['price'] ?? '')));
        if ($price !== '') $price = (float) str_replace(',', '.', $price);
        $row = [
            'client_id'    => $client_id,
            'type'         => $type,
            'label'        => sanitize_text_field($data['label'] ?? ''),
            'price'        => $price === '' ? null : $price,
            'cycle_months' => max(1, min(120, absint($data['cycle_months'] ?? 12))),
            'start_date'   => CN_Util::parse_date($data['start_date'] ?? '') ?: null,
            'expiry_date'  => CN_Util::parse_date($data['expiry_date'] ?? '') ?: null,
            'auto_remind'  => empty($data['auto_remind']) ? 0 : 1,
            'status'       => isset(CN_Util::SERVICE_STATUSES[$data['status'] ?? '']) ? $data['status'] : 'active',
            'notes'        => sanitize_textarea_field($data['notes'] ?? ''),
            'updated_at'   => CN_Util::now(),
        ];
        if ($id) {
            $wpdb->update(self::t('services'), $row, ['id' => $id]);
            return (int) $id;
        }
        $row['created_at'] = $row['updated_at'];
        $wpdb->insert(self::t('services'), $row);
        return (int) $wpdb->insert_id;
    }

    public static function delete_service($id) {
        global $wpdb;
        $wpdb->delete(self::t('reports'), ['service_id' => (int) $id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('messages') . " WHERE service_id = %d AND status = 'queued'", $id));
        $wpdb->delete(self::t('services'), ['id' => (int) $id]);
    }

    /** Moves expiry forward by one billing cycle (from the old expiry, or today if none). */
    public static function renew_service($id) {
        global $wpdb;
        $s = self::service($id);
        if (!$s) return false;
        $base = $s->expiry_date ?: CN_Util::today();
        $new = CN_Util::add_months($base, $s->cycle_months);
        $wpdb->update(self::t('services'), ['expiry_date' => $new, 'status' => 'active', 'updated_at' => CN_Util::now()], ['id' => $id]);
        // Reminders queued for the old expiry date are now stale.
        $wpdb->query($wpdb->prepare('UPDATE ' . self::t('messages') . " SET status = 'cancelled', last_error = %s
            WHERE service_id = %d AND kind = 'renewal' AND status = 'queued'", 'Η υπηρεσία ανανεώθηκε.', $id));
        return $new;
    }

    /* ---------- Reports ---------- */

    public static function report($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('reports') . ' WHERE id = %d', $id));
    }

    public static function reports() {
        global $wpdb;
        return $wpdb->get_results('SELECT r.*, s.label AS service_label, s.type AS service_type, c.name AS client_name, c.company AS client_company
            FROM ' . self::t('reports') . ' r JOIN ' . self::t('services') . ' s ON s.id = r.service_id
            JOIN ' . self::t('clients') . ' c ON c.id = s.client_id ORDER BY r.created_at DESC LIMIT 500');
    }

    /* ---------- Campaigns ---------- */

    public static function campaign($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('campaigns') . ' WHERE id = %d', $id));
    }

    public static function campaigns() {
        global $wpdb;
        $m = self::t('messages');
        return $wpdb->get_results('SELECT k.*,
            (SELECT COUNT(*) FROM ' . $m . " WHERE campaign_id = k.id AND status = 'sent') AS sent_count,
            (SELECT COUNT(*) FROM " . $m . " WHERE campaign_id = k.id AND status = 'failed') AS failed_count
            FROM " . self::t('campaigns') . ' k ORDER BY k.created_at DESC LIMIT 500');
    }

    /** Client IDs the campaign audience resolves to (active, not unsubscribed). */
    public static function audience_client_ids(array $aud) {
        global $wpdb;
        $c = self::t('clients');
        $s = self::t('services');
        $base = "SELECT c.id FROM $c c WHERE c.status = 'active' AND c.unsubscribed_at IS NULL";
        $mode = $aud['mode'] ?? 'all';
        if ($mode === 'types') {
            $types = array_values(array_intersect(array_keys(CN_Util::SERVICE_TYPES), (array) ($aud['types'] ?? [])));
            if (!$types) return [];
            $ph = implode(',', array_fill(0, count($types), '%s'));
            return array_map('intval', $wpdb->get_col($wpdb->prepare(
                "$base AND EXISTS (SELECT 1 FROM $s s WHERE s.client_id = c.id AND s.status = 'active' AND s.type IN ($ph))", $types)));
        }
        if ($mode === 'clients') {
            $ids = CN_Util::ids($aud['clients'] ?? []);
            if (!$ids) return [];
            $ph = implode(',', array_fill(0, count($ids), '%d'));
            return array_map('intval', $wpdb->get_col($wpdb->prepare("$base AND c.id IN ($ph)", $ids)));
        }
        return array_map('intval', $wpdb->get_col($base));
    }

    /* ---------- Messages ---------- */

    /** Queues a message. A duplicate non-null ref is ignored; returns the new id or 0. */
    public static function queue_message(array $m) {
        global $wpdb;
        $now = CN_Util::now();
        $cols = [
            'client_id'    => ['%d', (int) ($m['client_id'] ?? 0)],
            'kind'         => ['%s', $m['kind']],
            'to_email'     => ['%s', $m['to_email']],
            'subject'      => ['%s', $m['subject']],
            'body'         => ['%s', $m['body']],
            'status'       => ['%s', 'queued'],
            'created_at'   => ['%s', $now],
            'scheduled_at' => ['%s', $m['scheduled_at'] ?? $now],
        ];
        // Optional columns stay NULL when empty, so the unique ref index allows many NULLs.
        if (!empty($m['ref'])) $cols['ref'] = ['%s', $m['ref']];
        if (!empty($m['service_id'])) $cols['service_id'] = ['%d', (int) $m['service_id']];
        if (!empty($m['campaign_id'])) $cols['campaign_id'] = ['%d', (int) $m['campaign_id']];
        $sql = 'INSERT IGNORE INTO ' . self::t('messages') . ' (' . implode(', ', array_keys($cols)) . ') VALUES ('
            . implode(', ', array_column($cols, 0)) . ')';
        $wpdb->query($wpdb->prepare($sql, array_column($cols, 1)));
        return (int) $wpdb->insert_id;
    }

    public static function message($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('messages') . ' WHERE id = %d', $id));
    }

    public static function messages(array $f, $page = 1, $per_page = 50) {
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        if (!empty($f['kind'])) { $where[] = 'm.kind = %s'; $args[] = $f['kind']; }
        if (!empty($f['status'])) { $where[] = 'm.status = %s'; $args[] = $f['status']; }
        if (!empty($f['client_id'])) { $where[] = 'm.client_id = %d'; $args[] = (int) $f['client_id']; }
        if (!empty($f['campaign_id'])) { $where[] = 'm.campaign_id = %d'; $args[] = (int) $f['campaign_id']; }
        $w = implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*) FROM ' . self::t('messages') . " m WHERE $w";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, $args)) : $wpdb->get_var($count_sql));
        $sql = 'SELECT m.id, m.client_id, m.kind, m.to_email, m.subject, m.status, m.attempts, m.last_error, m.created_at, m.scheduled_at, m.sent_at, c.name AS client_name
                FROM ' . self::t('messages') . ' m LEFT JOIN ' . self::t('clients') . " c ON c.id = m.client_id
                WHERE $w ORDER BY m.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, [$per_page, ($page - 1) * $per_page])));
        return [$rows, $total];
    }

    public static function message_counts() {
        global $wpdb;
        $out = array_fill_keys(array_keys(CN_Util::MESSAGE_STATUSES), 0);
        foreach ($wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::t('messages') . ' GROUP BY status') as $r) {
            $out[$r->status] = (int) $r->n;
        }
        return $out;
    }
}
