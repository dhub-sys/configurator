/* Jewelry Product Configurator - shared helpers (live price text, main product image swap).
 * Used by configurator.js (metals) and wedding.js (wedding rings). */
window.JPCCommon = (function ($) {
  'use strict';
  // YOOtheme sometimes puts the currency sign in a separate text node, or
  // appends an icon after the price. Match the amount without requiring "€".
  const amountPattern = /(?:\d{1,3}(?:[.\s  ]\d{3})+|\d+)(?:[,.]\d{1,2})?/;
  const amountPatternAll = new RegExp(amountPattern.source, 'g');

  function amountTextNodes(element) {
    const nodes = [];
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      if (node.parentElement && node.parentElement.closest('svg,script,style,[uk-icon],.uk-icon,.screen-reader-text')) continue;
      if (amountPattern.test(node.nodeValue || '')) nodes.push(node);
    }
    return nodes;
  }
  /** Visible amounts in a price HTML snippet (ignores struck-through sale prices). */
  function amountsFromHtml(html) {
    const $tmp = $('<div>').html(html);
    $tmp.find('.screen-reader-text, del').remove();
    return $tmp.text().match(amountPatternAll) || [];
  }

  // Per price element: what it showed originally, so it can always be restored.
  const priceStates = new WeakMap();
  /**
   * Show `html` in a price element, or restore its original content (html === null).
   * A single amount (typical YOOtheme button with icons) is changed in its text node
   * only, leaving the surrounding markup untouched. A price range (variable products)
   * is replaced as a whole inside the smallest element that contains it.
   */
  function setPrice(element, html) {
    let state = priceStates.get(element);
    if (state && !element.contains(state.node || state.box)) state = null; // re-rendered
    if (!state) {
      if (html === null) return;
      const nodes = amountTextNodes(element);
      if (!nodes.length) return;
      if (nodes.length === 1) {
        const text = nodes[0].nodeValue;
        state = {node: nodes[0], text: text, amount: text.match(amountPattern)[0]};
      } else {
        let box = nodes[0].parentNode;
        while (box !== element && !nodes.every(n => box.contains(n))) box = box.parentNode;
        state = {box: box, html: box.innerHTML, current: null};
      }
      priceStates.set(element, state);
    }
    if (state.node) {
      let next = state.text;
      if (html !== null) {
        const amounts = amountsFromHtml(html);
        if (amounts.length) {
          const last = amounts[amounts.length - 1];
          next = state.text.replace(state.amount, amounts.length > 1 && last !== amounts[0] ? amounts[0] + ' – ' + last : amounts[0]);
        }
      }
      if (state.node.nodeValue !== next) state.node.nodeValue = next;
    } else {
      const next = html === null ? state.html : html;
      if (state.current !== next) { state.box.innerHTML = next; state.current = next; }
    }
  }

  const priceSelectors = [
    '.jpc-live-price',
    '.tm-element-woo-price .price',
    '.tm-element-woo-price',
    '.woocommerce div.product .summary > p.price',
    '.woocommerce div.product .summary > span.price',
    '.single-product .summary .price'
  ];

  function locatePrices() {
    // Prefer the explicitly marked element, including if it lives outside a
    // standard .product wrapper in a YOOtheme template. The selected variation's
    // own price (inside the cart form) is handled separately.
    for (const selector of priceSelectors) {
      const $matches = $(selector).filter(function () {
        return !$(this).closest('.jewelry-configurator, .jpc-wedding, .woocommerce-variation-price, .single_variation_wrap').length;
      });
      if ($matches.length) return $matches;
    }
    return $();
  }


  const imageStates = new WeakMap();

  /** File "stem" shared by every size of one WordPress image, incl. YOOtheme's cache copies. */
  function imageStem(url) {
    let name = String(url || '').split(/[?#]/)[0].split('/').pop() || '';
    try { name = decodeURIComponent(name); } catch (e) { /* keep as is */ }
    return name.toLowerCase()
      .replace(/\.(jpe?g|png|gif|webp|avif)$/, '')
      .replace(/-[0-9a-f]{8}$/, '')   // YOOtheme cache: name-1a2b3c4d.jpeg
      .replace(/-\d+x\d+$/, '')        // WordPress sizes: name-300x300.jpg
      .replace(/-(scaled|rotated)$/, '');
  }
  function urlsOfImage(img) {
    const urls = [img.getAttribute('src'), img.getAttribute('data-src'), img.getAttribute('data-large_image'), img.currentSrc];
    const sets = [img.getAttribute('srcset'), img.getAttribute('data-srcset')];
    const picture = img.parentElement && img.parentElement.tagName === 'PICTURE' ? img.parentElement : null;
    if (picture) picture.querySelectorAll('source').forEach(function (s) { sets.push(s.getAttribute('srcset'), s.getAttribute('data-srcset')); });
    sets.forEach(function (set) { String(set || '').split(',').forEach(function (part) { urls.push(part.trim().split(/\s+/)[0]); }); });
    return urls.filter(Boolean);
  }
  let mainStems = new Set();
  /** Tell the swapper which image is the product's main photo (server data: src, thumb, stems). */
  function setMainImage(main) {
    const stems = new Set();
    main = main || {};
    (main.stems || []).forEach(function (s) { if (s) stems.add(String(s).toLowerCase()); });
    [main.src, main.thumb].forEach(function (u) { if (u) stems.add(imageStem(u)); });
    stems.delete('');
    mainStems = stems;
  }
  function isMainProductImage(img) {
    return urlsOfImage(img).some(function (u) { return mainStems.has(imageStem(u)); });
  }
  function isThumbnail(img) {
    if ($(img).closest('[uk-slideshow-item],[uk-slider-item],.uk-thumbnav,.el-nav,.flex-control-thumbs,.product-thumbnails,.tm-element-woo-images-nav').length) return true;
    const width = img.getBoundingClientRect().width;
    return width > 0 && width < 160;
  }
  function galleryImages() {
    const selectors = [
      '.woocommerce-product-gallery img',
      '.tm-element-woo-images img',
      '.woocommerce div.product div.images img'
    ];
    return $(selectors.join(',')).filter(function () {
      return !$(this).closest('.jewelry-configurator, .jpc-wedding').length && !$(this).closest('.woocommerce-product-gallery__trigger').length;
    }).get();
  }
  /** Images showing the main product photo. Fallback: the first slide and the first thumbnail. */
  function mainImageNodes() {
    const all = galleryImages();
    const matched = all.filter(isMainProductImage);
    if (matched.length) return matched;
    const first = [];
    const big = all.filter(function (img) { return !isThumbnail(img); });
    const thumbs = all.filter(isThumbnail);
    if (big.length) first.push(big[0]);
    if (thumbs.length) first.push(thumbs[0]);
    return first;
  }

  const imageAttrs = ['src', 'srcset', 'sizes', 'alt', 'data-src', 'data-srcset', 'data-large_image', 'data-large_image_width', 'data-large_image_height', 'data-caption', 'data-thumb', 'data-thumb-alt'];
  function snapshotImage(img) {
    if (imageStates.has(img)) return imageStates.get(img);
    const anchor = img.closest('a');
    const picture = img.parentElement && img.parentElement.tagName === 'PICTURE' ? img.parentElement : null;
    const state = {attrs: {}, anchor: null, sources: []};
    imageAttrs.forEach(function (name) { state.attrs[name] = img.getAttribute(name); });
    // Only links that open the image itself (lightbox/zoom); thumbnail links ("#") must keep working.
    if (anchor && /\.(jpe?g|png|gif|webp|avif)(?:[?#]|$)/i.test(anchor.getAttribute('href') || '')) {
      state.anchor = {node: anchor, href: anchor.getAttribute('href'), srcset: anchor.getAttribute('data-srcset'), sizes: anchor.getAttribute('data-sizes')};
    }
    // <picture><source type="image/webp"> would keep showing the old photo.
    if (picture) picture.querySelectorAll('source').forEach(function (source) { state.sources.push({node: source, parent: picture, next: source.nextSibling}); });
    imageStates.set(img, state);
    return state;
  }
  const setAttr = function (el, name, value) { if (value) el.setAttribute(name, value); else el.removeAttribute(name); };
  function applyImageDataToNode(img, imageData) {
    if (!img || !imageData || !imageData.src) return;
    const state = snapshotImage(img);
    const thumb = isThumbnail(img);
    const src = thumb && imageData.thumb ? imageData.thumb : imageData.src;
    const srcset = thumb ? (imageData.thumbSrcset || '') : (imageData.srcset || '');
    const sizes = thumb ? (imageData.thumbSizes || '') : (imageData.sizes || '');
    state.sources.forEach(function (s) { if (s.node.parentNode) s.node.parentNode.removeChild(s.node); });
    setAttr(img, 'src', src);
    setAttr(img, 'srcset', srcset);
    setAttr(img, 'sizes', sizes);
    // Lazy-loading attributes would otherwise reload the old photo later.
    if (img.hasAttribute('data-src')) setAttr(img, 'data-src', src);
    if (img.hasAttribute('data-srcset')) setAttr(img, 'data-srcset', srcset);
    if (imageData.alt) img.setAttribute('alt', imageData.alt);
    img.setAttribute('data-large_image', imageData.src);
    if (imageData.caption) img.setAttribute('data-caption', imageData.caption);
    if (imageData.thumb && img.hasAttribute('data-thumb')) img.setAttribute('data-thumb', imageData.thumb);
    if (state.anchor) {
      state.anchor.node.setAttribute('href', imageData.src);
      setAttr(state.anchor.node, 'data-srcset', state.anchor.srcset ? imageData.srcset : '');
      setAttr(state.anchor.node, 'data-sizes', state.anchor.sizes ? imageData.sizes : '');
    }
  }
  function restoreImageNode(img) {
    const state = imageStates.get(img);
    if (!state) return;
    imageAttrs.forEach(function (name) { setAttr(img, name, state.attrs[name]); });
    if (state.anchor) {
      setAttr(state.anchor.node, 'href', state.anchor.href);
      setAttr(state.anchor.node, 'data-srcset', state.anchor.srcset);
      setAttr(state.anchor.node, 'data-sizes', state.anchor.sizes);
    }
    state.sources.forEach(function (s) {
      if (s.node.parentNode) return;
      const img2 = s.parent.querySelector('img');
      s.parent.insertBefore(s.node, img2 || null);
    });
    imageStates.delete(img);
  }
  /** Bring the slide with the main photo into view, so the change is visible. */
  function showMainSlide(nodes) {
    if (!window.UIkit) return;
    nodes.forEach(function (img) {
      const item = img.closest('.uk-slideshow-items > *, .uk-slider-items > *');
      if (!item) return;
      const host = item.closest('[uk-slideshow],[uk-slider]');
      const index = Array.prototype.indexOf.call(item.parentNode.children, item);
      const component = host && (UIkit.getComponent(host, 'slideshow') || UIkit.getComponent(host, 'slider'));
      if (component && typeof component.show === 'function' && index >= 0) component.show(index);
    });
  }
  /** Show `imageData` on the main product photo (large image + its own thumbnail only). */
  function showImage(imageData) {
    const nodes = mainImageNodes();
    if (!nodes.length) return [];
    nodes.forEach(function (img) { applyImageDataToNode(img, imageData); });
    refreshGallery();
    return nodes;
  }
  function restoreImages() {
    galleryImages().forEach(restoreImageNode);
    refreshGallery();
  }
  function refreshGallery() {
    const $mainWrap = $('.woocommerce-product-gallery, .tm-element-woo-images').first();
    if ($mainWrap.length) {
      $mainWrap.trigger('wc-product-gallery-after-init');
      $mainWrap.trigger('woocommerce_gallery_init_zoom');
    }
  }

  return {
    amountsFromHtml: amountsFromHtml,
    setPrice: setPrice,
    locatePrices: function (customSelector) {
      if (customSelector && customSelector !== priceSelectors[0]) priceSelectors[0] = customSelector;
      return locatePrices();
    },
    setMainImage: setMainImage,
    showImage: showImage,
    restoreImages: restoreImages,
    showMainSlide: showMainSlide
  };
})(jQuery);
