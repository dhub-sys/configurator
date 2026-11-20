<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Calc;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

$account = Auth::requireAccount();
$id = $this->in->getInt('id', 0);
$b = $id > 0 ? Repo::building($id) : null;
$tab = $this->in->getCmd('tab', $b ? 'periods' : 'info');
if (!$b) {
    $tab = 'info';
    // Νέο κτίριο: ο διαχειριστής προσυμπληρώνεται από τα στοιχεία του λογαριασμού.
    $b = (object) [
        'id' => 0, 'title' => '', 'street' => '', 'street_no' => '', 'area' => '', 'city' => '', 'postcode' => '', 'floors' => '', 'afm' => '', 'iban' => '', 'bank' => '', 'beneficiary' => '',
        'manager_name' => $account->name, 'manager_phone' => $account->phone, 'manager_mobile' => '', 'manager_email' => $account->email, 'manager_address' => $account->address,
        'manager_hours' => '', 'rep_name' => '', 'rep_phone' => '', 'heating_type' => 'none', 'heating_method' => 'mills', 'heating_fixed_pct' => 30, 'fuel_unit' => 'lt',
        'due_days' => 10, 'notes' => '', 'archived' => 0,
    ];
}
$isNew = !(int) $b->id;
$keys = $isNew ? [] : Repo::keys((int) $b->id);
$units = $isNew ? [] : Repo::units((int) $b->id);
$tabs = $isNew ? ['info' => 'Στοιχεία κτιρίου'] : ['periods' => 'Μήνες / εκδόσεις', 'units' => 'Ιδιοκτησίες & χιλιοστά', 'info' => 'Στοιχεία κτιρίου', 'import' => 'Εισαγωγή από Excel'];
$sub = $isNew ? 'Συμπληρώστε τα στοιχεία· τις ιδιοκτησίες τις προσθέτετε στο επόμενο βήμα.' : Fmt::e(Fmt::address($b)) . ($b->archived ? ' ' . Ui::badge('Αρχειοθετημένο', 'neutral') : '');
$actions = $isNew ? '' : Ui::btn('Εισπράξεις', Url::to('view=payments&building_id=' . (int) $b->id), 'secondary');
echo Ui::open('buildings', $isNew ? 'Νέο κτίριο' : $b->title, $actions, $sub);
?>
<nav class="kx-tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="<?= $tab === $k ? 'is-active' : '' ?>" href="<?= Url::e('view=building&id=' . (int) $b->id . '&tab=' . $k) ?>"><?= Fmt::e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'info'): ?>
<?= Ui::form('building.save', 'class="kx-stack"') ?>
  <input type="hidden" name="id" value="<?= (int) $b->id ?>"><input type="hidden" name="tab" value="info">
  <section class="kx-card">
    <h2>Κτίριο</h2>
    <div class="kx-form-grid">
      <?= Ui::input('title', $b->title, 'Ονομασία', ['placeholder' => 'π.χ. Πολυκατοικία Αγίου Νικολάου 12', 'wide' => true, 'help' => 'Αν μείνει κενό, χρησιμοποιείται η οδός και ο αριθμός.']) ?>
      <?= Ui::input('street', $b->street, 'Οδός', ['required' => true]) ?>
      <?= Ui::input('street_no', $b->street_no, 'Αριθμός') ?>
      <?= Ui::input('area', $b->area, 'Περιοχή') ?>
      <?= Ui::input('postcode', $b->postcode, 'Τ.Κ.', ['inputmode' => 'numeric', 'maxlength' => 10]) ?>
      <?= Ui::input('city', $b->city, 'Πόλη') ?>
      <?= Ui::input('floors', $b->floors, 'Όροφοι') ?>
      <?= Ui::input('afm', $b->afm, 'ΑΦΜ κτιρίου', ['help' => 'Αν η πολυκατοικία έχει ΑΦΜ (π.χ. για υπάλληλο ή μισθώσεις).']) ?>
    </div>
  </section>
  <section class="kx-card">
    <h2>Λογαριασμός πληρωμών</h2>
    <div class="kx-form-grid">
      <?= Ui::input('iban', $b->iban, 'IBAN', ['placeholder' => 'GR..', 'wide' => true]) ?>
      <?= Ui::input('bank', $b->bank, 'Τράπεζα') ?>
      <?= Ui::input('beneficiary', $b->beneficiary, 'Δικαιούχος') ?>
      <?= Ui::input('due_days', $b->due_days, 'Προθεσμία πληρωμής (ημέρες από την έκδοση)', ['type' => 'number', 'min' => 0, 'max' => 90]) ?>
    </div>
  </section>
  <section class="kx-card">
    <h2>Διαχειριστής</h2>
    <div class="kx-form-grid">
      <?= Ui::input('manager_name', $b->manager_name, 'Ονοματεπώνυμο / επωνυμία') ?>
      <?= Ui::input('manager_phone', $b->manager_phone, 'Τηλέφωνο', ['type' => 'tel']) ?>
      <?= Ui::input('manager_mobile', $b->manager_mobile, 'Κινητό', ['type' => 'tel']) ?>
      <?= Ui::input('manager_email', $b->manager_email, 'Email', ['type' => 'email']) ?>
      <?= Ui::input('manager_address', $b->manager_address, 'Διεύθυνση γραφείου') ?>
      <?= Ui::input('manager_hours', $b->manager_hours, 'Ώρες επικοινωνίας', ['placeholder' => 'π.χ. Δευτ.–Παρ. 09:00–17:00']) ?>
      <?= Ui::input('rep_name', $b->rep_name, 'Εκπρόσωπος / πρόεδρος κτιρίου') ?>
      <?= Ui::input('rep_phone', $b->rep_phone, 'Τηλέφωνο εκπροσώπου', ['type' => 'tel']) ?>
    </div>
  </section>
  <section class="kx-card">
    <h2>Θέρμανση</h2>
    <div class="kx-form-grid">
      <?= Ui::select('heating_type', Repo::HEATING_TYPES, $b->heating_type, 'Είδος θέρμανσης') ?>
      <?php $methods = Repo::HEATING_METHODS; if (!Auth::feature('heating_meters')) { foreach (['hours', 'units'] as $m) $methods[$m] .= ' (αναβάθμιση πακέτου)'; } ?>
      <?= Ui::select('heating_method', $methods, $b->heating_method, 'Τρόπος κατανομής', ['help' => 'Με ωρομετρητές/θερμιδομετρητές: το πάγιο ποσοστό μοιράζεται με τα χιλιοστά θέρμανσης και το υπόλοιπο με την κατανάλωση × συντελεστή κάθε διαμερίσματος (μελέτη θέρμανσης).']) ?>
      <?= Ui::input('heating_fixed_pct', Fmt::qty($b->heating_fixed_pct), 'Πάγιο ποσοστό %', ['inputmode' => 'decimal', 'help' => 'Συνήθως 20–40% σύμφωνα με τη μελέτη ή τον κανονισμό.']) ?>
      <?= Ui::select('fuel_unit', ['lt' => 'λίτρα (lt)', 'm³' => 'κυβικά (m³)', 'kWh' => 'kWh'], $b->fuel_unit, 'Μονάδα καυσίμου') ?>
    </div>
  </section>
  <section class="kx-card">
    <?= Ui::input('notes', $b->notes, 'Σημειώσεις (εσωτερικές, δεν εκτυπώνονται)', ['type' => 'textarea', 'wide' => true]) ?>
  </section>
  <div class="kx-savebar"><button type="submit" class="kx-btn kx-btn-primary"><?= $isNew ? 'Δημιουργία κτιρίου' : 'Αποθήκευση' ?></button></div>
</form>
<?php if (!$isNew): ?>
<section class="kx-card kx-danger">
  <h2>Αρχειοθέτηση / διαγραφή</h2>
  <p class="kx-muted">Η αρχειοθέτηση κρύβει το κτίριο χωρίς να χαθούν δεδομένα και δεν μετράει στο όριο του πακέτου.</p>
  <div class="kx-row">
    <?= Ui::postButton('building.archive', ['id' => $b->id], $b->archived ? 'Επαναφορά από αρχείο' : 'Αρχειοθέτηση', 'secondary') ?>
    <?php if (Auth::isOwner()): ?>
    <?= Ui::form('building.delete', 'class="kx-row" data-confirm="Οριστική διαγραφή κτιρίου με όλους τους μήνες, τις δαπάνες και τις εισπράξεις;"') ?>
      <input type="hidden" name="id" value="<?= (int) $b->id ?>">
      <input type="text" name="confirm_title" placeholder="Πληκτρολογήστε: <?= Fmt::e($b->title) ?>">
      <button type="submit" class="kx-btn kx-btn-danger">Οριστική διαγραφή</button>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php elseif ($tab === 'units'): ?>
<?php
$sums = [];
foreach ($keys as $k) $sums[(int) $k->id] = array_sum(array_map(static fn($u) => (float) ($u->mills[(int) $k->id] ?? 0), $units));
$showHeat = $b->heating_method !== 'mills';
?>
<section class="kx-card">
  <div class="kx-card-head"><h2>Ιδιοκτησίες (<?= count($units) ?>)</h2>
    <label class="kx-check kx-check-inline"><input type="checkbox" data-toggle-class="kx-show-contacts" data-target="#kx-units"><span>Στοιχεία επικοινωνίας</span></label>
  </div>
  <p class="kx-muted">Γράψτε όπως σε λογιστικό φύλλο. Τα σύνολα χιλιοστών κάθε στήλης πρέπει συνήθως να είναι 1000. Η στήλη «Βαρύνει» ορίζει ποιος είναι ο υπόχρεος που εμφανίζεται στο ειδοποιητήριο.</p>
  <?= Ui::form('building.saveunits', 'id="kx-units-form"') ?>
  <input type="hidden" name="id" value="<?= (int) $b->id ?>">
  <div class="kx-table-wrap kx-sheet-wrap"><table class="kx-table kx-sheet" id="kx-units">
    <thead><tr>
      <th class="kx-sticky">Κωδ.</th><th>Όροφος</th><th>Είδος</th><th>Τ.μ.</th><th>Ιδιοκτήτης</th>
      <th class="kx-contact">Τηλ. ιδιοκτ.</th><th class="kx-contact">Email ιδιοκτ.</th><th class="kx-contact">ΑΦΜ ιδιοκτ.</th>
      <th>Ένοικος</th><th class="kx-contact">Τηλ. ενοίκου</th><th class="kx-contact">Email ενοίκου</th><th>Βαρύνει</th>
      <?php foreach ($keys as $k): ?><th class="kx-num">Χιλ. <?= Fmt::e($k->title) ?></th><?php endforeach; ?>
      <?php if ($showHeat): ?><th class="kx-num" title="Συντελεστής θερμαντικών σωμάτων από τη μελέτη θέρμανσης">Συντ. θέρμ.</th><?php endif; ?>
      <th class="kx-num" title="Υπόλοιπο πριν από τον πρώτο μήνα στην εφαρμογή (οφειλή θετική, πίστωση αρνητική)">Υπόλ. έναρξης</th><th></th>
    </tr></thead>
    <tbody>
    <?php
    $row = static function (string $key, ?object $u) use ($keys, $showHeat): string {
        $v = static fn($f, $d = '') => Fmt::e($u ? $u->$f : $d);
        $n = 'u[' . $key . ']';
        $h = '<tr' . ($u ? '' : ' class="kx-tpl-row"') . '>';
        $h .= '<td class="kx-sticky"><input name="' . $n . '[code]" value="' . $v('code') . '" size="5" placeholder="Α1"></td>';
        $h .= '<td><input name="' . $n . '[floor]" value="' . $v('floor') . '" size="5"></td>';
        $h .= '<td>' . Ui::select($n . '[kind]', array_combine(Repo::UNIT_KINDS, Repo::UNIT_KINDS), $u->kind ?? 'Διαμέρισμα') . '</td>';
        $h .= '<td><input name="' . $n . '[area]" value="' . ($u ? Fmt::e(Fmt::qty($u->area)) : '') . '" size="5" inputmode="decimal"></td>';
        $h .= '<td><input name="' . $n . '[owner_name]" value="' . $v('owner_name') . '" size="18"></td>';
        $h .= '<td class="kx-contact"><input name="' . $n . '[owner_phone]" value="' . $v('owner_phone') . '" size="11" type="tel"></td>';
        $h .= '<td class="kx-contact"><input name="' . $n . '[owner_email]" value="' . $v('owner_email') . '" size="18" type="email"></td>';
        $h .= '<td class="kx-contact"><input name="' . $n . '[owner_afm]" value="' . $v('owner_afm') . '" size="9"></td>';
        $h .= '<td><input name="' . $n . '[tenant_name]" value="' . $v('tenant_name') . '" size="18"></td>';
        $h .= '<td class="kx-contact"><input name="' . $n . '[tenant_phone]" value="' . $v('tenant_phone') . '" size="11" type="tel"></td>';
        $h .= '<td class="kx-contact"><input name="' . $n . '[tenant_email]" value="' . $v('tenant_email') . '" size="18" type="email"></td>';
        $h .= '<td>' . Ui::select($n . '[payer]', ['tenant' => 'Ένοικο', 'owner' => 'Ιδιοκτήτη'], $u->payer ?? 'tenant') . '</td>';
        foreach ($keys as $k) {
            $val = $u ? Fmt::mills($u->mills[(int) $k->id] ?? 0) : '';
            $h .= '<td class="kx-num"><input class="kx-mill" data-key="' . (int) $k->id . '" name="' . $n . '[mills][' . (int) $k->id . ']" value="' . Fmt::e($val) . '" size="6" inputmode="decimal"></td>';
        }
        $coef = $u ? Fmt::e(Fmt::qty($u->heat_coef, 4)) : '1';
        if ($showHeat) $h .= '<td class="kx-num"><input name="' . $n . '[heat_coef]" value="' . $coef . '" size="5" inputmode="decimal"></td>';
        $h .= '<td class="kx-num">' . ($showHeat ? '' : '<input type="hidden" name="' . $n . '[heat_coef]" value="' . $coef . '">') . '<input name="' . $n . '[opening_balance]" value="' . ($u && (float) $u->opening_balance != 0 ? Fmt::e(Fmt::money($u->opening_balance)) : '') . '" size="7" inputmode="decimal"></td>';
        $h .= '<td><label class="kx-del" title="Διαγραφή γραμμής"><input type="checkbox" name="' . $n . '[_delete]" value="1"><span>✕</span></label></td>';
        return $h . '</tr>';
    };
    foreach ($units as $u) echo $row((string) $u->id, $u);
    if (!$units) for ($i = 1; $i <= 4; $i++) echo str_replace(' class="kx-tpl-row"', '', $row('n' . $i, null));
    ?>
    </tbody>
    <tfoot><tr>
      <td class="kx-sticky"><strong>Σύνολα</strong></td><td colspan="4"></td><td class="kx-contact" colspan="3"></td><td></td><td class="kx-contact" colspan="2"></td><td></td>
      <?php foreach ($keys as $k): $s = $sums[(int) $k->id]; ?><td class="kx-num"><strong class="kx-sum <?= abs($s - 1000) < 0.001 ? 'is-ok' : 'is-off' ?>" data-sum="<?= (int) $k->id ?>"><?= Fmt::mills($s) ?></strong></td><?php endforeach; ?>
      <?php if ($showHeat): ?><td></td><?php endif; ?><td></td><td></td>
    </tr></tfoot>
  </table></div>
  <template id="kx-unit-row"><?= $row('__N__', null) ?></template>
  <div class="kx-row kx-mt">
    <button type="button" class="kx-btn" data-add-row="#kx-units" data-template="#kx-unit-row">+ Γραμμή</button>
    <button type="button" class="kx-btn" data-add-row="#kx-units" data-template="#kx-unit-row" data-count="5">+ 5 γραμμές</button>
    <span class="kx-flex"></span>
    <button type="submit" class="kx-btn kx-btn-primary">Αποθήκευση ιδιοκτησιών</button>
  </div>
  </form>
</section>

<section class="kx-card">
  <h2>Στήλες χιλιοστών (κλείδες κατανομής)</h2>
  <p class="kx-muted">Οι τρεις βασικές στήλες υπάρχουν πάντα. Προσθέστε επιπλέον στήλες για ειδικές περιπτώσεις (π.χ. «Κήπος», «Γκαράζ», «Κλιμακοστάσιο Β»).</p>
  <?= Ui::form('building.savekeys', 'class="kx-keys"') ?>
    <input type="hidden" name="id" value="<?= (int) $b->id ?>">
    <?php foreach ($keys as $k): ?>
      <div class="kx-row">
        <input type="text" name="key_title[<?= (int) $k->id ?>]" value="<?= Fmt::e($k->title) ?>" maxlength="100">
        <span class="kx-muted"><?= ['general' => 'βασική: γενικά', 'elevator' => 'βασική: ανελκυστήρας', 'heating' => 'βασική: θέρμανση', 'custom' => 'πρόσθετη · πεδίο εκτύπωσης mills.' . Fmt::e($k->code)][$k->kind] ?></span>
        <?php if ($k->kind === 'custom'): ?><label class="kx-check kx-check-inline"><input type="checkbox" name="key_delete[<?= (int) $k->id ?>]" value="1"><span>Διαγραφή</span></label><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="kx-row"><input type="text" name="new_key" placeholder="Νέα στήλη χιλιοστών…" maxlength="100"><button type="submit" class="kx-btn">Αποθήκευση στηλών</button></div>
  </form>
</section>

<?php elseif ($tab === 'import'): ?>
<section class="kx-card">
  <h2>Εισαγωγή ιδιοκτησιών από Excel</h2>
  <p>Αντιγράψτε τις γραμμές από το Excel και επικολλήστε τις εδώ. Σειρά στηλών:</p>
  <p><code>Κωδικός · Όροφος · Ιδιοκτήτης · Ένοικος · Τ.μ.<?php foreach ($keys as $k) echo ' · Χιλ. ' . Fmt::e($k->title); ?></code></p>
  <p class="kx-muted">Οι ιδιοκτησίες προστίθενται στις υπάρχουσες. Μια πρώτη γραμμή με επικεφαλίδες αγνοείται. Δεκαδικά με κόμμα ή τελεία.</p>
  <?= Ui::form('building.import') ?>
    <input type="hidden" name="id" value="<?= (int) $b->id ?>">
    <textarea name="paste" rows="12" class="kx-mono kx-w100" placeholder="Α1&#9;Α΄&#9;Παπαδόπουλος Γ.&#9;&#9;85&#9;95,5&#9;100&#9;95"></textarea>
    <div class="kx-row kx-mt"><button type="submit" class="kx-btn kx-btn-primary">Εισαγωγή</button></div>
  </form>
</section>

<?php else: /* periods */ ?>
<?php
$periods = Repo::periods((int) $b->id);
$last = $periods[0] ?? null;
$nm = $last ? ((int) $last->month % 12) + 1 : (int) date('n', strtotime('first day of last month'));
$ny = $last ? (int) $last->year + ((int) $last->month === 12 ? 1 : 0) : (int) date('Y', strtotime('first day of last month'));
$months = Fmt::MONTHS;
$years = [];
for ($y = (int) date('Y') - 3; $y <= (int) date('Y') + 1; $y++) $years[$y] = $y;
$balances = Auth::feature('payments') && $units ? Repo::balancesNow((int) $b->id, $units) : [];
$owed = array_sum(array_map(static fn($x) => max(0, $x['balance']), $balances));
?>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <div class="kx-card-head"><h2>Μήνες</h2></div>
    <?php if (!$periods): ?>
      <?= Ui::empty('Δεν υπάρχουν μήνες', $units ? 'Δημιουργήστε τον πρώτο μήνα για να καταχωρίσετε δαπάνες.' : 'Προσθέστε πρώτα τις <a href="' . Url::e('view=building&id=' . (int) $b->id . '&tab=units') . '">ιδιοκτησίες & χιλιοστά</a>.') ?>
    <?php else: ?>
    <div class="kx-table-wrap"><table class="kx-table">
      <thead><tr><th>Μήνας</th><th>Έκδοση</th><th>Πληρωμή έως</th><th class="kx-num">Δαπάνες €</th><th>Κατάσταση</th><th></th></tr></thead><tbody>
      <?php foreach ($periods as $p): ?>
        <tr>
          <td><a class="kx-strong" href="<?= Url::e('view=period&id=' . (int) $p->id) ?>"><?= Fmt::e($p->title) ?></a></td>
          <td><?= Fmt::date($p->issue_date) ?></td><td><?= Fmt::date($p->due_date) ?></td>
          <td class="kx-num"><?= Fmt::money($p->expenses_total) ?></td>
          <td><?= $p->status === 'issued' ? Ui::badge('Εκδόθηκε', 'success') : Ui::badge('Πρόχειρο', 'warning') ?></td>
          <td class="kx-right"><a class="kx-btn kx-btn-small" href="<?= Url::e('view=period&id=' . (int) $p->id . ($p->status === 'issued' ? '&tab=print' : '')) ?>"><?= $p->status === 'issued' ? 'Εκτύπωση' : 'Συνέχεια' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <aside class="kx-col-side">
    <section class="kx-card">
      <h2>Νέος μήνας</h2>
      <?= Ui::form('period.create', 'class="kx-stack-sm"') ?>
        <input type="hidden" name="building_id" value="<?= (int) $b->id ?>">
        <div class="kx-row"><?= Ui::select('month', $months, $nm) ?><?= Ui::select('year', $years, $ny) ?></div>
        <?= Ui::input('issue_date', date('d/m/Y'), 'Ημερομηνία έκδοσης', ['placeholder' => 'ηη/μμ/εεεε']) ?>
        <?= Ui::input('due_date', '', 'Πληρωμή έως', ['placeholder' => 'αυτόματα +' . (int) $b->due_days . ' ημέρες']) ?>
        <?php if ($last): ?><?= Ui::check('copy_recurring', true, 'Αντιγραφή επαναλαμβανόμενων δαπανών από ' . $last->title) ?><?php endif; ?>
        <button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Δημιουργία μήνα</button>
      </form>
    </section>
    <section class="kx-card">
      <h2>Κτίριο</h2>
      <dl class="kx-dl">
        <dt>Ιδιοκτησίες</dt><dd><?= count($units) ?></dd>
        <?php foreach ($keys as $k): $s = array_sum(array_map(static fn($u) => (float) ($u->mills[(int) $k->id] ?? 0), $units)); ?>
          <dt>Χιλ. <?= Fmt::e($k->title) ?></dt><dd class="<?= abs($s - 1000) < 0.001 ? '' : 'kx-warn' ?>"><?= Fmt::mills($s) ?></dd>
        <?php endforeach; ?>
        <dt>Θέρμανση</dt><dd><?= Fmt::e(Repo::HEATING_METHODS[$b->heating_method] ?? '') ?></dd>
        <?php if ($balances): ?><dt>Οφειλές</dt><dd><a href="<?= Url::e('view=payments&building_id=' . (int) $b->id) ?>"><?= Fmt::moneyCents($owed) ?> €</a></dd><?php endif; ?>
        <dt>Διαχειριστής</dt><dd><?= Fmt::e($b->manager_name) ?></dd>
      </dl>
    </section>
  </aside>
</div>
<?php endif;
echo Ui::close();
