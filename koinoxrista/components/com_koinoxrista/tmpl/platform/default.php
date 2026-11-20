<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

Auth::requirePlatformAdmin();
$tab = $this->in->getCmd('tab', 'overview');
$tabs = ['overview' => 'Επισκόπηση', 'accounts' => 'Συνδρομητές', 'packages' => 'Πακέτα χρεώσεων', 'settings' => 'Ρυθμίσεις πλατφόρμας'];
$packages = Db::all('SELECT * FROM #__kx_packages ORDER BY ordering, id');
$pkgOpts = [];
foreach ($packages as $p) $pkgOpts[$p->id] = $p->title . ((int) $p->published ? '' : ' (ανενεργό)');
$today = Db::today();
$accounts = Db::all('SELECT a.*, p.title AS package, p.price_month, p.price_year,
    (SELECT COUNT(*) FROM #__kx_buildings b WHERE b.account_id=a.id AND b.archived=0) AS buildings,
    (SELECT COUNT(*) FROM #__kx_units u JOIN #__kx_buildings b ON b.id=u.building_id WHERE b.account_id=a.id AND b.archived=0) AS units,
    (SELECT u.email FROM #__kx_account_users m JOIN #__users u ON u.id=m.user_id WHERE m.account_id=a.id AND m.role=\'owner\' LIMIT 1) AS owner_email
    FROM #__kx_accounts a LEFT JOIN #__kx_packages p ON p.id=a.package_id ORDER BY a.created DESC');
$stateBadge = static function ($a) {
    $st = Auth::state($a);
    return Ui::badge($st['label'], $st['ok'] ? ($st['code'] === 'trial' ? 'info' : 'success') : ($st['code'] === 'suspended' ? 'neutral' : 'danger'));
};
echo Ui::open('platform', 'Πλατφόρμα', '', 'Διαχείριση συνδρομητών, πακέτων και ρυθμίσεων του SaaS');
?>
<nav class="kx-tabs">
  <?php foreach ($tabs as $k => $label): ?><a class="<?= $tab === $k || ($tab === 'account' && $k === 'accounts') ? 'is-active' : '' ?>" href="<?= Url::e('view=platform&tab=' . $k) ?>"><?= Fmt::e($label) ?></a><?php endforeach; ?>
  <a href="<?= Url::e('view=templates') ?>">Πρότυπα συστήματος</a>
</nav>

<?php if ($tab === 'overview'):
    $active = $trial = $expired = 0; $mrr = 0.0; $soon = [];
    foreach ($accounts as $a) {
        if (Auth::isInternal($a)) continue;
        $st = Auth::state($a);
        if ($st['code'] === 'active') { $active++; $mrr += $a->billing_cycle === 'year' ? (float) $a->price_year / 12 : (float) $a->price_month; }
        elseif ($st['code'] === 'trial') $trial++;
        elseif ($st['code'] === 'expired') $expired++;
        if ($st['ok'] && $st['until'] && $st['until'] <= date('Y-m-d', strtotime($today . ' +14 days'))) $soon[] = $a;
    }
    $requests = Db::all("SELECT b.*, a.name, p.title FROM #__kx_billing b JOIN #__kx_accounts a ON a.id=b.account_id LEFT JOIN #__kx_packages p ON p.id=b.package_id WHERE b.kind='request' AND b.status='pending' ORDER BY b.id DESC");
    $revenue = (float) Db::value("SELECT SUM(amount) FROM #__kx_billing WHERE kind='payment' AND status='paid' AND created>=" . Db::q(date('Y-01-01')));
?>
<section class="kx-kpis">
  <div class="kx-kpi"><span>Ενεργοί συνδρομητές</span><strong><?= $active ?></strong><small><?= $trial ?> σε δοκιμή · <?= $expired ?> ληγμένοι</small></div>
  <div class="kx-kpi"><span>Μηνιαία έσοδα (MRR)</span><strong><?= Fmt::money($mrr) ?> €</strong><small>προ ΦΠΑ</small></div>
  <div class="kx-kpi"><span>Εισπράξεις <?= date('Y') ?></span><strong><?= Fmt::money($revenue) ?> €</strong></div>
  <div class="kx-kpi"><span>Κτίρια στην πλατφόρμα</span><strong><?= array_sum(array_map(static fn($a) => (int) $a->buildings, array_filter($accounts, static fn($a) => !Auth::isInternal($a)))) ?></strong><small><?= array_sum(array_map(static fn($a) => (int) $a->units, array_filter($accounts, static fn($a) => !Auth::isInternal($a)))) ?> ιδιοκτησίες</small></div>
</section>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <h2>Αιτήματα πακέτων</h2>
    <?php if (!$requests): ?><p class="kx-muted">Δεν υπάρχουν εκκρεμή αιτήματα.</p><?php else: ?>
    <table class="kx-table"><thead><tr><th>Συνδρομητής</th><th>Πακέτο</th><th class="kx-num">Ποσό</th><th>Ημ/νία</th><th></th></tr></thead><tbody>
      <?php foreach ($requests as $r): ?><tr><td><?= Fmt::e($r->name) ?></td><td><?= Fmt::e($r->title) ?> · <?= $r->cycle === 'year' ? 'ετήσια' : 'μηνιαία' ?></td><td class="kx-num"><?= Fmt::euro($r->amount) ?></td><td><?= Fmt::date(substr((string) $r->created, 0, 10)) ?></td>
        <td class="kx-right"><a class="kx-btn kx-btn-small" href="<?= Url::e('view=platform&tab=account&id=' . (int) $r->account_id) ?>">Καταχώριση πληρωμής</a></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
  <aside class="kx-col-side"><section class="kx-card">
    <h2>Λήγουν σύντομα</h2>
    <?php if (!$soon): ?><p class="kx-muted">Καμία λήξη τις επόμενες 14 ημέρες.</p><?php else: ?><ul class="kx-list">
      <?php foreach ($soon as $a): $st = Auth::state($a); ?><li><a href="<?= Url::e('view=platform&tab=account&id=' . (int) $a->id) ?>"><strong><?= Fmt::e($a->name) ?></strong><span><?= Fmt::e($st['label']) ?> · <?= Fmt::date($st['until']) ?></span></a></li><?php endforeach; ?>
    </ul><?php endif; ?>
  </section></aside>
</div>

<?php elseif ($tab === 'accounts'): ?>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <h2>Συνδρομητές (<?= count($accounts) ?>)</h2>
    <div class="kx-table-wrap"><table class="kx-table">
      <thead><tr><th>Συνδρομητής</th><th>Πακέτο</th><th>Κατάσταση</th><th class="kx-num">Κτίρια</th><th class="kx-num">Ιδιοκτ.</th><th>Εγγραφή</th><th></th></tr></thead><tbody>
      <?php foreach ($accounts as $a): ?>
        <tr><td><a class="kx-strong" href="<?= Url::e('view=platform&tab=account&id=' . (int) $a->id) ?>"><?= Fmt::e($a->name) ?></a><?= Auth::isInternal($a) ? ' ' . Ui::badge('Δικός μου', 'info') : '' ?><div class="kx-muted"><?= Fmt::e($a->owner_email) ?></div></td>
          <td><?= Fmt::e($a->package) ?></td><td><?= $stateBadge($a) ?></td><td class="kx-num"><?= (int) $a->buildings ?></td><td class="kx-num"><?= (int) $a->units ?></td>
          <td><?= Fmt::date(substr((string) $a->created, 0, 10)) ?></td>
          <td class="kx-right"><?= Ui::postButton('platform.enter', ['id' => $a->id], 'Είσοδος', 'small') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </section>
  <aside class="kx-col-side"><section class="kx-card">
    <h2>Νέος συνδρομητής</h2>
    <?= Ui::form('platform.createaccount', 'class="kx-stack-sm"') ?>
      <?= Ui::input('company', '', 'Επωνυμία', ['required' => true]) ?>
      <?= Ui::input('name', '', 'Υπεύθυνος') ?>
      <?= Ui::input('email', '', 'Email (όνομα χρήστη)', ['type' => 'email', 'required' => true]) ?>
      <?= Ui::input('phone', '', 'Τηλέφωνο') ?>
      <?= Ui::input('password', '', 'Κωδικός (8+)', ['type' => 'text', 'autocomplete' => 'off', 'help' => 'Αν υπάρχει ήδη χρήστης με αυτό το email, συνδέεται ο υπάρχων.']) ?>
      <?= Ui::select('package_id', $pkgOpts, (int) Settings::get('signup_package_id'), 'Πακέτο') ?>
      <button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Δημιουργία</button>
    </form>
  </section></aside>
</div>

<?php elseif ($tab === 'account'):
    $a = Db::one('SELECT * FROM #__kx_accounts WHERE id=' . $this->in->getInt('id', 0));
    if (!$a) { echo Ui::empty('Δεν βρέθηκε', '') . Ui::close(); return; }
    $billing = Db::all('SELECT b.*, p.title FROM #__kx_billing b LEFT JOIN #__kx_packages p ON p.id=b.package_id WHERE b.account_id=' . (int) $a->id . ' ORDER BY b.id DESC');
    $members = Db::all('SELECT m.role, u.name, u.email, u.block FROM #__kx_account_users m JOIN #__users u ON u.id=m.user_id WHERE m.account_id=' . (int) $a->id);
    $usage = Auth::usage($a);
?>
<div class="kx-row kx-mb"><a href="<?= Url::e('view=platform&tab=accounts') ?>">← Συνδρομητές</a><span class="kx-flex"></span><?= Ui::postButton('platform.enter', ['id' => $a->id], 'Είσοδος στον λογαριασμό', 'secondary') ?></div>
<div class="kx-cols">
  <div class="kx-col-main">
    <section class="kx-card">
      <div class="kx-card-head"><h2><?= Fmt::e($a->name) ?></h2><?= $stateBadge($a) ?></div>
      <?= Ui::form('platform.saveaccount') ?>
        <input type="hidden" name="id" value="<?= (int) $a->id ?>">
        <div class="kx-form-grid">
          <?= Ui::input('name', $a->name, 'Επωνυμία') ?>
          <?= Ui::select('package_id', $pkgOpts, $a->package_id, 'Πακέτο') ?>
          <?= Ui::select('status', ['trial' => 'Δοκιμαστική περίοδος', 'active' => 'Ενεργή συνδρομή', 'suspended' => 'Αναστολή'], $a->status, 'Κατάσταση') ?>
          <?= Ui::select('billing_cycle', ['month' => 'Μηνιαία', 'year' => 'Ετήσια'], $a->billing_cycle, 'Χρέωση') ?>
          <?= Ui::input('trial_ends', Fmt::date($a->trial_ends), 'Λήξη δοκιμής', ['placeholder' => 'ηη/μμ/εεεε']) ?>
          <?= Ui::input('paid_until', Fmt::date($a->paid_until), 'Εξοφλημένη έως', ['placeholder' => 'ηη/μμ/εεεε']) ?>
          <?= Ui::input('notes', $a->notes, 'Σημειώσεις (εσωτερικές)', ['type' => 'textarea', 'wide' => true]) ?>
        </div>
        <div class="kx-row"><button type="submit" class="kx-btn kx-btn-primary">Αποθήκευση</button></div>
      </form>
    </section>
    <section class="kx-card">
      <h2>Χρεώσεις & αιτήματα</h2>
      <?php if (!$billing): ?><p class="kx-muted">Καμία εγγραφή.</p><?php else: ?>
      <div class="kx-table-wrap"><table class="kx-table"><thead><tr><th>Ημ/νία</th><th>Είδος</th><th>Πακέτο</th><th>Περίοδος</th><th class="kx-num">Ποσό</th><th>Κατάσταση</th><th></th></tr></thead><tbody>
        <?php foreach ($billing as $r): ?><tr><td><?= Fmt::date(substr((string) $r->created, 0, 10)) ?></td><td><?= $r->kind === 'payment' ? 'Πληρωμή' : 'Αίτημα' ?></td><td><?= Fmt::e($r->title) ?></td>
          <td><?= $r->period_from ? Fmt::date($r->period_from) . ' – ' . Fmt::date($r->period_to) : '' ?></td><td class="kx-num"><?= Fmt::euro($r->amount) ?></td>
          <td><?= Fmt::e(['paid' => 'Εξοφλήθηκε', 'pending' => 'Εκκρεμεί', 'done' => 'Ολοκληρώθηκε', 'cancelled' => 'Ακυρώθηκε'][$r->status] ?? $r->status) ?><?= $r->reference ? ' · ' . Fmt::e($r->reference) : '' ?></td>
          <td><?= Ui::postButton('platform.deletebilling', ['id' => $r->id], '✕', 'small', 'Διαγραφή εγγραφής;') ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
  <aside class="kx-col-side">
    <section class="kx-card">
      <h2>Καταχώριση πληρωμής</h2>
      <?= Ui::form('platform.recordpayment', 'class="kx-stack-sm"') ?>
        <input type="hidden" name="account_id" value="<?= (int) $a->id ?>">
        <?= Ui::select('package_id', $pkgOpts, $a->package_id, 'Πακέτο') ?>
        <?= Ui::select('cycle', ['month' => 'Μηνιαία', 'year' => 'Ετήσια'], $a->billing_cycle, 'Χρέωση') ?>
        <?= Ui::input('months', $a->billing_cycle === 'year' ? 12 : 1, 'Μήνες', ['type' => 'number', 'min' => 1, 'max' => 60]) ?>
        <?= Ui::input('amount', '', 'Ποσό € (κενό = τιμή πακέτου)', ['inputmode' => 'decimal']) ?>
        <?= Ui::input('period_from', '', 'Από (κενό = συνέχεια)', ['placeholder' => 'ηη/μμ/εεεε']) ?>
        <?= Ui::select('method', ['Τράπεζα' => 'Τράπεζα', 'Κάρτα' => 'Κάρτα', 'Μετρητά' => 'Μετρητά', 'Άλλο' => 'Άλλο'], 'Τράπεζα', 'Τρόπος') ?>
        <?= Ui::input('reference', '', 'Αιτιολογία / αρ. τιμολογίου') ?>
        <button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Καταχώριση & ενεργοποίηση</button>
      </form>
    </section>
    <section class="kx-card">
      <h2>Χρήση</h2>
      <?= Ui::meter('Κτίρια', ...$usage['buildings']) . Ui::meter('Ιδιοκτησίες', ...$usage['units']) . Ui::meter('Χρήστες', ...$usage['users']) ?>
      <h3>Χρήστες</h3>
      <ul class="kx-list"><?php foreach ($members as $m): ?><li><span><strong><?= Fmt::e($m->name) ?></strong><span><?= Fmt::e($m->email) ?> · <?= $m->role === 'owner' ? 'υπεύθυνος' : 'συνεργάτης' ?><?= (int) $m->block ? ' · μπλοκαρισμένος' : '' ?></span></span></li><?php endforeach; ?></ul>
    </section>
  </aside>
</div>

<?php elseif ($tab === 'packages'):
    $edit = $this->in->getInt('pid', -1);
    $pk = $edit > 0 ? Db::one('SELECT * FROM #__kx_packages WHERE id=' . $edit) : null;
    $counts = [];
    foreach (Db::all('SELECT package_id, COUNT(*) AS n FROM #__kx_accounts GROUP BY package_id') as $r) $counts[(int) $r->package_id] = (int) $r->n;
?>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <div class="kx-card-head"><h2>Πακέτα χρεώσεων</h2><a class="kx-btn kx-btn-small" href="<?= Url::e('view=platform&tab=packages&pid=0') ?>">+ Νέο πακέτο</a></div>
    <div class="kx-table-wrap"><table class="kx-table">
      <thead><tr><th>Πακέτο</th><th class="kx-num">€/μήνα</th><th class="kx-num">€/έτος</th><th>Όρια</th><th>Δοκιμή</th><th class="kx-num">Συνδρ.</th><th></th></tr></thead><tbody>
      <?php foreach ($packages as $p): ?>
        <tr><td><strong><?= Fmt::e($p->title) ?></strong> <?= (int) $p->is_default ? Ui::badge('Προεπιλογή', 'info') : '' ?> <?= (int) $p->published ? '' : Ui::badge('Ανενεργό', 'neutral') ?></td>
          <td class="kx-num"><?= Fmt::money($p->price_month) ?></td><td class="kx-num"><?= Fmt::money($p->price_year) ?></td>
          <td class="kx-muted"><?= (int) $p->max_buildings ?: '∞' ?> κτίρια · <?= (int) $p->max_units ?: '∞' ?> ιδιοκτ. · <?= (int) $p->max_users ?: '∞' ?> χρήστες</td>
          <td><?= (int) $p->trial_days ? (int) $p->trial_days . ' ημ.' : '—' ?></td><td class="kx-num"><?= $counts[(int) $p->id] ?? 0 ?></td>
          <td class="kx-right kx-nowrap"><a class="kx-btn kx-btn-small" href="<?= Url::e('view=platform&tab=packages&pid=' . (int) $p->id) ?>">Επεξεργασία</a>
            <?php if (empty($counts[(int) $p->id])): ?><?= Ui::postButton('platform.deletepackage', ['id' => $p->id], '✕', 'small', 'Διαγραφή πακέτου;') ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p class="kx-muted kx-mt">Οι τιμές είναι προ ΦΠΑ (<?= Fmt::qty(Settings::get('vat_rate')) ?>%). Όριο 0 = απεριόριστο. Το πακέτο «Προεπιλογή» δίνεται στις νέες εγγραφές όταν δεν έχει οριστεί άλλο στις ρυθμίσεις.</p>
  </section>
  <aside class="kx-col-side">
    <?php if ($edit >= 0): $pk = $pk ?: (object) ['id' => 0, 'title' => '', 'description' => '', 'price_month' => 0, 'price_year' => 0, 'max_buildings' => 0, 'max_units' => 0, 'max_users' => 1, 'trial_days' => 0, 'features' => '{}', 'highlight' => 0, 'published' => 1, 'ordering' => count($packages) + 1, 'is_default' => 0];
        $f = json_decode((string) $pk->features, true) ?: []; ?>
    <section class="kx-card">
      <h2><?= (int) $pk->id ? 'Επεξεργασία πακέτου' : 'Νέο πακέτο' ?></h2>
      <?= Ui::form('platform.savepackage', 'class="kx-stack-sm"') ?>
        <input type="hidden" name="id" value="<?= (int) $pk->id ?>">
        <?= Ui::input('title', $pk->title, 'Ονομασία', ['required' => true]) ?>
        <?= Ui::input('description', $pk->description, 'Περιγραφή', ['type' => 'textarea', 'rows' => 2]) ?>
        <div class="kx-row"><?= Ui::input('price_month', Fmt::money($pk->price_month), '€ / μήνα', ['inputmode' => 'decimal']) ?><?= Ui::input('price_year', Fmt::money($pk->price_year), '€ / έτος', ['inputmode' => 'decimal']) ?></div>
        <div class="kx-row"><?= Ui::input('max_buildings', $pk->max_buildings, 'Κτίρια', ['type' => 'number', 'min' => 0]) ?><?= Ui::input('max_units', $pk->max_units, 'Ιδιοκτησίες', ['type' => 'number', 'min' => 0]) ?><?= Ui::input('max_users', $pk->max_users, 'Χρήστες', ['type' => 'number', 'min' => 0]) ?></div>
        <div class="kx-row"><?= Ui::input('trial_days', $pk->trial_days, 'Ημέρες δοκιμής', ['type' => 'number', 'min' => 0]) ?><?= Ui::input('ordering', $pk->ordering, 'Σειρά', ['type' => 'number']) ?></div>
        <fieldset class="kx-fieldset"><legend>Λειτουργίες</legend>
          <?php foreach (Auth::FEATURES as $k => $label): ?><?= Ui::check('features[' . $k . ']', !empty($f[$k]), $label) ?><?php endforeach; ?>
        </fieldset>
        <?= Ui::check('published', (bool) (int) $pk->published, 'Διαθέσιμο στους πελάτες') ?>
        <?= Ui::check('highlight', (bool) (int) $pk->highlight, 'Προβολή ως «Δημοφιλές»') ?>
        <?= Ui::check('is_default', (bool) (int) $pk->is_default, 'Προεπιλογή για νέες εγγραφές') ?>
        <button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Αποθήκευση</button>
      </form>
    </section>
    <?php else: ?>
    <section class="kx-card"><p class="kx-muted">Επιλέξτε πακέτο για επεξεργασία ή δημιουργήστε νέο.</p></section>
    <?php endif; ?>
  </aside>
</div>

<?php else: /* settings */ $s = Settings::all(); ?>
<?= Ui::form('platform.savesettings', 'class="kx-stack"', true) ?>
<section class="kx-card">
  <h2>Επωνυμία εφαρμογής (white label)</h2>
  <div class="kx-form-grid">
    <?= Ui::input('brand_name', $s['brand_name'], 'Όνομα εφαρμογής') ?>
    <?= Ui::input('brand_tagline', $s['brand_tagline'], 'Υπότιτλος (οθόνη σύνδεσης)') ?>
    <?= Ui::input('brand_color', $s['brand_color'], 'Χρώμα', ['type' => 'color']) ?>
    <label class="kx-field"><span>Λογότυπο εφαρμογής</span><input type="file" name="brand_logo_file" accept="image/png,image/jpeg,image/webp"></label>
    <?php if ($s['brand_logo']): ?><div class="kx-logo-preview kx-logo-dark"><img src="<?= Fmt::e(Settings::asset($s['brand_logo'])) ?>" alt=""><?= Ui::check('remove_logo', false, 'Αφαίρεση') ?></div><?php endif; ?>
  </div>
</section>
<section class="kx-card">
  <h2>Εγγραφές & ασφάλεια</h2>
  <div class="kx-form-grid">
    <?= Ui::check('signup_enabled', (bool) $s['signup_enabled'], 'Ανοιχτές δημόσιες εγγραφές') ?>
    <?= Ui::select('signup_package_id', [0 => '— Πακέτο «Προεπιλογή» —'] + $pkgOpts, $s['signup_package_id'], 'Πακέτο νέων εγγραφών') ?>
    <?= Ui::check('otp_enabled', (bool) $s['otp_enabled'], 'Κωδικός μίας χρήσης στο email σε κάθε σύνδεση (2FA)') ?>
    <?= Ui::input('terms_url', $s['terms_url'], 'URL όρων χρήσης') ?>
  </div>
</section>
<section class="kx-card">
  <h2>Χρεώσεις & υποστήριξη</h2>
  <div class="kx-form-grid">
    <?= Ui::input('vat_rate', Fmt::qty($s['vat_rate']), 'ΦΠΑ %', ['inputmode' => 'decimal']) ?>
    <?= Ui::input('support_email', $s['support_email'], 'Email υποστήριξης (ειδοποιήσεις εγγραφών & αιτημάτων)', ['type' => 'email']) ?>
    <?= Ui::input('support_phone', $s['support_phone'], 'Τηλέφωνο υποστήριξης') ?>
    <?= Ui::input('payment_info', $s['payment_info'], 'Οδηγίες πληρωμής συνδρομής (IBAN, αιτιολογία…)', ['type' => 'textarea', 'wide' => true, 'rows' => 4]) ?>
    <?= Ui::input('itemid', $s['itemid'], 'Joomla Menu Item ID (προαιρετικό)', ['type' => 'number', 'min' => 0, 'help' => 'Αν θέλετε όμορφο URL ή κανόνες ορατότητας ανά μενού.']) ?>
  </div>
</section>
<div class="kx-savebar"><button type="submit" class="kx-btn kx-btn-primary">Αποθήκευση</button></div>
</form>
<?php endif;
echo Ui::close();
