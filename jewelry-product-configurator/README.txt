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

Version 1.1.6:
- Fix: Platinum/Silver could be offered at the Gold price when their price field was empty.
  The price field is now the ONLY source of truth: empty, 0 or invalid = metal not offered.
- Single save path through WooCommerce CRUD; removed the extra "_available" flags and the
  direct database writes that could get out of sync with WooCommerce's object cache.
- One-time migration on first admin page load: where the old flag said "not available",
  the stale price is deleted too, so no hidden metal reappears.
- Front-end: the browser receives the list of available metals; a stale radio for a metal
  without a price is removed, and a non-gold metal is never shown with the gold price.
- Cart: alternative metal prices are read live from the product; a line whose metal is no
  longer offered is removed on cart load and blocks checkout.

Version 1.2.0:
- Price next to each metal option on the product page ("Πλατίνα — 1.250 €").
  For variable products it follows the selected variation; metals the variation
  does not offer are shown greyed out as "δεν διατίθεται".
  Setting: WooCommerce > Jewelry Configurator > Εμφάνιση τιμών.
- Shop/category listings show "Από X €" when a metal is cheaper than gold
  (e.g. silver). Not applied on the product's own page. Same settings section.
- Products list (Products > All Products): new "Πλατίνα / Ασήμι" column and
  Platinum/Silver price fields in Quick Edit (simple products).
- Variable products: each variation has its own Platinum/Silver price fields
  (Product data > Variations > pricing). Empty = that variation is not offered
  in that metal. Gold is the variation's normal price.
- Ring sizes per category: WooCommerce > Jewelry Configurator, columns
  "Νούμερα EU/US" (from – to, step). Empty = defaults (EU 47–58, US 5–13 by 1).
  Step 0.5 gives half sizes; shown as "5,5" to customers and in orders.
- Fix: broken CSS rule (missing closing brace) after the invalid ring-size style.

Version 1.2.1:
- No more "flash" of a metal the product does not offer: those options are hidden by
  CSS printed in <head>, before the page is painted, whatever outputs them.
- The plugin's configurator markup carries data-jc-source="plugin-<version>", so it can
  be told apart from markup produced by an old snippet or a theme template.

Version 1.2.2:
- Fix: category settings (e.g. "no ring size" for crosses) could be silently lost on save.
  Each category row posts 11 inputs; with many categories this exceeded PHP's
  max_input_vars (default 1000) and PHP dropped the rest. The table is now posted as a
  single JSON field.
- Fix: a product in several equally specific categories (e.g. Σταυροί + Μενταγιόν) used the
  lowest category ID. Now a field is shown only if all of them enable it; the deepest
  category still takes precedence over its parents.
- Product edit screen (General tab) shows which category settings apply to the product.

Version 1.2.3:
- Fields switched off for a category (ring size, gold colour) are hidden/removed even when
  the configurator markup does not come from this plugin (old snippet, YOOtheme template,
  cached HTML): CSS in <head> plus the server's category settings in configurator.js.
- Diagnostics for shop managers only: a red dashed box above the configurator lists
  foreign configurator markup, the separate ring configurator (ring-configurator.js /
  jw_* fields / JWRingCfg), old scripts still loaded, and size fields shown outside the
  plugin. Visitors never see it.

Version 1.2.4:
- Fix: choosing Χρυσός → Κίτρινο χρυσό replaced EVERY gallery image and thumbnail with the
  Yellow Gold photo. Now only the main product photo (its large image and its own
  thumbnail) is swapped; other gallery images (e.g. the model photo) stay as they are.
  The main photo is recognised by file name in any size, including YOOtheme's cache copies;
  if it cannot be recognised, the first slide and first thumbnail are used.
- <picture> WebP sources are handled (they kept showing the old photo), thumbnail links
  ("#") are left untouched, and the slideshow moves to the main photo when it changes.

Version 1.3.0: Wedding rings ("Βέρες")
- WooCommerce > Jewelry Configurator: each category has a "Τύπος": Κανονικό (metal
  configurator) or Βέρες. The category must also be ticked as "Ενεργός configurator".
  Ring sizes follow the category's EU/US columns.
- Settings > Βέρες: material text (default "Χρυσός 14K"), engraving max characters and
  optional engraving fee per ring, and the shop-wide colour list (name, swatch colour,
  optional 2nd colour for two-tone designs such as Ροζ - Λευκό).
- Product > "Βέρες" tab (simple products): the normal WooCommerce price is the PAIR price;
  optional men's / women's single ring prices (empty = not sold alone); tick the colours
  the design comes in, with an optional photo per colour (replaces the main photo only).
- Customer: Ζευγάρι / Ανδρική / Γυναικεία, colour, size system, one size per ring, and
  optional name engraving per ring (for a pair, one or both). Live price incl. engraving.
- Server-side validation, cart pricing, cart/order meta (Επιλογή, Υλικό, Χρώμα, Νούμερο
  ανδρικής/γυναικείας, Χάραξη ανδρικής/γυναικείας). Listings show "Από X €" when a single
  ring is cheaper than the pair.
- Shared front-end helpers moved to assets/js/jpc-common.js.

Version 1.3.1:
- Fix: an old snippet or theme registering the same [jewelry_configurator] shortcode later
  replaced this plugin's one (WordPress keeps the last), so the page showed the old
  configurator (e.g. the solitaire one on wedding rings). The plugin now takes the shortcode
  back at the end of loading.
- Admin notice + front-end note (shop managers only) naming the function, file and line of
  the code that had registered it, so it can be removed.
- New alias [jpc_configurator] that no old code can collide with.

Version 1.3.2: Wedding rings, per-ring choices
- Instead of choosing one of Ζευγάρι / Ανδρική / Γυναικεία, the customer ticks Ανδρική
  and/or Γυναικεία. Both ticked = the pair (normal WooCommerce price); one ticked = that
  ring's own price. A ring without its own price shows "μόνο σε ζευγάρι".
- Each ticked ring gets its own box with its own size and its own engraving tick + text.
  Engraving fee applies per engraved ring.
- Carts created with 1.3.0/1.3.1 (single engraving tick) keep working.

Version 1.3.6 (= 1.3.2 + switch for the old theme configurator; 1.3.3–1.3.5 withdrawn):
- WooCommerce > Jewelry Configurator > "Παλιός configurator στο theme": disables the old
  configurator found in the theme's functions.php without editing the file. Unhooks only,
  in that one file, functions named jc_… and anonymous functions that use jc_… (cart
  validation, cart item data, cart display, order meta, script loader), and dequeues
  jewelry-config.js. Other code in the file (e.g. WooCommerce notices CSS) stays. Untick to
  restore. The admin notice links to it.
- Not included: the 1.3.3 diagnostics (JavaScript check note, hook list in the notice).

Version 1.3.7:
- With the "disable old theme configurator" switch on, the managers-only note about the
  theme's [jewelry_configurator] is no longer shown on product pages (the theme still
  registers it, but it is already handled).

Version 1.3.8:
- Colour/Yellow-Gold photo swap finds the product's main photo anywhere on the page by
  file name (YOOtheme Gallery/Slideshow/Image with a dynamic source, not only the Woo
  Product Images element). Related products, upsells, header and footer are skipped.
- Fix: switching from one colour with a photo to another colour with a photo did not
  change the image (the already-swapped image was no longer recognised).

Version 1.3.9: Wedding rings, colour per ring + compact layout
- Each ring (men's / women's) has its own colour choice. Cart/order show "Χρώμα ανδρικής"
  and "Χρώμα γυναικείας". Carts created before 1.3.9 (one colour) still display correctly.
- The photo follows the colour chosen last; with one ring ticked, that ring's colour.
- Compact layout: one top box (rings + prices + pair price, size system as EU/US buttons,
  material), then one box per ring with colour (small round swatches + selected name),
  size and engraving on compact rows.

Version 1.4.0:
- Wedding colour swatches are rounded squares again (38px, 10px radius, as before).
- Ring size and engraving fields at normal size; 8px space above the size row.
- Product > Βέρες > "Φωτογραφίες συνδυασμών": optional photo for a men's colour + women's
  colour combination (e.g. men's Κίτρινο + women's Ροζ - Λευκό). Shown when the customer
  picks exactly that combination; otherwise the photo of the colour chosen last.

Version 1.4.1:
- Fix: in the product's "Βέρες" tab, the colour combination rows were laid out by
  WooCommerce's panel styles (labels stacked in the left column, second dropdown hidden).
  Each combination is now one line: Ανδρική [colour] + Γυναικεία [colour] → Φωτογραφία ✕.

Version 1.4.2:
- Wedding colour list: 9 standard colours in this order: Κίτρινο, Λευκό, Ροζ,
  Κίτρινο - Λευκό, Λευκό - Κίτρινο, Κίτρινο - Ροζ, Ροζ - Κίτρινο, Λευκό - Ροζ, Ροζ - Λευκό.
  One-time merge on upgrade: existing colours keep their key (products keep their colours
  and photos), missing ones are added, custom colours go after them.
- Settings > Χρώματα βερών: ↑ ↓ buttons to change the order.
- Product > Βέρες: link to the colour list (new colours and order are managed there).
