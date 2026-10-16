<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
delete_option('vrc_settings');
delete_option('vrc_log');
foreach (['vrc_access_token', 'vrc_result_', 'vrc_rate_'] as $prefix) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_' . $prefix) . '%',
        $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
    ));
}
