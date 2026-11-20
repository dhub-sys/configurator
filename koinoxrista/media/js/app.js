/* Μικρές αλληλεπιδράσεις της εφαρμογής (χωρίς εξωτερικές βιβλιοθήκες). */
(function () {
  'use strict';
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function num(v) { v = String(v || '').trim().replace(/\s|€/g, ''); if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.'); var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function fmt(n, d) { return n.toLocaleString('el-GR', { minimumFractionDigits: d == null ? 2 : 0, maximumFractionDigits: d == null ? 2 : d }); }

  // Κλείσιμο μηνυμάτων.
  document.addEventListener('click', function (e) {
    if (e.target.classList.contains('kx-alert-x')) e.target.parentNode.remove();
  });

  // Επιβεβαίωση πριν από διαγραφές κ.λπ.
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute && f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  }, true);

  // Εναλλαγή κλάσης (π.χ. εμφάνιση στηλών επικοινωνίας), με μνήμη ανά browser.
  $$('[data-toggle-class]').forEach(function (c) {
    var cls = c.getAttribute('data-toggle-class'), t = $(c.getAttribute('data-target'));
    var key = 'kx.' + cls;
    try { c.checked = localStorage.getItem(key) === '1'; } catch (x) {}
    var apply = function () { if (t) t.classList.toggle(cls, c.checked); try { localStorage.setItem(key, c.checked ? '1' : '0'); } catch (x) {} };
    c.addEventListener('change', apply);
    apply();
  });

  // Πίνακας ιδιοκτησιών: νέες γραμμές και ζωντανά σύνολα χιλιοστών.
  var rowCounter = 0;
  $$('[data-add-row]').forEach(function (b) {
    b.addEventListener('click', function () {
      var table = $(b.getAttribute('data-add-row')), tpl = $(b.getAttribute('data-template'));
      var n = +(b.getAttribute('data-count') || 1), first = null;
      for (var i = 0; i < n; i++) {
        rowCounter++;
        var html = tpl.innerHTML.replace(/__N__/g, 'n' + Date.now() + rowCounter);
        var tb = table.tBodies[0];
        tb.insertAdjacentHTML('beforeend', html);
        var row = tb.lastElementChild;
        row.classList.remove('kx-tpl-row');
        if (!first) first = row;
      }
      if (first) { var inp = first.querySelector('input'); if (inp) inp.focus(); }
      sums();
    });
  });
  function sums() {
    $$('[data-sum]').forEach(function (el) {
      var k = el.getAttribute('data-sum'), s = 0;
      $$('.kx-mill[data-key="' + k + '"]').forEach(function (i) {
        var tr = i.closest('tr'), del = tr && tr.querySelector('input[name$="[_delete]"]');
        if (!del || !del.checked) s += num(i.value);
      });
      el.textContent = fmt(s, 3);
      el.classList.toggle('is-ok', Math.abs(s - 1000) < 0.001);
      el.classList.toggle('is-off', Math.abs(s - 1000) >= 0.001);
    });
  }
  document.addEventListener('input', function (e) { if (e.target.classList && e.target.classList.contains('kx-mill')) sums(); });
  document.addEventListener('change', function (e) {
    if (e.target.name && /\[_delete\]$/.test(e.target.name)) { e.target.closest('tr').classList.toggle('is-deleted', e.target.checked); sums(); }
  });
  // Enter στο φύλλο ιδιοκτησιών: πήγαινε στο ίδιο κελί της επόμενης γραμμής (όπως στο Excel).
  var sheet = $('#kx-units');
  if (sheet) sheet.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
    e.preventDefault();
    var td = e.target.closest('td'), tr = td.parentNode, idx = Array.prototype.indexOf.call(tr.children, td);
    var next = tr.nextElementSibling;
    var inp = next && next.children[idx] && next.children[idx].querySelector('input:not([type=hidden]),select');
    if (inp) inp.focus();
  });

  // Ενδείξεις θέρμανσης: ζωντανή κατανάλωση.
  var readings = $('[data-readings]');
  if (readings) readings.addEventListener('input', function (e) {
    var tr = e.target.closest('tr'), ins = tr.querySelectorAll('input');
    var d = num(ins[1].value) - num(ins[0].value);
    $('[data-diff]', tr).textContent = d >= 0 ? fmt(d, 2) : '⚠ ' + fmt(d, 2);
  });

  // Φόρμα δαπάνης.
  var ef = $('[data-expense-form]');
  if (ef) {
    var keymap = JSON.parse(ef.getAttribute('data-keymap') || '{}');
    var method = $('[data-method]', ef), key = $('[data-key]', ef), cat = $('[data-category]', ef);
    var custom = $('[data-custom]', ef), subset = $('[data-subset]', ef);
    var sync = function () {
      var m = method.value;
      key.closest('label').hidden = !(m === 'key' || m === 'heating');
      custom.hidden = m !== 'custom';
      subset.hidden = m === 'custom';
      var n = $$('input[name="unit_ids[]"]:checked', ef).length;
      $('[data-subset-count]', ef).textContent = n ? '(' + n + ' επιλεγμένες)' : '';
    };
    cat.addEventListener('change', function () {
      if (keymap[cat.value]) key.value = keymap[cat.value];
      if (cat.value === 'heating' && method.value === 'key' && method.querySelector('option[value=heating]')) method.value = 'heating';
      if (cat.value !== 'heating' && method.value === 'heating') method.value = 'key';
      sync();
    });
    method.addEventListener('change', sync);
    ef.addEventListener('change', function (e) { if (e.target.name === 'unit_ids[]') sync(); });
    // Αυτόματη κατηγορία από γνωστές περιγραφές.
    $('[name=title]', ef).addEventListener('change', function () {
      var t = this.value.toLowerCase();
      var c = /ανελκ|ασανσ/.test(t) ? 'elevator' : /πετρέλ|καυσ|θέρμαν|καυστ|φυσικό αέριο/.test(t) ? 'heating' : null;
      if (c && cat.value === 'general' && ef.querySelector('[name=expense_id]').value === '0') { cat.value = c; cat.dispatchEvent(new Event('change')); }
      if (/αποθεματικ/.test(t)) ef.querySelector('[name=payer]').value = 'owner';
    });
    var title = $('[data-expense-title]'), reset = $('[data-expense-reset]'), submit = $('[data-expense-submit]', ef);
    var clear = function () {
      ef.reset();
      ef.querySelector('[name=expense_id]').value = '0';
      title.textContent = 'Νέα δαπάνη';
      submit.textContent = 'Προσθήκη δαπάνης';
      reset.hidden = true;
      subset.open = false;
      sync();
    };
    reset.addEventListener('click', clear);
    $$('[data-edit-expense]').forEach(function (b) {
      b.addEventListener('click', function () {
        var x = JSON.parse(b.getAttribute('data-edit-expense'));
        clear();
        ef.querySelector('[name=expense_id]').value = x.id;
        ['title', 'amount', 'category', 'method', 'key_id', 'payer', 'supplier', 'doc_no', 'doc_date'].forEach(function (k) {
          var el = ef.querySelector('[name=' + k + ']');
          if (el && x[k] != null) el.value = x[k];
        });
        ef.querySelector('[name=recurring]').checked = !!x.recurring;
        $$('input[name="unit_ids[]"]', ef).forEach(function (c) { c.checked = x.unit_ids.indexOf(+c.value) >= 0; });
        subset.open = x.unit_ids.length > 0;
        Object.keys(x.custom || {}).forEach(function (u) { var el = ef.querySelector('[name="custom[' + u + ']"]'); if (el) el.value = x.custom[u]; });
        title.textContent = 'Επεξεργασία: ' + x.title;
        submit.textContent = 'Αποθήκευση αλλαγών';
        reset.hidden = false;
        sync();
        $('#expense-form').scrollIntoView({ behavior: 'smooth' });
      });
    });
    sync();
  }

  // Επιλογή τύπου εκτύπωσης.
  $$('[data-print-type]').forEach(function (r) {
    r.addEventListener('change', function () {
      $$('[data-for-type]').forEach(function (el) { el.hidden = el.getAttribute('data-for-type') !== r.value; });
    });
  });
})();
