/* Jewelry Product Configurator - wedding rings: men's and/or women's ring, colour,
 * a size and optional engraving per ring. Both rings ticked = the pair price. */
jQuery(function ($) {
  'use strict';
  const $root = $('.jpc-wedding[data-jc-wedding="1"]').first();
  if (!$root.length) return;
  const data = window.JPCWedding || {};
  const common = window.JPCCommon;
  // Display prices per karat; the shown set follows the karat dropdown (14K when there is none).
  const pricesByKarat = data.pricesByKarat || {'14': data.prices || {}};
  const karat = () => String($root.find('#jcw_karat').val() || '14');
  const currentPrices = () => pricesByKarat[karat()] || {};
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
  const fieldNames = ['jcw_karat', 'jcw_option', 'jcw_color_men', 'jcw_color_women', 'jcw_size_system', 'jcw_size_men', 'jcw_size_women',
    'jcw_engrave_men', 'jcw_engrave_women', 'jcw_name_men', 'jcw_name_women'];

  const checkedVal = name => $root.find('input[name="' + name + '"]:checked').val() || '';
  /** Ticked rings, always in the order men, women. */
  const rings = () => RINGS.filter(ring => $root.find('input[name="jcw_ring_ui"][value="' + ring + '"]').is(':checked'));
  const option = () => { const r = rings(); return r.length === 2 ? 'pair' : (r[0] || ''); };
  const engraves = ring => $root.find('#jcw_engrave_' + ring).is(':checked');
  const nameOf = ring => String($root.find('#jcw_name_' + ring).val() || '').trim();
  const sizeOf = ring => String($root.find('#jcw_size_' + ring).val() || '');
  const colorOf = ring => checkedVal('jcw_color_' + ring + '_ui');
  const engravedCount = () => rings().filter(ring => engraves(ring) && nameOf(ring) !== '').length;

  /** Why the ticked combination cannot be bought, or ''. */
  function optionProblem() {
    const opt = option();
    if (!opt) return 'Επιλέξτε ανδρική, γυναικεία ή και τις δύο βέρες.';
    if (currentPrices()[opt] !== undefined) return '';
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
    const prices = currentPrices();
    if (prices[opt] === undefined) return; // keep the last valid price; the message explains
    const extra = fee * engravedCount();
    // The page's own price IS the pair price: restore it untouched (keeps a real sale display).
    // The page's own price is the 14K pair price.
    const html = karat() === '14' && opt === 'pair' && extra === 0 ? null : priceHtml(prices[opt] + extra);
    common.locatePrices(data.priceSelector).each(function () { common.setPrice(this, html); });
  }

  /* ---- Colour photo ------------------------------------------------- */
  common.setMainImage(data.mainImage);
  let shownPhoto = '';
  let lastColorRing = '';
  /**
   * Which photo to show: a photo made for the exact men's + women's colour combination
   * when both rings are ticked; otherwise the colour chosen last (with one ring ticked,
   * that ring's colour). Returns {key, image} or null.
   */
  function photoChoice() {
    const active = rings();
    const combos = data.comboImages || {};
    // The main photo shows the product's starting colours: keep it while they are selected.
    const defaults = data.defaultColors || {};
    if (active.length && active.every(ring => defaults[ring] && colorOf(ring) === defaults[ring])) return null;
    if (active.length === 2 && colorOf('men') && colorOf('women')) {
      const pair = colorOf('men') + '|' + colorOf('women');
      if (combos[pair] && combos[pair].src) return {key: 'combo:' + pair, image: combos[pair]};
    }
    let color = '';
    if (active.length === 1) color = colorOf(active[0]);
    else if (lastColorRing && active.indexOf(lastColorRing) !== -1 && colorOf(lastColorRing)) color = colorOf(lastColorRing);
    else color = active.map(colorOf).filter(Boolean)[0] || '';
    const image = color && data.colorImages ? data.colorImages[color] : null;
    return image && image.src ? {key: 'color:' + color, image: image} : null;
  }
  function updateImage() {
    const choice = photoChoice();
    if (choice) {
      const nodes = common.showImage(choice.image);
      if (nodes.length && shownPhoto !== choice.key) common.showMainSlide(nodes);
      shownPhoto = choice.key;
    } else if (shownPhoto) {
      common.restoreImages();
      shownPhoto = '';
    }
  }
  function updateColorNames() {
    RINGS.forEach(function (ring) {
      const $checked = $root.find('input[name="jcw_color_' + ring + '_ui"]:checked');
      $root.find('#jcw_ring_' + ring + ' .jcw-color-name').text($checked.attr('data-name') || '');
    });
  }

  /* ---- Sizes / visibility ------------------------------------------- */
  function populateSizes() {
    const list = sizes[checkedVal('jcw_system_ui')] || [];
    RINGS.forEach(function (ring) {
      const $select = $root.find('#jcw_size_' + ring);
      const previous = String($select.val() || '');
      $select.empty().append($('<option>', {value: '', text: list.length ? 'Επιλέξτε νούμερο…' : 'Επιλέξτε τύπο μεγέθους'}));
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
    const vals = {jcw_karat: karat(), jcw_option: option(), jcw_size_system: checkedVal('jcw_system_ui')};
    RINGS.forEach(function (ring) {
      const on = active.indexOf(ring) !== -1;
      const engrave = on && engraves(ring);
      vals['jcw_size_' + ring] = on ? sizeOf(ring) : '';
      vals['jcw_color_' + ring] = on ? colorOf(ring) : '';
      vals['jcw_engrave_' + ring] = engrave ? '1' : '';
      vals['jcw_name_' + ring] = engrave ? nameOf(ring) : '';
    });
    return vals;
  }
  function syncFields() {
    window.setTimeout(notifyChange, 0);
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
  /**
   * Missing choices, in page order: [{box: jQuery, ok: bool}], checks only (marks nothing).
   * The first incomplete entry is where the customer still has to choose.
   */
  function fieldChecks() {
    const vals = values();
    const list = (sizes[vals.jcw_size_system] || []).map(s => s.v);
    const result = [{box: $root.find('#jcw_option_box'), ok: optionProblem() === ''},
      {box: $root.find('#jcw_system_row'), ok: vals.jcw_size_system === 'eu' || vals.jcw_size_system === 'us'}];
    rings().forEach(function (ring) {
      result.push({box: $root.find('#jcw_ring_' + ring + ' .jcw-color-row'), ok: !data.hasColors || colorOf(ring) !== ''});
      result.push({box: $root.find('#jcw_ring_' + ring + ' .jcw-size-row'), ok: list.indexOf(sizeOf(ring)) !== -1});
      result.push({box: $root.find('#jcw_ring_' + ring + ' .jcw-name'), ok: !engraves(ring) || (nameOf(ring) !== '' && nameOf(ring).length <= max)});
    });
    return result;
  }
  /** Tell other scripts (e.g. the sticky bar) that the choices changed. */
  function notifyChange() {
    document.dispatchEvent(new CustomEvent('jpc:change', {detail: {complete: fieldChecks().every(c => c.ok)}}));
  }
  /** Smooth scroll to a box and focus its first control, without marking anything. */
  function revealBox($box, $focusIn) {
    if (!$box || !$box.length) return;
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top: Math.max(0, window.scrollY + $box[0].getBoundingClientRect().top - window.JPCCommon.scrollOffset()), behavior: reducedMotion ? 'auto' : 'smooth'});
    const $focus = ($focusIn && $focusIn.length ? $focusIn : $box).find('input:visible,select:visible').first();
    if ($focus.length) window.setTimeout(function () { try { $focus[0].focus({preventScroll: true}); } catch (e) { /* no-op */ } }, reducedMotion ? 0 : 400);
  }
  function validate() {
    const vals = values();
    const list = (sizes[vals.jcw_size_system] || []).map(s => s.v);
    const systemOk = vals.jcw_size_system === 'eu' || vals.jcw_size_system === 'us';
    $root.find('#jcw_system_row').toggleClass('jcw-row-invalid', !systemOk);
    const checks = {jcw_option_box: optionProblem() === '' && systemOk};
    rings().forEach(function (ring) {
      const colorOk = !data.hasColors || colorOf(ring) !== '';
      const sizeOk = list.indexOf(sizeOf(ring)) !== -1;
      const nameOk = !engraves(ring) || (nameOf(ring) !== '' && nameOf(ring).length <= max);
      checks['jcw_ring_' + ring] = colorOk && sizeOk && nameOk;
      $root.find('#jcw_ring_' + ring + ' .jcw-color-row').toggleClass('jcw-row-invalid', !colorOk);
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
    window.scrollTo({top: Math.max(0, window.scrollY + $first[0].getBoundingClientRect().top - window.JPCCommon.scrollOffset()), behavior: reducedMotion ? 'auto' : 'smooth'});
    const $focus = $first.find('.jc-invalid-control:visible, input:visible, select:visible').first();
    if ($focus.length) window.setTimeout(function () { try { $focus[0].focus({preventScroll: true}); } catch (e) { /* no-op */ } }, reducedMotion ? 0 : 400);
  }

  /* ---- Events ------------------------------------------------------- */
  /** Prices next to the ring ticks and the pair price, for the chosen karat. */
  function updateOptionPrices() {
    const prices = currentPrices();
    $root.find('input[name="jcw_ring_ui"]').each(function () {
      const ring = this.value;
      $(this).closest('label').find('.jc-metal-price').html(prices[ring] !== undefined ? priceHtml(prices[ring]) : 'μόνο σε ζευγάρι');
    });
    const $pair = $root.find('.jcw-pair-note');
    if (prices.pair !== undefined) $pair.prop('hidden', false).find('strong').html(priceHtml(prices.pair));
    else $pair.prop('hidden', true);
  }
  function refresh() { updateVisibility(); updateCounters(); syncFields(); updatePrice(); }
  $root.on('change', 'input[name="jcw_ring_ui"], .jcw-engrave-toggle input', refresh);
  $root.on('change', '#jcw_karat', function () { updateOptionPrices(); refresh(); });
  $root.on('change', 'input[name="jcw_ring_ui"]', updateImage);
  $root.on('change', '.jcw-engrave-toggle input', function () {
    if (this.checked) $(this).closest('.jcw-ring').find('.jcw-name input').trigger('focus');
  });
  $root.on('input change', '.jcw-name input', refresh);
  $root.on('change', 'input[name="jcw_system_ui"]', function () { $root.find('#jcw_system_row').removeClass('jcw-row-invalid'); populateSizes(); syncFields(); });
  $root.on('change', '.jcw-size-row select', syncFields);
  $root.on('change', '.jcw-swatches input', function () {
    lastColorRing = $(this).closest('.jcw-ring').attr('data-ring') || '';
    $(this).closest('.jcw-color-row').removeClass('jcw-row-invalid');
    updateColorNames(); syncFields(); updateImage();
  });
  $root.on('change input', 'input,select', function () {
    $(this).closest('.jc-box').removeClass('jc-invalid');
    $(this).removeClass('jc-invalid-control');
  });
  /** For other scripts (e.g. a sticky add-to-cart bar): see configurator.js. */
  window.JPCValidate = function () {
    syncFields();
    if (validate()) return true;
    scrollToInvalid();
    return false;
  };
  /** Gentle helpers for other scripts: check without marking, and show the first missing field. */
  window.JPCConfigurator = {
    isComplete: function () { return fieldChecks().every(c => c.ok); },
    reveal: function () {
      const first = fieldChecks().find(c => !c.ok);
      // Scroll to the top of the box holding the missing field, so its context shows too.
      revealBox(first && first.box.closest('.jc-box').length ? first.box.closest('.jc-box') : $root, first ? first.box : null);
    },
    validate: window.JPCValidate
  };
  function guard(event) {
    syncFields();
    if (!validate()) { event.preventDefault(); event.stopImmediatePropagation(); scrollToInvalid(); return false; }
  }
  $(document).on('click', 'form.cart button.single_add_to_cart_button, form.cart button[name="add-to-cart"]', guard);
  $(document).on('submit', 'form.cart', guard);

  populateSizes();
  updateColorNames();
  refresh();
  updateImage();
});
