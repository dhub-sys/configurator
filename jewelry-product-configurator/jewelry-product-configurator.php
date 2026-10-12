<?php
/**
 * Plugin Name: Jewelry Product Configurator
 * Description: Επιλογή μετάλλου, χρώματος και συστήματος/μεγέθους δαχτυλιδιού για επιλεγμένες κατηγορίες WooCommerce.
 * Version: 1.1.5
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
    const VERSION = '1.1.5';
    private static $rendered = false;

    public static function init() {
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'platinum_admin_field']);
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'silver_admin_field']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'yellow_gold_image_admin_field']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_platinum_admin_field']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_silver_admin_field']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'sync_metal_availability'], 999);
        // Cleanup runs after WooCommerce persists the product and clears duplicate/stale meta records.
        add_action('woocommerce_process_product_meta', [__CLASS__, 'finalize_metal_price_meta'], 100, 1);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_yellow_gold_image_admin_field']);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_prices'], 20);
        add_action('woocommerce_cart_loaded_from_session', [__CLASS__, 'remove_unavailable_cart_metals'], 30);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
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
    /** Additional price, stored per simple product using WooCommerce decimal format. */
    public static function platinum_admin_field() {
        global $product_object;
        if (!function_exists('woocommerce_wp_text_input')) return;
        woocommerce_wp_text_input([
            'id' => '_jpc_platinum_price',
            'label' => 'Τιμή Πλατίνας (' . get_woocommerce_currency_symbol() . ')',
            'description' => 'Προαιρετική τελική τιμή προϊόντος για επιλογή Πλατίνας. Αν μείνει κενή, η Πλατίνα δεν είναι διαθέσιμη και δεν εμφανίζεται. Υποστηρίζεται σε απλά προϊόντα.',
            'desc_tip' => true,
            'data_type' => 'price',
            'value' => $product_object ? $product_object->get_meta('_jpc_platinum_price', true, 'edit') : '',
        ]);
        self::availability_admin_hint($product_object, 'platinum');
    }
    public static function save_platinum_admin_field($product) {
        if (!$product || !$product->is_type('simple')) return;
        $value = isset($_POST['_jpc_platinum_price']) && is_scalar($_POST['_jpc_platinum_price'])
            ? wc_clean(wp_unslash($_POST['_jpc_platinum_price'])) : '';
        if ($value === '') {
            $product->delete_meta_data('_jpc_platinum_price');
        } else {
            $decimal = wc_format_decimal($value);
            if (is_numeric($decimal) && (float)$decimal >= 0) {
                $product->update_meta_data('_jpc_platinum_price', $decimal);
            }
        }
    }
    /**
     * The pricing fields are persisted via CRUD above. Some shops may have older
     * duplicate postmeta rows or a late save hook restoring the old value.
     * Normalize the final database state after WooCommerce has saved the product.
     * An empty field deletes ALL rows for the metal; it must never mean gold price.
     */
    public static function finalize_metal_price_meta($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || !current_user_can('edit_post', $product_id)) return;
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('simple')) return;
        foreach (['_jpc_platinum_price', '_jpc_silver_price'] as $key) {
            if (!array_key_exists($key, $_POST)) continue;
            $raw = wp_unslash($_POST[$key]);
            if (!is_scalar($raw)) continue;
            $value = trim((string) wc_clean($raw));
            if ($value === '') {
                // Delete duplicate/legacy rows too; Woo CRUD delete_meta_data()
                // alone can leave stale values in installations with duplicate meta.
                delete_post_meta($product_id, $key);
            } else {
                $decimal = wc_format_decimal($value);
                if (is_numeric($decimal) && (float) $decimal >= 0) {
                    delete_post_meta($product_id, $key);
                    add_post_meta($product_id, $key, $decimal, true);
                }
            }
        }
        // Explicit availability is authoritative even if another integration
        // restores an old price meta row after a blank save.
        foreach (['_jpc_platinum_price' => '_jpc_platinum_available', '_jpc_silver_price' => '_jpc_silver_available'] as $price_key => $available_key) {
            if (!array_key_exists($price_key, $_POST) || !is_scalar($_POST[$price_key])) continue;
            $submitted = trim((string) wc_clean(wp_unslash($_POST[$price_key])));
            $decimal = $submitted !== '' ? wc_format_decimal($submitted) : '';
            update_post_meta($product_id, $available_key,
                $submitted !== '' && is_numeric($decimal) && (float) $decimal >= 0 ? 'yes' : 'no');
        }
        clean_post_cache($product_id);
        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($product_id);
    }
    /** Availability is persisted in the SAME WooCommerce CRUD save as its price. */
    public static function sync_metal_availability($product) {
        if (!$product || !$product->is_type('simple')) return;
        foreach (['_jpc_platinum_price' => '_jpc_platinum_available', '_jpc_silver_price' => '_jpc_silver_available'] as $price_key => $flag_key) {
            if (!isset($_POST[$price_key]) || !is_scalar($_POST[$price_key])) continue;
            $raw = trim((string) wc_clean(wp_unslash($_POST[$price_key])));
            $decimal = $raw === '' ? '' : wc_format_decimal($raw);
            $valid = $raw !== '' && is_numeric($decimal) && (float) $decimal >= 0;
            if ($valid) {
                $product->update_meta_data($price_key, $decimal);
                $product->update_meta_data($flag_key, 'yes');
            } else {
                $product->delete_meta_data($price_key);
                $product->update_meta_data($flag_key, 'no');
            }
        }
    }
    /** Admin-only diagnostics: show the product values actually read by this plugin. */
    private static function availability_admin_hint($product, $metal) {
        if (!$product || !$product->is_type('simple')) return;
        $key = $metal === 'platinum' ? '_jpc_platinum_price' : '_jpc_silver_price';
        $flag = $metal === 'platinum' ? '_jpc_platinum_available' : '_jpc_silver_available';
        $price = trim((string) $product->get_meta($key, true, 'edit'));
        $availability = (string) $product->get_meta($flag, true, 'edit');
        $active = $metal === 'platinum' ? self::platinum_price($product) : self::silver_price($product);
        echo '<p class="form-field"><span class="description" style="margin-left:162px;display:block">JPC έλεγχος (' . esc_html($metal) . '): αποθηκευμένη τιμή <code>' . esc_html($price === '' ? '[κενή]' : $price) . '</code>, ένδειξη <code>' . esc_html($availability === '' ? '[καμία]' : $availability) . '</code>, διαθεσιμότητα <strong>' . esc_html($active === '' ? 'ΟΧΙ' : 'ΝΑΙ') . '</strong>.</span></p>';
    }
    private static function platinum_price($product) {
        if (!$product || !$product->is_type('simple')) return '';
        // An explicitly blank field makes Platinum unavailable, never Gold-priced.
        if ($product->get_meta('_jpc_platinum_available', true) === 'no') return '';
        $value = trim((string) $product->get_meta('_jpc_platinum_price', true));
        return $value !== '' && is_numeric($value) && (float)$value >= 0 ? $value : '';
    }
    /** Optional silver alternative for simple products. An empty value means unavailable. */
    public static function silver_admin_field() {
        global $product_object;
        if (!function_exists('woocommerce_wp_text_input')) return;
        woocommerce_wp_text_input([
            'id' => '_jpc_silver_price',
            'label' => 'Τιμή Ασημιού (' . get_woocommerce_currency_symbol() . ')',
            'description' => 'Αφήστε κενό για να μην εμφανίζεται η επιλογή Ασήμι στο συγκεκριμένο προϊόν.',
            'desc_tip' => true,
            'data_type' => 'price',
            'value' => $product_object ? $product_object->get_meta('_jpc_silver_price', true, 'edit') : '',
        ]);
        self::availability_admin_hint($product_object, 'silver');
    }
    public static function save_silver_admin_field($product) {
        if (!$product || !$product->is_type('simple')) return;
        $value = isset($_POST['_jpc_silver_price']) && is_scalar($_POST['_jpc_silver_price'])
            ? wc_clean(wp_unslash($_POST['_jpc_silver_price'])) : '';
        if ($value === '') {
            $product->delete_meta_data('_jpc_silver_price');
        } else {
            $decimal = wc_format_decimal($value);
            if (is_numeric($decimal) && (float)$decimal >= 0) {
                $product->update_meta_data('_jpc_silver_price', $decimal);
            }
        }
    }
    private static function silver_price($product) {
        if (!$product || !$product->is_type('simple')) return '';
        if ($product->get_meta('_jpc_silver_available', true) === 'no') return '';
        $value = trim((string) $product->get_meta('_jpc_silver_price', true));
        return $value !== '' && is_numeric($value) && (float)$value >= 0 ? $value : '';
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
            // The base price is obtained from the stored cart snapshot, preventing
            // repeated total calculations from compounding price changes.
            if (isset($cart_item['jpc_base_price']) && is_numeric($cart_item['jpc_base_price'])) {
                $base_price = (float)$cart_item['jpc_base_price'];
            } else {
                $base_price = (float)$product->get_price('edit');
            }
            $metal = $cart_item['jc_config']['metal_type'];
            $price_key = $metal === 'platinum' ? 'jpc_platinum_price' : ($metal === 'silver' ? 'jpc_silver_price' : '');
            if ($price_key !== '' && isset($cart_item[$price_key]) &&
                $cart_item[$price_key] !== '' && is_numeric($cart_item[$price_key])) {
                $selected_price = (float)$cart_item[$price_key];
                $product->set_price($selected_price);
                // Alternate metals are distinct configurations, NOT a discount/sale.
                $product->set_regular_price($selected_price);
                $product->set_sale_price('');
            } else {
                // Never silently charge the Gold price for unavailable alternative metals.
                if ($metal !== 'gold') continue;
                $product->set_price($base_price);
                // The selected base is displayed as its actual price, not a sale.
                // Avoid changing unrelated product regular/sale fields if the store
                // has a genuine WooCommerce sale configured for the gold option.
                if (isset($cart_item['jpc_base_on_sale']) && !$cart_item['jpc_base_on_sale']) {
                    $product->set_regular_price($base_price);
                    $product->set_sale_price('');
                }
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
        $product = wc_get_product($variation_id ?: $product_id);
        if ($product && $product->is_type('simple')) {
            $cart_item_data['jpc_base_price'] = $product->get_price('edit');
            $cart_item_data['jpc_base_on_sale'] = $product->is_on_sale('edit');
            $cart_item_data['jpc_platinum_price'] = self::platinum_price($product);
            $cart_item_data['jpc_silver_price'] = self::silver_price($product);
        }
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
