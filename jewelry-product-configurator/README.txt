JEWELRY PRODUCT CONFIGURATOR 1.0.0

INSTALLATION
1. Backup website and ideally test on a staging copy first.
2. Disable old PHP snippet and old jewelry-config.js before enabling this plugin (otherwise duplicate hooks/fields can conflict).
3. Remove [jewelry_configurator] from the YOOtheme Text Element; the plugin outputs the configurator automatically inside the add-to-cart form.
4. WordPress > Plugins > Add New > Upload Plugin > choose the ZIP and activate.
5. WooCommerce > Jewelry Configurator: select product categories (defaults 38,40,42,43) and save.
6. Remove the old configurator-only CSS (lines approximately 48-178) from custom CSS to avoid duplication; preserve the other unrelated theme CSS.
7. Clear site/CDN/cache/minification caches and test selection changes, cart, checkout and order metadata.

SCOPE
- Uses original metal/color/size UX: gold/platinum, white/yellow gold, EU 47-58, US 5-13.
- On native WooCommerce product form; custom AJAX add-to-cart YOOtheme elements may require adjustment.
- Only jewelry-config.js logic is included. The separate ring-configurator.js handles different jw_* fields and depends on JWRingCfg; its related PHP was not provided and it is NOT bundled (avoid mixing two distinct configurators).
- Applies to products directly assigned to configured product categories.
- Does not add pricing variations or WooCommerce attribute taxonomy; choices are custom order item meta.
- Keeps compatibility shortcode as a fallback, but avoid using it in a YOOtheme Text element outside the cart form.

Version 1.0.2: Added placement mode setting (automatic or YOOtheme shortcode). To position above specifications, select shortcode placement and add [jewelry_configurator] into a Text element above the specifications section in the relevant product template.

Version 1.0.3: Product Data > General > Platinum Price for simple products. Standard WooCommerce active price is gold price. In cart, pricing is set server-side with before_calculate_totals. Front-end JS attempts to refresh primary YOOtheme/WooCommerce price widget; custom widget structures may require CSS selector adjustment. Use staging and test tax/discount integration.

Version 1.0.4:
- For YOOtheme custom product-price element, add CSS class jpc-live-price to the element holding the displayed price (YOOtheme Advanced > CSS Class). This is the most reliable way to target it.
- Platinum selected price is treated as regular price on the cart product instance, not a discounted sale.
- Duplicate item-data labels are suppressed if already present when our filter runs. Disable legacy jewelry configurator PHP snippets to prevent unrelated duplicates; clear and refill the test cart after upgrading.
- Other pricing extensions and special sales may require integration-specific review.

Version 1.0.8: Preserve YOOtheme Button After HTML/icons when changing live price. Only the price text node is replaced; button/link markup remains unchanged.

1.0.8: Scroll to first invalid field on add to cart; normalize duplicate configurator metadata rows at final WooCommerce item data stage.

1.0.8: Τιμή Ασημιού ανά απλό προϊόν, δυναμικές διαθέσιμες επιλογές μετάλλου, προεπιλογή Χρυσού.

1.0.9: Sync YOOtheme Grid UIkit filtering with the selected configurator metal.
In YOOtheme Grid > Advanced > CSS Class, add jpc-metal-specs. Grid items
need the localized Tags Χρυσός, Πλατίνα, Ασήμι. The Grid's filter
navigation is hidden, while its internal UIkit controls remain functional.
The filter follows the configurator at startup and on metal changes.

Version 1.0.10: Gold material display text in YOOtheme custom field can be marked with CSS class jpc-gold-material; changes only the gold color wording based on the configurator, retaining K18 etc. No product meta data is modified.

Version 1.1.0:
- WooCommerce > Jewelry Configurator: per-category optional Gold Color and Ring Size settings.
- Gold remains selected; metal options follow their per-product configured prices.
- Category settings determine rendering, client validation, server validation and cart/order metadata.
- Existing categories default to both options enabled.
- If product belongs to multiple selected categories, deepest term takes precedence, then lowest term ID.
