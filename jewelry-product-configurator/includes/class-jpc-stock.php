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
        // YOOtheme Grid (dynamic content) does not run WooCommerce's loop hooks: badge those
        // items in the browser, from the list of out-of-stock product links.
        add_action('wp_footer', [__CLASS__, 'grid_script'], 50);
        foreach (['woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'save_post_product', 'trashed_post', 'deleted_post', 'untrashed_post'] as $hook) {
            add_action($hook, [__CLASS__, 'flush_paths']);
        }
        add_action('update_option_' . self::OPTION, [__CLASS__, 'flush_paths']);
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
            . '.el-item .jpc-oos-badge{font-weight:normal;text-transform:none}'
            . '.jpc-oos-badge{position:absolute;top:10px;left:10px;z-index:3;padding:4px 10px;border-radius:4px;'
            . 'background:#111;color:#fff;font-size:12px;line-height:1.4;letter-spacing:.02em;pointer-events:none}'
            . '.jpc-oos-cart{margin:10px 0}'
            . '.jpc-oos-button,.jpc-oos-button:hover{opacity:.55;cursor:not-allowed!important;pointer-events:none}';
        wp_register_style('jpc-stock', false, [], JPC_Product_Configurator::VERSION);
        wp_enqueue_style('jpc-stock');
        wp_add_inline_style('jpc-stock', $css);
    }

    const PATHS_TRANSIENT = 'jpc_oos_paths';
    const MAX_PATHS = 3000;
    public static function flush_paths() {
        delete_transient(self::PATHS_TRANSIENT);
    }
    /** Site-relative links of all published out-of-stock products (cached until stock changes). */
    public static function out_of_stock_paths() {
        $paths = get_transient(self::PATHS_TRANSIENT);
        if (is_array($paths)) return $paths;
        $paths = [];
        if (function_exists('wc_get_products')) {
            $ids = wc_get_products(['status' => 'publish', 'stock_status' => 'outofstock', 'limit' => self::MAX_PATHS, 'return' => 'ids']);
            foreach ($ids as $id) {
                $link = get_permalink($id);
                if ($link) $paths[] = wp_make_link_relative($link);
            }
        }
        set_transient(self::PATHS_TRANSIENT, $paths, 12 * HOUR_IN_SECONDS);
        return $paths;
    }
    public static function grid_script() {
        if (!self::enabled() || is_admin()) return;
        $paths = self::out_of_stock_paths();
        if (!$paths) return;
        $data = ['paths' => array_values($paths), 'label' => self::label()];
        ?>
<script id="jpc-oos-grid">
(function () {
  'use strict';
  var data = <?php echo wp_json_encode($data); ?>;
  function norm(href) {
    try {
      var url = new URL(href, window.location.href);
      if (url.host !== window.location.host) return '';
      return decodeURIComponent(url.pathname).replace(/\/+$/, '').toLowerCase();
    } catch (e) { return ''; }
  }
  var outOfStock = {};
  data.paths.forEach(function (path) { var key = norm(path); if (key) outOfStock[key] = true; });
  // Product boxes only: never menus, breadcrumbs, header/footer or links inside page text.
  var SKIP = 'header, footer, nav, .tm-header, .tm-footer, .tm-toolbar, .uk-navbar-container, .woocommerce-breadcrumb, .uk-breadcrumb, .jewelry-configurator, .jpc-wedding';
  var ITEM = '.el-item, li.product, .uk-card';
  function badge(item) {
    if (item.querySelector('.jpc-oos-badge')) return;
    var img = item.querySelector('img');
    var host = img ? img.parentElement : item;
    if (host && host.tagName === 'PICTURE') host = host.parentElement;
    if (!host || !item.contains(host)) host = item;
    if (window.getComputedStyle(host).position === 'static') host.style.position = 'relative';
    var el = document.createElement('span');
    el.className = 'jpc-oos-badge';
    el.textContent = data.label;
    host.insertBefore(el, host.firstChild);
  }
  function run() {
    document.querySelectorAll('a[href]').forEach(function (a) {
      if (!outOfStock[norm(a.getAttribute('href'))] || a.closest(SKIP)) return;
      var item = a.closest(ITEM);
      if (item) badge(item);
    });
  }
  run();
  // Grids filtered or loaded later (UIkit filter, "load more", AJAX).
  var timer = null;
  if (window.MutationObserver) {
    new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(run, 150); })
      .observe(document.body, {childList: true, subtree: true});
  }
})();
</script>
        <?php
    }

    /** Section inside the plugin's settings form. */
    public static function settings_section() {
        echo '<h2>Εξαντλημένα προϊόντα</h2><fieldset>';
        echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="' . esc_attr(self::OPTION) . '" value="yes" ' . checked(self::enabled(), true, false) . '> '
            . 'Badge στα εξαντλημένα προϊόντα στη λίστα/grid (και στο Grid του YOOtheme), και ανενεργό κουμπί στη σελίδα προϊόντος αντί για «Προσθήκη στο καλάθι»</label>';
        echo '<label style="display:block;margin:8px 0">Κείμενο: <input type="text" class="regular-text" name="' . esc_attr(self::LABEL_OPTION) . '" value="' . esc_attr(self::label()) . '"></label>';
        echo '<p class="description">Ισχύει για όλα τα προϊόντα με απόθεμα «Εξαντλημένο» (όχι για «Σε προπαραγγελία»). Σε εξαντλημένο προϊόν δεν εμφανίζεται ο configurator, αφού δεν αγοράζεται.</p>';
        echo '</fieldset>';
    }
}
