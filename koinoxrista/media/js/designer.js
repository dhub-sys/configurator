/*
 * Σχεδιαστής προτύπων εκτύπωσης. Όλες οι τιμές είναι σε χιλιοστά του χαρτιού A4.
 * Η προεπισκόπηση εδώ είναι κατά προσέγγιση· η ακριβής είναι η «Δοκιμαστική εκτύπωση»
 * (ο ίδιος renderer με την πραγματική εκτύπωση).
 */
(function () {
  'use strict';
  var dataEl = document.getElementById('kx-designer-data');
  var root = document.getElementById('kx-designer');
  if (!dataEl || !root) return;
  var D = JSON.parse(dataEl.textContent);
  var L = D.layout;
  var editable = !!D.editable;
  var isNotice = D.type === 'notice';
  var zoom = 3.5;
  var selected = null;
  var dirty = false;
  var view = { form: true, data: true, bg: true, grid: false };
  var PT = 0.3528; // 1pt σε mm

  var paper = root.querySelector('[data-paper]');
  var stage = root.querySelector('[data-stage]');
  var propsEl = root.querySelector('[data-props]');
  var listEl = root.querySelector('[data-list]');
  var pageEl = root.querySelector('[data-page-props]');
  var form = document.getElementById('kx-designer-form');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v, d) { v = parseFloat(String(v).replace(',', '.')); return isNaN(v) ? (d || 0) : v; }
  function r2(v) { return Math.round(v * 100) / 100; }
  function pageSize() { return L.orientation === 'landscape' ? [297, 210] : [210, 297]; }
  function touch() { dirty = true; }
  function uid() { var n = 1; var ids = L.elements.map(function (e) { return e.id; }); while (ids.indexOf('e' + n) >= 0) n++; return 'e' + n; }

  // ---------------------------------------------------------------- κατάλογος πεδίων
  var labels = {};
  function addLabels(obj) { Object.keys(obj).forEach(function (k) { if (typeof obj[k] === 'object') addLabels(obj[k]); else labels[k] = obj[k]; }); }
  addLabels(D.catalog);

  function fieldGroups() {
    var g = {};
    Object.keys(D.catalog.header).forEach(function (k) { g[k] = D.catalog.header[k]; });
    if (isNotice) g['Ιδιοκτησία & ποσά'] = D.catalog.unit;
    return g;
  }
  function columnCatalog(source) {
    return source === 'expenses' ? D.catalog.expense : source === 'unit_expenses' ? D.catalog.unit_expense : D.catalog.unit;
  }
  function selectHtml(name, groups, value, flat) {
    var h = '<select data-p="' + name + '">';
    var found = false;
    function opts(o) { Object.keys(o).forEach(function (k) { if (k === value) found = true; h += '<option value="' + esc(k) + '"' + (k === value ? ' selected' : '') + '>' + esc(o[k]) + '</option>'; }); }
    if (flat) opts(groups); else Object.keys(groups).forEach(function (g) { h += '<optgroup label="' + esc(g) + '">'; opts(groups[g]); h += '</optgroup>'; });
    if (value && !found) h += '<option value="' + esc(value) + '" selected>' + esc(value) + '</option>';
    return h + '</select>';
  }
  function sampleFields() {
    var f = {};
    Object.keys(D.sample.fields).forEach(function (k) { f[k] = D.sample.fields[k]; });
    if (isNotice && D.sample.units[0]) Object.keys(D.sample.units[0]).forEach(function (k) { f[k] = D.sample.units[0][k]; });
    return f;
  }
  function tableRows(source) {
    if (source === 'expenses') return D.sample.expenses;
    if (source === 'unit_expenses') return D.sample.unit_expenses[0] || [];
    return isNotice ? D.sample.units.slice(0, 1) : D.sample.units;
  }

  // ---------------------------------------------------------------- σχεδίαση
  function mm(v) { return (v * zoom) + 'px'; }
  function textCss(e, size) {
    var s = 'font-size:' + ((size || e.size || 9) * PT * zoom) + 'px;';
    if (e.bold) s += 'font-weight:700;';
    if (e.italic) s += 'font-style:italic;';
    s += 'text-align:' + (e.align || 'left') + ';';
    if (e.color) s += 'color:' + e.color + ';';
    if (e.upper) s += 'text-transform:uppercase;';
    return s;
  }

  function render() {
    var ps = pageSize();
    paper.style.width = mm(ps[0]);
    paper.style.height = mm(ps[1]);
    paper.style.fontFamily = (L.font || 'Arial') + ',Helvetica,sans-serif';
    var h = '';
    if (D.background && view.bg) h += '<img class="kx-dz-bg" src="' + esc(D.background) + '" style="opacity:' + (L.bg_opacity == null ? 0.45 : L.bg_opacity) + '" alt="">';
    if (view.grid) {
      for (var x = 10; x < ps[0]; x += 10) h += '<i class="kx-dz-gv" style="left:' + mm(x) + '"></i>';
      for (var y = 10; y < ps[1]; y += 10) h += '<i class="kx-dz-gh" style="top:' + mm(y) + '"></i>';
    }
    if (isNotice) {
      var pitch = L.pitch || ps[1] / (L.per_page || 1);
      for (var s = 1; s < (L.per_page || 1); s++) h += '<i class="kx-dz-slot" style="top:' + mm(pitch * s) + '"><b>Ειδοποιητήριο ' + (s + 1) + ' (επαναλαμβάνεται)</b></i>';
    }
    var f = sampleFields();
    L.elements.forEach(function (e) { h += renderEl(e, f); });
    paper.innerHTML = h;
    renderList();
  }

  function renderEl(e, f) {
    var layer = e.layer === 'form' ? 'form' : 'data';
    var hidden = e.type !== 'table' && !view[layer];
    var cls = 'kx-dz-el kx-dz-' + e.type + ' kx-dz-l-' + layer + (e.id === selected ? ' is-sel' : '') + (hidden ? ' is-hidden' : '');
    var box = 'left:' + mm(e.x) + ';top:' + mm(e.y) + ';';
    var inner = '';
    switch (e.type) {
      case 'field':
      case 'text': {
        var t = e.type === 'text' ? e.text : (f[e.field] ? (e.prefix || '') + f[e.field] + (e.suffix || '') : '');
        var ph = !t;
        if (ph) t = '{' + (labels[e.field] || e.field || 'πεδίο') + '}';
        box += (e.w ? 'width:' + mm(e.w) + ';' : '') + (e.h ? 'height:' + mm(e.h) + ';' : '') + textCss(e) + (e.wrap ? 'white-space:pre-line;' : '');
        inner = '<span' + (ph ? ' class="kx-dz-ph"' : '') + '>' + esc(t) + '</span>';
        break;
      }
      case 'line': {
        var horiz = (e.w || 0) >= (e.h || 0);
        var st = (e.dashed ? 'dashed' : 'solid') + ' ' + (e.color || '#000');
        var th = Math.max(1, (e.stroke || 0.25) * zoom) + 'px ';
        box += horiz ? 'width:' + mm(e.w) + ';height:6px;margin-top:-3px;background:linear-gradient(transparent 2.5px,' + (e.color || '#000') + ' 2.5px,' + (e.color || '#000') + ' 3.5px,transparent 3.5px);' + (e.dashed ? 'opacity:.6;' : '')
          : 'height:' + mm(e.h) + ';width:6px;margin-left:-3px;border-left:0;background:linear-gradient(90deg,transparent 2.5px,' + (e.color || '#000') + ' 2.5px,' + (e.color || '#000') + ' 3.5px,transparent 3.5px);';
        void th; void st;
        break;
      }
      case 'box':
        box += 'width:' + mm(e.w) + ';height:' + mm(e.h) + ';border:' + Math.max(1, (e.stroke || 0.25) * zoom) + 'px solid ' + (e.color || '#000') + ';' + (e.fill ? 'background:' + e.fill + ';' : '') + (e.radius ? 'border-radius:' + mm(e.radius) + ';' : '');
        break;
      case 'image': {
        var src = (D.sample.images || {})[e.field];
        box += 'width:' + mm(e.w) + ';height:' + mm(e.h) + ';';
        inner = src ? '<img src="' + esc(src) + '" alt="">' : '<span class="kx-dz-ph">{λογότυπο}</span>';
        break;
      }
      case 'table':
        return renderTable(e, cls, f);
    }
    return '<div class="' + cls + '" data-id="' + esc(e.id) + '" style="' + box + '">' + inner + (e.id === selected && editable ? handles(e) : '') + '</div>';
  }

  function handles(e) {
    var h = '';
    if (e.type !== 'line' || (e.w || 0) >= (e.h || 0)) h += '<b class="kx-dz-h kx-dz-hr" data-handle="w"></b>';
    if (e.type === 'box' || e.type === 'image' || (e.type === 'line' && (e.h || 0) > (e.w || 0)) || ((e.type === 'field' || e.type === 'text') && e.wrap)) h += '<b class="kx-dz-h kx-dz-hb" data-handle="h"></b>';
    return h;
  }

  function renderTable(e, cls, f) {
    var cols = e.columns || [];
    var width = cols.reduce(function (s, c) { return s + (c.w || 10); }, 0);
    var rows = Math.max(1, e.rows || 1), rh = e.row_h || 6, hh = e.header_h || 0;
    var data = tableRows(e.source);
    var h = '<div class="' + cls + '" data-id="' + esc(e.id) + '" style="left:' + mm(e.x) + ';top:' + mm(e.y - hh) + ';width:' + mm(width) + ';height:' + mm(rows * rh + hh) + ';">';
    if (view.form) {
      if (hh) {
        h += '<div class="kx-dz-thead" style="height:' + mm(hh) + ';' + (e.header_fill ? 'background:' + e.header_fill + ';' : '') + '">';
        cols.forEach(function (c) { h += '<span style="width:' + mm(c.w || 10) + ';font-size:' + ((e.header_size || e.size || 8) * PT * zoom) + 'px;text-align:' + (c.align || 'left') + '">' + esc(c.title || '') + '</span>'; });
        h += '</div>';
      }
      if (e.grid) {
        h += '<div class="kx-dz-tgrid" style="top:' + mm(hh) + ';height:' + mm(rows * rh) + ';background-size:100% ' + mm(rh) + '"></div>';
        var cx = 0;
        cols.forEach(function (c, i) { if (i) h += '<i class="kx-dz-tcol" style="left:' + mm(cx) + ';top:' + mm(0) + ';height:' + mm(rows * rh + hh) + '"></i>'; cx += c.w || 10; });
      }
    }
    if (view.data) {
      data.slice(0, rows).forEach(function (row, r) {
        h += '<div class="kx-dz-trow" style="top:' + mm(hh + r * rh) + ';height:' + mm(rh) + ';line-height:' + mm(rh) + ';font-size:' + ((e.size || 8) * PT * zoom) + 'px">';
        cols.forEach(function (c) { h += '<span style="width:' + mm(c.w || 10) + ';text-align:' + (c.align || 'left') + (c.bold ? ';font-weight:700' : '') + '">' + esc(row[c.field] || '') + '</span>'; });
        h += '</div>';
      });
    }
    return h + '</div>';
  }

  function describe(e) {
    var t = { field: 'Πεδίο', text: 'Κείμενο', table: 'Πίνακας', line: 'Γραμμή', box: 'Πλαίσιο', image: 'Λογότυπο' }[e.type];
    var d = e.type === 'text' ? e.text : e.type === 'table' ? ({ units: 'ιδιοκτησίες', expenses: 'δαπάνες', unit_expenses: 'ανάλυση ιδιοκτησίας' }[e.source]) : e.type === 'field' || e.type === 'image' ? (labels[e.field] || e.field) : r2(e.w) + '×' + r2(e.h) + ' mm';
    return '<b>' + t + '</b> ' + esc(String(d || '').slice(0, 40)) + (e.layer === 'form' ? ' <small>έντυπο</small>' : '');
  }

  function renderList() {
    listEl.innerHTML = L.elements.map(function (e) { return '<li data-id="' + esc(e.id) + '" class="' + (e.id === selected ? 'is-sel' : '') + '">' + describe(e) + '</li>'; }).join('');
  }

  // ---------------------------------------------------------------- ιδιότητες
  function find(id) { for (var i = 0; i < L.elements.length; i++) if (L.elements[i].id === id) return L.elements[i]; return null; }

  function inp(label, prop, value, attrs) { return '<label class="kx-field"><span>' + label + '</span><input data-p="' + prop + '" value="' + esc(value == null ? '' : value) + '" ' + (attrs || '') + '></label>'; }
  function chk(label, prop, value) { return '<label class="kx-check"><input type="checkbox" data-p="' + prop + '"' + (value ? ' checked' : '') + '><span>' + label + '</span></label>'; }
  function sel(label, prop, opts, value) {
    var h = '<label class="kx-field"><span>' + label + '</span><select data-p="' + prop + '">';
    Object.keys(opts).forEach(function (k) { h += '<option value="' + k + '"' + (String(k) === String(value) ? ' selected' : '') + '>' + opts[k] + '</option>'; });
    return h + '</select></label>';
  }
  var ALIGN = { left: 'Αριστερά', center: 'Κέντρο', right: 'Δεξιά' };
  var LAYER = { data: 'Δεδομένα (τυπώνεται πάντα)', form: 'Έντυπο (μόνο σε λευκό χαρτί)' };

  function renderProps() {
    var e = find(selected);
    if (!e) { propsEl.innerHTML = '<p class="kx-muted">Επιλέξτε ένα στοιχείο στη σελίδα.</p>'; return; }
    var h = '<div class="kx-dz-grid">' + inp('X mm', 'x', e.x, 'inputmode="decimal"') + inp('Y mm', 'y', e.y, 'inputmode="decimal"');
    if (e.type !== 'table') h += inp('Πλάτος mm', 'w', e.w, 'inputmode="decimal"') + inp('Ύψος mm', 'h', e.h, 'inputmode="decimal"');
    h += '</div>' + sel('Επίπεδο', 'layer', LAYER, e.layer);
    if (e.type === 'field') {
      h += '<label class="kx-field"><span>Πεδίο</span>' + selectHtml('field', fieldGroups(), e.field) + '</label>';
      h += '<div class="kx-dz-grid">' + inp('Πρόθεμα', 'prefix', e.prefix) + inp('Επίθεμα', 'suffix', e.suffix) + '</div>';
    }
    if (e.type === 'image') h += '<label class="kx-field"><span>Εικόνα</span>' + selectHtml('field', D.catalog.images, e.field, true) + '</label>';
    if (e.type === 'text') h += '<label class="kx-field"><span>Κείμενο</span><textarea data-p="text" rows="3">' + esc(e.text) + '</textarea></label>';
    if (e.type === 'field' || e.type === 'text') {
      h += '<div class="kx-dz-grid">' + inp('Μέγεθος pt', 'size', e.size, 'type="number" step="0.5" min="4" max="48"') + sel('Στοίχιση', 'align', ALIGN, e.align) + '</div>';
      h += '<div class="kx-dz-checks">' + chk('Έντονα', 'bold', e.bold) + chk('Πλάγια', 'italic', e.italic) + chk('Κεφαλαία', 'upper', e.upper) + chk('Αναδίπλωση (πολλές γραμμές)', 'wrap', e.wrap) + (e.type === 'field' ? chk('Απόκρυψη αν είναι κενό', 'hide_empty', e.hide_empty) : '') + '</div>';
      h += inp('Χρώμα', 'color', e.color || '#000000', 'type="color"');
    }
    if (e.type === 'line' || e.type === 'box') {
      h += '<div class="kx-dz-grid">' + inp('Πάχος mm', 'stroke', e.stroke || 0.25, 'inputmode="decimal"') + inp('Χρώμα', 'color', e.color || '#000000', 'type="color"') + '</div>';
      if (e.type === 'line') h += chk('Διακεκομμένη', 'dashed', e.dashed);
      if (e.type === 'box') h += '<div class="kx-dz-grid">' + inp('Γέμισμα', 'fill', e.fill || '#ffffff', 'type="color"') + inp('Καμπύλη mm', 'radius', e.radius || 0, 'inputmode="decimal"') + '</div>' + chk('Χωρίς γέμισμα', 'nofill', !e.fill);
    }
    if (e.type === 'table') h += tableProps(e);
    if (editable) h += '<div class="kx-row kx-mt"><button type="button" class="kx-btn kx-btn-small" data-act="dup">Διπλότυπο</button><button type="button" class="kx-btn kx-btn-small kx-btn-danger" data-act="del">Διαγραφή</button></div>';
    propsEl.innerHTML = h;
    if (!editable) propsEl.querySelectorAll('input,select,textarea,button').forEach(function (x) { x.disabled = true; });
  }

  function tableProps(e) {
    var sources = isNotice ? { unit_expenses: 'Ανάλυση δαπανών ιδιοκτησίας', expenses: 'Δαπάνες κτιρίου' } : { units: 'Ιδιοκτησίες', expenses: 'Δαπάνες κτιρίου' };
    var h = sel('Δεδομένα', 'source', sources, e.source);
    h += '<div class="kx-dz-grid">' + inp('Ύψος γραμμής mm', 'row_h', e.row_h, 'inputmode="decimal"') + inp('Γραμμές/σελίδα', 'rows', e.rows, 'type="number" min="1" max="200"')
      + inp('Μέγεθος pt', 'size', e.size, 'type="number" step="0.5" min="4" max="30"') + inp('Ύψος επικεφαλίδας', 'header_h', e.header_h, 'inputmode="decimal"')
      + inp('Μέγεθος επικεφ. pt', 'header_size', e.header_size || e.size, 'type="number" step="0.5" min="4" max="30"') + inp('Πάχος γραμμών', 'stroke', e.stroke || 0.2, 'inputmode="decimal"') + '</div>';
    h += '<div class="kx-dz-checks">' + chk('Γραμμές πλέγματος (επίπεδο έντυπο)', 'grid', e.grid) + chk('Γκρι επικεφαλίδα', 'header_fill_on', !!e.header_fill) + (isNotice ? '' : chk('Συνέχεια σε νέα σελίδα όταν δεν χωρούν', 'paginate', e.paginate !== false)) + '</div>';
    h += '<p class="kx-muted">Η Y είναι η θέση της πρώτης γραμμής δεδομένων· η επικεφαλίδα μπαίνει από πάνω.</p>';
    h += '<h4>Στήλες</h4><div class="kx-dz-cols">';
    var cat = columnCatalog(e.source);
    (e.columns || []).forEach(function (c, i) {
      h += '<div class="kx-dz-col" data-col="' + i + '">' + selectHtml('col.field', cat, c.field, true)
        + '<input data-p="col.title" value="' + esc(c.title) + '" placeholder="Τίτλος">'
        + '<input data-p="col.w" value="' + esc(c.w) + '" size="3" inputmode="decimal" title="Πλάτος mm">'
        + '<select data-p="col.align">' + Object.keys(ALIGN).map(function (k) { return '<option value="' + k + '"' + (c.align === k ? ' selected' : '') + '>' + ALIGN[k][0] + '</option>'; }).join('') + '</select>'
        + '<label title="Έντονα"><input type="checkbox" data-p="col.bold"' + (c.bold ? ' checked' : '') + '>B</label>'
        + '<button type="button" data-col-act="up" title="Πάνω">↑</button><button type="button" data-col-act="del" title="Διαγραφή">✕</button></div>';
    });
    h += '</div><button type="button" class="kx-btn kx-btn-small" data-col-act="add">+ Στήλη</button>';
    var w = (e.columns || []).reduce(function (s, c) { return s + (c.w || 10); }, 0);
    h += '<p class="kx-muted">Συνολικό πλάτος: ' + r2(w) + ' mm · τέλος στα ' + r2(e.x + w) + ' mm.</p>';
    return h;
  }

  function renderPage() {
    var h = '<div class="kx-dz-grid">' + sel('Προσανατολισμός', 'orientation', { portrait: 'Κάθετο A4', landscape: 'Οριζόντιο A4' }, L.orientation)
      + sel('Γραμματοσειρά', 'font', { Arial: 'Arial', 'Times New Roman': 'Times New Roman', Verdana: 'Verdana', Tahoma: 'Tahoma', 'Courier New': 'Courier New' }, L.font || 'Arial') + '</div>'
      + '<div class="kx-dz-grid">' + inp('Μετατόπιση X mm', 'offset_x', L.offset_x || 0, 'inputmode="decimal" title="Διόρθωση για όλη τη σελίδα αυτού του προτύπου"') + inp('Μετατόπιση Y mm', 'offset_y', L.offset_y || 0, 'inputmode="decimal"') + '</div>';
    if (isNotice) h += '<div class="kx-dz-grid">' + sel('Ανά σελίδα', 'per_page', { 1: '1', 2: '2', 3: '3', 4: '4' }, L.per_page || 1) + inp('Απόσταση mm', 'pitch', L.pitch || 148.5, 'inputmode="decimal" title="Κατακόρυφη απόσταση μεταξύ των ειδοποιητηρίων στη σελίδα"') + '</div>';
    if (D.background) h += inp('Διαφάνεια φόντου', 'bg_opacity', L.bg_opacity == null ? 0.45 : L.bg_opacity, 'type="range" min="0" max="1" step="0.05"');
    pageEl.innerHTML = h;
    if (!editable) pageEl.querySelectorAll('input,select').forEach(function (x) { x.disabled = true; });
  }

  // ---------------------------------------------------------------- αλλαγές
  var NUMS = ['x', 'y', 'w', 'h', 'size', 'stroke', 'radius', 'row_h', 'rows', 'header_h', 'header_size', 'offset_x', 'offset_y', 'per_page', 'pitch', 'bg_opacity'];
  function applyProp(obj, p, input) {
    var v = input.type === 'checkbox' ? input.checked : input.value;
    if (NUMS.indexOf(p) >= 0) v = num(v);
    if (p === 'nofill') { if (v) delete obj.fill; else obj.fill = '#eeeeee'; return; }
    if (p === 'header_fill_on') { if (v) obj.header_fill = '#ececec'; else delete obj.header_fill; return; }
    if (p === 'color' && (v === '#000000')) { delete obj.color; return; }
    obj[p] = v;
  }

  propsEl.addEventListener('input', onPropChange);
  propsEl.addEventListener('change', onPropChange);
  function onPropChange(ev) {
    var t = ev.target, p = t.getAttribute('data-p'), e = find(selected);
    if (!p || !e || !editable) return;
    if (p.indexOf('col.') === 0) {
      var i = +t.closest('[data-col]').getAttribute('data-col');
      var c = e.columns[i], k = p.slice(4);
      c[k] = t.type === 'checkbox' ? t.checked : (k === 'w' ? num(t.value, 10) : t.value);
      if (k === 'field' && ev.type === 'change') {
        var cat = columnCatalog(e.source);
        if (!c.title || c.title === (cat[c._prev] || '')) c.title = cat[c.field] || '';
        delete c._prev;
      }
    } else {
      applyProp(e, p, t);
      if (p === 'source' && ev.type === 'change') { e.columns = defaultColumns(e.source); }
    }
    touch();
    render();
    if (ev.type === 'change' && (['source', 'type', 'nofill', 'header_fill_on', 'wrap'].indexOf(p) >= 0 || p === 'col.field')) renderProps();
  }
  propsEl.addEventListener('focusin', function (ev) {
    var t = ev.target;
    if (t.getAttribute && t.getAttribute('data-p') === 'col.field') {
      var e = find(selected), i = +t.closest('[data-col]').getAttribute('data-col');
      if (e) e.columns[i]._prev = e.columns[i].field;
    }
  });
  propsEl.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b || !editable) return;
    var e = find(selected);
    var act = b.getAttribute('data-act'), cact = b.getAttribute('data-col-act');
    if (act === 'del') { L.elements = L.elements.filter(function (x) { return x.id !== selected; }); selected = null; }
    if (act === 'dup') { var c = JSON.parse(JSON.stringify(e)); c.id = uid(); c.x += 3; c.y += 3; L.elements.push(c); selected = c.id; }
    if (cact === 'add') e.columns.push({ field: Object.keys(columnCatalog(e.source))[0], title: '', w: 15, align: 'left' });
    if (cact === 'up' || cact === 'del') {
      var i = +b.closest('[data-col]').getAttribute('data-col');
      if (cact === 'del') e.columns.splice(i, 1);
      else if (i > 0) { var t = e.columns[i - 1]; e.columns[i - 1] = e.columns[i]; e.columns[i] = t; }
    }
    if (act || cact) { touch(); render(); renderProps(); }
  });
  pageEl.addEventListener('input', function (ev) {
    var p = ev.target.getAttribute('data-p');
    if (!p || !editable) return;
    applyProp(L, p, ev.target);
    touch();
    render();
  });
  pageEl.addEventListener('change', function () { renderPage(); });

  function defaultColumns(source) {
    if (source === 'expenses') return [{ field: 'exp.title', title: 'Περιγραφή', w: 80, align: 'left' }, { field: 'exp.amount', title: 'Ποσό €', w: 20, align: 'right' }];
    if (source === 'unit_expenses') return [{ field: 'uexp.title', title: 'Δαπάνη', w: 60, align: 'left' }, { field: 'uexp.share', title: 'Αναλογεί €', w: 22, align: 'right' }];
    return [{ field: 'unit.code', title: 'Διαμ.', w: 14, align: 'left' }, { field: 'unit.payer_name', title: 'Ονοματεπώνυμο', w: 50, align: 'left' }, { field: 'mills.general', title: 'Χιλ.', w: 14, align: 'right' }, { field: 'amt.current', title: 'Σύνολο', w: 20, align: 'right' }];
  }

  root.querySelectorAll('[data-add]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!editable) return;
      var t = b.getAttribute('data-add'), ps = pageSize();
      var e = { id: uid(), type: t, x: 20, y: 20, w: 60, h: 0, layer: 'data', size: 9, align: 'left' };
      if (t === 'field') e.field = isNotice ? 'unit.payer_name' : 'building.title';
      if (t === 'text') { e.text = 'Κείμενο'; e.layer = 'form'; }
      if (t === 'line') { e.layer = 'form'; e.w = 100; e.stroke = 0.3; }
      if (t === 'box') { e.layer = 'form'; e.w = 60; e.h = 25; e.stroke = 0.3; }
      if (t === 'image') { e.field = 'account.logo'; e.layer = 'form'; e.w = 35; e.h = 15; }
      if (t === 'table') {
        e.source = isNotice ? 'unit_expenses' : 'units'; e.y = 60; e.row_h = 6; e.rows = isNotice ? 8 : 20; e.size = 8; e.header_h = 6; e.header_size = 7; e.grid = true; e.columns = defaultColumns(e.source);
      }
      // Νέο στοιχείο στο ορατό σημείο της σελίδας.
      e.y = Math.min(ps[1] - 20, Math.max(10, Math.round((stage.scrollTop + 80) / zoom)));
      L.elements.push(e);
      selected = e.id;
      touch(); render(); renderProps();
    });
  });

  // ---------------------------------------------------------------- επιλογή & σύρσιμο
  var drag = null;
  paper.addEventListener('pointerdown', function (ev) {
    var el = ev.target.closest('.kx-dz-el');
    if (!el) { selected = null; render(); renderProps(); return; }
    var id = el.getAttribute('data-id');
    if (selected !== id) { selected = id; render(); renderProps(); }
    if (!editable) return;
    var e = find(id);
    var handle = ev.target.getAttribute('data-handle');
    drag = { e: e, handle: handle, sx: ev.clientX, sy: ev.clientY, x: e.x, y: e.y, w: e.w || 0, h: e.h || 0, moved: false };
    paper.setPointerCapture(ev.pointerId);
    ev.preventDefault();
  });
  paper.addEventListener('pointermove', function (ev) {
    if (!drag) return;
    var step = ev.altKey ? 0.1 : 0.5;
    var dx = (ev.clientX - drag.sx) / zoom, dy = (ev.clientY - drag.sy) / zoom;
    var snap = function (v) { return r2(Math.round(v / step) * step); };
    if (Math.abs(dx) + Math.abs(dy) > 0.3) drag.moved = true;
    if (drag.handle === 'w') drag.e.w = Math.max(1, snap(drag.w + dx));
    else if (drag.handle === 'h') drag.e.h = Math.max(1, snap(drag.h + dy));
    else { drag.e.x = snap(drag.x + dx); drag.e.y = snap(drag.y + dy); }
    render();
  });
  paper.addEventListener('pointerup', function () {
    if (drag && drag.moved) { touch(); renderProps(); }
    drag = null;
  });
  listEl.addEventListener('click', function (ev) {
    var li = ev.target.closest('li');
    if (!li) return;
    selected = li.getAttribute('data-id');
    render(); renderProps();
    var el = paper.querySelector('[data-id="' + selected + '"]');
    if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  });
  document.addEventListener('keydown', function (ev) {
    if (!selected || !editable) return;
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (/INPUT|SELECT|TEXTAREA/.test(tag)) return;
    var e = find(selected), step = ev.shiftKey ? 5 : ev.altKey ? 0.1 : 0.5;
    var k = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[ev.key];
    if (k) { e.x = r2(e.x + k[0] * step); e.y = r2(e.y + k[1] * step); ev.preventDefault(); touch(); render(); renderProps(); }
    if (ev.key === 'Delete') { L.elements = L.elements.filter(function (x) { return x.id !== selected; }); selected = null; touch(); render(); renderProps(); }
  });

  root.querySelectorAll('[data-view]').forEach(function (c) { c.addEventListener('change', function () { view[c.getAttribute('data-view')] = c.checked; render(); }); });
  var z = root.querySelector('[data-zoom]');
  z.addEventListener('input', function () { zoom = num(z.value, 3.5); render(); });

  if (!editable) root.querySelectorAll('[data-only-edit]').forEach(function (x) { x.hidden = true; });
  if (form) {
    form.addEventListener('submit', function () {
      L.elements.forEach(function (e) { if (e.columns) e.columns.forEach(function (c) { delete c._prev; }); });
      form.querySelector('[name="layout"]').value = JSON.stringify(L);
      dirty = false;
    });
  }
  window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

  render();
  renderPage();
  renderProps();
})();
