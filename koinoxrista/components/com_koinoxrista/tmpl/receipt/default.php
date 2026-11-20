<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

/* Απόδειξη είσπραξης (A4, δύο αντίτυπα: στέλεχος + απόδειξη). */
$account = Auth::requireAccount();
$pay = Db::one('SELECT * FROM #__kx_payments WHERE id=' . $this->in->getInt('id', 0));
if (!$pay) Auth::deny('Δεν βρέθηκε.', 'view=payments');
$b = Repo::building((int) $pay->building_id);
$u = Db::one('SELECT * FROM #__kx_units WHERE id=' . (int) $pay->unit_id);
$footer = (string) Accounts::settings($account)['receipt_footer'];
$logo = Settings::asset((string) $account->logo);
$media = rtrim(Uri::root(true), '/') . '/media/com_koinoxrista/';
$copy = static function (string $label) use ($account, $b, $u, $pay, $footer, $logo): string {
    return '<div class="kx-receipt"><div class="kx-receipt-head">' . ($logo ? '<img src="' . Fmt::e($logo) . '" alt="">' : '') . '<div><strong>' . Fmt::e($account->name) . '</strong><br>' . Fmt::e(implode(' · ', array_filter([$account->address, $account->phone, $account->email]))) . '</div>'
        . '<div class="kx-receipt-no"><span>' . Fmt::e($label) . '</span><strong>ΑΠΟΔΕΙΞΗ ΕΙΣΠΡΑΞΗΣ</strong><span>Αρ. ' . (int) $pay->receipt_no . ' · ' . Fmt::date($pay->pay_date) . '</span></div></div>'
        . '<table><tr><th>Κτίριο</th><td>' . Fmt::e($b->title) . ' — ' . Fmt::e(Fmt::address($b)) . '</td></tr>'
        . '<tr><th>Ιδιοκτησία</th><td>' . Fmt::e($u->code . ($u->floor ? ' · ' . $u->floor : '')) . '</td></tr>'
        . '<tr><th>Εισπράχθηκε από</th><td>' . Fmt::e(Repo::payerName($u)) . '</td></tr>'
        . '<tr><th>Αιτιολογία</th><td>Κοινόχρηστα' . ($pay->notes ? ' — ' . Fmt::e($pay->notes) : '') . '</td></tr>'
        . '<tr><th>Τρόπος</th><td>' . Fmt::e(Repo::PAY_METHODS[$pay->method] ?? '') . '</td></tr>'
        . '<tr class="kx-receipt-amount"><th>Ποσό</th><td>' . Fmt::euro($pay->amount) . '</td></tr></table>'
        . '<div class="kx-receipt-sign"><span>Ο εισπράξας</span><span>Ο καταβάλλων</span></div>'
        . ($footer !== '' ? '<p class="kx-receipt-foot">' . nl2br(Fmt::e($footer)) . '</p>' : '') . '</div>';
};
?>
<link rel="stylesheet" href="<?= $media ?>css/app.css?v=1.0.1">
<link rel="stylesheet" href="<?= $media ?>css/print.css?v=1.0.1">
<div class="kx-printbar kx-noprint">
  <a class="kx-btn kx-btn-small" href="<?= Url::e('view=payments&building_id=' . (int) $b->id . '&unit_id=' . (int) $u->id) ?>">← Πίσω</a>
  <strong class="kx-printbar-title">Απόδειξη <?= (int) $pay->receipt_no ?></strong>
  <button type="button" class="kx-btn kx-btn-primary" onclick="window.print()">Εκτύπωση</button>
</div>
<div class="kx-pages"><section class="kx-page kx-page-receipt" style="width:210mm;height:297mm"><?= $copy('ΣΤΕΛΕΧΟΣ') ?><div class="kx-cut"></div><?= $copy('ΑΝΤΙΤΥΠΟ ΠΕΛΑΤΗ') ?></section></div>
<style>@page{size:A4 portrait;margin:0}@media screen{body{background:#e5e7eb!important;margin:0}}</style>
