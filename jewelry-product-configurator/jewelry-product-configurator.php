<?php
/**
 * Plugin Name: Jewelry Product Configurator
 * Description: Επιλογή μετάλλου, χρώματος και συστήματος/μεγέθους δαχτυλιδιού για επιλεγμένες κατηγορίες WooCommerce.
 * Version: 1.2.4
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
    const VERSION = '1.2.4';
    private static $rendered = false;

    /** Alternative metals: one price meta per metal. Gold is the normal WooCommerce price. */
    const METAL_PRICE_KEYS = ['platinum' => '_jpc_platinum_price', 'silver' => '_jpc_silver_price'];
    /** Legacy (≤1.1.5) availability flags, migrated away in 1.1.6. */
    const LEGACY_FLAG_KEYS = ['platinum' => '_jpc_platinum_available', 'silver' => '_jpc_silver_available'];
    /** Variation fields use their own names so they never collide with the simple-product fields. */
    const VARIATION_FIELD_NAMES = ['platinum' => '_jpc_var_platinum_price', 'silver' => '_jpc_var_silver_price'];
    const METAL_LABELS = ['gold' => 'Χρυσός', 'platinum' => 'Πλατίνα', 'silver' => 'Ασήμι'];
    const DB_VERSION_OPTION = 'jpc_db_version';
    const LABEL_PRICES_OPTION = 'jpc_show_label_prices';
    const FROM_PRICE_OPTION = 'jpc_show_from_price';
    /** Ring size defaults, used when a category has no custom range. */
    const DEFAULT_SIZES = ['eu' => [47, 58, 1], 'us' => [5, 13, 1]];
    /** Per-request cache of metal price tables, keyed by product ID. */
    private static $price_tables = [];

    public static function init() {
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'platinum_admin_field']);
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'silver_admin_field']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'yellow_gold_image_admin_field']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'category_fields_admin_hint'], 5);
        // Single save path for the metal prices, through WooCommerce CRUD only.
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_metal_prices'], 999);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_yellow_gold_image_admin_field']);
        // Variable products: Platinum/Silver price per variation.
        add_action('woocommerce_variation_options_pricing', [__CLASS__, 'variation_admin_fields'], 10, 3);
        add_action('woocommerce_admin_process_variation_object', [__CLASS__, 'save_variation_metal_prices'], 10, 2);
        add_filter('woocommerce_available_variation', [__CLASS__, 'variation_data'], 10, 3);
        // Products list: metal price column + Quick Edit.
        add_filter('manage_edit-product_columns', [__CLASS__, 'admin_columns'], 20);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'admin_column_content'], 10, 2);
        add_action('woocommerce_product_quick_edit_end', [__CLASS__, 'quick_edit_fields']);
        add_action('woocommerce_product_quick_edit_save', [__CLASS__, 'quick_edit_save']);
        // Shop/category listings: "Από X €" when a metal is cheaper than gold.
        add_filter('woocommerce_get_price_html', [__CLASS__, 'from_price_html'], 20, 2);
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
            'Τελική τιμή προϊόντος σε Πλατίνα. Αν μείνει κενή (ή 0), η Πλατίνα δεν διατίθεται και δεν εμφανίζεται. Σε προϊόντα με παραλλαγές, η τιμή ορίζεται σε κάθε παραλλαγή.');
    }
    /** Final product price for Silver. An empty value means unavailable. */
    public static function silver_admin_field() {
        self::metal_admin_field('silver', 'Τιμή Ασημιού',
            'Τελική τιμή προϊόντος σε Ασήμι. Αν μείνει κενή (ή 0), το Ασήμι δεν διατίθεται και δεν εμφανίζεται. Σε προϊόντα με παραλλαγές, η τιμή ορίζεται σε κάθε παραλλαγή.');
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
            // Fields not posted (REST, imports, etc.) leave the stored value untouched.
            if (!array_key_exists($key, $_POST) || !is_scalar($_POST[$key])) continue;
            self::set_metal_price($product, $metal, wc_clean(wp_unslash($_POST[$key])));
        }
    }
    /** Store a metal price on a product/variation object (not saved here). Blank/invalid removes it. */
    private static function set_metal_price($product, $metal, $raw) {
        $key = self::METAL_PRICE_KEYS[$metal];
        $price = self::normalize_price($raw);
        // delete_meta_data() removes every row with this key, including legacy duplicates.
        $product->delete_meta_data($key);
        if ($price !== '') $product->add_meta_data($key, $price, true);
        $product->delete_meta_data(self::LEGACY_FLAG_KEYS[$metal]);
        unset(self::$price_tables[$product->get_id()]);
        if ($product->get_parent_id()) unset(self::$price_tables[$product->get_parent_id()]);
    }
    /** Price of an alternative metal for a simple product or a variation, or '' if not offered. */
    private static function metal_price($product, $metal) {
        if (!$product || !$product->is_type(['simple', 'variation']) || !isset(self::METAL_PRICE_KEYS[$metal])) return '';
        return self::normalize_price($product->get_meta(self::METAL_PRICE_KEYS[$metal], true, 'edit'));
    }

    /* ------------------------------------------------------------------
     * Variable products
     * ------------------------------------------------------------------ */
    public static function variation_admin_fields($loop, $variation_data, $variation) {
        if (!function_exists('woocommerce_wp_text_input')) return;
        $object = wc_get_product($variation);
        $i = 0;
        foreach (self::VARIATION_FIELD_NAMES as $metal => $name) {
            woocommerce_wp_text_input([
                'id' => $name . '_' . $loop,
                'name' => $name . '[' . $loop . ']',
                'label' => 'Τιμή ' . ($metal === 'platinum' ? 'Πλατίνας' : 'Ασημιού') . ' (' . get_woocommerce_currency_symbol() . ')',
                'placeholder' => 'Δεν διατίθεται',
                'description' => 'Κενό = η παραλλαγή δεν διατίθεται σε ' . ($metal === 'platinum' ? 'Πλατίνα' : 'Ασήμι') . '.',
                'desc_tip' => true,
                'data_type' => 'price',
                'wrapper_class' => 'form-row ' . ($i++ % 2 ? 'form-row-last' : 'form-row-first'),
                'value' => $object ? $object->get_meta(self::METAL_PRICE_KEYS[$metal], true, 'edit') : '',
            ]);
        }
    }
    public static function save_variation_metal_prices($variation, $i) {
        foreach (self::VARIATION_FIELD_NAMES as $metal => $name) {
            if (!isset($_POST[$name]) || !is_array($_POST[$name]) || !array_key_exists($i, $_POST[$name])) continue;
            self::set_metal_price($variation, $metal, wc_clean(wp_unslash($_POST[$name][$i])));
        }
    }
    /** Metal prices for the selected variation, consumed by configurator.js. */
    public static function variation_data($data, $product, $variation) {
        if (!self::matches($product->get_id())) return $data;
        $gold = $variation->get_price_html();
        $metals = ['gold' => $gold !== '' ? $gold : wc_price(wc_get_price_to_display($variation))];
        foreach (array_keys(self::METAL_PRICE_KEYS) as $metal) {
            $price = self::metal_price($variation, $metal);
            if ($price !== '') $metals[$metal] = wc_price(wc_get_price_to_display($variation, ['price' => (float) $price]));
        }
        $data['jpc_metals'] = $metals;
        return $data;
    }

    /**
     * Display prices (incl./excl. tax as the shop shows them) per metal:
     * ['gold' => [min, max], 'platinum' => [min, max], ...]. Metals not offered are absent.
     * Simple products have min === max; variable products span their visible variations.
     */
    private static function price_table($product) {
        if (!$product) return [];
        $id = $product->get_id();
        if (isset(self::$price_tables[$id])) return self::$price_tables[$id];
        $table = [];
        if ($product->is_type('simple')) {
            $gold = (float) wc_get_price_to_display($product);
            $table['gold'] = [$gold, $gold];
            foreach (array_keys(self::METAL_PRICE_KEYS) as $metal) {
                $price = self::metal_price($product, $metal);
                if ($price === '') continue;
                $display = (float) wc_get_price_to_display($product, ['price' => (float) $price]);
                $table[$metal] = [$display, $display];
            }
        } elseif ($product->is_type('variable')) {
            // Only visible, purchasable variations (WooCommerce's own cached list).
            $prices = $product->get_variation_prices(true);
            $gold = isset($prices['price']) ? array_map('floatval', $prices['price']) : [];
            if ($gold) $table['gold'] = [min($gold), max($gold)];
            $ids = array_keys($gold);
            if ($ids) update_meta_cache('post', $ids);
            foreach (self::METAL_PRICE_KEYS as $metal => $key) {
                $values = [];
                foreach ($ids as $variation_id) {
                    $price = self::normalize_price(get_post_meta($variation_id, $key, true));
                    if ($price !== '') $values[] = (float) wc_get_price_to_display($product, ['price' => (float) $price]);
                }
                if ($values) $table[$metal] = [min($values), max($values)];
            }
        }
        return self::$price_tables[$id] = $table;
    }
    /** "1.250,00 €" or "από 980,00 €" when the metal's price varies across variations. */
    private static function range_html($range) {
        return $range[0] == $range[1] ? wc_price($range[0]) : 'από ' . wc_price($range[0]);
    }
    /** Metals a product offers at all (for variable products: in at least one variation). */
    private static function offered_metals($product) {
        return array_keys(self::price_table($product)) ?: ['gold'];
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
    private static function image_stems($attachment_id) {
        $stems = [];
        foreach ([get_attached_file($attachment_id), wp_get_original_image_path($attachment_id)] as $path) {
            if (!$path) continue;
            $stem = strtolower(pathinfo($path, PATHINFO_FILENAME));
            $stems[] = preg_replace('/-(scaled|rotated)$/', '', $stem);
        }
        return array_values(array_unique(array_filter($stems)));
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
    /* ------------------------------------------------------------------
     * Products list: column + Quick Edit
     * ------------------------------------------------------------------ */
    public static function admin_columns($columns) {
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ($key === 'price') $result['jpc_metals'] = 'Πλατίνα / Ασήμι';
        }
        if (!isset($result['jpc_metals'])) $result['jpc_metals'] = 'Πλατίνα / Ασήμι';
        return $result;
    }
    public static function admin_column_content($column, $post_id) {
        if ($column !== 'jpc_metals') return;
        $product = wc_get_product($post_id);
        if (!$product) return;
        if ($product->is_type('variable')) {
            $table = self::price_table($product);
            foreach (['platinum' => 'Π', 'silver' => 'Α'] as $metal => $short) {
                echo '<div>' . esc_html($short) . ': ' . (isset($table[$metal])
                    ? wp_kses_post(self::range_html($table[$metal])) : '<span style="color:#999">—</span>') . '</div>';
            }
            echo '<div style="color:#999"><small>ανά παραλλαγή</small></div>';
            return;
        }
        if (!$product->is_type('simple')) { echo '<span style="color:#999">—</span>'; return; }
        $raw = [];
        foreach (['platinum' => 'Π', 'silver' => 'Α'] as $metal => $short) {
            $price = self::metal_price($product, $metal);
            $raw[$metal] = $price === '' ? '' : wc_format_localized_price($price);
            echo '<div>' . esc_html($short) . ': ' . ($price === '' ? '<span style="color:#999">—</span>' : wp_kses_post(wc_price($price))) . '</div>';
        }
        // Raw values for Quick Edit (read by admin-product.js).
        echo '<div class="hidden jpc-inline-data" data-platinum="' . esc_attr($raw['platinum']) . '" data-silver="' . esc_attr($raw['silver']) . '"></div>';
    }
    public static function quick_edit_fields() {
        echo '<div class="jpc-quick-edit" style="clear:both">';
        foreach (self::METAL_PRICE_KEYS as $metal => $key) {
            echo '<label><span class="title">' . esc_html($metal === 'platinum' ? 'Πλατίνα' : 'Ασήμι') . '</span>'
                . '<span class="input-text-wrap"><input type="text" name="' . esc_attr($key) . '" class="text wc_input_price" placeholder="Δεν διατίθεται" value=""></span></label><br class="clear">';
        }
        echo '</div>';
    }
    /** Runs after WooCommerce has verified the Quick Edit nonce and saved the product. */
    public static function quick_edit_save($product) {
        if (!$product || !$product->is_type('simple') || !current_user_can('edit_post', $product->get_id())) return;
        $changed = false;
        foreach (self::METAL_PRICE_KEYS as $metal => $key) {
            if (!array_key_exists($key, $_POST) || !is_scalar($_POST[$key])) continue;
            self::set_metal_price($product, $metal, wc_clean(wp_unslash($_POST[$key])));
            $changed = true;
        }
        if ($changed) $product->save();
    }

    /* ------------------------------------------------------------------
     * Listings: "Από X €"
     * ------------------------------------------------------------------ */
    public static function from_price_html($html, $product) {
        if (get_option(self::FROM_PRICE_OPTION, 'yes') !== 'yes') return $html;
        if ((is_admin() && !wp_doing_ajax()) || !$product || !$product->is_type(['simple', 'variable'])) return $html;
        // On the product's own page the configurator shows the selected metal's price;
        // configurator.js needs the original Gold price there.
        if (function_exists('is_product') && is_product() && $product->get_id() === get_queried_object_id()) return $html;
        if (!self::matches($product->get_id())) return $html;
        $table = self::price_table($product);
        if (empty($table['gold'])) return $html;
        $lowest = $table['gold'][0];
        foreach ($table as $metal => $range) $lowest = min($lowest, $range[0]);
        if ($lowest >= $table['gold'][0]) return $html;
        return '<span class="jpc-from-price">Από ' . wc_price($lowest) . '</span>' . $product->get_price_suffix($lowest);
    }

    /** Metals that can be bought for this exact product (simple product or variation). */
    private static function allowed_metals($product) {
        $allowed = ['gold'];
        foreach (array_keys(self::METAL_PRICE_KEYS) as $metal) {
            if (self::metal_price($product, $metal) !== '') $allowed[] = $metal;
        }
        return $allowed;
    }
    /** Discard stale cart lines for metal options no longer offered by the product. */
    public static function remove_unavailable_cart_metals($cart) {
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) return;
        foreach ($cart->get_cart() as $key => $cart_item) {
            if (empty($cart_item['jc_config']['metal_type'])) continue;
            $metal = $cart_item['jc_config']['metal_type'];
            if ($metal === 'gold') continue;
            $product_id = !empty($cart_item['variation_id']) ? absint($cart_item['variation_id'])
                : (isset($cart_item['product_id']) ? absint($cart_item['product_id']) : 0);
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
            if (!$product || !$product->is_type(['simple', 'variation'])) continue;
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
        register_setting('jpc_settings', self::LABEL_PRICES_OPTION, ['type'=>'string', 'sanitize_callback'=>[__CLASS__, 'sanitize_yes_no'], 'default'=>'yes']);
        register_setting('jpc_settings', self::FROM_PRICE_OPTION, ['type'=>'string', 'sanitize_callback'=>[__CLASS__, 'sanitize_yes_no'], 'default'=>'yes']);
    }
    public static function sanitize_yes_no($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }
    public static function sanitize_placement($value) {
        return $value === 'shortcode' ? 'shortcode' : 'automatic';
    }
    public static function placement() {
        return get_option(self::PLACEMENT_OPTION, 'automatic') === 'shortcode' ? 'shortcode' : 'automatic';
    }
    public static function sanitize_categories($value) {
        $json = self::posted_settings_json();
        if ($json !== null) $value = isset($json['categories']) && is_array($json['categories']) ? $json['categories'] : [];
        return array_values(array_unique(array_filter(array_map('absint', is_array($value) ? $value : []))));
    }
    /**
     * The settings page posts the whole category table as ONE JSON field. A table with
     * 11 inputs per category easily exceeds PHP's max_input_vars (default 1000), and PHP
     * then silently drops the rest: later categories lost their settings.
     */
    private static function posted_settings_json() {
        static $parsed = false, $data = null;
        if ($parsed) return $data;
        $parsed = true;
        if (empty($_POST['jpc_settings_json']) || !is_string($_POST['jpc_settings_json'])) return null;
        $decoded = json_decode(wp_unslash($_POST['jpc_settings_json']), true);
        $data = is_array($decoded) ? $decoded : null;
        return $data;
    }
    /** Defaults preserve all previous field behavior until a category is edited. */
    public static function category_fields($product_id) {
        $resolved = self::resolve_category_fields($product_id);
        unset($resolved['categories']);
        return $resolved;
    }
    /**
     * Which configurator fields a product gets, plus the category IDs that decided it.
     * - Only categories with the configurator enabled count.
     * - The most specific (deepest) of them decides.
     * - If several are equally specific (e.g. "Σταυροί" and "Μενταγιόν"), a field is
     *   shown only if ALL of them enable it: unchecking "Μέγεθος" on a category is never
     *   overridden by a sibling category that happens to have a lower ID.
     */
    public static function resolve_category_fields($product_id) {
        $default = ['color' => true, 'size' => true, 'sizes' => self::size_lists([]), 'categories' => []];
        $stored = get_option(self::FIELDS_OPTION, []);
        if (!is_array($stored)) $stored = [];
        $product_id = wp_get_post_parent_id($product_id) ?: $product_id;
        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) return $default;
        $enabled = self::category_ids();
        $candidates = [];
        foreach ($terms as $term) {
            if (!in_array((int)$term->term_id, $enabled, true)) continue;
            $candidates[(int)$term->term_id] = count(get_ancestors($term->term_id, 'product_cat', 'taxonomy'));
        }
        if (!$candidates) return $default;
        $deepest = max($candidates);
        $ids = array_keys(array_filter($candidates, static function ($depth) use ($deepest) { return $depth === $deepest; }));
        sort($ids);
        $result = ['color' => true, 'size' => true, 'sizes' => null, 'categories' => $ids];
        foreach ($ids as $id) {
            // A category never saved in the settings behaves as "everything enabled".
            $fields = isset($stored[$id]) && is_array($stored[$id]) ? $stored[$id] : ['color' => 1, 'size' => 1];
            $result['color'] = $result['color'] && !empty($fields['color']);
            $result['size'] = $result['size'] && !empty($fields['size']);
            if ($result['sizes'] === null && !empty($fields['size'])) $result['sizes'] = self::size_lists($fields);
        }
        if ($result['sizes'] === null) $result['sizes'] = self::size_lists([]);
        return $result;
    }
    /** Product edit screen: show which category settings apply, so surprises are visible. */
    public static function category_fields_admin_hint() {
        global $product_object;
        if (!$product_object || !$product_object->get_id()) return;
        if (!self::matches($product_object->get_id())) {
            echo '<p class="form-field"><span class="description" style="margin-left:162px;display:block">Jewelry Configurator: δεν εμφανίζεται σε αυτό το προϊόν (καμία ενεργή κατηγορία).</span></p>';
            return;
        }
        $r = self::resolve_category_fields($product_object->get_id());
        $names = array_map(static function ($id) { $t = get_term($id, 'product_cat'); return $t && !is_wp_error($t) ? $t->name : '#' . $id; }, $r['categories']);
        echo '<p class="form-field"><span class="description" style="margin-left:162px;display:block">Jewelry Configurator: ρυθμίσεις από <strong>'
            . esc_html(implode(', ', $names)) . '</strong> → Χρώμα χρυσού: <strong>' . ($r['color'] ? 'ναι' : 'όχι')
            . '</strong>, Νούμερο δαχτυλιδιού: <strong>' . ($r['size'] ? 'ναι' : 'όχι') . '</strong>.</span></p>';
    }
    /** ['eu' => ['47', '47.5', ...], 'us' => [...]] for a category's stored settings (defaults when unset). */
    public static function size_lists($fields) {
        $lists = [];
        foreach (self::DEFAULT_SIZES as $system => $default) {
            $range = isset($fields[$system]) && is_array($fields[$system]) && count($fields[$system]) === 3
                ? $fields[$system] : $default;
            // Work in hundredths to avoid floating point drift (e.g. 5 → 13 step 0.25).
            [$from, $to, $step] = array_map(static function ($n) { return (int) round((float) $n * 100); }, $range);
            $values = [];
            for ($n = $from; $step > 0 && $n <= $to && count($values) < 200; $n += $step) {
                $values[] = rtrim(rtrim(number_format($n / 100, 2, '.', ''), '0'), '.');
            }
            $lists[$system] = $values;
        }
        return $lists;
    }
    /** Ring sizes are stored as "5.5" and shown the Greek way, "5,5". */
    public static function size_label($value) {
        return str_replace('.', ',', (string) $value);
    }
    /** Range [from, to, step] from the settings form, or null to use the default. */
    private static function sanitize_size_range($input) {
        if (!is_array($input)) return null;
        $nums = [];
        foreach (['from', 'to', 'step'] as $k) {
            $v = isset($input[$k]) ? str_replace(',', '.', trim((string) $input[$k])) : '';
            if ($v === '' || !is_numeric($v) || (float) $v <= 0) return null;
            $nums[] = round((float) $v, 2);
        }
        if ($nums[0] > $nums[1] || ($nums[1] - $nums[0]) / $nums[2] > 200) return null;
        return $nums;
    }
    public static function sanitize_category_fields($value) {
        $json = self::posted_settings_json();
        if ($json !== null) $value = isset($json['fields']) && is_array($json['fields']) ? $json['fields'] : [];
        $result = [];
        if (!is_array($value)) return $result;
        foreach ($value as $id => $fields) {
            $id = absint($id);
            if (!$id || !is_array($fields)) continue;
            $result[$id] = ['color' => !empty($fields['color']) ? 1 : 0, 'size' => !empty($fields['size']) ? 1 : 0];
            foreach (array_keys(self::DEFAULT_SIZES) as $system) {
                $range = self::sanitize_size_range($fields[$system] ?? null);
                if ($range) $result[$id][$system] = $range;
            }
        }
        return $result;
    }
    public static function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $selected = self::category_ids();
        $cats = get_terms(['taxonomy'=>'product_cat', 'hide_empty'=>false]);
        echo '<div class="wrap"><h1>Jewelry Product Configurator</h1><p>Επίλεξε κατηγορίες και τρόπο εμφάνισης. Απενεργοποίησε τον παλιό PHP/JS κώδικα για να αποφύγεις διπλό configurator.</p><form action="options.php" method="post">';
        settings_fields('jpc_settings');
        // Filled by the script below on submit; first in the form so it is never truncated.
        echo '<input type="hidden" name="jpc_settings_json" id="jpc_settings_json" value="">';
        $stored_fields = get_option(self::FIELDS_OPTION, []);
        if (!is_array($stored_fields)) $stored_fields = [];
        echo '<p>Επίλεξε ποιες κατηγορίες χρησιμοποιούν τον configurator και ποια πεδία εμφανίζονται σε κάθε μία. Η επιλογή μετάλλου εμφανίζεται πάντα.</p>';
        echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>Κατηγορία</th><th>Ενεργός configurator</th><th>Χρώμα χρυσού</th><th>Μέγεθος δαχτυλιδιού</th><th>Νούμερα EU (από – έως, βήμα)</th><th>Νούμερα US (από – έως, βήμα)</th></tr></thead><tbody>';
        if (!is_wp_error($cats)) foreach ($cats as $cat) {
            $id = (int) $cat->term_id;
            $f = isset($stored_fields[$id]) && is_array($stored_fields[$id]) ? $stored_fields[$id] : ['color'=>1,'size'=>1];
            $indent = str_repeat('— ', count(get_ancestors($id, 'product_cat', 'taxonomy')));
            echo '<tr class="jpc-cat-row" data-cat="'.$id.'"><td>'.esc_html($indent.$cat->name).' <small>(ID '.$id.')</small></td>';
            echo '<td><input type="checkbox" name="'.esc_attr(self::OPTION).'[]" value="'.$id.'" '.checked(in_array($id,$selected,true),true,false).'></td>';
            // Hidden defaults guarantee that unchecked fields are saved as false.
            foreach (['color','size'] as $key) {
                echo '<td><input type="hidden" name="'.esc_attr(self::FIELDS_OPTION).'['.$id.']['.$key.']" value="0"><input type="checkbox" name="'.esc_attr(self::FIELDS_OPTION).'['.$id.']['.$key.']" value="1" '.checked(!empty($f[$key]),true,false).'></td>';
            }
            foreach (self::DEFAULT_SIZES as $system => $default) {
                $range = isset($f[$system]) && is_array($f[$system]) ? $f[$system] : ['', '', ''];
                $base = esc_attr(self::FIELDS_OPTION).'['.$id.']['.$system.']';
                echo '<td style="white-space:nowrap">';
                foreach (['from', 'to', 'step'] as $i => $k) {
                    if ($k === 'to') echo ' – ';
                    if ($k === 'step') echo ' ανά ';
                    echo '<input type="number" min="0" step="0.25" style="width:'.($k === 'step' ? '60' : '70').'px" name="'.$base.'['.$k.']" value="'.esc_attr($range[$i]).'" placeholder="'.esc_attr($default[$i]).'">';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Ensure unchecking every category saves an empty array.
        echo '<input type="hidden" name="'.esc_attr(self::OPTION).'[]" value="0">';
        echo '<p class="description">Αν ένα προϊόν ανήκει σε περισσότερες ενεργές κατηγορίες, εφαρμόζεται η βαθύτερη (πιο ειδική). Αν είναι στο ίδιο βάθος (π.χ. Σταυροί και Μενταγιόν), ένα πεδίο εμφανίζεται μόνο αν το έχουν ενεργό όλες. Στη σελίδα επεξεργασίας κάθε προϊόντος φαίνεται ποια ρύθμιση εφαρμόζεται.</p>';
        // Serialize the table into one field and keep the per-row inputs out of the POST.
        ?>
        <script>
        (function () {
          var form = document.getElementById('jpc_settings_json').form;
          form.addEventListener('submit', function () {
            var data = {categories: [], fields: {}};
            form.querySelectorAll('tr.jpc-cat-row').forEach(function (row) {
              var id = row.getAttribute('data-cat');
              var field = function (suffix, type) {
                var name = '<?php echo esc_js(self::FIELDS_OPTION); ?>[' + id + ']' + suffix;
                return Array.prototype.find.call(row.querySelectorAll('input'), function (el) {
                  return el.name === name && (!type || el.type === type);
                });
              };
              var checked = function (suffix) { var el = field(suffix, 'checkbox'); return el && el.checked ? 1 : 0; };
              var enabled = Array.prototype.find.call(row.querySelectorAll('input'), function (el) { return el.name === '<?php echo esc_js(self::OPTION); ?>[]'; });
              if (enabled && enabled.checked) data.categories.push(parseInt(id, 10));
              var f = {color: checked('[color]'), size: checked('[size]')};
              ['eu', 'us'].forEach(function (sys) {
                f[sys] = {};
                ['from', 'to', 'step'].forEach(function (k) { var el = field('[' + sys + '][' + k + ']'); f[sys][k] = el ? el.value : ''; });
              });
              data.fields[id] = f;
              row.querySelectorAll('input').forEach(function (input) { input.disabled = true; });
            });
            document.getElementById('jpc_settings_json').value = JSON.stringify(data);
          });
          // Coming back with the browser's Back button must not leave the table disabled.
          window.addEventListener('pageshow', function () {
            form.querySelectorAll('tr.jpc-cat-row input').forEach(function (input) { input.disabled = false; });
          });
        })();
        </script>
        <?php
        echo '<p class="description">Νούμερα: κενά πεδία = προεπιλογή (EU 47–58, US 5–13, ανά 1). Για μισά νούμερα βάλτε βήμα 0.5 (π.χ. US 5 – 13 ανά 0.5 → 5, 5½, 6 …).</p>';
        echo '<h2>Εμφάνιση τιμών</h2><fieldset>';
        echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="'.esc_attr(self::LABEL_PRICES_OPTION).'" value="yes" '.checked(get_option(self::LABEL_PRICES_OPTION, 'yes'), 'yes', false).'> Τιμή δίπλα σε κάθε μέταλλο στη σελίδα προϊόντος (π.χ. «Πλατίνα — 1.250 €»)</label>';
        echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="'.esc_attr(self::FROM_PRICE_OPTION).'" value="yes" '.checked(get_option(self::FROM_PRICE_OPTION, 'yes'), 'yes', false).'> «Από X €» στις λίστες προϊόντων όταν κάποιο μέταλλο είναι φθηνότερο από τον χρυσό</label>';
        echo '</fieldset>';
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
        wp_enqueue_script('jpc-common', plugin_dir_url(__FILE__).'assets/js/jpc-common.js', ['jquery'], self::VERSION, true);
        wp_enqueue_script('jpc-configurator', plugin_dir_url(__FILE__).'assets/js/configurator.js', ['jquery', 'jpc-common'], self::VERSION, true);
        $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : false;
        if ($product && $product->is_type('simple')) {
            // Yellow Gold image swap is for simple products; variations have their own images.
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
                        // File name stems used to recognise this image in any size or cache copy.
                        'stems' => self::image_stems($product_image_id),
                    ];
                }
            }
            $images = [
                'default' => $primary_image,
                'yellowGold' => self::yellow_gold_image_data($product),
            ];
        }
        if ($product && $product->is_type(['simple', 'variable'])) {
            $table = self::price_table($product);
            // Hide metals this product does not offer with CSS printed in <head>, i.e.
            // before the first paint. Otherwise a radio coming from cached HTML or from
            // another source (old snippet, theme template) is visible until
            // configurator.js removes it, and "flashes" on page load.
            $hidden = array_diff(array_keys(self::METAL_PRICE_KEYS), array_keys($table));
            $selectors = [];
            foreach ($hidden as $metal) {
                $selectors[] = '.jewelry-configurator label:has(> input[name="jc_metal_type_ui"][value="' . $metal . '"])';
            }
            // Same for fields this product's category has switched off.
            $fields = self::category_fields($product->get_id());
            if (!$fields['size']) {
                $selectors[] = '.jewelry-configurator #jc_size_system_box';
                $selectors[] = '.jewelry-configurator #jc_size_box';
            }
            if (!$fields['color']) $selectors[] = '.jewelry-configurator #jc_color_box';
            if ($selectors) wp_add_inline_style('jpc-configurator', implode(',', $selectors) . '{display:none!important}');
            $metals = [];   // HTML for the main price element when no variation is selected
            $labels = [];   // HTML next to each metal radio
            foreach ($table as $metal => $range) {
                $metals[$metal] = $product->is_type('simple') ? wc_price($range[0]) : self::range_html($range);
                $labels[$metal] = self::range_html($range);
            }
            wp_localize_script('jpc-configurator', 'JPCPrices', [
                'enabled' => true,
                'variable' => $product->is_type('variable'),
                // Authoritative list for the browser: a metal missing here must not be offered.
                'metals' => $metals,
                'labels' => $labels,
                'showLabelPrices' => get_option(self::LABEL_PRICES_OPTION, 'yes') === 'yes',
                'unavailableText' => 'δεν διατίθεται',
                // Kept for themes/snippets that read the 1.1.x keys.
                'goldHtml' => $metals['gold'] ?? '',
                'platinumHtml' => $metals['platinum'] ?? '',
                'silverHtml' => $metals['silver'] ?? '',
                'hasPlatinumPrice' => isset($metals['platinum']),
                'hasSilverPrice' => isset($metals['silver']),
                'priceSelector' => '.jpc-live-price',
                'fields' => $fields,
                // Diagnostics banner on the product page, only for shop managers.
                'debug' => current_user_can('manage_woocommerce'),
                'version' => self::VERSION,
                'images' => $images ?? ['default' => [], 'yellowGold' => []],
            ]);
        }
    }
    public static function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['product', 'edit-product'], true)) return;
        if ($screen->id === 'product') wp_enqueue_media();
        $deps = $screen->id === 'edit-product' ? ['jquery', 'inline-edit-post'] : ['jquery'];
        wp_enqueue_script('jpc-admin-configurator', plugin_dir_url(__FILE__) . 'assets/js/admin-product.js', $deps, self::VERSION, true);
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
        $jpc_product = wc_get_product(get_queried_object_id());
        $table = $jpc_product ? self::price_table($jpc_product) : [];
        $show_prices = get_option(self::LABEL_PRICES_OPTION, 'yes') === 'yes';
        $sizes = [];
        foreach ($config_fields['sizes'] as $system => $values) {
            $sizes[$system] = array_map(static function ($v) { return ['v' => $v, 'l' => self::size_label($v)]; }, $values);
        }
        ob_start(); ?>
        <div class="jewelry-configurator" data-jc-configurator="1" data-jc-has-color="<?php echo $config_fields['color'] ? '1' : '0'; ?>" data-jc-has-size="<?php echo $config_fields['size'] ? '1' : '0'; ?>" data-jc-sizes="<?php echo esc_attr(wp_json_encode($sizes)); ?>" data-jc-source="plugin-<?php echo esc_attr(self::VERSION); ?>">
          <div class="jc-grid">
            <div id="jc_metal_box" class="jc-box jc-box-full uk-card uk-card-conf uk-card-small uk-card-body uk-border-rounded">
              <h4 class="jc-box-title">Επιλογή Μετάλλου</h4>
              <div class="jc-radio-group">
                <?php foreach (self::METAL_LABELS as $metal => $label) :
                  if ($metal !== 'gold' && !isset($table[$metal])) continue; ?>
                  <label class="jc-radio-option"><input type="radio" name="jc_metal_type_ui" value="<?php echo esc_attr($metal); ?>"<?php checked($metal, 'gold'); ?>><span><?php echo esc_html($label); ?></span><?php
                    if ($show_prices) : ?><span class="jc-metal-price"><?php echo isset($table[$metal]) ? wp_kses_post(self::range_html($table[$metal])) : ''; ?></span><?php endif; ?></label>
                <?php endforeach; ?>
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
        $fields = $product ? self::category_fields($product->get_id()) : ['color'=>true,'size'=>true,'sizes'=>self::size_lists([])];
        if (!in_array($cfg['metal_type'], $product ? self::allowed_metals($product) : ['gold'], true)) return false;
        if ($fields['color'] && $cfg['metal_type']==='gold' && !in_array($cfg['gold_color'], ['λευκόχρυσο','κίτρινο χρυσό'], true)) return false;
        if (!$fields['size']) return true;
        if (!in_array($cfg['size_system'], ['eu','us'], true)) return false;
        $sizes = $product ? $fields['sizes'] : self::size_lists([]);
        return in_array($cfg['ring_size'], $sizes[$cfg['size_system']], true);
    }
    public static function validate($passed, $product_id, $quantity=1, $variation_id=0, $variations=[], $cart_item_data=[]) {
        if (!$passed || !self::matches($product_id)) return $passed;
        if (!self::valid(self::selection(), wc_get_product($variation_id ?: $product_id))) {
            if (function_exists('wc_add_notice')) wc_add_notice('Παρακαλώ συμπληρώστε τις υποχρεωτικές επιλογές του προϊόντος.', 'error');
            return false;
        }
        return $passed;
    }
    public static function save_cart_item($cart_item_data, $product_id, $variation_id=0) {
        if (!self::matches($product_id)) return $cart_item_data;
        $cfg = self::selection();
        if (!self::valid($cfg, wc_get_product($variation_id ?: $product_id))) return $cart_item_data;
        $enabled_fields = self::category_fields($product_id);
        if ($cfg['metal_type'] !== 'gold' || !$enabled_fields['color']) $cfg['gold_color']='';
        if (!$enabled_fields['size']) { $cfg['size_system']=''; $cfg['ring_size']=''; }
        $cart_item_data['jc_config']=$cfg;
        // Διαφορετικές διαμορφώσεις διαχωρίζονται κανονικά από το WooCommerce μέσω cart item data.
        return $cart_item_data;
    }
    private static function labels($cfg) {
        $rows = ['Επιλογή Μετάλλου' => self::METAL_LABELS[$cfg['metal_type']] ?? 'Χρυσός'];
        if ($cfg['metal_type']==='gold' && !empty($cfg['gold_color'])) $rows['Χρώμα Μετάλλου']=$cfg['gold_color'];
        if (!empty($cfg['size_system']) && !empty($cfg['ring_size'])) {
            $rows['Τύπος Μεγέθους']=$cfg['size_system']==='eu' ? 'Ευρωπαϊκά':'Αμερικάνικα';
            $rows['Νούμερο Δαχτυλιδιού']=self::size_label($cfg['ring_size']);
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
