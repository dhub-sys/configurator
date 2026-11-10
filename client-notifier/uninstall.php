<?php
// Runs only when the plugin is deleted from WordPress > Plugins (not on deactivation).
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// Data is kept unless the "delete data on uninstall" option was enabled in the settings.
if (!get_option('cn_delete_on_uninstall')) return;
global $wpdb;
foreach (['messages', 'campaigns', 'reports', 'services', 'clients'] as $t) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cn_' . $t);
}
delete_option('cn_settings');
delete_option('cn_db_version');
delete_option('cn_delete_on_uninstall');
$role = get_role('administrator');
if ($role) $role->remove_cap('cn_manage_clients');
