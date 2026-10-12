/* Jewelry Product Configurator v1.1.0 - UI independent of YOOtheme form position. */
jQuery(function ($) {
  'use strict';
  const $root = $('.jewelry-configurator[data-jc-configurator="1"]').first();
  if (!$root.length) return;

  const hasColor = $root.attr('data-jc-has-color') !== '0';
  const hasSize = $root.attr('data-jc-has-size') !== '0';
  const euSizes = Array.from({length: 12}, (_, i) => String(47 + i));
  const usSizes = Array.from({length: 9}, (_, i) => String(5 + i));
  const fields = ['jc_metal_type', 'jc_gold_color', 'jc_size_system', 'jc_ring_size'];
  const selected = name => $root.find('input[name="' + name + '"]:checked').val() || '';
  const currentSize = () => String($root.find('#jc_ring_size').val() || '');

  function showColor() {
    const gold = hasColor && selected('jc_metal_type_ui') === 'gold';
    $root.find('#jc_color_box').prop('hidden', !gold).toggle(gold);
    $root.find('#jc_metal_box').toggleClass('jc-box-full', !gold).toggleClass('jc-box-half', gold);
    if (!gold) $root.find('input[name="jc_gold_color_ui"]').prop('checked', false);
  }

  function populateSizes() {
    if (!hasSize) return;
    const previous = currentSize();
    const system = selected('jc_size_system_ui');
    const options = system === 'eu' ? euSizes : system === 'us' ? usSizes : [];
    const $select = $root.find('#jc_ring_size');
    $select.empty().append($('<option>', {value: '', text: 'Επιλέξτε νούμερο…'}));
    options.forEach(n => $select.append($('<option>', {value: n, text: n})));
    $select.val(options.includes(previous) ? previous : '');
  }

  function values() {
    const metal = selected('jc_metal_type_ui');
    return {
      jc_metal_type: metal,
      jc_gold_color: hasColor && metal === 'gold' ? selected('jc_gold_color_ui') : '',
      jc_size_system: hasSize ? selected('jc_size_system_ui') : '',
      jc_ring_size: hasSize ? currentSize() : ''
    };
  }

  function syncFields() {
    const vals = values();
    // Handle normal WooCommerce forms as well as YOOtheme layouts where
    // the configurator is not a descendant of form.cart.
    $('form.cart').each(function () {
      const $form = $(this);
      let $enabled = $form.find('input[name="jc_configurator_enabled"]');
      if (!$enabled.length) $enabled = $('<input>', {type: 'hidden', name: 'jc_configurator_enabled'}).appendTo($form);
      $enabled.val('1');
      fields.forEach(key => {
        let $input = $form.find('input[type="hidden"][name="' + key + '"]');
        if (!$input.length) $input = $('<input>', {type: 'hidden', name: key}).appendTo($form);
        $input.val(vals[key]);
      });
    });
  }

  // A YOOtheme custom price element can be targeted reliably by assigning
  // CSS class `jpc-live-price` to the price element in the builder.
  // Common native WooCommerce price widgets also work without that class.
  const priceInfo = window.JPCPrices || null;
  // Save the original price text nodes, never replace the whole button HTML.
  // YOOtheme may append icons/SVGs to the button's After field.
  const originalPriceTexts = new WeakMap();
  // YOOtheme sometimes puts the currency sign in a separate text node, or
  // appends an icon after the price. Match the amount without requiring "€".
  const amountPattern = /(?:\d{1,3}(?:[.\s\u00a0\u202f]\d{3})+|\d+)(?:[,.]\d{1,2})?/;

  function findPriceTextNode(element) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      if (node.parentElement && node.parentElement.closest('svg,script,style,[uk-icon],.uk-icon')) continue;
      if (amountPattern.test(node.nodeValue || '')) return node;
    }
    return null;
  }

  function priceAmountFromHtml(html) {
    const text = $('<div>').html(html).text().trim();
    const match = text.match(amountPattern);
    return match ? match[0] : '';
  }
  const priceSelectors = [
    (priceInfo && priceInfo.priceSelector) || '.jpc-live-price',
    '.tm-element-woo-price .price',
    '.tm-element-woo-price',
    '.woocommerce div.product .summary > p.price',
    '.woocommerce div.product .summary > span.price',
    '.single-product .summary .price'
  ];

  function locatePrices() {
    if (!priceInfo || !priceInfo.enabled) return $();
    // Prefer the explicitly marked element, including if it lives outside a
    // standard .product wrapper in a YOOtheme template.
    for (const selector of priceSelectors) {
      const $matches = $(selector).filter(function () { return !$(this).closest('.jewelry-configurator').length; });
      if ($matches.length) return $matches;
    }
    return $();
  }

  function updatePrice() {
    if (!priceInfo || !priceInfo.enabled) return;
    const metal = selected('jc_metal_type_ui');
    const alternativeHtml = metal === 'platinum' ? priceInfo.platinumHtml : (metal === 'silver' ? priceInfo.silverHtml : '');
    const $targets = locatePrices();
    $targets.each(function () {
      // Only replace the text node containing the price; leave the existing
      // YOOtheme button/link, After HTML, UK icons and SVG nodes untouched.
      const node = findPriceTextNode(this);
      if (!node) return;
      if (!originalPriceTexts.has(node)) {
        const originalText = node.nodeValue || '';
        const match = originalText.match(amountPattern);
        if (!match) return;
        originalPriceTexts.set(node, {originalText, originalPrice: match[0]});
      }
      const state = originalPriceTexts.get(node);
      const next = alternativeHtml
        ? state.originalText.replace(state.originalPrice, priceAmountFromHtml(alternativeHtml))
        : state.originalText;
      if (node.nodeValue !== next) node.nodeValue = next;
    });
  }

  // Synchronize the YOOtheme/UIkit Grid filter with the selected metal.
  // Only elements deliberately marked with `jpc-metal-specs` are affected.
  const filterLabels = {gold: 'Χρυσός', platinum: 'Πλατίνα', silver: 'Ασήμι'};
  function normalizeFilterLabel(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
  }
  function syncMetalSpecifications() {
    const label = filterLabels[selected('jc_metal_type_ui')];
    if (!label) return;
    $('.jpc-metal-specs').each(function () {
      const grid = this;
      const host = grid.matches('[uk-filter],[data-uk-filter]') ? grid : grid.querySelector('[uk-filter],[data-uk-filter]');
      if (!host) return;
      const controls = Array.from(host.querySelectorAll('[uk-filter-control],[data-uk-filter-control]'));
      const desired = normalizeFilterLabel(label);
      const control = controls.find(function (node) {
        // Match the visible label rather than the filter expression, because
        // YOOtheme can encode localized tags inside a CSS attribute selector.
        const titleNode = node.querySelector('a,button') || node;
        return normalizeFilterLabel(titleNode.textContent) === desired;
      });
      if (!control) return; // No matching item/tag configured for this metal.
      const component = window.UIkit && typeof window.UIkit.getComponent === 'function'
        ? window.UIkit.getComponent(host, 'filter') : null;
      if (component && typeof component.apply === 'function') {
        component.apply(control);
      } else {
        const clickable = control.querySelector('a,button') || control;
        clickable.click();
      }
    });
  }

  // YOOtheme dynamic custom field: keep the karat/prefix (e.g. K18),
  // and only change the visible gold-color description. Add the class
  // `jpc-gold-material` to the element rendering the material field.
  // This never edits the stored product meta or other specification items.
  const materialOriginalText = new WeakMap();
  const materialColorPattern = /(?:λευκόχρυσος|λευκόχρυσο|λευκόχρυσ[οό]ς|κίτρινος\s+χρυσός|κιτρινος\s+χρυσος|κίτρινο\s+χρυσό|κιτρινο\s+χρυσο)/iu;
  function updateGoldMaterial() {
    const gold = hasColor && selected('jc_metal_type_ui') === 'gold';
    const color = selected('jc_gold_color_ui');
    const replacement = color === 'λευκόχρυσο' ? 'Λευκόχρυσος' :
      color === 'κίτρινο χρυσό' ? 'Κίτρινος Χρυσός' : '';

    $('.jpc-metal-specs .jpc-gold-material, .jpc-gold-material').each(function () {
      // A class may be assigned to a wrapper rather than a simple text node.
      // Only touch text nodes containing an actual gold material description.
      const walker = document.createTreeWalker(this, NodeFilter.SHOW_TEXT);
      let node;
      while ((node = walker.nextNode())) {
        if (node.parentElement && node.parentElement.closest('script,style,svg')) continue;
        if (!materialOriginalText.has(node)) {
          if (!materialColorPattern.test(node.nodeValue || '')) continue;
          materialOriginalText.set(node, node.nodeValue);
        }
        const original = materialOriginalText.get(node);
        const next = (gold && replacement)
          ? original.replace(materialColorPattern, replacement)
          : original;
        if (node.nodeValue !== next) node.nodeValue = next;
      }
    });
  }

  // Swap the visible product image for Yellow Gold when configured.
  // If no alternate image exists, keep the product's default visuals.
  const imageInfo = (priceInfo && priceInfo.images) || {};
  const originalProductImageNodes = new WeakMap();
  function snapshotImageNode(img) {
    if (!img || originalProductImageNodes.has(img)) return;
    const anchor = img.closest('a');
    originalProductImageNodes.set(img, {
      src: img.getAttribute('src') || '',
      srcset: img.getAttribute('srcset') || '',
      sizes: img.getAttribute('sizes') || '',
      alt: img.getAttribute('alt') || '',
      currentSrc: img.currentSrc || '',
      dataLarge: img.getAttribute('data-large_image') || '',
      dataLargeWidth: img.getAttribute('data-large_image_width') || '',
      dataLargeHeight: img.getAttribute('data-large_image_height') || '',
      dataCaption: img.getAttribute('data-caption') || '',
      dataThumb: img.getAttribute('data-thumb') || '',
      dataThumbAlt: img.getAttribute('data-thumb-alt') || '',
      anchorHref: anchor ? (anchor.getAttribute('href') || '') : '',
      anchorSrcset: anchor ? (anchor.getAttribute('data-srcset') || '') : '',
      anchorSizes: anchor ? (anchor.getAttribute('data-sizes') || '') : ''
    });
  }
  function applyImageDataToNode(img, imageData) {
    if (!img || !imageData || !imageData.src) return;
    snapshotImageNode(img);
    const anchor = img.closest('a');
    const isThumb = $(img).closest('.flex-control-thumbs, .wc-tabs, .product-thumbnails, .tm-element-woo-images-nav').length > 0;
    const src = isThumb && imageData.thumb ? imageData.thumb : imageData.src;
    const srcset = isThumb ? (imageData.thumbSrcset || imageData.srcset || '') : (imageData.srcset || '');
    const sizes = isThumb ? (imageData.thumbSizes || imageData.sizes || '') : (imageData.sizes || '');
    img.setAttribute('src', src);
    if (srcset) img.setAttribute('srcset', srcset); else img.removeAttribute('srcset');
    if (sizes) img.setAttribute('sizes', sizes); else img.removeAttribute('sizes');
    if (imageData.alt) img.setAttribute('alt', imageData.alt);
    img.setAttribute('data-large_image', imageData.src);
    if (imageData.caption) img.setAttribute('data-caption', imageData.caption);
    if (imageData.thumb) img.setAttribute('data-thumb', imageData.thumb);
    if (anchor) {
      anchor.setAttribute('href', imageData.src);
      if (srcset) anchor.setAttribute('data-srcset', imageData.srcset || '');
      if (sizes) anchor.setAttribute('data-sizes', imageData.sizes || '');
    }
  }
  function restoreImageNode(img) {
    const state = img ? originalProductImageNodes.get(img) : null;
    if (!img || !state) return;
    const anchor = img.closest('a');
    img.setAttribute('src', state.src);
    if (state.srcset) img.setAttribute('srcset', state.srcset); else img.removeAttribute('srcset');
    if (state.sizes) img.setAttribute('sizes', state.sizes); else img.removeAttribute('sizes');
    if (state.alt) img.setAttribute('alt', state.alt);
    if (state.dataLarge) img.setAttribute('data-large_image', state.dataLarge); else img.removeAttribute('data-large_image');
    if (state.dataLargeWidth) img.setAttribute('data-large_image_width', state.dataLargeWidth); else img.removeAttribute('data-large_image_width');
    if (state.dataLargeHeight) img.setAttribute('data-large_image_height', state.dataLargeHeight); else img.removeAttribute('data-large_image_height');
    if (state.dataCaption) img.setAttribute('data-caption', state.dataCaption); else img.removeAttribute('data-caption');
    if (state.dataThumb) img.setAttribute('data-thumb', state.dataThumb); else img.removeAttribute('data-thumb');
    if (state.dataThumbAlt) img.setAttribute('data-thumb-alt', state.dataThumbAlt); else img.removeAttribute('data-thumb-alt');
    if (anchor) {
      if (state.anchorHref) anchor.setAttribute('href', state.anchorHref); else anchor.removeAttribute('href');
      if (state.anchorSrcset) anchor.setAttribute('data-srcset', state.anchorSrcset); else anchor.removeAttribute('data-srcset');
      if (state.anchorSizes) anchor.setAttribute('data-sizes', state.anchorSizes); else anchor.removeAttribute('data-sizes');
    }
  }
  function updateProductImage() {
    if (!imageInfo) return;
    const shouldUseYellow = hasColor && selected('jc_metal_type_ui') === 'gold' && selected('jc_gold_color_ui') === 'κίτρινο χρυσό' && imageInfo.yellowGold && imageInfo.yellowGold.src;
    const selectors = [
      '.woocommerce-product-gallery img',
      '.tm-element-woo-images img',
      '.single-product .product img',
      '.woocommerce div.product div.images img'
    ];
    const $images = $(selectors.join(',')).filter(function () {
      return !$(this).closest('.jewelry-configurator').length && !$(this).closest('.woocommerce-product-gallery__trigger').length;
    });
    if (!$images.length) return;
    $images.each(function () {
      if (shouldUseYellow) applyImageDataToNode(this, imageInfo.yellowGold); else restoreImageNode(this);
    });
    const $mainWrap = $('.woocommerce-product-gallery, .tm-element-woo-images').first();
    if ($mainWrap.length) {
      $mainWrap.trigger('wc-product-gallery-after-init');
      $mainWrap.trigger('woocommerce_gallery_init_zoom');
    }
  }

  function scrollToInvalidField() {
    const $first = $root.find('.jc-box.jc-invalid:visible').first();
    if (!$first.length) return;
    const node = $first[0];
    const top = Math.max(0, window.scrollY + node.getBoundingClientRect().top - 110);
    // Respect reduced-motion preferences while keeping the missing field visible.
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top: top, behavior: reducedMotion ? 'auto' : 'smooth'});
    const $focus = $first.find('input:visible,select:visible').first();
    if ($focus.length) {
      // Avoid forcing the browser's own immediate scroll after our smooth scroll.
      window.setTimeout(function () {
        try { $focus[0].focus({preventScroll: true}); } catch (e) { /* no-op */ }
      }, reducedMotion ? 0 : 400);
    }
  }

  function validate() {
    const vals = values();
    const allowed = vals.jc_size_system === 'eu' ? euSizes : vals.jc_size_system === 'us' ? usSizes : [];
    const checks = {
      jc_metal_box: $root.find('input[name="jc_metal_type_ui"]:checked').length === 1,
      jc_color_box: !hasColor || vals.jc_metal_type !== 'gold' || ['λευκόχρυσο', 'κίτρινο χρυσό'].includes(vals.jc_gold_color),
      jc_size_system_box: !hasSize || ['eu', 'us'].includes(vals.jc_size_system),
      jc_size_box: !hasSize || allowed.includes(vals.jc_ring_size)
    };
    Object.entries(checks).forEach(([id, ok]) => $root.find('#' + id).toggleClass('jc-invalid', !ok));
    $root.find('#jc_ring_size').toggleClass('jc-invalid-control', !checks.jc_size_box);
    return Object.values(checks).every(Boolean);
  }

  $root.on('change', 'input[name="jc_metal_type_ui"]', function () {showColor(); syncFields(); updatePrice(); syncMetalSpecifications(); updateGoldMaterial(); updateProductImage();});
  $root.on('change', 'input[name="jc_gold_color_ui"]', function () { syncFields(); updateGoldMaterial(); updateProductImage(); });
  $root.on('change', 'input[name="jc_size_system_ui"]', function () {populateSizes(); syncFields();});
  $root.on('change', '#jc_ring_size', syncFields);
  $root.on('change', 'input,select', function () {
    $(this).closest('.jc-box').removeClass('jc-invalid');
    if (this.id === 'jc_ring_size') $(this).removeClass('jc-invalid-control');
  });

  $(document).on('click', 'form.cart button.single_add_to_cart_button, form.cart button[name="add-to-cart"]', function (event) {
    syncFields();
    if (!validate()) {event.preventDefault(); event.stopImmediatePropagation(); scrollToInvalidField(); return false;}
  });
  $(document).on('submit', 'form.cart', function (event) {
    syncFields();
    if (!validate()) {event.preventDefault(); event.stopImmediatePropagation(); scrollToInvalidField(); return false;}
  });

  showColor();
  populateSizes();
  syncFields();
  updatePrice();
  updateGoldMaterial();
  updateProductImage();
  // UIkit may initialize its filter after DOM-ready. Reapply until initialized.
  syncMetalSpecifications();
  window.setTimeout(function () { syncMetalSpecifications(); updateGoldMaterial(); updateProductImage(); }, 300);
  window.setTimeout(function () { syncMetalSpecifications(); updateGoldMaterial(); updateProductImage(); }, 1000);
});
