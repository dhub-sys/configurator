/* Jewelry Product Configurator - wedding rings: men's and/or women's ring, colour,
 * a size and optional engraving per ring. Both rings ticked = the pair price. */
jQuery(function ($) {
  'use strict';
  const $root = $('.jpc-wedding[data-jc-wedding="1"]').first();
  if (!$root.length) return;
  $('.jpc-js-check').remove(); // the script is running
  const data = window.JPCWedding || {};
  const common = window.JPCCommon;
  const prices = data.prices || {};
  const fee = Number(data.engraveFee) || 0;
  const max = Number(data.engraveMax) || 20;
  const RINGS = ['men', 'women'];
  const sizes = (function () {
    try {
      const parsed = JSON.parse($root.attr('data-jc-sizes') || '');
      if (parsed && Array.isArray(parsed.eu) && Array.isArray(parsed.us)) return parsed;
    } catch (e) { /* fall through */ }
    return {eu: [], us: []};
  })();
  const fieldNames = ['jcw_option', 'jcw_color', 'jcw_size_system', 'jcw_size_men', 'jcw_size_women',
    'jcw_engrave_men', 'jcw_engrave_women', 'jcw_name_men', 'jcw_name_women'];

  const checkedVal = name => $root.find('input[name="' + name + '"]:checked').val() || '';
  /** Ticked rings, always in the order men, women. */
  const rings = () => RINGS.filter(ring => $root.find('input[name="jcw_ring_ui"][value="' + ring + '"]').is(':checked'));
  const option = () => { const r = rings(); return r.length === 2 ? 'pair' : (r[0] || ''); };
  const engraves = ring => $root.find('#jcw_engrave_' + ring).is(':checked');
  const nameOf = ring => String($root.find('#jcw_name_' + ring).val() || '').trim();
  const sizeOf = ring => String($root.find('#jcw_size_' + ring).val() || '');
  const engravedCount = () => rings().filter(ring => engraves(ring) && nameOf(ring) !== '').length;

  /** Why the ticked combination cannot be bought, or ''. */
  function optionProblem() {
    const opt = option();
    if (!opt) return 'Επιλέξτε ανδρική, γυναικεία ή και τις δύο βέρες.';
    if (prices[opt] !== undefined) return '';
    if (opt === 'pair') return 'Το ζευγάρι δεν διατίθεται.';
    return 'Η ' + (opt === 'men' ? 'ανδρική' : 'γυναικεία') + ' βέρα πωλείται μόνο σε ζευγάρι. Τσεκάρετε και την ' + (opt === 'men' ? 'γυναικεία' : 'ανδρική') + '.';
  }

  /* ---- Price -------------------------------------------------------- */
  function formatAmount(amount) {
    const f = data.format || {};
    const decimals = Number.isInteger(f.decimals) ? f.decimals : 2;
    const parts = Math.abs(amount).toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, f.thousandSep === undefined ? '.' : f.thousandSep);
    const number = parts.join(f.decimalSep === undefined ? ',' : f.decimalSep);
    const pattern = f.pattern || '%2$s %1$s';
    return pattern.replace('%1$s', f.symbol || '€').replace('%2$s', number);
  }
  function priceHtml(amount) {
    return '<span class="woocommerce-Price-amount amount"><bdi>' + $('<div>').text(formatAmount(amount)).html() + '</bdi></span>';
  }
  function updatePrice() {
    const opt = option();
    if (prices[opt] === undefined) return; // keep the last valid price; the message explains
    const extra = fee * engravedCount();
    // The page's own price IS the pair price: restore it untouched (keeps a real sale display).
    const html = opt === 'pair' && extra === 0 ? null : priceHtml(prices[opt] + extra);
    common.locatePrices(data.priceSelector).each(function () { common.setPrice(this, html); });
  }

  /* ---- Colour photo ------------------------------------------------- */
  common.setMainImage(data.mainImage);
  let shownColor = '';
  function updateImage() {
    const color = checkedVal('jcw_color_ui');
    const image = color && data.colorImages ? data.colorImages[color] : null;
    if (image && image.src) {
      const nodes = common.showImage(image);
      if (nodes.length && shownColor !== color) common.showMainSlide(nodes);
      shownColor = color;
    } else if (shownColor) {
      common.restoreImages();
      shownColor = '';
    }
  }

  /* ---- Sizes / visibility ------------------------------------------- */
  function populateSizes() {
    const list = sizes[checkedVal('jcw_system_ui')] || [];
    RINGS.forEach(function (ring) {
      const $select = $root.find('#jcw_size_' + ring);
      const previous = String($select.val() || '');
      $select.empty().append($('<option>', {value: '', text: 'Επιλέξτε νούμερο…'}));
      list.forEach(s => $select.append($('<option>', {value: s.v, text: s.l})));
      $select.val(list.some(s => s.v === previous) ? previous : '');
    });
  }
  function updateVisibility() {
    const active = rings();
    RINGS.forEach(function (ring) {
      const $box = $root.find('#jcw_ring_' + ring);
      const on = active.indexOf(ring) !== -1;
      $box.prop('hidden', !on);
      $box.find('.jcw-name').prop('hidden', !engraves(ring));
    });
    // One ring: its box takes the full width.
    $root.find('.jcw-ring').toggleClass('jc-box-full', active.length === 1).toggleClass('jc-box-half', active.length !== 1);
    const problem = optionProblem();
    $root.find('.jcw-option-msg').text(active.length ? problem : '').prop('hidden', !active.length || !problem);
  }
  function updateCounters() {
    RINGS.forEach(ring => $root.find('#jcw_ring_' + ring + ' .jcw-counter').text(String($root.find('#jcw_name_' + ring).val() || '').length + '/' + max));
  }

  /* ---- Hidden fields for the cart form ------------------------------ */
  function values() {
    const active = rings();
    const vals = {jcw_option: option(), jcw_color: checkedVal('jcw_color_ui'), jcw_size_system: checkedVal('jcw_system_ui')};
    RINGS.forEach(function (ring) {
      const on = active.indexOf(ring) !== -1;
      const engrave = on && engraves(ring);
      vals['jcw_size_' + ring] = on ? sizeOf(ring) : '';
      vals['jcw_engrave_' + ring] = engrave ? '1' : '';
      vals['jcw_name_' + ring] = engrave ? nameOf(ring) : '';
    });
    return vals;
  }
  function syncFields() {
    const vals = values();
    // Also works when YOOtheme places the configurator outside form.cart.
    $('form.cart').each(function () {
      const $form = $(this);
      ['jcw_enabled'].concat(fieldNames).forEach(function (key) {
        let $input = $form.find('input[type="hidden"][name="' + key + '"]');
        if (!$input.length) $input = $('<input>', {type: 'hidden', name: key}).appendTo($form);
        $input.val(key === 'jcw_enabled' ? '1' : vals[key]);
      });
    });
  }

  /* ---- Validation --------------------------------------------------- */
  function validate() {
    const vals = values();
    const list = (sizes[vals.jcw_size_system] || []).map(s => s.v);
    const checks = {
      jcw_option_box: optionProblem() === '',
      jcw_color_box: !data.hasColors || vals.jcw_color !== '',
      jcw_system_box: vals.jcw_size_system === 'eu' || vals.jcw_size_system === 'us'
    };
    rings().forEach(function (ring) {
      const sizeOk = list.indexOf(sizeOf(ring)) !== -1;
      const nameOk = !engraves(ring) || (nameOf(ring) !== '' && nameOf(ring).length <= max);
      checks['jcw_ring_' + ring] = sizeOk && nameOk;
      $root.find('#jcw_size_' + ring).toggleClass('jc-invalid-control', !sizeOk);
      $root.find('#jcw_name_' + ring).toggleClass('jc-invalid-control', !nameOk);
    });
    if (!rings().length) $root.find('.jcw-option-msg').text(optionProblem()).prop('hidden', false);
    $root.find('.jc-box').each(function () { if (checks[this.id] !== undefined) $(this).toggleClass('jc-invalid', !checks[this.id]); });
    return Object.keys(checks).every(id => checks[id]);
  }
  function scrollToInvalid() {
    const $first = $root.find('.jc-box.jc-invalid:visible').first();
    if (!$first.length) return;
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top: Math.max(0, window.scrollY + $first[0].getBoundingClientRect().top - 110), behavior: reducedMotion ? 'auto' : 'smooth'});
    const $focus = $first.find('.jc-invalid-control:visible, input:visible, select:visible').first();
    if ($focus.length) window.setTimeout(function () { try { $focus[0].focus({preventScroll: true}); } catch (e) { /* no-op */ } }, reducedMotion ? 0 : 400);
  }

  /* ---- Events ------------------------------------------------------- */
  function refresh() { updateVisibility(); updateCounters(); syncFields(); updatePrice(); }
  $root.on('change', 'input[name="jcw_ring_ui"], .jcw-engrave-toggle input', refresh);
  $root.on('change', '.jcw-engrave-toggle input', function () {
    if (this.checked) $(this).closest('.jcw-ring').find('.jcw-name input').trigger('focus');
  });
  $root.on('input change', '.jcw-name input', refresh);
  $root.on('change', 'input[name="jcw_system_ui"]', function () { populateSizes(); syncFields(); });
  $root.on('change', '.jcw-size select', syncFields);
  $root.on('change', 'input[name="jcw_color_ui"]', function () { syncFields(); updateImage(); });
  $root.on('change input', 'input,select', function () {
    $(this).closest('.jc-box').removeClass('jc-invalid');
    $(this).removeClass('jc-invalid-control');
  });
  function guard(event) {
    syncFields();
    if (!validate()) { event.preventDefault(); event.stopImmediatePropagation(); scrollToInvalid(); return false; }
  }
  $(document).on('click', 'form.cart button.single_add_to_cart_button, form.cart button[name="add-to-cart"]', guard);
  $(document).on('submit', 'form.cart', guard);

  populateSizes();
  refresh();
  updateImage();
});
