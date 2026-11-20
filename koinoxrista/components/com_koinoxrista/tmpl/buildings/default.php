<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

Auth::requireAccount();
$archived = $this->in->getInt('archived', 0) === 1;
$rows = Repo::buildings($archived);
$q = mb_strtolower(trim($this->in->getString('q', '')));
if ($q !== '') {
    $rows = array_filter($rows, static fn($b) => str_contains(mb_strtolower($b->title . ' ' . Fmt::address($b) . ' ' . $b->manager_name), $q));
}
echo Ui::open('buildings', $archived ? 'Αρχειοθετημένα κτίρια' : 'Κτίρια', Ui::btn('+ Νέο κτίριο', Url::to('view=building&id=0'), 'primary'));
?>
<div class="kx-toolbar">
  <form method="get" action="<?= Url::e() ?>" class="kx-search">
    <input type="hidden" name="option" value="com_koinoxrista"><input type="hidden" name="tmpl" value="component"><input type="hidden" name="view" value="buildings">
    <?php if ($archived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <input type="search" name="q" value="<?= Fmt::e($q) ?>" placeholder="Αναζήτηση κτιρίου, διεύθυνσης, διαχειριστή…">
  </form>
  <a class="kx-link" href="<?= Url::e('view=buildings' . ($archived ? '' : '&archived=1')) ?>"><?= $archived ? '← Ενεργά κτίρια' : 'Αρχειοθετημένα' ?></a>
</div>
<?php if (!$rows): ?>
  <?= Ui::empty($q !== '' ? 'Δεν βρέθηκαν κτίρια' : 'Δεν υπάρχουν κτίρια', $q !== '' ? 'Δοκιμάστε άλλη αναζήτηση.' : 'Προσθέστε το πρώτο κτίριο για να ξεκινήσετε.', $q !== '' ? '' : Ui::btn('+ Νέο κτίριο', Url::to('view=building&id=0'), 'primary')) ?>
<?php else: ?>
<div class="kx-grid-cards">
  <?php foreach ($rows as $b): $last = $b->last_period ? explode(':', $b->last_period) : null; ?>
    <a class="kx-bcard" href="<?= Url::e('view=building&id=' . (int) $b->id) ?>">
      <strong><?= Fmt::e($b->title) ?></strong>
      <span class="kx-muted"><?= Fmt::e(Fmt::address($b)) ?></span>
      <span class="kx-bcard-meta">
        <span><?= (int) $b->units_count ?> ιδιοκτησίες</span>
        <span><?= $b->heating_type !== 'none' ? 'Κεντρική θέρμανση' : 'Χωρίς κεντρική θέρμανση' ?></span>
      </span>
      <span class="kx-bcard-foot"><?php if ($last): ?><?= Fmt::e(Fmt::period((int) substr($last[0], 4), (int) substr($last[0], 0, 4))) ?> <?= $last[1] === 'issued' ? Ui::badge('Εκδόθηκε', 'success') : Ui::badge('Πρόχειρο', 'warning') ?><?php else: ?><span class="kx-muted">Χωρίς μήνες</span><?php endif; ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif;
echo Ui::close();
