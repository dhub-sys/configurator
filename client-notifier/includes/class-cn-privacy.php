<?php
if (!defined('ABSPATH')) exit;

/** Hooks into Tools > Export / Erase Personal Data (GDPR). */
final class CN_Privacy {
    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', function ($e) {
            $e['client-notifier'] = ['exporter_friendly_name' => 'Client Notifier', 'callback' => [__CLASS__, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', function ($e) {
            $e['client-notifier'] = ['eraser_friendly_name' => 'Client Notifier', 'callback' => [__CLASS__, 'erase']];
            return $e;
        });
    }

    public static function export($email, $page = 1) {
        $client = CN_DB::client_by_email(sanitize_email($email));
        $data = [];
        if ($client) {
            $data[] = ['group_id' => 'cn-client', 'group_label' => 'Πελάτης', 'item_id' => 'cn-client-' . $client->id, 'data' => [
                ['name' => 'Όνομα', 'value' => $client->name],
                ['name' => 'Εταιρεία', 'value' => $client->company],
                ['name' => 'Email', 'value' => $client->email],
                ['name' => 'Τηλέφωνο', 'value' => $client->phone],
                ['name' => 'Διαγραφή από ενημερώσεις', 'value' => $client->unsubscribed_at ?: 'Όχι'],
            ]];
            foreach (CN_DB::services(['client_id' => $client->id]) as $s) {
                $data[] = ['group_id' => 'cn-services', 'group_label' => 'Υπηρεσίες', 'item_id' => 'cn-service-' . $s->id, 'data' => [
                    ['name' => 'Τύπος', 'value' => CN_Util::type_label($s->type)],
                    ['name' => 'Περιγραφή', 'value' => $s->label],
                    ['name' => 'Λήξη', 'value' => CN_Util::format_date($s->expiry_date)],
                ]];
            }
        }
        return ['data' => $data, 'done' => true];
    }

    public static function erase($email, $page = 1) {
        $client = CN_DB::client_by_email(sanitize_email($email));
        if ($client) CN_DB::delete_client($client->id);
        return ['items_removed' => (bool) $client, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
