<?php
/**
 * Wedding rings ("Βέρες") configurator.
 *
 * Used for product categories whose type is set to "Βέρες" in
 * WooCommerce > Jewelry Configurator. Per product:
 *   - pair price  = the normal WooCommerce price,
 *   - men's / women's single ring price = optional fields (empty = not sold alone),
 *   - available colours (from a shop-wide list) with an optional photo per colour.
 * The customer picks pair / men's / women's ring, colour, ring size(s) and optional
 * name engraving per ring.
 */
if (!defined('ABSPATH')) exit;

final class JPC_Wedding {
    const MODE = 'wedding';
    const COLORS_OPTION = 'jpc_wedding_colors';
    const MATERIAL_OPTION = 'jpc_wedding_material';
    const ENGRAVE_MAX_OPTION = 'jpc_wedding_engrave_max';
    const ENGRAVE_FEE_OPTION = 'jpc_wedding_engrave_fee';

    const PRICE_KEYS = ['men' => '_jpc_w_price_men', 'women' => '_jpc_w_price_women'];
    const PRODUCT_COLORS_KEY = '_jpc_w_colors';
    const PRODUCT_COLOR_IMAGES_KEY = '_jpc_w_color_images';

    const OPTION_LABELS = ['pair' => 'Ζευγάρι βερών (ανδρική + γυναικεία)', 'men' => 'Ανδρική βέρα', 'women' => 'Γυναικεία βέρα'];
    const RING_LABELS = ['men' => 'ανδρικής', 'women' => 'γυναικείας'];

    public static function init() {
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'product_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'product_panel']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */
    public static function register_settings() {
        register_setting('jpc_settings', self::COLORS_OPTION, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize_colors'], 'default' => self::default_colors()]);
        register_setting('jpc_settings', self::MATERIAL_OPTION, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Χρυσός 14K']);
        register_setting('jpc_settings', self::ENGRAVE_MAX_OPTION, ['type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_max'], 'default' => 20]);
        register_setting('jpc_settings', self::ENGRAVE_FEE_OPTION, ['type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_fee'], 'default' => '']);
    }
    public static function default_colors() {
        return [
            ['key' => 'yellow', 'name' => 'Κίτρινο', 'c1' => '#d9bb58', 'c2' => ''],
            ['key' => 'white', 'name' => 'Λευκό', 'c1' => '#e3e3e3', 'c2' => ''],
            ['key' => 'rose', 'name' => 'Ροζ', 'c1' => '#e0a58c', 'c2' => ''],
            ['key' => 'rose-white', 'name' => 'Ροζ - Λευκό', 'c1' => '#e0a58c', 'c2' => '#e3e3e3'],
            ['key' => 'yellow-white', 'name' => 'Κίτρινο - Λευκό', 'c1' => '#d9bb58', 'c2' => '#e3e3e3'],
        ];
    }
    /** Shop-wide colour list: [['key','name','c1','c2'], ...]. */
    public static function colors() {
        $colors = get_option(self::COLORS_OPTION, null);
        if (!is_array($colors)) return self::default_colors();
        return $colors;
    }
    public static function color($key) {
        foreach (self::colors() as $color) if ($color['key'] === $key) return $color;
        return null;
    }
    public static function sanitize_colors($value) {
        $result = [];
        $keys = [];
        if (!is_array($value)) return $result;
        foreach (array_values($value) as $i => $row) {
            if (!is_array($row)) continue;
            $name = sanitize_text_field($row['name'] ?? '');
            if ($name === '') continue;
            $key = sanitize_key($row['key'] ?? '');
            // Keys are referenced by products, so an existing key never changes.
            if ($key === '' || isset($keys[$key])) $key = 'c' . substr(md5($name . '|' . $i . '|' . microtime()), 0, 8);
            $keys[$key] = true;
            $c1 = sanitize_hex_color($row['c1'] ?? '') ?: '#cccccc';
            $c2 = !empty($row['two']) ? (sanitize_hex_color($row['c2'] ?? '') ?: '') : '';
            $result[] = ['key' => $key, 'name' => $name, 'c1' => $c1, 'c2' => $c2];
        }
        return $result;
    }
    public static function sanitize_max($value) {
        $value = absint($value);
        return $value > 0 ? min($value, 100) : 20;
    }
    public static function sanitize_fee($value) {
        $value = trim(str_replace(',', '.', (string) $value));
        return is_numeric($value) && (float) $value > 0 ? wc_format_decimal($value) : '';
    }
    public static function engrave_max() {
        return self::sanitize_max(get_option(self::ENGRAVE_MAX_OPTION, 20));
    }
    public static function engrave_fee() {
        $fee = get_option(self::ENGRAVE_FEE_OPTION, '');
        return is_numeric($fee) && (float) $fee > 0 ? (float) $fee : 0.0;
    }
    public static function material() {
        return (string) get_option(self::MATERIAL_OPTION, 'Χρυσός 14K');
    }
    /** Section printed inside the plugin's settings form. */
    public static function settings_section() {
        $colors = self::colors();
        echo '<h2>Βέρες</h2>';
        echo '<p>Ισχύουν για κατηγορίες με Τύπο «Βέρες» στον πίνακα παραπάνω. Σε κάθε προϊόν, στην καρτέλα «Βέρες», ορίζετε τιμές ανδρικής/γυναικείας και ποια χρώματα διαθέτει.</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="jpc_w_material">Υλικό</label></th><td><input type="text" id="jpc_w_material" class="regular-text" name="' . esc_attr(self::MATERIAL_OPTION) . '" value="' . esc_attr(self::material()) . '"><p class="description">Εμφανίζεται στον πελάτη και στην παραγγελία (π.χ. «Χρυσός 14K»).</p></td></tr>';
        echo '<tr><th scope="row"><label for="jpc_w_max">Χάραξη: μέγιστοι χαρακτήρες</label></th><td><input type="number" min="1" max="100" id="jpc_w_max" name="' . esc_attr(self::ENGRAVE_MAX_OPTION) . '" value="' . esc_attr(self::engrave_max()) . '" style="width:80px"></td></tr>';
        echo '<tr><th scope="row"><label for="jpc_w_fee">Χάραξη: χρέωση ανά βέρα (' . esc_html(get_woocommerce_currency_symbol()) . ')</label></th><td><input type="text" id="jpc_w_fee" name="' . esc_attr(self::ENGRAVE_FEE_OPTION) . '" value="' . esc_attr(get_option(self::ENGRAVE_FEE_OPTION, '')) . '" placeholder="Δωρεάν" style="width:100px"><p class="description">Κενό = δωρεάν χάραξη.</p></td></tr>';
        echo '</tbody></table>';
        echo '<h3>Χρώματα βερών</h3><p class="description">Τα χρώματα που μπορείτε να ενεργοποιήσετε σε κάθε προϊόν. Για δίχρωμα σχέδια τσεκάρετε «Δίχρωμο» και διαλέξτε το 2ο χρώμα του δείγματος.</p>';
        echo '<table class="widefat striped" id="jpc_w_colors" style="max-width:720px"><thead><tr><th>Όνομα</th><th>Χρώμα</th><th>Δίχρωμο</th><th>2ο χρώμα</th><th></th></tr></thead><tbody>';
        $base = esc_attr(self::COLORS_OPTION);
        $row = static function ($i, $c) use ($base) {
            $two = !empty($c['c2']);
            return '<tr class="jpc-w-color">'
                . '<td><input type="hidden" name="' . $base . '[' . $i . '][key]" value="' . esc_attr($c['key']) . '"><input type="text" name="' . $base . '[' . $i . '][name]" value="' . esc_attr($c['name']) . '" placeholder="π.χ. Ροζ - Λευκό"></td>'
                . '<td><input type="color" name="' . $base . '[' . $i . '][c1]" value="' . esc_attr($c['c1'] ?: '#cccccc') . '"></td>'
                . '<td><input type="checkbox" name="' . $base . '[' . $i . '][two]" value="1" ' . checked($two, true, false) . '></td>'
                . '<td><input type="color" name="' . $base . '[' . $i . '][c2]" value="' . esc_attr($c['c2'] ?: '#e3e3e3') . '"></td>'
                . '<td><button type="button" class="button-link jpc-w-remove" aria-label="Διαγραφή">✕</button></td></tr>';
        };
        foreach ($colors as $i => $c) echo $row($i, $c); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="jpc_w_add_color">+ Προσθήκη χρώματος</button></p>';
        // Keeps an empty list saveable.
        echo '<input type="hidden" name="' . $base . '[__empty]" value="">';
        $template = $row('__i__', ['key' => '', 'name' => '', 'c1' => '#cccccc', 'c2' => '']);
        ?>
        <script>
        (function () {
          var table = document.querySelector('#jpc_w_colors tbody');
          var template = <?php echo wp_json_encode($template); ?>;
          var next = <?php echo (int) count($colors); ?> + 1000;
          document.getElementById('jpc_w_add_color').addEventListener('click', function () {
            table.insertAdjacentHTML('beforeend', template.replace(/__i__/g, String(next++)));
          });
          table.addEventListener('click', function (e) {
            if (e.target.classList.contains('jpc-w-remove')) {
              if (window.confirm('Διαγραφή χρώματος; Θα αφαιρεθεί και από τα προϊόντα που το έχουν.')) e.target.closest('tr').remove();
            }
          });
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Product admin: "Βέρες" tab
     * ------------------------------------------------------------------ */
    public static function product_tab($tabs) {
        $tabs['jpc_wedding'] = ['label' => 'Βέρες', 'target' => 'jpc_wedding_data', 'class' => ['show_if_simple'], 'priority' => 25];
        return $tabs;
    }
    public static function product_panel() {
        global $product_object;
        $product = $product_object;
        echo '<div id="jpc_wedding_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group"><p class="form-field"><span class="description" style="margin-left:0">Ισχύει μόνο για προϊόντα σε κατηγορία με Τύπο «Βέρες». <strong>Η κανονική τιμή του προϊόντος (καρτέλα Γενικά) είναι η τιμή του ζευγαριού.</strong></span></p>';
        foreach (self::PRICE_KEYS as $ring => $key) {
            woocommerce_wp_text_input([
                'id' => $key,
                'label' => 'Τιμή μόνο ' . self::RING_LABELS[$ring] . ' (' . get_woocommerce_currency_symbol() . ')',
                'placeholder' => 'Δεν πωλείται μόνη',
                'desc_tip' => true,
                'description' => 'Κενό = η ' . ($ring === 'men' ? 'ανδρική' : 'γυναικεία') . ' βέρα δεν πωλείται μόνη της, μόνο στο ζευγάρι.',
                'data_type' => 'price',
                'value' => $product ? $product->get_meta($key, true, 'edit') : '',
            ]);
        }
        echo '</div><div class="options_group"><p class="form-field"><label>Διαθέσιμα χρώματα</label><span class="description" style="display:block">Τσεκάρετε τα χρώματα του σχεδίου. Προαιρετικά, φωτογραφία ανά χρώμα: εμφανίζεται στη θέση της βασικής φωτογραφίας όταν ο πελάτης το επιλέγει. Κανένα χρώμα = δεν εμφανίζεται επιλογή χρώματος.</span></p>';
        $selected = $product ? (array) $product->get_meta(self::PRODUCT_COLORS_KEY, true, 'edit') : [];
        $images = $product ? (array) $product->get_meta(self::PRODUCT_COLOR_IMAGES_KEY, true, 'edit') : [];
        foreach (self::colors() as $color) {
            $key = $color['key'];
            $image_id = isset($images[$key]) ? (int) $images[$key] : 0;
            $preview = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
            $field_id = 'jpc_w_img_' . $key;
            echo '<p class="form-field"><label><input type="checkbox" name="jpc_w_colors[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, $selected, true), true, false) . '> '
                . '<span style="display:inline-block;width:14px;height:14px;border-radius:3px;vertical-align:middle;background:' . esc_attr(self::swatch_css($color)) . '"></span> ' . esc_html($color['name']) . '</label>';
            echo '<input type="hidden" id="' . esc_attr($field_id) . '" name="jpc_w_images[' . esc_attr($key) . ']" value="' . esc_attr($image_id ?: '') . '">';
            echo '<button type="button" class="button jpc-media-select" data-target="#' . esc_attr($field_id) . '">Φωτογραφία</button> ';
            echo '<button type="button" class="button jpc-media-clear" data-target="#' . esc_attr($field_id) . '"' . ($image_id ? '' : ' style="display:none;"') . '>Καθαρισμός</button>';
            echo '<span class="jpc-media-preview" style="display:inline-block;margin-left:8px;vertical-align:middle">' . ($preview ? '<img src="' . esc_url($preview) . '" alt="" style="max-width:40px;height:auto;border:1px solid #ddd">' : '') . '</span></p>';
        }
        echo '</div></div>';
    }
    public static function save_product($product) {
        if (!$product || !$product->is_type('simple')) return;
        foreach (self::PRICE_KEYS as $key) {
            if (!array_key_exists($key, $_POST) || !is_scalar($_POST[$key])) continue;
            $raw = trim((string) wc_clean(wp_unslash($_POST[$key])));
            $decimal = $raw === '' ? '' : wc_format_decimal($raw);
            $product->delete_meta_data($key);
            if ($decimal !== '' && is_numeric($decimal) && (float) $decimal > 0) $product->add_meta_data($key, $decimal, true);
        }
        // Only touch colours when the tab was on the page (it always is on the edit screen).
        if (!isset($_POST['jpc_w_images']) && !isset($_POST['jpc_w_colors'])) return;
        $valid = array_column(self::colors(), 'key');
        $chosen = isset($_POST['jpc_w_colors']) && is_array($_POST['jpc_w_colors'])
            ? array_values(array_intersect($valid, array_map('sanitize_key', wp_unslash($_POST['jpc_w_colors'])))) : [];
        $images = [];
        if (isset($_POST['jpc_w_images']) && is_array($_POST['jpc_w_images'])) {
            foreach (wp_unslash($_POST['jpc_w_images']) as $key => $id) {
                $key = sanitize_key($key);
                if (in_array($key, $valid, true) && absint($id)) $images[$key] = absint($id);
            }
        }
        $product->update_meta_data(self::PRODUCT_COLORS_KEY, $chosen);
        $product->update_meta_data(self::PRODUCT_COLOR_IMAGES_KEY, $images);
    }

    /* ------------------------------------------------------------------
     * Product data
     * ------------------------------------------------------------------ */
    public static function is_wedding($product_id) {
        if (!$product_id || !JPC_Product_Configurator::matches($product_id)) return false;
        $fields = JPC_Product_Configurator::resolve_category_fields($product_id);
        return ($fields['mode'] ?? '') === self::MODE;
    }
    /** Raw (not display) prices: ['pair' => 1500.0, 'men' => 800.0, ...]; unavailable options absent. */
    public static function prices($product) {
        $prices = [];
        if (!$product || !$product->is_type('simple')) return $prices;
        $pair = $product->get_price('edit');
        if ($pair !== '' && is_numeric($pair) && (float) $pair > 0) $prices['pair'] = (float) $pair;
        foreach (self::PRICE_KEYS as $ring => $key) {
            $value = $product->get_meta($key, true, 'edit');
            if (is_numeric($value) && (float) $value > 0) $prices[$ring] = (float) $value;
        }
        return $prices;
    }
    /** Colours this product offers, in the shop-wide order. */
    public static function product_colors($product) {
        if (!$product) return [];
        $chosen = (array) $product->get_meta(self::PRODUCT_COLORS_KEY, true);
        return array_values(array_filter(self::colors(), static function ($c) use ($chosen) { return in_array($c['key'], $chosen, true); }));
    }
    public static function swatch_css($color) {
        return !empty($color['c2'])
            ? 'linear-gradient(135deg, ' . $color['c1'] . ' 0 50%, ' . $color['c2'] . ' 50% 100%)'
            : $color['c1'];
    }
    /** Rings an option consists of. */
    public static function rings($option) {
        return $option === 'pair' ? ['men', 'women'] : [$option];
    }
    /** Lowest display price (for "Από X €" in listings), or null. */
    public static function lowest_display_price($product) {
        $prices = self::prices($product);
        return $prices ? (float) wc_get_price_to_display($product, ['price' => min($prices)]) : null;
    }

    /* ------------------------------------------------------------------
     * Front end
     * ------------------------------------------------------------------ */
    public static function render($product) {
        $prices = self::prices($product);
        $colors = self::product_colors($product);
        $fields = JPC_Product_Configurator::category_fields($product->get_id());
        $sizes = [];
        foreach ($fields['sizes'] as $system => $values) {
            $sizes[$system] = array_map(static function ($v) { return ['v' => $v, 'l' => JPC_Product_Configurator::size_label($v)]; }, $values);
        }
        $fee = self::engrave_fee();
        $max = self::engrave_max();
        $box = 'jc-box uk-card uk-card-conf uk-card-small uk-card-body uk-border-rounded';
        $price_html = static function ($amount) use ($product) { return wp_kses_post(wc_price(wc_get_price_to_display($product, ['price' => $amount]))); };
        // Both rings ticked = the pair; a ring can be ticked alone only if it has its own price.
        $default = isset($prices['pair']) ? ['men', 'women'] : array_values(array_intersect(['men', 'women'], array_keys($prices)));
        ob_start(); ?>
        <div class="jpc-wedding" data-jc-wedding="1" data-jc-source="plugin-<?php echo esc_attr(JPC_Product_Configurator::VERSION); ?>" data-jc-sizes="<?php echo esc_attr(wp_json_encode($sizes)); ?>">
          <div class="jc-grid">
            <div id="jcw_option_box" class="<?php echo esc_attr($box); ?> jc-box-full">
              <h4 class="jc-box-title">Επιλογή βέρας</h4>
              <div class="jc-radio-group">
                <?php foreach (['men' => 'Ανδρική', 'women' => 'Γυναικεία'] as $ring => $label) :
                  $alone = isset($prices[$ring]);
                  if (!$alone && !isset($prices['pair'])) continue; ?>
                  <label class="jc-radio-option"><input type="checkbox" name="jcw_ring_ui" value="<?php echo esc_attr($ring); ?>"<?php checked(in_array($ring, $default, true)); ?>><span><?php echo esc_html($label); ?></span><span class="jc-metal-price"><?php echo $alone ? $price_html($prices[$ring]) : 'μόνο σε ζευγάρι'; ?></span></label>
                <?php endforeach; ?>
              </div>
              <?php if (isset($prices['pair'])) : ?><p class="jcw-pair-note">Και οι δύο (ζευγάρι): <strong><?php echo $price_html($prices['pair']); ?></strong></p><?php endif; ?>
              <p class="jcw-option-msg" hidden></p>
              <?php if (self::material() !== '') : ?><p class="jcw-material">Υλικό: <strong><?php echo esc_html(self::material()); ?></strong></p><?php endif; ?>
            </div>
            <?php if ($colors) : ?>
            <div id="jcw_color_box" class="<?php echo esc_attr($box); ?> jc-box-full">
              <h4 class="jc-box-title">Χρώμα</h4>
              <div class="jc-swatches jcw-swatches" role="radiogroup" aria-label="Χρώμα">
                <?php foreach ($colors as $color) : ?>
                  <label class="jc-swatch jcw-swatch" title="<?php echo esc_attr($color['name']); ?>"><input type="radio" name="jcw_color_ui" value="<?php echo esc_attr($color['key']); ?>"<?php checked(count($colors) === 1); ?>><span class="jc-swatch-tile" aria-hidden="true" style="background:<?php echo esc_attr(self::swatch_css($color)); ?>"></span><span class="jcw-swatch-name"><?php echo esc_html($color['name']); ?></span></label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
            <div id="jcw_system_box" class="<?php echo esc_attr($box); ?> jc-box-full">
              <h4 class="jc-box-title">Τύπος Μεγέθους</h4>
              <div class="jc-radio-group"><label class="jc-radio-option"><input type="radio" name="jcw_system_ui" value="eu"><span>Ευρωπαϊκά</span></label><label class="jc-radio-option"><input type="radio" name="jcw_system_ui" value="us"><span>Αμερικάνικα</span></label></div>
            </div>
            <?php foreach (['men' => 'Ανδρική βέρα', 'women' => 'Γυναικεία βέρα'] as $ring => $title) : ?>
            <div id="jcw_ring_<?php echo esc_attr($ring); ?>" class="<?php echo esc_attr($box); ?> jc-box-half jcw-ring" data-ring="<?php echo esc_attr($ring); ?>">
              <h4 class="jc-box-title"><?php echo esc_html($title); ?></h4>
              <label class="jcw-size"><span>Νούμερο</span><select id="jcw_size_<?php echo esc_attr($ring); ?>" class="jc-control uk-select"><option value="">Επιλέξτε νούμερο…</option></select></label>
              <label class="jc-radio-option jcw-engrave-toggle"><input type="checkbox" id="jcw_engrave_<?php echo esc_attr($ring); ?>"><span>Χάραξη ονόματος<?php if ($fee > 0) echo ' <small>(+' . $price_html($fee) . ')</small>'; ?></span></label>
              <label class="jcw-name" hidden><span>Κείμενο χάραξης</span><input type="text" id="jcw_name_<?php echo esc_attr($ring); ?>" class="jc-control uk-input" maxlength="<?php echo (int) $max; ?>" autocomplete="off" placeholder="π.χ. όνομα ή ημερομηνία"><small class="jcw-counter">0/<?php echo (int) $max; ?></small></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php foreach (['jcw_enabled' => '1', 'jcw_option' => '', 'jcw_color' => '', 'jcw_size_system' => '', 'jcw_size_men' => '', 'jcw_size_women' => '', 'jcw_engrave_men' => '', 'jcw_engrave_women' => '', 'jcw_name_men' => '', 'jcw_name_women' => ''] as $name => $value) : ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
        <?php endforeach;
        return ob_get_clean();
    }
    /** Data for wedding.js. */
    public static function script_data($product) {
        $display = [];
        foreach (self::prices($product) as $option => $price) $display[$option] = (float) wc_get_price_to_display($product, ['price' => $price]);
        $images = (array) $product->get_meta(self::PRODUCT_COLOR_IMAGES_KEY, true);
        $color_images = [];
        foreach (self::product_colors($product) as $color) {
            if (!empty($images[$color['key']])) {
                $data = JPC_Product_Configurator::attachment_image_data((int) $images[$color['key']]);
                if ($data) $color_images[$color['key']] = $data;
            }
        }
        return [
            'prices' => $display,
            'engraveFee' => self::engrave_fee() > 0 ? (float) wc_get_price_to_display($product, ['price' => self::engrave_fee()]) : 0,
            'engraveMax' => self::engrave_max(),
            'hasColors' => (bool) self::product_colors($product),
            'colorImages' => $color_images,
            'mainImage' => JPC_Product_Configurator::attachment_image_data((int) $product->get_image_id(), true),
            'priceSelector' => '.jpc-live-price',
            'format' => [
                'decimals' => wc_get_price_decimals(),
                'decimalSep' => wc_get_price_decimal_separator(),
                'thousandSep' => wc_get_price_thousand_separator(),
                'symbol' => html_entity_decode(get_woocommerce_currency_symbol()),
                'pattern' => html_entity_decode(get_woocommerce_price_format()),
            ],
        ];
    }

    /* ------------------------------------------------------------------
     * Validation, cart, order
     * ------------------------------------------------------------------ */
    private static function posted($key) {
        return isset($_POST[$key]) && is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }
    public static function selection() {
        return ['option' => self::posted('jcw_option'), 'color' => sanitize_key(self::posted('jcw_color')), 'size_system' => self::posted('jcw_size_system'),
            'size_men' => self::posted('jcw_size_men'), 'size_women' => self::posted('jcw_size_women'),
            'engrave_men' => self::posted('jcw_engrave_men') === '1', 'engrave_women' => self::posted('jcw_engrave_women') === '1',
            'name_men' => self::posted('jcw_name_men'), 'name_women' => self::posted('jcw_name_women')];
    }
    /** Engraving wanted on a ring (also reads carts saved by 1.3.0, which had one "engrave" flag). */
    private static function engraves($cfg, $ring) {
        return array_key_exists('engrave_' . $ring, $cfg) ? !empty($cfg['engrave_' . $ring]) : !empty($cfg['engrave']);
    }
    /** Returns '' when valid, otherwise a message for the customer. */
    public static function error($cfg, $product) {
        $prices = self::prices($product);
        if (!in_array($cfg['option'], ['pair', 'men', 'women'], true)) return 'Επιλέξτε ανδρική, γυναικεία ή και τις δύο βέρες.';
        if (!isset($prices[$cfg['option']])) {
            return $cfg['option'] === 'pair' ? 'Το ζευγάρι δεν διατίθεται.' : 'Η ' . ($cfg['option'] === 'men' ? 'ανδρική' : 'γυναικεία') . ' βέρα πωλείται μόνο σε ζευγάρι.';
        }
        $colors = array_column(self::product_colors($product), 'key');
        if ($colors && !in_array($cfg['color'], $colors, true)) return 'Επιλέξτε χρώμα.';
        $sizes = JPC_Product_Configurator::category_fields($product->get_id())['sizes'];
        if (!isset($sizes[$cfg['size_system']])) return 'Επιλέξτε τύπο μεγέθους.';
        foreach (self::rings($cfg['option']) as $ring) {
            if (!in_array($cfg['size_' . $ring], $sizes[$cfg['size_system']], true)) return 'Επιλέξτε νούμερο ' . self::RING_LABELS[$ring] . '.';
            if (!self::engraves($cfg, $ring)) continue;
            $name = $cfg['name_' . $ring];
            if ($name === '') return 'Γράψτε το κείμενο της χάραξης ' . self::RING_LABELS[$ring] . ' ή ξετσεκάρετε τη χάραξη.';
            if (mb_strlen($name) > self::engrave_max()) return 'Η χάραξη μπορεί να έχει έως ' . self::engrave_max() . ' χαρακτήρες.';
        }
        return '';
    }
    /** Keep only what the chosen rings use. */
    public static function normalize($cfg) {
        $rings = self::rings($cfg['option']);
        foreach (['men', 'women'] as $ring) {
            $used = in_array($ring, $rings, true);
            $cfg['engrave_' . $ring] = $used && self::engraves($cfg, $ring) && $cfg['name_' . $ring] !== '';
            if (!$used) $cfg['size_' . $ring] = '';
            if (!$cfg['engrave_' . $ring]) $cfg['name_' . $ring] = '';
        }
        unset($cfg['engrave']);
        return $cfg;
    }
    public static function engraved_count($cfg) {
        $count = 0;
        foreach (['men', 'women'] as $ring) if (self::engraves($cfg, $ring) && ($cfg['name_' . $ring] ?? '') !== '') $count++;
        return $count;
    }
    /** Final unit price for a cart line, or null if the option is no longer sold. */
    public static function cart_price($product_id, $cfg) {
        $product = wc_get_product($product_id); // fresh object: the cart's copy has its price changed
        $prices = self::prices($product);
        if (!isset($prices[$cfg['option']])) return null;
        return $prices[$cfg['option']] + self::engrave_fee() * self::engraved_count($cfg);
    }
    public static function labels($cfg) {
        $rows = ['Επιλογή' => self::OPTION_LABELS[$cfg['option']] ?? ''];
        if (self::material() !== '') $rows['Υλικό'] = self::material();
        if ($cfg['color'] !== '' && ($color = self::color($cfg['color']))) $rows['Χρώμα'] = $color['name'];
        if ($cfg['size_system'] !== '') $rows['Τύπος Μεγέθους'] = $cfg['size_system'] === 'eu' ? 'Ευρωπαϊκά' : 'Αμερικάνικα';
        foreach (['men', 'women'] as $ring) {
            if ($cfg['size_' . $ring] !== '') $rows['Νούμερο ' . self::RING_LABELS[$ring]] = JPC_Product_Configurator::size_label($cfg['size_' . $ring]);
        }
        foreach (['men', 'women'] as $ring) {
            if (self::engraves($cfg, $ring) && $cfg['name_' . $ring] !== '') $rows['Χάραξη ' . self::RING_LABELS[$ring]] = '«' . $cfg['name_' . $ring] . '»';
        }
        return $rows;
    }
}
