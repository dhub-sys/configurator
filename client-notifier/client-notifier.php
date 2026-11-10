<?php
/**
 * Plugin Name: Client Notifier
 * Description: Λίστα πελατών και υπηρεσιών (web hosting, domain names, maintenance), αυτόματες υπενθυμίσεις ανανέωσης, αναφορές συντήρησης (updates) και μαζικές αποστολές email.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Custom
 * Text Domain: client-notifier
 */
if (!defined('ABSPATH')) exit;

define('CN_VERSION', '1.0.0');
define('CN_FILE', __FILE__);
define('CN_DIR', plugin_dir_path(__FILE__));
/** Only users with this capability see or use anything in the plugin. */
define('CN_CAP', 'cn_manage_clients');

require_once CN_DIR . 'includes/class-cn-util.php';
require_once CN_DIR . 'includes/class-cn-settings.php';
require_once CN_DIR . 'includes/class-cn-db.php';
require_once CN_DIR . 'includes/class-cn-mailer.php';
require_once CN_DIR . 'includes/class-cn-cron.php';
require_once CN_DIR . 'includes/class-cn-public.php';
require_once CN_DIR . 'includes/class-cn-privacy.php';
if (is_admin()) {
    require_once CN_DIR . 'includes/class-cn-admin.php';
}

final class CN_Plugin {
    public static function init() {
        CN_DB::maybe_upgrade();
        self::ensure_cap();
        CN_Cron::init();
        CN_Public::init();
        CN_Privacy::init();
        if (is_admin()) CN_Admin::init();
    }

    public static function activate() {
        CN_DB::install();
        self::ensure_cap();
        CN_Cron::schedule();
    }

    public static function deactivate() {
        CN_Cron::unschedule();
    }

    /** Administrators always get the capability; other roles only if granted explicitly. */
    private static function ensure_cap() {
        $role = get_role('administrator');
        if ($role && !$role->has_cap(CN_CAP)) $role->add_cap(CN_CAP);
    }
}

register_activation_hook(__FILE__, ['CN_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['CN_Plugin', 'deactivate']);
add_action('plugins_loaded', ['CN_Plugin', 'init']);
