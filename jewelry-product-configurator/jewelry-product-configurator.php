<?php
/**
 * Plugin Name: Jewelry Product Configurator
 * Description: Επιλογή μετάλλου, χρώματος και συστήματος/μεγέθους δαχτυλιδιού για επιλεγμένες κατηγορίες WooCommerce.
 * Version: 1.1.6
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Author: Custom
 * Text Domain: jewelry-product-configurator
 */
if (!defined('ABSPATH')) exit;

final class JPC_Product_Configurator {
    const OPTION = 'jpc_category_ids';
    const PLACEMENT_OPTION = 'jpc_placement_mode';
    const FIELDS_OPTION = 'jpc_category_fields';
    const VERSION = '1.1.6';
    private static $rendered = false;

    /** Alternative metals: one price meta per metal. Gold is the normal WooCommerce price. */
    const METAL_PRICE_KEYS = ['platinum' => '_jpc_platinum_price', 'silver' => '_jpc_silver_price'];
    /** Legacy (≤1.1.5) availability flags, migrated away in 1.1.6. */
    const LEGACY_FLAG_KEYS = ['platinum' => '_jpc_platinum_available', 'silver' => '_jpc_silver_available'];
    const DB_VERSION_OPTION = 'jpc_db_version';

    public static function init() {
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'platinum_admin_field']);
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'silver_admin_field']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'yellow_gold_image_admin_field']);
        // Single save path for the metal prices, through WooCommerce CRUD only.
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_metal_prices'], 999);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_yellow_gold_image_admin_field']);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_prices'], 20);
        add_action('woocommerce_cart_loaded_from_session', [__CLASS__, 'remove_unavailable_cart_metals'], 30);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_metals']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'maybe_migrate']);
        add_action('admin_notices', [__CLASS__, 'dependency_notice']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'auto_fields'], 5);
        add_shortcode('jewelry_configurator', [__CLASS__, 'shortcode']);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate'], 10, 6);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'save_cart_item'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_data'], 10, 2);
        // Normalize identical configurator rows after other extensions have added theirs.
        add_filter('woocommerce_get_item_data', [__CLASS__, 'deduplicate_cart_data'], PHP_INT_MAX, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_order_item'], 10, 4);
    }
    /** Final product price for Platinum, stored per simple product using WooCommerce decimal format. */
    public static function platinum_admin_field() {
        self::metal_admin_field('platinum', 'Τιμή Πλατίνας',
            'Τελική τιμή προϊόντος σε Πλατίνα. Αν μείνει κενή (ή 0), η Πλατίνα δεν διατίθεται και δεν εμφανίζεται. Υποστηρίζεται σε απλά προϊόντα.');
    }
    /** Final product price for Silver. An empty value means unavailable. */
    public static function silver_admin_field() {
        self::metal_admin_field('silver', 'Τιμή Ασημιού',
            'Τελική τιμή προϊόντος σε Ασήμι. Αν μείνει κενή (ή 0), το Ασήμι δεν διατίθεται και δεν εμφανίζεται. Υποστηρίζεται σε απλά προϊόντα.');
    }
    private static function metal_admin_field($metal, $label, $description) {
        global $product_object;
        if (!function_exists('woocommerce_wp_text_input')) return;
        $key = self::METAL_PRICE_KEYS[$metal];
        woocommerce_wp_text_input([
            'id' => $key,
            'label' => $label . ' (' . get_woocommerce_currency_symbol() . ')',
            'description' => $description,
            'desc_tip' => true,
            'data_type' => 'price',
            'placeholder' => 'Δεν διατίθεται',
            'value' => $product_object ? $product_object->get_meta($key, true, 'edit') : '',
        ]);
        if ($product_object && $product_object->is_type('simple')) {
            $active = self::metal_price($product_object, $metal) !== '';
            echo '<p class="form-field"><span class="description" style="margin-left:162px;display:block">'
                . ($active ? '✔ Διαθέσιμο στο κατάστημα.' : '✘ Δεν εμφανίζεται στο κατάστημα.') . '</span></p>';
        }
    }
    /** Normalize a submitted/stored price. Returns '' when it does not make the metal available. */
    private static function normalize_price($raw) {
        if (!is_scalar($raw)) return '';
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        $decimal = wc_format_decimal($raw);
        // 0 is treated as "not available": a jewel is never sold for free, and
        // imports/bulk tools often write 0 where the shop meant "empty".
        return is_numeric($decimal) && (float) $decimal > 0 ? (string) $decimal : '';
    }
    /**
     * The price field is the ONLY source of truth for a metal's availability.
     * Blank/invalid → every meta row for that metal is removed.
     */
    public static function save_metal_prices($product) {
        if (!$product || !$product->is_type('simple')) return;
        foreach (self::METAL_PRICE_KEYS as $metal => $key) {
            // Fields not posted (quick edit, REST, etc.) leave the stored value untouched.
            if (!array_key_exists($key, $_POST)) continue;
            $price = self::normalize_price(wc_clean(wp_unslash($_POST[$key])));
            // delete_meta_data() removes every row with this key, including legacy duplicates.
            $product->delete_meta_data($key);
            if ($price !== '') $product->add_meta_data($key, $price, true);
            $product->delete_meta_data(self::LEGACY_FLAG_KEYS[$metal]);
        }
    }
    /** Price of an alternative metal for this product, or '' if that metal is not offered. */
    private static function metal_price($product, $metal) {
        if (!$product || !$product->is_type('simple') || !isset(self::METAL_PRICE_KEYS[$metal])) return '';
        return self::normalize_price($product->get_meta(self::METAL_PRICE_KEYS[$metal], true, 'edit'));
    }
    private static function platinum_price($product) {
        return self::metal_price($product, 'platinum');
    }
    private static function silver_price($product) {
        return self::metal_price($product, 'silver');
    }
    /**
     * 1.1.5 and earlier kept a separate "_available" flag that could disagree with
     * the price meta. Make the price authoritative once: where the flag said "no",
     * drop the (stale) price as well, so nothing that was hidden reappears.
     */
    public static function maybe_migrate() {
        if (get_option(self::DB_VERSION_OPTION) === self::VERSION) return;
        global $wpdb;
        foreach (self::LEGACY_FLAG_KEYS as $metal => $flag_key) {
            $price_key = self::METAL_PRICE_KEYS[$metal];
            $hidden = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'no'", $flag_key));
            foreach ($hidden as $product_id) {
                delete_post_meta((int) $product_id, $price_key);
            }
            $flagged = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $flag_key));
            foreach ($flagged as $product_id) {
                delete_post_meta((int) $product_id, $flag_key);
                self::flush_product_cache((int) $product_id);
            }
        }
        update_option(self::DB_VERSION_OPTION, self::VERSION, false);
    }
    private static function flush_product_cache($product_id) {
        clean_post_cache($product_id);
        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($product_id);
        // WooCommerce keeps its own CRUD meta cache, which direct postmeta writes do not clear.
        if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
            WC_Cache_Helper::invalidate_cache_group('object_' . $product_id);
        }
    }

    /** Optional alternate main image used for Χρυσός → Κίτρινο χρυσό. */
    public static function yellow_gold_image_admin_field() {
        global $product_object;
        if (!$product_object || !$product_object->is_type('simple')) return;
        $attachment_id = (int) $product_object->get_meta('_jpc_yellow_gold_image_id', true, 'edit');
        $preview = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'thumbnail') : '';
        echo '<div class="options_group">';
        echo '<p class="form-field _jpc_yellow_gold_image_id_field">';
        echo '<label for="_jpc_yellow_gold_image_id">Εικόνα Κίτρινου Χρυσού</label>';
        echo '<span class="wrap">';
        echo '<input type="hidden" id="_jpc_yellow_gold_image_id" name="_jpc_yellow_gold_image_id" value="' . esc_attr($attachment_id) . '">';
        echo '<button type="button" class="button jpc-media-select" data-target="#_jpc_yellow_gold_image_id">' . esc_html__('Επιλογή εικόνας', 'jewelry-product-configurator') . '</button> ';
        echo '<button type="button" class="button jpc-media-clear" data-target="#_jpc_yellow_gold_image_id"' . ($attachment_id ? '' : ' style="display:none;"') . '>' . esc_html__('Καθαρισμός', 'jewelry-product-configurator') . '</button>';
        echo '<span class="description" style="display:block;margin-top:6px;">Προαιρετική εικόνα που θα εμφανίζεται όταν ο πελάτης επιλέγει Χρυσός → Κίτρινο χρυσό. Αν μείνει κενή, χρησιμοποιείται η βασική εικόνα του προϊόντος.</span>';
        echo '<span class="jpc-media-preview" style="display:block;margin-top:10px;">';
        if ($preview) {
            echo '<img src="' . esc_url($preview) . '" alt="" style="max-width:80px;height:auto;border:1px solid #ddd;padding:3px;background:#fff;">';
        }
        echo '</span>';
        echo '</span>';
        echo '</p>';
        echo '</div>';
    }
    public static function save_yellow_gold_image_admin_field($product) {
        if (!$product || !$product->is_type('simple')) return;
        $attachment_id = isset($_POST['_jpc_yellow_gold_image_id']) ? absint(wp_unslash($_POST['_jpc_yellow_gold_image_id'])) : 0;
        if ($attachment_id > 0) {
            $product->update_meta_data('_jpc_yellow_gold_image_id', $attachment_id);
        } else {
            $product->delete_meta_data('_jpc_yellow_gold_image_id');
        }
    }
    private static function yellow_gold_image_data($product) {
        if (!$product || !$product->is_type('simple')) return [];
        $attachment_id = (int) $product->get_meta('_jpc_yellow_gold_image_id', true);
        if (!$attachment_id) return [];
        $full = wp_get_attachment_image_src($attachment_id, 'full');
        if (!$full) return [];
        return [
            'id' => $attachment_id,
            'src' => $full[0],
            'srcset' => wp_get_attachment_image_srcset($attachment_id, 'full') ?: '',
            'sizes' => wp_get_attachment_image_sizes($attachment_id, 'full') ?: '',
            'thumb' => wp_get_attachment_image_url($attachment_id, 'woocommerce_thumbnail') ?: $full[0],
            'thumbSrcset' => wp_get_attachment_image_srcset($attachment_id, 'woocommerce_thumbnail') ?: '',
            'thumbSizes' => wp_get_attachment_image_sizes($attachment_id, 'woocommerce_thumbnail') ?: '',
            'caption' => wp_get_attachment_caption($attachment_id) ?: '',
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: '',
        ];
    }
    private static function allowed_metals($product) {
        $allowed = ['gold'];
        if (self::platinum_price($product) !== '') $allowed[] = 'platinum';
        if (self::silver_price($product) !== '') $allowed[] = 'silver';
        return $allowed;
    }
    /** Discard stale cart lines for metal options no longer offered by the product. */
    public static function remove_unavailable_cart_metals($cart) {
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) return;
        foreach ($cart->get_cart() as $key => $cart_item) {
            if (empty($cart_item['jc_config']['metal_type'])) continue;
            $metal = $cart_item['jc_config']['metal_type'];
            if ($metal === 'gold') continue;
            $product_id = isset($cart_item['product_id']) ? absint($cart_item['product_id']) : 0;
            $product = $product_id && function_exists('wc_get_product') ? wc_get_product($product_id) : false;
            if (!$product || !in_array($metal, self::allowed_metals($product), true)) {
                $cart->remove_cart_item($key);
                if (function_exists('wc_add_notice')) {
                    wc_add_notice('Αφαιρέθηκε από το καλάθι μια επιλογή μετάλλου που δεν είναι πλέον διαθέσιμη.', 'notice');
                }
            }
        }
    }
    /** Price set server-side; the browser display is only a preview. */
    public static function apply_cart_prices($cart) {
        if ((is_admin() && !wp_doing_ajax()) || !$cart || !is_object($cart)) return;
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['jc_config']) || !isset($cart_item['jc_config']['metal_type'])) continue;
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            if (!$product || !$product->is_type('simple')) continue;
            $metal = $cart_item['jc_config']['metal_type'];
            // Gold is the product's own WooCommerce price (including any real sale): leave it alone.
            if ($metal === 'gold') continue;
            // Read the live product price meta (not set_price()), so repeated
            // total calculations can never compound, and admin edits apply.
            $price = self::metal_price($product, $metal);
            // An unavailable metal is removed on cart load (remove_unavailable_cart_metals)
            // and rejected at checkout (validate_cart_metals); never price it as gold.
            if ($price === '') continue;
            $product->set_price((float) $price);
            // Alternate metals are distinct configurations, NOT a discount/sale.
            $product->set_regular_price((float) $price);
            $product->set_sale_price('');
        }
    }
    /** Last line of defence: block checkout for a non-gold line whose metal is no longer offered. */
    public static function validate_cart_metals() {
        if (!function_exists('WC') || !WC()->cart) return;
        foreach (WC()->cart->get_cart() as $cart_item) {
            $metal = $cart_item['jc_config']['metal_type'] ?? 'gold';
            if ($metal === 'gold' || empty($cart_item['data'])) continue;
            if (self::metal_price($cart_item['data'], $metal) === '') {
                wc_add_notice(sprintf('Το προϊόν «%s» δεν διατίθεται πλέον στο επιλεγμένο μέταλλο. Αφαιρέστε το από το καλάθι.',
                    $cart_item['data']->get_name()), 'error');
            }
        }
    }
    public static function dependency_notice() {
        if (!class_exists('WooCommerce')) echo '<div class="notice notice-warning"><p>Jewelry Product Configurator: απαιτείται ενεργό WooCommerce.</p></div>';
    }
    public static function category_ids() {
        $ids = get_option(self::OPTION, [38, 40, 42, 43]);
        return array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
    }
    public static function matches($id) {
        if (!function_exists('has_term') || !$id || !self::category_ids()) return false;
        $parent = wp_get_post_parent_id($id);
        if ($parent) $id = $parent;
        return has_term(self::category_ids(), 'product_cat', $id);
    }
    private static function target_page() {
        return function_exists('is_product') && is_product() && self::matches(get_queried_object_id());
    }
    public static function admin_menu() {
        add_submenu_page('woocommerce', 'Jewelry Configurator', 'Jewelry Configurator', 'manage_woocommerce', 'jpc-settings', [__CLASS__, 'settings_page']);
    }
    public static function register_settings() {
        register_setting('jpc_settings', self::OPTION, ['type'=>'array', 'sanitize_callback'=>[__CLASS__, 'sanitize_categories'], 'default'=>[38,40,42,43]]);
        register_setting('jpc_settings', self::FIELDS_OPTION, ['type'=>'array', 'sanitize_callback'=>[__CLASS__, 'sanitize_category_fields'], 'default'=>[]]);
        register_setting('jpc_settings', self::PLACEMENT_OPTION, ['type'=>'string', 'sanitize_callback'=>[__CLASS__, 'sanitize_placement'], 'default'=>'automatic']);
    }
    public static function sanitize_placement($value) {
        return $value === 'shortcode' ? 'shortcode' : 'automatic';
    }
    public static function placement() {
        return get_option(self::PLACEMENT_OPTION, 'automatic') === 'shortcode' ? 'shortcode' : 'automatic';
    }
    public static function sanitize_categories($value) {
        return array_values(array_unique(array_filter(array_map('absint', is_array($value) ? $value : []))));
    }
    /** Defaults preserve all previous field behavior until a category is edited. */
    public static function category_fields($product_id) {
        $default = ['color' => true, 'size' => true];
        $stored = get_option(self::FIELDS_OPTION, []);
        if (!is_array($stored)) $stored = [];
        $product_id = wp_get_post_parent_id($product_id) ?: $product_id;
        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) return $default;
        $enabled = self::category_ids();
        $candidates = [];
        foreach ($terms as $term) {
            if (!in_array((int)$term->term_id, $enabled, true)) continue;
            // Most specific selected category takes precedence if a product is in several.
            $candidates[] = ['id' => (int)$term->term_id, 'depth' => count(get_ancestors($term->term_id, 'product_cat', 'taxonomy'))];
        }
        if (!$candidates) return $default;
        usort($candidates, static function($a, $b) {
            return ($b['depth'] <=> $a['depth']) ?: ($a['id'] <=> $b['id']);
        });
        $id = $candidates[0]['id'];
        if (!array_key_exists($id, $stored) || !is_array($stored[$id])) return $default;
        return [
            'color' => !empty($stored[$id]['color']),
            'size' => !empty($stored[$id]['size']),
        ];
    }
    public static function sanitize_category_fields($value) {
        $result = [];
        if (!is_array($value)) return $result;
        foreach ($value as $id => $fields) {
            $id = absint($id);
            if (!$id || !is_array($fields)) continue;
            $result[$id] = ['color' => !empty($fields['color']) ? 1 : 0, 'size' => !empty($fields['size']) ? 1 : 0];
        }
        return $result;
    }
    public static function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $selected = self::category_ids();
        $cats = get_terms(['taxonomy'=>'product_cat', 'hide_empty'=>false]);
        echo '<div class="wrap"><h1>Jewelry Product Configurator</h1><p>Επίλεξε κατηγορίες και τρόπο εμφάνισης. Απενεργοποίησε τον παλιό PHP/JS κώδικα για να αποφύγεις διπλό configurator.</p><form action="options.php" method="post">';
        settings_fields('jpc_settings');
        $stored_fields = get_option(self::FIELDS_OPTION, []);
        if (!is_array($stored_fields)) $stored_fields = [];
        echo '<p>Επίλεξε ποιες κατηγορίες χρησιμοποιούν τον configurator και ποια πεδία εμφανίζονται σε κάθε μία. Η επιλογή μετάλλου εμφανίζεται πάντα.</p>';
        echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>Κατηγορία</th><th>Ενεργός configurator</th><th>Χρώμα χρυσού</th><th>Μέγεθος δαχτυλιδιού</th></tr></thead><tbody>';
        if (!is_wp_error($cats)) foreach ($cats as $cat) {
            $id = (int) $cat->term_id;
            $f = isset($stored_fields[$id]) && is_array($stored_fields[$id]) ? $stored_fields[$id] : ['color'=>1,'size'=>1];
            $indent = str_repeat('— ', count(get_ancestors($id, 'product_cat', 'taxonomy')));
            echo '<tr><td>'.esc_html($indent.$cat->name).' <small>(ID '.$id.')</small></td>';
            echo '<td><input type="checkbox" name="'.esc_attr(self::OPTION).'[]" value="'.$id.'" '.checked(in_array($id,$selected,true),true,false).'></td>';
            // Hidden defaults guarantee that unchecked fields are saved as false.
            foreach (['color','size'] as $key) {
                echo '<td><input type="hidden" name="'.esc_attr(self::FIELDS_OPTION).'['.$id.']['.$key.']" value="0"><input type="checkbox" name="'.esc_attr(self::FIELDS_OPTION).'['.$id.']['.$key.']" value="1" '.checked(!empty($f[$key]),true,false).'></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Ensure unchecking every category saves an empty array.
        echo '<input type="hidden" name="'.esc_attr(self::OPTION).'[]" value="0">';
        echo '<p class="description">Αν ένα προϊόν ανήκει σε περισσότερες επιλεγμένες κατηγορίες, εφαρμόζεται η βαθύτερη κατηγορία (ή η μικρότερη ID αν έχουν ίδιο βάθος).</p>';
        echo '<h2>Θέση εμφάνισης</h2><fieldset>';
        echo '<label style="display:block;margin:8px 0"><input type="radio" name="'.esc_attr(self::PLACEMENT_OPTION).'" value="automatic" '.checked(self::placement(), 'automatic', false).'> Αυτόματα στη φόρμα προσθήκης στο καλάθι</label>';
        echo '<label style="display:block;margin:8px 0"><input type="radio" name="'.esc_attr(self::PLACEMENT_OPTION).'" value="shortcode" '.checked(self::placement(), 'shortcode', false).'> Στο σημείο που ορίζω με shortcode [jewelry_configurator] (YOOtheme Builder)</label>';
        echo '<p class="description">Για εμφάνιση πάνω από τα Χαρακτηριστικά, επέλεξε το shortcode και πρόσθεσε στοιχείο Text με [jewelry_configurator] πάνω από την ενότητα στο template προϊόντος. Η θέση του WooCommerce Add to Cart παραμένει ανεξάρτητη.</p>';
        echo '</fieldset>';
        submit_button('Αποθήκευση');
        echo '</form><p><strong>Σημείωση:</strong> Συμπεριλαμβάνονται προϊόντα που ανήκουν απευθείας στις επιλεγμένες κατηγορίες. Τα υποκατηγοριακά προϊόντα απαιτούν επιλογή και της υποκατηγορίας.</p></div>';
    }
    public static function assets() {
        if (!self::target_page()) return;
        wp_enqueue_style('jpc-configurator', plugin_dir_url(__FILE__).'assets/css/configurator.css', [], self::VERSION);
        wp_enqueue_script('jpc-configurator', plugin_dir_url(__FILE__).'assets/js/configurator.js', ['jquery'], self::VERSION, true);
        $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : false;
        if ($product && $product->is_type('simple')) {
            $product_image_id = $product->get_image_id();
            $primary_image = [];
            if ($product_image_id) {
                $full = wp_get_attachment_image_src($product_image_id, 'full');
                if ($full) {
                    $primary_image = [
                        'id' => $product_image_id,
                        'src' => $full[0],
                        'srcset' => wp_get_attachment_image_srcset($product_image_id, 'full') ?: '',
                        'sizes' => wp_get_attachment_image_sizes($product_image_id, 'full') ?: '',
                        'thumb' => wp_get_attachment_image_url($product_image_id, 'woocommerce_thumbnail') ?: $full[0],
                        'thumbSrcset' => wp_get_attachment_image_srcset($product_image_id, 'woocommerce_thumbnail') ?: '',
                        'thumbSizes' => wp_get_attachment_image_sizes($product_image_id, 'woocommerce_thumbnail') ?: '',
                        'caption' => wp_get_attachment_caption($product_image_id) ?: '',
                        'alt' => get_post_meta($product_image_id, '_wp_attachment_image_alt', true) ?: '',
                    ];
                }
            }
            $platinum = self::platinum_price($product);
            $silver = self::silver_price($product);
            wp_localize_script('jpc-configurator', 'JPCPrices', [
                'enabled' => true,
                'goldHtml' => wc_price(wc_get_price_to_display($product)),
                'platinumHtml' => $platinum === '' ? '' : wc_price(wc_get_price_to_display($product, ['price' => (float)$platinum])),
                'hasPlatinumPrice' => $platinum !== '',
                'silverHtml' => $silver === '' ? '' : wc_price(wc_get_price_to_display($product, ['price' => (float)$silver])),
                'hasSilverPrice' => $silver !== '',
                // Authoritative list for the browser: a metal missing here must not be offered.
                'metals' => array_filter([
                    'gold' => wc_price(wc_get_price_to_display($product)),
                    'platinum' => $platinum === '' ? '' : wc_price(wc_get_price_to_display($product, ['price' => (float)$platinum])),
                    'silver' => $silver === '' ? '' : wc_price(wc_get_price_to_display($product, ['price' => (float)$silver])),
                ]),
                'priceSelector' => '.jpc-live-price',
                'fields' => self::category_fields($product->get_id()),
                'images' => [
                    'default' => $primary_image,
                    'yellowGold' => self::yellow_gold_image_data($product),
                ],
            ]);
        }
    }
    public static function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') return;
        wp_enqueue_media();
        wp_enqueue_script('jpc-admin-configurator', plugin_dir_url(__FILE__) . 'assets/js/admin-product.js', ['jquery'], self::VERSION, true);
    }
    public static function auto_fields() {
        global $product;
        if (!$product || !self::matches($product->get_id()) || self::placement() !== 'automatic') return;
        echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    public static function shortcode() {
        return self::target_page() && self::placement() === 'shortcode' ? self::render() : '';
    }
    private static function render() {
        if (self::$rendered) return '';
        self::$rendered = true;
        $config_fields = self::category_fields(get_queried_object_id());
        ob_start(); ?>
        <div class="jewelry-configurator" data-jc-configurator="1" data-jc-has-color="<?php echo $config_fields['color'] ? '1' : '0'; ?>" data-jc-has-size="<?php echo $config_fields['size'] ? '1' : '0'; ?>">
          <div class="jc-grid">
            <div id="jc_metal_box" class="jc-box jc-box-full uk-card uk-card-conf uk-card-small uk-card-body uk-border-rounded">
              <h4 class="jc-box-title">Επιλογή Μετάλλου</h4>
              <div class="jc-radio-group">
                <label class="jc-radio-option"><input type="radio" name="jc_metal_type_ui" value="gold" checked><span>Χρυσός</span></label>
                <?php
                $jpc_product = wc_get_product(get_queried_object_id());
                if ($jpc_product && self::platinum_price($jpc_product) !== '') : ?>
                  <label class="jc-radio-option"><input type="radio" name="jc_metal_type_ui" value="platinum"><span>Πλατίνα</span></label>
                <?php endif;
                if ($jpc_product && self::silver_price($jpc_product) !== '') : ?>
                  <label class="jc-radio-option"><input type="radio" name="jc_metal_type_ui" value="silver"><span>Ασήμι</span></label>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($config_fields['color']) : ?>
            <div id="jc_color_box" class="jc-box jc-box-half uk-card uk-card-conf uk-card-small uk-card-body uk-border-rounded" hidden>
              <h4 class="jc-box-title">Χρώμα Μετάλλου</h4>
              <div class="jc-swatches" role="radiogroup" aria-label="Χρώμα Μετάλλου"><label class="jc-swatch jc-swatch-whitegold" aria-label="Λευκόχρυσο" title="Λευκόχρυσο"><input type="radio" name="jc_gold_color_ui" value="λευκόχρυσο"><span class="jc-swatch-tile" aria-hidden="true"></span></label><label class="jc-swatch jc-swatch-yellowgold" aria-label="Κίτρινο χρυσό" title="Κίτρινο χρυσό"><input type="radio" name="jc_gold_color_ui" value="κίτρινο χρυσό"><span class="jc-swatch-tile" aria-hidden="true"></span></label></div>
            </div>
            <?php endif; ?>
            <?php if ($config_fields['size']) : ?>
            <div id="jc_size_system_box" class="jc-box jc-box-half uk-card uk-card-conf uk-card-small uk-card-body uk-border-rounded"><h4 class="jc-box-title">Τύπος Μεγέθους</h4><div class="jc-radio-group"><label class="jc-radio-option"><input type="radio" name="jc_size_system_ui" value="eu"><span>Ευρωπαϊκά</span></label><label class="jc-radio-option"><input type="radio" name="jc_size_system_ui" value="us"><span>Αμερικάνικα</span></label></div></div>
            <div id="jc_size_box" class="jc-box jc-box-half uk-card uk-card-conf uk-card-small uk-card-body uk-border-rounded"><h4 class="jc-box-title">Νούμερο Δαχτυλιδιού</h4><select id="jc_ring_size" class="jc-control uk-select"><option value="">Επιλέξτε νούμερο…</option></select></div>
            <?php endif; ?>
          </div>
        </div>
        <input type="hidden" name="jc_configurator_enabled" value="1">
        <input type="hidden" name="jc_metal_type" value="">
        <input type="hidden" name="jc_gold_color" value="">
        <input type="hidden" name="jc_size_system" value="">
        <input type="hidden" name="jc_ring_size" value="">
        <?php return ob_get_clean();
    }
    private static function posted($key) {
        return isset($_POST[$key]) && is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }
    private static function selection() {
        return ['metal_type'=>self::posted('jc_metal_type'), 'gold_color'=>self::posted('jc_gold_color'), 'size_system'=>self::posted('jc_size_system'), 'ring_size'=>self::posted('jc_ring_size')];
    }
    private static function valid($cfg, $product = null) {
        $fields = $product ? self::category_fields($product->get_id()) : ['color'=>true,'size'=>true];
        $sizes_eu = array_map('strval', range(47,58));
        $sizes_us = array_map('strval', range(5,13));
        if (!in_array($cfg['metal_type'], $product ? self::allowed_metals($product) : ['gold'], true)) return false;
        if ($fields['color'] && $cfg['metal_type']==='gold' && !in_array($cfg['gold_color'], ['λευκόχρυσο','κίτρινο χρυσό'], true)) return false;
        if (!$fields['size']) return true;
        if (!in_array($cfg['size_system'], ['eu','us'], true)) return false;
        return in_array($cfg['ring_size'], $cfg['size_system']==='eu' ? $sizes_eu : $sizes_us, true);
    }
    public static function validate($passed, $product_id, $quantity=1, $variation_id=0, $variations=[], $cart_item_data=[]) {
        if (!$passed || !self::matches($product_id)) return $passed;
        if (!self::valid(self::selection(), wc_get_product($product_id))) {
            if (function_exists('wc_add_notice')) wc_add_notice('Παρακαλώ συμπληρώστε τις υποχρεωτικές επιλογές του προϊόντος.', 'error');
            return false;
        }
        return $passed;
    }
    public static function save_cart_item($cart_item_data, $product_id, $variation_id=0) {
        if (!self::matches($product_id)) return $cart_item_data;
        $cfg = self::selection();
        if (!self::valid($cfg, wc_get_product($product_id))) return $cart_item_data;
        $enabled_fields = self::category_fields($product_id);
        if ($cfg['metal_type'] !== 'gold' || !$enabled_fields['color']) $cfg['gold_color']='';
        if (!$enabled_fields['size']) { $cfg['size_system']=''; $cfg['ring_size']=''; }
        $cart_item_data['jc_config']=$cfg;
        // Διαφορετικές διαμορφώσεις διαχωρίζονται κανονικά από το WooCommerce μέσω cart item data.
        return $cart_item_data;
    }
    private static function labels($cfg) {
        $metal_labels = ['gold' => 'Χρυσός', 'platinum' => 'Πλατίνα', 'silver' => 'Ασήμι'];
        $rows = ['Επιλογή Μετάλλου' => $metal_labels[$cfg['metal_type']] ?? 'Χρυσός'];
        if ($cfg['metal_type']==='gold' && !empty($cfg['gold_color'])) $rows['Χρώμα Μετάλλου']=$cfg['gold_color'];
        if (!empty($cfg['size_system']) && !empty($cfg['ring_size'])) {
            $rows['Τύπος Μεγέθους']=$cfg['size_system']==='eu' ? 'Ευρωπαϊκά':'Αμερικάνικα';
            $rows['Νούμερο Δαχτυλιδιού']=$cfg['ring_size'];
        }
        return $rows;
    }
    public static function display_cart_data($item_data,$cart_item) {
        if (empty($cart_item['jc_config'])) return $item_data;
        // Prevent duplicate rows when an old snippet or another compatible integration
        // already added the same configurator labels to WooCommerce item data.
        $existing = [];
        foreach ($item_data as $row) {
            $name = isset($row['name']) ? wp_strip_all_tags((string)$row['name']) : '';
            $value = isset($row['value']) ? wp_strip_all_tags((string)$row['value']) : '';
            $existing[$name . "\0" . $value] = true;
        }
        foreach (self::labels($cart_item['jc_config']) as $name=>$value) {
            $key = $name . "\0" . $value;
            if (!isset($existing[$key])) {
                $item_data[]=['name'=>$name, 'value'=>$value];
                $existing[$key] = true;
            }
        }
        return $item_data;
    }
    public static function deduplicate_cart_data($item_data, $cart_item) {
        if (empty($cart_item['jc_config']) || !is_array($item_data)) return $item_data;
        // Only touch our own labels, never metadata belonging to other plugins.
        $labels = self::labels($cart_item['jc_config']);
        $seen = [];
        $output = [];
        foreach ($item_data as $row) {
            if (!is_array($row)) { $output[] = $row; continue; }
            $name = wp_strip_all_tags((string)($row['name'] ?? $row['key'] ?? ''));
            $value = wp_strip_all_tags((string)($row['display'] ?? $row['value'] ?? ''));
            $name = trim(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
            $value = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
            // If an older snippet uses matching keys but different values,
            // prefer only the authoritative choice from this plugin.
            if (array_key_exists($name, $labels)) {
                if (isset($seen[$name])) continue;
                $row['name'] = $name;
                $row['value'] = $labels[$name];
                unset($row['display'], $row['display_key']);
                $seen[$name] = true;
            }
            $output[] = $row;
        }
        return $output;
    }
    public static function save_order_item($item, $cart_item_key, $values, $order) {
        if (empty($values['jc_config'])) return;
        foreach (self::labels($values['jc_config']) as $name=>$value) $item->add_meta_data($name,$value,true);
    }
}
add_action('plugins_loaded', ['JPC_Product_Configurator','init']);
