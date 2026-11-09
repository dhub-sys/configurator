<?php
/**
 * Out-of-stock display (optional setting, all products):
 *   - a badge on products in WooCommerce product grids (shop, categories, YOOtheme product elements),
 *   - on the product page, a visible but disabled "out of stock" button where WooCommerce
 *     would otherwise show no add-to-cart button at all.
 */
if (!defined('ABSPATH')) exit;

final class JPC_Stock {
    const OPTION = 'jpc_out_of_stock_ui';
    const LABEL_OPTION = 'jpc_out_of_stock_label';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        // Grid badge: same place WooCommerce prints its "Sale!" badge.
        add_action('woocommerce_before_shop_loop_item_title', [__CLASS__, 'loop_badge'], 9);
        // Product page: WooCommerce prints no form for an out-of-stock product; add a disabled button.
        add_action('woocommerce_simple_add_to_cart', [__CLASS__, 'disabled_button'], 31);
        add_action('woocommerce_variable_add_to_cart', [__CLASS__, 'disabled_button'], 31);
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles'], 20);
    }

    public static function register_settings() {
        register_setting('jpc_settings', self::OPTION, ['type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_yes_no'], 'default' => 'no']);
        register_setting('jpc_settings', self::LABEL_OPTION, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Εξαντλήθηκε']);
    }
    public static function sanitize_yes_no($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }
    public static function enabled() {
        return get_option(self::OPTION, 'no') === 'yes';
    }
    public static function label() {
        $label = trim((string) get_option(self::LABEL_OPTION, 'Εξαντλήθηκε'));
        return $label !== '' ? $label : 'Εξαντλήθηκε';
    }
    /** Out of stock as WooCommerce sees it (backorders count as in stock). */
    public static function is_out_of_stock($product) {
        return self::enabled() && $product && is_object($product) && method_exists($product, 'is_in_stock') && !$product->is_in_stock();
    }

    public static function loop_badge() {
        global $product;
        if (!self::is_out_of_stock($product)) return;
        echo '<span class="jpc-oos-badge">' . esc_html(self::label()) . '</span>';
    }
    public static function disabled_button() {
        global $product;
        if (!self::is_out_of_stock($product)) return;
        echo '<div class="jpc-oos-cart"><button type="button" class="single_add_to_cart_button button alt disabled jpc-oos-button" disabled aria-disabled="true">'
            . esc_html(self::label()) . '</button></div>';
    }
    public static function styles() {
        if (!self::enabled() || !function_exists('is_woocommerce')) return;
        $css = '.woocommerce ul.products li.product,.products .product{position:relative}'
            . '.jpc-oos-badge{position:absolute;top:10px;left:10px;z-index:3;padding:4px 10px;border-radius:4px;'
            . 'background:#111;color:#fff;font-size:12px;line-height:1.4;letter-spacing:.02em;pointer-events:none}'
            . '.jpc-oos-cart{margin:10px 0}'
            . '.jpc-oos-button,.jpc-oos-button:hover{opacity:.55;cursor:not-allowed!important;pointer-events:none}';
        wp_register_style('jpc-stock', false, [], JPC_Product_Configurator::VERSION);
        wp_enqueue_style('jpc-stock');
        wp_add_inline_style('jpc-stock', $css);
    }

    /** Section inside the plugin's settings form. */
    public static function settings_section() {
        echo '<h2>Εξαντλημένα προϊόντα</h2><fieldset>';
        echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="' . esc_attr(self::OPTION) . '" value="yes" ' . checked(self::enabled(), true, false) . '> '
            . 'Badge στα εξαντλημένα προϊόντα στη λίστα/grid, και ανενεργό κουμπί στη σελίδα προϊόντος αντί για «Προσθήκη στο καλάθι»</label>';
        echo '<label style="display:block;margin:8px 0">Κείμενο: <input type="text" class="regular-text" name="' . esc_attr(self::LABEL_OPTION) . '" value="' . esc_attr(self::label()) . '"></label>';
        echo '<p class="description">Ισχύει για όλα τα προϊόντα με απόθεμα «Εξαντλημένο» (όχι για «Σε προπαραγγελία»). Σε εξαντλημένο προϊόν δεν εμφανίζεται ο configurator, αφού δεν αγοράζεται.</p>';
        echo '</fieldset>';
    }
}
