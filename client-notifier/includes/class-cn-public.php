<?php
if (!defined('ABSPATH')) exit;

/** Public unsubscribe endpoint: ?cn_unsub=<client id>&t=<HMAC token>. */
final class CN_Public {
    public static function init() {
        add_action('init', [__CLASS__, 'maybe_unsubscribe'], 1);
    }

    public static function maybe_unsubscribe() {
        if (!isset($_GET['cn_unsub'])) return;
        $client = CN_DB::client(absint($_GET['cn_unsub']));
        $token = isset($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        if (!CN_Mailer::verify_unsubscribe($client, $token)) {
            wp_die('Ο σύνδεσμος δεν είναι έγκυρος ή έχει λήξει.', 'Διαγραφή από ενημερώσεις', ['response' => 403]);
        }
        $site = esc_html(get_bloginfo('name'));
        // Only a POST unsubscribes (a button click, or the mail client's one-click RFC 8058 request),
        // so link scanners that open every URL in an email cannot unsubscribe anyone.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$client->unsubscribed_at) CN_DB::set_unsubscribed($client->id, true);
            wp_die('<h1>Η διαγραφή ολοκληρώθηκε</h1><p>Δεν θα λαμβάνετε πλέον ενημερωτικά μηνύματα από ' . $site
                . '. Θα συνεχίσετε να λαμβάνετε μόνο ειδοποιήσεις που αφορούν τις υπηρεσίες σας (π.χ. ανανεώσεις).</p>',
                'Διαγραφή από ενημερώσεις', ['response' => 200]);
        }
        if ($client->unsubscribed_at) {
            wp_die('<h1>Έχετε ήδη διαγραφεί</h1><p>Δεν λαμβάνετε ενημερωτικά μηνύματα από ' . $site . '.</p>', 'Διαγραφή από ενημερώσεις', ['response' => 200]);
        }
        $action = esc_url(add_query_arg(['cn_unsub' => (int) $client->id, 't' => $token], home_url('/')));
        wp_die('<h1>Διαγραφή από ενημερώσεις</h1><p>Θέλετε να σταματήσετε να λαμβάνετε ενημερωτικά μηνύματα από ' . $site
            . ' στο <strong>' . esc_html($client->email) . '</strong>;</p>'
            . '<form method="post" action="' . $action . '"><p><button type="submit" class="button">Ναι, διαγραφή</button></p></form>',
            'Διαγραφή από ενημερώσεις', ['response' => 200]);
    }
}
