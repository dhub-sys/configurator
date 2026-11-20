<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

$a = Auth::requireAccount();
$owner = Auth::isOwner();
$tab = $this->in->getCmd('tab', 'profile');
$tabs = ['profile' => 'Στοιχεία εταιρείας', 'print' => 'Εκτύπωση & email', 'team' => 'Χρήστες', 'me' => 'Ο λογαριασμός μου', 'billing' => 'Συνδρομή & πακέτα'];
if (!isset($tabs[$tab])) $tab = 'profile';
$s = Accounts::settings($a);
echo Ui::open($tab === 'billing' ? 'billing' : 'account', $tab === 'billing' ? 'Συνδρομή & πακέτα' : 'Λογαριασμός', '', Fmt::e($a->name));
?>
<nav class="kx-tabs">
  <?php foreach ($tabs as $k => $label): ?><a class="<?= $tab === $k ? 'is-active' : '' ?>" href="<?= Url::e('view=account&tab=' . $k) ?>"><?= Fmt::e($label) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'profile'): ?>
<?= Ui::form('account.saveprofile', 'class="kx-stack"', true) ?>
<section class="kx-card">
  <h2>Στοιχεία διαχειριστικής εταιρείας</h2>
  <p class="kx-muted">Εμφανίζονται στην κεφαλίδα των εκτυπώσεων (στο επίπεδο «Έντυπο») και στα email.</p>
  <div class="kx-form-grid">
    <?= Ui::input('name', $a->name, 'Επωνυμία', ['required' => true]) ?>
    <?= Ui::input('afm', $a->afm, 'ΑΦΜ') ?>
    <?= Ui::input('doy', $a->doy, 'ΔΟΥ') ?>
    <?= Ui::input('address', $a->address, 'Διεύθυνση', ['wide' => true]) ?>
    <?= Ui::input('phone', $a->phone, 'Τηλέφωνο', ['type' => 'tel']) ?>
    <?= Ui::input('email', $a->email, 'Email', ['type' => 'email']) ?>
    <?= Ui::input('website', $a->website, 'Website') ?>
    <label class="kx-field kx-span"><span>Λογότυπο (PNG/JPG)</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
    <?php if ($a->logo): ?><div class="kx-logo-preview"><img src="<?= Fmt::e(Settings::asset($a->logo)) ?>" alt=""><?= Ui::check('remove_logo', false, 'Αφαίρεση λογοτύπου') ?></div><?php endif; ?>
  </div>
</section>
<?php if ($owner): ?><div class="kx-savebar"><button type="submit" class="kx-btn kx-btn-primary">Αποθήκευση</button></div><?php endif; ?>
</form>

<?php elseif ($tab === 'print'): ?>
<?= Ui::form('account.savesettings', 'class="kx-stack"') ?>
<section class="kx-card">
  <h2>Βαθμονόμηση εκτυπωτή</h2>
  <p class="kx-muted">Κάθε εκτυπωτής τραβά το χαρτί λίγο διαφορετικά. Τυπώστε μια δοκιμή με «Πλέγμα», μετρήστε πόσο απέχουν τα σταυρόνηματα από τις θέσεις 20/20 mm και βάλτε τη διόρθωση εδώ. Εφαρμόζεται σε όλες τις εκτυπώσεις.</p>
  <div class="kx-form-grid">
    <?= Ui::input('offset_x', Fmt::qty($s['offset_x'], 1), 'Οριζόντια μετατόπιση mm (+ δεξιά)', ['inputmode' => 'decimal']) ?>
    <?= Ui::input('offset_y', Fmt::qty($s['offset_y'], 1), 'Κατακόρυφη μετατόπιση mm (+ κάτω)', ['inputmode' => 'decimal']) ?>
  </div>
</section>
<section class="kx-card">
  <h2>Κείμενα</h2>
  <div class="kx-form-grid">
    <?= Ui::input('notice_email_text', $s['notice_email_text'], 'Εισαγωγικό κείμενο στο email ειδοποιητηρίων', ['type' => 'textarea', 'wide' => true]) ?>
    <?= Ui::input('receipt_footer', $s['receipt_footer'], 'Υποσέλιδο αποδείξεων είσπραξης', ['type' => 'textarea', 'wide' => true]) ?>
  </div>
</section>
<?php if ($owner): ?><div class="kx-savebar"><button type="submit" class="kx-btn kx-btn-primary">Αποθήκευση</button></div><?php endif; ?>
</form>

<?php elseif ($tab === 'team'): ?>
<?php $members = Db::all('SELECT m.*, u.name, u.email, u.block FROM #__kx_account_users m JOIN #__users u ON u.id=m.user_id WHERE m.account_id=' . (int) $a->id . ' ORDER BY m.role=\'owner\' DESC, u.name'); ?>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <h2>Χρήστες λογαριασμού</h2>
    <div class="kx-table-wrap"><table class="kx-table"><thead><tr><th>Όνομα</th><th>Email</th><th>Ρόλος</th><th></th></tr></thead><tbody>
      <?php foreach ($members as $m): ?>
        <tr><td><?= Fmt::e($m->name) ?></td><td><?= Fmt::e($m->email) ?></td><td><?= $m->role === 'owner' ? Ui::badge('Υπεύθυνος', 'info') : 'Συνεργάτης' ?></td>
          <td class="kx-right"><?php if ($owner && $m->role !== 'owner'): ?><?= Ui::postButton('account.removeuser', ['user_id' => $m->user_id], 'Αφαίρεση', 'small', 'Αφαίρεση του χρήστη ' . $m->name . ';') ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php if ($owner): $u = Auth::usage($a)['users']; ?>
  <aside class="kx-col-side"><section class="kx-card">
    <h2>Νέος χρήστης</h2>
    <?= Ui::meter('Χρήστες', $u[0], $u[1]) ?>
    <?= Ui::form('account.adduser', 'class="kx-stack-sm"') ?>
      <?= Ui::input('name', '', 'Ονοματεπώνυμο', ['required' => true]) ?>
      <?= Ui::input('email', '', 'Email', ['type' => 'email', 'required' => true]) ?>
      <?= Ui::input('password', '', 'Κωδικός (κενό = αυτόματος)', ['type' => 'text', 'autocomplete' => 'off']) ?>
      <button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Προσθήκη</button>
    </form>
    <p class="kx-muted kx-mt">Ο συνεργάτης βλέπει και επεξεργάζεται κτίρια και μήνες· δεν αλλάζει τα στοιχεία του λογαριασμού ούτε τη συνδρομή.</p>
  </section></aside>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'me'): $me = Auth::user(); ?>
<section class="kx-card kx-narrow">
  <h2>Αλλαγή κωδικού</h2>
  <p class="kx-muted"><?= Fmt::e($me->name) ?> · <?= Fmt::e($me->email) ?></p>
  <?= Ui::form('account.password', 'class="kx-stack-sm"') ?>
    <?= Ui::input('current', '', 'Τρέχων κωδικός', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
    <?= Ui::input('new', '', 'Νέος κωδικός (8+)', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <?= Ui::input('new2', '', 'Επιβεβαίωση', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="kx-btn kx-btn-primary">Αλλαγή κωδικού</button>
  </form>
</section>

<?php else: /* billing */ ?>
<?php
$st = Auth::state($a);
$pkg = Auth::package($a);
$packages = Db::all('SELECT * FROM #__kx_packages WHERE published=1 ORDER BY ordering, id');
$pending = Db::one('SELECT b.*, p.title FROM #__kx_billing b LEFT JOIN #__kx_packages p ON p.id=b.package_id WHERE b.account_id=' . (int) $a->id . " AND b.kind='request' AND b.status='pending' ORDER BY b.id DESC LIMIT 1");
$history = Db::all('SELECT b.*, p.title FROM #__kx_billing b LEFT JOIN #__kx_packages p ON p.id=b.package_id WHERE b.account_id=' . (int) $a->id . " AND b.kind='payment' ORDER BY b.id DESC");
$usage = Auth::usage($a);
$vat = (float) Settings::get('vat_rate');
?>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <div class="kx-card-head"><h2>Τρέχουσα συνδρομή</h2><?= Ui::badge($st['label'], $st['ok'] ? ($st['code'] === 'trial' ? 'info' : 'success') : 'danger') ?></div>
    <p class="kx-big"><?= Fmt::e($pkg->title ?? '—') ?><?= $st['until'] ? ' <span class="kx-muted">· έως ' . Fmt::date($st['until']) . '</span>' : '' ?></p>
    <div class="kx-meters"><?= Ui::meter('Κτίρια', ...$usage['buildings']) . Ui::meter('Ιδιοκτησίες', ...$usage['units']) . Ui::meter('Χρήστες', ...$usage['users']) ?></div>
    <?php if ($pending): ?><div class="kx-alert kx-alert-notice">Εκκρεμεί αίτημα για το πακέτο <strong><?= Fmt::e($pending->title) ?></strong> (<?= $pending->cycle === 'year' ? 'ετήσια' : 'μηνιαία' ?> χρέωση, <?= Fmt::euro($pending->amount) ?> + ΦΠΑ). Θα ενεργοποιηθεί με την επιβεβαίωση της πληρωμής.</div><?php endif; ?>
    <?php if (trim((string) Settings::get('payment_info')) !== ''): ?>
      <h3>Τρόπος πληρωμής</h3><div class="kx-prewrap"><?= Fmt::e(Settings::get('payment_info')) ?></div>
    <?php endif; ?>
  </section>
  <aside class="kx-col-side"><section class="kx-card">
    <h2>Υποστήριξη</h2>
    <p><?= Fmt::e((string) Settings::get('support_email')) ?><br><?= Fmt::e((string) Settings::get('support_phone')) ?></p>
  </section></aside>
</div>

<h2 class="kx-section-title">Πακέτα</h2>
<div class="kx-plans">
  <?php foreach ($packages as $p): $f = json_decode((string) $p->features, true) ?: []; $current = (int) $p->id === (int) $a->package_id; ?>
  <article class="kx-plan<?= (int) $p->highlight ? ' is-highlight' : '' ?><?= $current ? ' is-current' : '' ?>">
    <?php if ((int) $p->highlight): ?><span class="kx-plan-flag">Δημοφιλές</span><?php endif; ?>
    <h3><?= Fmt::e($p->title) ?></h3>
    <p class="kx-plan-price"><?php if ((float) $p->price_month > 0): ?><strong><?= Fmt::money($p->price_month) ?> €</strong><span>/μήνα + ΦΠΑ</span><?php else: ?><strong>Δωρεάν</strong><?php endif; ?></p>
    <?php if ((float) $p->price_year > 0): ?><p class="kx-muted">ή <?= Fmt::money($p->price_year) ?> €/έτος + ΦΠΑ</p><?php endif; ?>
    <?php if ($p->description): ?><p><?= Fmt::e($p->description) ?></p><?php endif; ?>
    <ul class="kx-plan-list">
      <li><?= (int) $p->max_buildings ? 'Έως ' . (int) $p->max_buildings . ' κτίρια' : 'Απεριόριστα κτίρια' ?></li>
      <li><?= (int) $p->max_units ? 'Έως ' . (int) $p->max_units . ' ιδιοκτησίες' : 'Απεριόριστες ιδιοκτησίες' ?></li>
      <li><?= (int) $p->max_users ? (int) $p->max_users . ((int) $p->max_users === 1 ? ' χρήστης' : ' χρήστες') : 'Απεριόριστοι χρήστες' ?></li>
      <?php foreach (Auth::FEATURES as $k => $label): ?><li class="<?= empty($f[$k]) ? 'is-off' : '' ?>"><?= Fmt::e($label) ?></li><?php endforeach; ?>
      <?php if ((int) $p->trial_days): ?><li><?= (int) $p->trial_days ?> ημέρες δοκιμή</li><?php endif; ?>
    </ul>
    <?php if ($current && $st['ok']): ?><p class="kx-plan-current">Το τρέχον πακέτο σας</p>
    <?php elseif ($owner && ((float) $p->price_month > 0 || (float) $p->price_year > 0)): ?>
      <?= Ui::form('account.requestpackage', 'class="kx-stack-sm"') ?>
        <input type="hidden" name="package_id" value="<?= (int) $p->id ?>">
        <?php $cycles = []; if ((float) $p->price_month > 0) $cycles['month'] = 'Μηνιαία: ' . Fmt::euro($p->price_month * (1 + $vat / 100)) . ' με ΦΠΑ'; if ((float) $p->price_year > 0) $cycles['year'] = 'Ετήσια: ' . Fmt::euro($p->price_year * (1 + $vat / 100)) . ' με ΦΠΑ'; ?>
        <?= Ui::select('cycle', $cycles, array_key_first($cycles)) ?>
        <button type="submit" class="kx-btn <?= (int) $p->highlight ? 'kx-btn-primary' : '' ?> kx-btn-block"><?= $current ? 'Ανανέωση' : 'Επιλογή' ?></button>
      </form>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>

<?php if ($history): ?>
<section class="kx-card">
  <h2>Ιστορικό πληρωμών</h2>
  <div class="kx-table-wrap"><table class="kx-table"><thead><tr><th>Ημ/νία</th><th>Πακέτο</th><th>Περίοδος</th><th class="kx-num">Ποσό</th><th>Τρόπος</th></tr></thead><tbody>
    <?php foreach ($history as $h): ?><tr><td><?= Fmt::date(substr((string) $h->created, 0, 10)) ?></td><td><?= Fmt::e($h->title) ?></td><td><?= Fmt::date($h->period_from) ?> – <?= Fmt::date($h->period_to) ?></td><td class="kx-num"><?= Fmt::euro($h->amount) ?></td><td><?= Fmt::e($h->method) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<?php endif;
echo Ui::close();
