<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

Auth::requireAccount();
Auth::requireFeature('payments');
$buildings = Repo::buildings();
$bid = $this->in->getInt('building_id', (int) ($buildings[0]->id ?? 0));
echo Ui::open('payments', 'Εισπράξεις & υπόλοιπα');
if (!$buildings) {
    echo Ui::empty('Δεν υπάρχουν κτίρια', 'Προσθέστε πρώτα ένα κτίριο.') . Ui::close();
    return;
}
$b = Repo::building($bid);
$units = Repo::units((int) $b->id);
$bal = Repo::balancesNow((int) $b->id, $units);
$uid = $this->in->getInt('unit_id', 0);
$unit = $units[$uid] ?? null;
$receipt = $this->in->getInt('receipt', 0);
$onlyDebt = $this->in->getInt('debt', 0) === 1;
$bopts = [];
foreach ($buildings as $x) $bopts[$x->id] = $x->title;
$tot = ['charged' => 0, 'paid' => 0, 'balance' => 0];
foreach ($bal as $x) { $tot['charged'] += $x['charged'] + $x['opening']; $tot['paid'] += $x['paid']; $tot['balance'] += $x['balance']; }
?>
<div class="kx-toolbar">
  <form method="get" action="<?= Url::e() ?>" class="kx-row">
    <input type="hidden" name="option" value="com_koinoxrista"><input type="hidden" name="tmpl" value="component"><input type="hidden" name="view" value="payments">
    <?= Ui::select('building_id', $bopts, $b->id, '', ['attrs' => 'onchange="this.form.submit()"']) ?>
    <label class="kx-check kx-check-inline"><input type="checkbox" name="debt" value="1"<?= $onlyDebt ? ' checked' : '' ?> onchange="this.form.submit()"><span>Μόνο οφειλέτες</span></label>
  </form>
</div>
<?php if ($receipt): ?>
  <div class="kx-alert kx-alert-success">Η είσπραξη καταχωρίστηκε. <a href="<?= Url::e('view=receipt&id=' . $receipt) ?>" target="_blank">Εκτύπωση απόδειξης</a></div>
<?php endif; ?>
<section class="kx-kpis kx-kpis-3">
  <div class="kx-kpi"><span>Χρεώσεις (με υπόλοιπα έναρξης)</span><strong><?= Fmt::moneyCents($tot['charged']) ?> €</strong></div>
  <div class="kx-kpi"><span>Εισπράξεις</span><strong><?= Fmt::moneyCents($tot['paid']) ?> €</strong></div>
  <div class="kx-kpi"><span>Υπόλοιπο κτιρίου</span><strong class="<?= $tot['balance'] > 0 ? 'kx-warn' : '' ?>"><?= Fmt::moneyCents($tot['balance']) ?> €</strong></div>
</section>
<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <h2><?= Fmt::e($b->title) ?></h2>
    <div class="kx-table-wrap"><table class="kx-table">
      <thead><tr><th>Ιδιοκτησία</th><th>Υπόχρεος</th><th class="kx-num">Χρεώσεις</th><th class="kx-num">Εισπράξεις</th><th class="kx-num">Υπόλοιπο</th><th></th></tr></thead><tbody>
      <?php foreach ($units as $u): $x = $bal[(int) $u->id]; if ($onlyDebt && $x['balance'] <= 0) continue; ?>
        <tr class="<?= (int) $u->id === $uid ? 'is-selected' : '' ?>">
          <td><strong><?= Fmt::e($u->code) ?></strong></td><td><?= Fmt::e(Repo::payerName($u)) ?></td>
          <td class="kx-num"><?= Fmt::moneyCents($x['charged'] + $x['opening']) ?></td><td class="kx-num"><?= Fmt::moneyCents($x['paid']) ?></td>
          <td class="kx-num <?= $x['balance'] > 0 ? 'kx-warn' : 'kx-ok' ?>"><strong><?= Fmt::moneyCents($x['balance']) ?></strong></td>
          <td class="kx-right"><a class="kx-btn kx-btn-small" href="<?= Url::e('view=payments&building_id=' . (int) $b->id . '&unit_id=' . (int) $u->id . ($onlyDebt ? '&debt=1' : '')) ?>">Καρτέλα / είσπραξη</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </section>
  <aside class="kx-col-side">
    <?php if ($unit): $x = $bal[(int) $unit->id]; ?>
    <section class="kx-card">
      <h2>Είσπραξη · <?= Fmt::e($unit->code) ?></h2>
      <p class="kx-muted"><?= Fmt::e(Repo::payerName($unit)) ?> · υπόλοιπο <strong><?= Fmt::moneyCents($x['balance']) ?> €</strong></p>
      <?= Ui::form('payment.save', 'class="kx-stack-sm"') ?>
        <input type="hidden" name="building_id" value="<?= (int) $b->id ?>"><input type="hidden" name="unit_id" value="<?= (int) $unit->id ?>">
        <?= Ui::input('amount', $x['balance'] > 0 ? Fmt::moneyCents($x['balance']) : '', 'Ποσό €', ['inputmode' => 'decimal', 'required' => true]) ?>
        <?= Ui::input('pay_date', date('d/m/Y'), 'Ημερομηνία', ['placeholder' => 'ηη/μμ/εεεε']) ?>
        <?= Ui::select('method', Repo::PAY_METHODS, 'cash', 'Τρόπος') ?>
        <?= Ui::input('notes', '', 'Σημείωση') ?>
        <button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Καταχώριση είσπραξης</button>
      </form>
    </section>
    <section class="kx-card">
      <h2>Καρτέλα</h2>
      <table class="kx-table kx-table-compact">
        <thead><tr><th>Ημ/νία</th><th>Κίνηση</th><th class="kx-num">Χρέωση</th><th class="kx-num">Πίστωση</th><th class="kx-num">Υπόλ.</th><th></th></tr></thead><tbody>
        <?php foreach (Repo::ledger($unit) as $r): ?>
          <tr><td class="kx-nowrap"><?= Fmt::date($r['date']) ?></td><td><?= Fmt::e($r['text']) ?></td><td class="kx-num"><?= Fmt::moneyCents($r['debit'], true) ?></td><td class="kx-num"><?= Fmt::moneyCents($r['credit'], true) ?></td><td class="kx-num"><?= Fmt::moneyCents($r['balance']) ?></td>
            <td class="kx-nowrap"><?php if ($r['payment_id']): ?><a href="<?= Url::e('view=receipt&id=' . $r['payment_id']) ?>" target="_blank" title="Απόδειξη">🧾</a> <?= Ui::postButton('payment.delete', ['payment_id' => $r['payment_id']], '✕', 'link', 'Διαγραφή της είσπραξης;') ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>
    <?php else: ?>
    <section class="kx-card"><p class="kx-muted">Επιλέξτε ιδιοκτησία για να δείτε την καρτέλα της και να καταχωρίσετε είσπραξη.</p></section>
    <?php endif; ?>
  </aside>
</div>
<?php echo Ui::close();
