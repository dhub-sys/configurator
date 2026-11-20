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
$buildings = Repo::buildings();
$aid = (int) $account->id;
$bids = Db::ids(array_map(static fn($b) => $b->id, $buildings));
$units = array_sum(array_map(static fn($b) => (int) $b->units_count, $buildings));
$drafts = Db::all('SELECT p.*, b.title AS building FROM #__kx_periods p JOIN #__kx_buildings b ON b.id=p.building_id WHERE b.account_id=' . $aid . " AND b.archived=0 AND p.status='draft' ORDER BY p.year DESC, p.month DESC LIMIT 8");
$charged = Calc::cents(Db::value('SELECT SUM(c.total) FROM #__kx_charges c JOIN #__kx_periods p ON p.id=c.period_id WHERE p.building_id IN (' . $bids . ')'));
$opening = Calc::cents(Db::value('SELECT SUM(opening_balance) FROM #__kx_units WHERE building_id IN (' . $bids . ')'));
$paid = Calc::cents(Db::value('SELECT SUM(amount) FROM #__kx_payments WHERE building_id IN (' . $bids . ')'));
$month = Calc::cents(Db::value('SELECT SUM(amount) FROM #__kx_payments WHERE building_id IN (' . $bids . ') AND pay_date>=' . Db::q(date('Y-m-01'))));
$st = Auth::state($account);
$pkg = Auth::package($account);

echo Ui::open('dashboard', 'Επισκόπηση', Ui::btn('+ Νέο κτίριο', Url::to('view=building&id=0'), 'primary'), Fmt::e($account->name));
?>
<section class="kx-kpis">
  <div class="kx-kpi"><span>Κτίρια</span><strong><?= count($buildings) ?></strong><small><?= $units ?> ιδιοκτησίες</small></div>
  <div class="kx-kpi"><span>Μήνες σε προετοιμασία</span><strong><?= count($drafts) ?></strong><small>πρόχειρα, προς έκδοση</small></div>
  <?php if (Auth::feature('payments')): ?>
  <div class="kx-kpi"><span>Ανεξόφλητα υπόλοιπα</span><strong><?= Fmt::moneyCents($opening + $charged - $paid) ?> €</strong><small>σε όλα τα κτίρια</small></div>
  <div class="kx-kpi"><span>Εισπράξεις μήνα</span><strong><?= Fmt::moneyCents($month) ?> €</strong><small><?= Fmt::e(Fmt::MONTHS[(int) date('n')]) ?></small></div>
  <?php endif; ?>
</section>

<div class="kx-cols">
  <section class="kx-card kx-col-main">
    <div class="kx-card-head"><h2>Κτίρια</h2><a href="<?= Url::e('view=buildings') ?>">Όλα</a></div>
    <?php if (!$buildings): ?>
      <?= Ui::empty('Ξεκινήστε με το πρώτο κτίριο', 'Καταχωρίστε διεύθυνση, στοιχεία διαχειριστή, ιδιοκτησίες και χιλιοστά. Μετά δημιουργείτε τον μήνα, βάζετε τις δαπάνες και εκτυπώνετε.', Ui::btn('+ Νέο κτίριο', Url::to('view=building&id=0'), 'primary')) ?>
    <?php else: ?>
      <div class="kx-table-wrap"><table class="kx-table">
        <thead><tr><th>Κτίριο</th><th>Ιδιοκτ.</th><th>Τελευταίος μήνας</th><th></th></tr></thead><tbody>
        <?php foreach (array_slice($buildings, 0, 12) as $b):
            $last = $b->last_period ? explode(':', $b->last_period) : null; ?>
          <tr>
            <td><a class="kx-strong" href="<?= Url::e('view=building&id=' . (int) $b->id) ?>"><?= Fmt::e($b->title) ?></a><div class="kx-muted"><?= Fmt::e(Fmt::address($b)) ?></div></td>
            <td><?= (int) $b->units_count ?></td>
            <td><?php if ($last): ?><a href="<?= Url::e('view=period&id=' . (int) $last[2]) ?>"><?= Fmt::e(Fmt::period((int) substr($last[0], 4), (int) substr($last[0], 0, 4))) ?></a> <?= $last[1] === 'issued' ? Ui::badge('Εκδόθηκε', 'success') : Ui::badge('Πρόχειρο', 'warning') ?><?php else: ?><span class="kx-muted">—</span><?php endif; ?></td>
            <td class="kx-right"><a class="kx-btn kx-btn-small" href="<?= Url::e('view=building&id=' . (int) $b->id . '&tab=periods') ?>">Μήνες</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </section>

  <aside class="kx-col-side">
    <section class="kx-card">
      <div class="kx-card-head"><h2>Συνδρομή</h2><?= Ui::badge($st['label'], $st['ok'] ? ($st['code'] === 'trial' ? 'info' : 'success') : 'danger') ?></div>
      <p class="kx-big"><?= Fmt::e($pkg->title ?? '—') ?></p>
      <?php if ($st['until']): ?><p class="kx-muted">Ισχύει έως <?= Fmt::date($st['until']) ?></p><?php endif; ?>
      <?php $u = Auth::usage($account); echo Ui::meter('Κτίρια', ...$u['buildings']) . Ui::meter('Ιδιοκτησίες', ...$u['units']); ?>
      <p><a class="kx-btn kx-btn-small" href="<?= Url::e('view=account&tab=billing') ?>">Πακέτα & πληρωμή</a></p>
    </section>
    <?php if ($drafts): ?>
    <section class="kx-card">
      <div class="kx-card-head"><h2>Προς έκδοση</h2></div>
      <ul class="kx-list">
        <?php foreach ($drafts as $d): ?>
          <li><a href="<?= Url::e('view=period&id=' . (int) $d->id) ?>"><strong><?= Fmt::e($d->title) ?></strong><span><?= Fmt::e($d->building) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php echo Ui::close();
