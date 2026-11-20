<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Notice;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Render;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;

/* Δημόσιο ειδοποιητήριο μίας ιδιοκτησίας (σύνδεσμος με υπογραφή HMAC, μόνο για εκδοθέντες μήνες). */
$pid = $this->in->getInt('p', 0);
$uid = $this->in->getInt('u', 0);
$ok = Notice::valid($pid, $uid, (string) $this->in->getString('t', ''));
$p = $ok ? Db::one('SELECT * FROM #__kx_periods WHERE id=' . $pid . " AND status='issued'") : null;
$b = $p ? Db::one('SELECT * FROM #__kx_buildings WHERE id=' . (int) $p->building_id) : null;
$snap = $p ? json_decode((string) $p->snapshot, true) : null;
$idx = $snap ? array_search($uid, array_map('intval', $snap['ctx']['unit_ids'] ?? []), true) : false;
if (!$b || $idx === false) {
    echo '<div style="font-family:Arial,sans-serif;max-width:480px;margin:80px auto;text-align:center"><h2>Ο σύνδεσμος δεν είναι διαθέσιμος</h2><p>Ίσως ο μήνας άνοιξε ξανά για διορθώσεις. Επικοινωνήστε με τον διαχειριστή του κτιρίου.</p></div>';
    return;
}
$account = Db::one('SELECT * FROM #__kx_accounts WHERE id=' . (int) $b->account_id);
$tpl = Repo::defaultTemplate('notice', (int) $b->account_id);
$ctx = $snap['ctx'];
$ctx['units'] = [$ctx['units'][$idx]];
$ctx['unit_expenses'] = [$ctx['unit_expenses'][$idx] ?? []];
$logo = Settings::asset((string) ($account->logo ?? ''));
$ctx['images'] = $logo !== '' ? ['account.logo' => $logo] : [];
$layout = Repo::layout($tpl);
$layout['per_page'] = 1;
Factory::getApplication()->getDocument()->setTitle('Ειδοποιητήριο ' . ($ctx['fields']['period.title'] ?? '') . ' - ' . ($ctx['units'][0]['unit.code'] ?? ''));
$media = rtrim(Uri::root(true), '/') . '/media/com_koinoxrista/';
?>
<link rel="stylesheet" href="<?= $media ?>css/app.css?v=1.0.1">
<link rel="stylesheet" href="<?= $media ?>css/print.css?v=1.0.1">
<div class="kx-printbar kx-noprint">
  <strong class="kx-printbar-title"><?= Fmt::e($b->title) ?> · <?= Fmt::e($ctx['units'][0]['unit.code'] ?? '') ?> · <?= Fmt::e($ctx['fields']['period.title'] ?? '') ?></strong>
  <span class="kx-printbar-total">Πληρωτέο: <strong><?= Fmt::e($ctx['units'][0]['amt.total'] ?? '') ?> €</strong></span>
  <button type="button" class="kx-btn kx-btn-primary" onclick="window.print()">Εκτύπωση / PDF</button>
</div>
<div class="kx-pages"><?= Render::document('notice', $layout, $ctx, ['mode' => 'full']) ?></div>
<style>@media screen{body{background:#e5e7eb!important;margin:0}}</style>
