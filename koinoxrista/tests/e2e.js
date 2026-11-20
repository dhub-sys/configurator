// End-to-end δοκιμή σε εγκατεστημένο Joomla 6:  node tests/e2e.js <base-url> <out-dir>
// Περνά όλη τη ροή συνδρομητή και διαχειριστή πλατφόρμας και ελέγχει κάθε σελίδα για σφάλματα PHP.
const { chromium } = require('playwright');
const BASE = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = process.argv[3] || '/tmp';
const U = (q) => `${BASE}/index.php?option=com_koinoxrista&tmpl=component&${q}`;
const ADMIN = { user: process.env.KX_ADMIN_USER || 'admin', pass: process.env.KX_ADMIN_PASS || 'Admin12345678!' };
let problems = 0;
let shot = 0;

async function check(page, label, { screenshot = true } = {}) {
  const html = await page.content();
  const bad = html.match(/(Fatal error|Warning<\/b>|Notice<\/b>|Deprecated<\/b>|Whoops, looks like|Uncaught|Parse error|Undefined (variable|array key|property)|Call to (a member|undefined)|An error has occurred|must be of type)[^<]{0,300}/);
  const alerts = await page.$$eval('.kx-alert-error', (els) => els.map((e) => e.textContent.trim()));
  if (bad) { problems++; console.log(`✗ ${label}: ${bad[0].replace(/\s+/g, ' ').slice(0, 300)}`); }
  else console.log(`✓ ${label}${alerts.length ? '  [error alert: ' + alerts.join(' | ') + ']' : ''}`);
  if (screenshot) await page.screenshot({ path: `${OUT}/${String(++shot).padStart(2, '0')}-${label.replace(/[^a-z0-9]+/gi, '-')}.png`, fullPage: true });
  return html;
}
async function fill(page, sel, val) { await page.fill(sel, String(val)); }

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => { problems++; console.log('✗ JS error:', e.message); });
  page.on('dialog', (d) => d.accept());

  // ------------------------------------------------ εγγραφή συνδρομητή
  await page.goto(U('view=app'));
  await check(page, 'login');
  await page.goto(U('view=signup'));
  await check(page, 'signup');
  const email = `test${Date.now()}@example.com`;
  await fill(page, '[name=company]', 'Διαχειρίσεις Δοκιμής Ε.Π.Ε.');
  await fill(page, '[name=name]', 'Γιώργος Δοκιμαστής');
  await fill(page, '[name=email]', email);
  await fill(page, '[name=phone]', '2101234567');
  await fill(page, '[name=password]', 'Secret12345');
  await fill(page, '[name=password2]', 'Secret12345');
  await page.check('[name=terms]');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await check(page, 'dashboard-empty');

  // ------------------------------------------------ στοιχεία εταιρείας
  await page.goto(U('view=account'));
  await check(page, 'account-profile');

  // ------------------------------------------------ κτίριο
  await page.goto(U('view=building&id=0'));
  await check(page, 'building-new');
  await fill(page, '[name=street]', 'Αγίου Νικολάου');
  await fill(page, '[name=street_no]', '12');
  await fill(page, '[name=area]', 'Παγκράτι');
  await fill(page, '[name=postcode]', '11635');
  await fill(page, '[name=city]', 'Αθήνα');
  await fill(page, '[name=iban]', 'GR16 0110 1250 0000 0001 2300 695');
  await fill(page, '[name=beneficiary]', 'Πολυκατοικία Αγ. Νικολάου 12');
  await page.selectOption('[name=heating_type]', 'oil');
  await page.selectOption('[name=heating_method]', 'hours');
  await Promise.all([page.waitForNavigation(), page.click('.kx-savebar button')]);
  await check(page, 'building-units-empty');
  const bid = new URL(page.url()).searchParams.get('id');

  // Ιδιοκτησίες: 4 κενές γραμμές + 1 νέα.
  await page.click('[data-add-row][data-count="5"]');
  const units = [
    ['Ι1', 'Ισόγ.', 'Παπαδόπουλος Γ.', '', 60, [150, 0, 160], 1],
    ['Α1', 'Α΄', 'Ιωάννου Μ.', 'Νικολάου Σ.', 90, [250, 200, 240], 1.1],
    ['Β1', 'Β΄', 'Κωνσταντίνου Ν.', '', 95, [280, 350, 280], 1],
    ['Γ1', 'Γ΄', 'Γεωργίου Ε.', 'Δημητρίου Α.', 110, [320, 450, 320], 0.9],
  ];
  const rows = await page.$$('#kx-units tbody tr:not(.kx-tpl-row)');
  for (let i = 0; i < units.length; i++) {
    const r = rows[i], u = units[i];
    await (await r.$('input[name$="[code]"]')).fill(u[0]);
    await (await r.$('input[name$="[floor]"]')).fill(u[1]);
    await (await r.$('input[name$="[owner_name]"]')).fill(u[2]);
    await (await r.$('input[name$="[tenant_name]"]')).fill(u[3]);
    await (await r.$('input[name$="[area]"]')).fill(String(u[4]));
    const mills = await r.$$('input.kx-mill');
    for (let k = 0; k < 3; k++) await mills[k].fill(String(u[5][k]).replace('.', ','));
    await (await r.$('input[name$="[heat_coef]"]')).fill(String(u[6]).replace('.', ','));
  }
  await (await rows[0].$('input[name$="[opening_balance]"]')).fill('25,50');
  const sums = await page.$$eval('[data-sum]', (els) => els.map((e) => e.textContent));
  console.log('  live mill sums:', sums.join(' / '));
  await Promise.all([page.waitForNavigation(), page.click('#kx-units-form button[type=submit]')]);
  await check(page, 'building-units-saved');
  const savedSums = await page.$$eval('[data-sum]', (els) => els.map((e) => e.textContent + (e.classList.contains('is-ok') ? ' ok' : ' OFF')));
  console.log('  saved mill sums:', savedSums.join(' / '));

  // Πρόσθετη στήλη χιλιοστών.
  await fill(page, '[name=new_key]', 'Κήπος');
  await Promise.all([page.waitForNavigation(), page.click('.kx-keys button[type=submit]')]);
  await check(page, 'building-keys', { screenshot: false });

  await page.goto(U(`view=building&id=${bid}&tab=import`));
  await check(page, 'building-import', { screenshot: false });
  await page.goto(U(`view=building&id=${bid}&tab=info`));
  await check(page, 'building-info', { screenshot: false });

  // ------------------------------------------------ μήνας 1
  await page.goto(U(`view=building&id=${bid}&tab=periods`));
  await check(page, 'building-periods');
  await page.selectOption('[name=month]', '10');
  await page.selectOption('[name=year]', '2026');
  await Promise.all([page.waitForNavigation(), page.click('form:has([name=building_id]) button[type=submit]')]);
  await check(page, 'period-new');
  const pid = new URL(page.url()).searchParams.get('id');

  async function addExpense(e) {
    await page.goto(U(`view=period&id=${pid}`));
    await fill(page, '[data-expense-form] [name=title]', e.title);
    await fill(page, '[data-expense-form] [name=amount]', e.amount);
    if (e.category) { await page.selectOption('[data-expense-form] [name=category]', e.category); }
    if (e.method) await page.selectOption('[data-expense-form] [name=method]', e.method);
    if (e.payer) await page.selectOption('[data-expense-form] [name=payer]', e.payer);
    if (e.recurring) await page.check('[data-expense-form] [name=recurring]');
    if (e.subset) { await page.click('[data-subset] summary'); for (const code of e.subset) await page.click(`.kx-chip:has-text("${code}")`); }
    if (e.custom) { for (const [code, v] of Object.entries(e.custom)) { const lab = page.locator('.kx-custom-grid label', { hasText: code }); await lab.locator('input').fill(v); } }
    await Promise.all([page.waitForNavigation(), page.click('[data-expense-submit]')]);
  }
  await addExpense({ title: 'ΔΕΗ κοινοχρήστων χώρων', amount: '123,45', recurring: true });
  await addExpense({ title: 'Συντήρηση ανελκυστήρα', amount: '80' });
  await addExpense({ title: 'Καθαρισμός', amount: '40', method: 'equal', recurring: true });
  await addExpense({ title: 'Αποθεματικό', amount: '100' });
  await addExpense({ title: 'Τζάμι εισόδου', amount: '', category: 'special', method: 'custom', custom: { 'Β1': '25' } });
  await addExpense({ title: 'Λαμπτήρες ορόφων', amount: '10', category: 'special', method: 'equal', subset: ['Α1', 'Β1', 'Γ1'] });
  await check(page, 'period-expenses');
  const cats = await page.$$eval('.kx-table tbody tr', (trs) => trs.map((t) => t.children[2] && t.children[2].textContent.trim() + '/' + (t.children[4] && t.children[4].textContent.trim())));
  console.log('  expense categories/payers:', cats.join(', '));

  // Επεξεργασία δαπάνης (JS γεμίζει τη φόρμα).
  await page.click('[data-edit-expense] >> nth=1');
  await fill(page, '[data-expense-form] [name=amount]', '85');
  await Promise.all([page.waitForNavigation(), page.click('[data-expense-submit]')]);
  await check(page, 'period-expense-edited', { screenshot: false });

  // Θέρμανση: καύσιμο → δαπάνη, ενδείξεις.
  await page.goto(U(`view=period&id=${pid}&tab=heating`));
  await check(page, 'period-heating-empty', { screenshot: false });
  await fill(page, '[name=start_qty]', '500'); await fill(page, '[name=start_price]', '1,10');
  await fill(page, '[name=bought_qty]', '1000'); await fill(page, '[name=bought_amount]', '1.250,00'); await fill(page, '[name=end_qty]', '300');
  await Promise.all([page.waitForNavigation(), page.click('button[name=make_expense]')]);
  await check(page, 'period-after-fuel', { screenshot: false });
  await page.goto(U(`view=period&id=${pid}&tab=heating`));
  const prev = await page.$$('input[name^="prev["]'), curr = await page.$$('input[name^="curr["]');
  const hours = [[0, 100], [0, 0], [0, 200], [0, 300]];
  for (let i = 0; i < prev.length; i++) { await prev[i].fill(String(hours[i][0])); await curr[i].fill(String(hours[i][1])); }
  await Promise.all([page.waitForNavigation(), page.click('form:has([name^="curr["]) button[type=submit]')]);
  await check(page, 'period-heating');

  await page.goto(U(`view=period&id=${pid}&tab=split`));
  await check(page, 'period-split');
  await page.goto(U(`view=period&id=${pid}&tab=details`));
  await fill(page, '[name=notes]', 'Η γενική συνέλευση θα γίνει την Πέμπτη 20/11 στις 19:00.');
  await Promise.all([page.waitForNavigation(), page.click('form:has([name=notes]) button[type=submit]')]);
  await check(page, 'period-details', { screenshot: false });
  await page.goto(U(`view=period&id=${pid}&tab=print`));
  await check(page, 'period-print-tab');

  // Εκτυπώσεις (πρόχειρο).
  await page.goto(U(`view=print&period_id=${pid}&type=sheet&mode=full`));
  await check(page, 'print-sheet-draft');
  await page.goto(U(`view=print&period_id=${pid}&type=sheet&mode=data&grid=1`));
  await check(page, 'print-sheet-data-grid');
  await page.goto(U(`view=print&period_id=${pid}&type=notice&mode=full`));
  await check(page, 'print-notices');

  // CSV
  const resp = await page.request.get(U(`task=period.export&id=${pid}`));
  const csv = await resp.text();
  console.log(resp.ok() && csv.includes('Πληρωτέο') ? '✓ csv export' : (problems++, '✗ csv export: ' + csv.slice(0, 200)));

  // Έκδοση.
  await page.goto(U(`view=period&id=${pid}&tab=print`));
  await Promise.all([page.waitForNavigation(), page.click('form:has([value="period.issue"]) button')]);
  await check(page, 'period-issued');
  await page.goto(U(`view=print&period_id=${pid}&type=notice&mode=full`));
  await check(page, 'print-notices-issued', { screenshot: false });

  // Εισπράξεις.
  await page.goto(U(`view=payments&building_id=${bid}`));
  await check(page, 'payments');
  await page.click('.kx-table tbody tr:nth-child(2) a.kx-btn');
  await page.waitForLoadState();
  await fill(page, '[name=amount]', '50');
  await Promise.all([page.waitForNavigation(), page.click('form:has([name=unit_id]) button[type=submit]')]);
  await check(page, 'payments-after');
  const receipt = await page.$('a[href*="view=receipt"]');
  if (receipt) { await page.goto(await receipt.getAttribute('href')); await check(page, 'receipt'); }

  // Μήνας 2: αντιγραφή επαναλαμβανόμενων, προηγούμενο υπόλοιπο.
  await page.goto(U(`view=building&id=${bid}&tab=periods`));
  await Promise.all([page.waitForNavigation(), page.click('form:has([name=building_id]) button[type=submit]')]);
  await check(page, 'period2', { screenshot: false });
  const pid2 = new URL(page.url()).searchParams.get('id');
  const copied = await page.$$eval('.kx-table tbody tr', (t) => t.length);
  console.log('  recurring expenses copied:', copied);
  await page.goto(U(`view=period&id=${pid2}&tab=split`));
  await check(page, 'period2-split');
  await page.goto(U(`view=period&id=${pid2}&tab=heating`));
  const prevVals = await page.$$eval('input[name^="prev["]', (els) => els.map((e) => e.value));
  console.log('  readings carried over:', prevVals.join(','));

  // Πρότυπα & σχεδιαστής.
  await page.goto(U('view=templates'));
  await check(page, 'templates');
  await Promise.all([page.waitForNavigation(), page.click('form:has([value="template.copy"]) button >> nth=0')]);
  await check(page, 'designer');
  const el = await page.$('.kx-dz-el.kx-dz-field');
  const box = await el.boundingBox();
  await page.mouse.move(box.x + 5, box.y + 5); await page.mouse.down(); await page.mouse.move(box.x + 60, box.y + 30, { steps: 5 }); await page.mouse.up();
  await page.click('[data-add="text"]');
  await check(page, 'designer-edited');
  await Promise.all([page.waitForNavigation(), page.click('#kx-designer-form button[type=submit]')]);
  await check(page, 'designer-saved', { screenshot: false });
  const tid = new URL(page.url()).searchParams.get('id');
  await page.goto(U(`view=print&sample=1&template_id=${tid}`));
  await check(page, 'template-sample-print', { screenshot: false });

  // Συνδρομή.
  await page.goto(U('view=account&tab=billing'));
  await check(page, 'billing');
  await Promise.all([page.waitForNavigation(), page.click('.kx-plan.is-highlight button[type=submit]')]);
  await check(page, 'billing-requested', { screenshot: false });
  for (const t of ['print', 'team', 'me']) { await page.goto(U('view=account&tab=' + t)); await check(page, 'account-' + t, { screenshot: false }); }
  await page.goto(U('view=dashboard'));
  await check(page, 'dashboard');
  await page.goto(U('view=buildings'));
  await check(page, 'buildings', { screenshot: false });
  await page.goto(U('view=help'));
  await check(page, 'help', { screenshot: false });

  // ------------------------------------------------ διαχειριστής πλατφόρμας
  await page.goto(U('task=auth.logout'));
  await page.goto(U('view=app'));
  await fill(page, '[name=username]', ADMIN.user); await fill(page, '[name=password]', ADMIN.pass);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await check(page, 'platform');
  for (const t of ['accounts', 'packages', 'settings']) { await page.goto(U('view=platform&tab=' + t)); await check(page, 'platform-' + t); }
  await page.goto(U('view=platform&tab=packages&pid=3'));
  await check(page, 'platform-package-edit', { screenshot: false });
  await page.goto(U('view=platform&tab=accounts'));
  await page.click('.kx-table tbody tr:first-child a.kx-strong');
  await page.waitForLoadState();
  await check(page, 'platform-account');
  await page.selectOption('form:has([value="platform.recordpayment"]) [name=package_id]', '3');
  await Promise.all([page.waitForNavigation(), page.click('form:has([value="platform.recordpayment"]) button[type=submit]')]);
  await check(page, 'platform-payment-recorded', { screenshot: false });
  await Promise.all([page.waitForNavigation(), page.click('form:has([value="platform.enter"]) button >> nth=0')]);
  await check(page, 'platform-acting-dashboard', { screenshot: false });
  await page.goto(U('view=templates'));
  await check(page, 'admin-templates', { screenshot: false });

  console.log(problems ? `\n${problems} problem(s)` : '\nall pages OK');
  await browser.close();
  process.exit(problems ? 1 : 0);
})().catch((e) => { console.error('✗ ABORT', e.message.split('\n')[0]); process.exit(2); });
