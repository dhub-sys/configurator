/* Jewelry Product Configurator - wedding rings (pair / single ring, colour, sizes, engraving). */
jQuery(function ($) {
  'use strict';
  const $root = $('.jpc-wedding[data-jc-wedding="1"]').first();
  if (!$root.length) return;
  const data = window.JPCWedding || {};
  const common = window.JPCCommon;
  const prices = data.prices || {};
  const fee = Number(data.engraveFee) || 0;
  const max = Number(data.engraveMax) || 20;
  const sizes = (function () {
    try {
      const parsed = JSON.parse($root.attr('data-jc-sizes') || '');
      if (parsed && Array.isArray(parsed.eu) && Array.isArray(parsed.us)) return parsed;
    } catch (e) { /* fall through */ }
    return {eu: [], us: []};
  })();
  const fieldNames = ['jcw_option', 'jcw_color', 'jcw_size_system', 'jcw_size_men', 'jcw_size_women', 'jcw_engrave', 'jcw_name_men', 'jcw_name_women'];

  const checkedVal = name => $root.find('input[name="' + name + '"]:checked').val() || '';
  const option = () => checkedVal('jcw_option_ui');
  const rings = () => (option() === 'pair' ? ['men', 'women'] : (option() ? [option()] : []));
  const engraving = () => $root.find('#jcw_engrave').is(':checked');
  const nameOf = ring => String($root.find('#jcw_name_' + ring).val() || '').trim();
  const sizeOf = ring => String($root.find('#jcw_size_' + ring).val() || '');

  function engravedCount() {
    return engraving() ? rings().filter(ring => nameOf(ring) !== '').length : 0;
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
    if (!opt || prices[opt] === undefined) return;
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
    ['men', 'women'].forEach(function (ring) {
      const $select = $root.find('#jcw_size_' + ring);
      const previous = String($select.val() || '');
      $select.empty().append($('<option>', {value: '', text: 'Επιλέξτε νούμερο…'}));
      list.forEach(s => $select.append($('<option>', {value: s.v, text: s.l})));
      $select.val(list.some(s => s.v === previous) ? previous : '');
    });
  }
  function updateVisibility() {
    const active = rings();
    $root.find('.jcw-size, .jcw-name').each(function () {
      $(this).prop('hidden', active.indexOf(this.getAttribute('data-ring')) === -1);
    });
    $root.find('#jcw_size_box .jc-box-title').text(active.length > 1 ? 'Νούμερα' : 'Νούμερο');
    $root.find('.jcw-engrave-fields').prop('hidden', !engraving());
    $root.find('.jcw-hint').prop('hidden', active.length < 2);
  }
  function updateCounters() {
    $root.find('.jcw-name').each(function () {
      const length = String($(this).find('input').val() || '').length;
      $(this).find('.jcw-counter').text(length + '/' + max);
    });
  }

  /* ---- Hidden fields for the cart form ------------------------------ */
  function values() {
    const active = rings();
    const engrave = engraving() && engravedCount() > 0;
    return {
      jcw_option: option(),
      jcw_color: checkedVal('jcw_color_ui'),
      jcw_size_system: checkedVal('jcw_system_ui'),
      jcw_size_men: active.indexOf('men') !== -1 ? sizeOf('men') : '',
      jcw_size_women: active.indexOf('women') !== -1 ? sizeOf('women') : '',
      jcw_engrave: engrave ? '1' : '',
      jcw_name_men: engrave && active.indexOf('men') !== -1 ? nameOf('men') : '',
      jcw_name_women: engrave && active.indexOf('women') !== -1 ? nameOf('women') : ''
    };
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
    const active = rings();
    const checks = {
      jcw_option_box: prices[vals.jcw_option] !== undefined,
      jcw_color_box: !data.hasColors || vals.jcw_color !== '',
      jcw_system_box: vals.jcw_size_system === 'eu' || vals.jcw_size_system === 'us',
      jcw_size_box: active.length > 0 && active.every(ring => list.indexOf(sizeOf(ring)) !== -1),
      jcw_engrave_box: !engraving() || (engravedCount() > 0 && active.every(ring => nameOf(ring).length <= max))
    };
    Object.keys(checks).forEach(id => $root.find('#' + id).toggleClass('jc-invalid', !checks[id]));
    active.forEach(ring => $root.find('#jcw_size_' + ring).toggleClass('jc-invalid-control', list.indexOf(sizeOf(ring)) === -1));
    return Object.keys(checks).every(id => checks[id]);
  }
  function scrollToInvalid() {
    const $first = $root.find('.jc-box.jc-invalid:visible').first();
    if (!$first.length) return;
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top: Math.max(0, window.scrollY + $first[0].getBoundingClientRect().top - 110), behavior: reducedMotion ? 'auto' : 'smooth'});
    const $focus = $first.find('input:visible,select:visible').first();
    if ($focus.length) window.setTimeout(function () { try { $focus[0].focus({preventScroll: true}); } catch (e) { /* no-op */ } }, reducedMotion ? 0 : 400);
  }

  /* ---- Events ------------------------------------------------------- */
  function refresh() { updateVisibility(); updateCounters(); syncFields(); updatePrice(); }
  $root.on('change', 'input[name="jcw_option_ui"], #jcw_engrave', refresh);
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
