<?php
/**
 * Plugin Name: VIN Recall Checker
 * Description: Έλεγχος μέσω αριθμού πλαισίου (VIN) για ανοιχτές καμπάνιες ανάκλησης / τεχνικής αναβάθμισης, απευθείας από το σύστημα του κατασκευαστή (IAS → iDMS). Shortcode: [vin_recall_check]
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Custom
 * Text Domain: vin-recall-checker
 */
if (!defined('ABSPATH')) exit;

define('VRC_VERSION', '1.0.0');
define('VRC_FILE', __FILE__);
define('VRC_DIR', plugin_dir_path(__FILE__));
define('VRC_URL', plugin_dir_url(__FILE__));

require_once VRC_DIR . 'includes/class-vrc-settings.php';
require_once VRC_DIR . 'includes/class-vrc-log.php';
require_once VRC_DIR . 'includes/class-vrc-api-client.php';
require_once VRC_DIR . 'includes/class-vrc-frontend.php';
require_once VRC_DIR . 'includes/class-vrc-admin.php';

add_action('plugins_loaded', function () {
    VRC_Frontend::init();
    if (is_admin()) VRC_Admin::init();
});
