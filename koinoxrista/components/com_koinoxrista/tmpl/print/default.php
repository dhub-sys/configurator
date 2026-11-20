<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Calc;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Layouts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Render;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

/*
 * Σελίδες A4 για εκτύπωση. Παράμετροι: period_id, type (sheet|notice), sheet_template / notice_template,
 * mode (full|data|form), unit_id, dx/dy (πρόσθετη μετατόπιση mm), grid, draft. Με sample=1&template_id=Χ
 * τυπώνεται το πρότυπο με δείγμα δεδομένων.
 */
$account = Auth::isPlatformAdmin() ? Auth::account() : Auth::requireAccount();
$in = $this->in;
$sample = $in->getInt('sample', 0) === 1;
$mode = in_array($in->getCmd('mode', 'full'), ['full', 'data', 'form'], true) ? $in->getCmd('mode', 'full') : 'full';
$issued = false;
$p = $b = null;

if ($sample) {
    $tpl = Repo::template($in->getInt('template_id', 0));
    if (!$tpl) Auth::deny('Το πρότυπο δεν βρέθηκε.', 'view=templates');
    $type = $tpl->type;
    $ctx = Layouts::sample();
    $title = 'Δοκιμή: ' . $tpl->title;
    $back = Url::to('view=template&id=' . (int) $tpl->id);
} else {
    [$p, $b] = Repo::period($in->getInt('period_id', 0));
    $type = $in->getCmd('type', 'sheet') === 'notice' ? 'notice' : 'sheet';
    $tpl = Repo::template($in->getInt($type . '_template', $in->getInt('template_id', 0))) ?: Repo::defaultTemplate($type);
    $issued = $p->status === 'issued';
    $snap = $issued ? json_decode((string) $p->snapshot, true) : null;
    $ctx = !empty($snap['ctx']) ? $snap['ctx'] : Repo::printContext(Repo::compute($p, $b), $account);
    $title = ($type === 'notice' ? 'Ειδοποιητήρια' : 'Κοινόχρηστα') . ' ' . ($ctx['fields']['period.title'] ?? '') . ' - ' . $b->title;
    $back = Url::to('view=period&id=' . (int) $p->id . '&tab=print');
    $logo = Settings::asset((string) ($account->logo ?? ""));
    $ctx['images'] = $logo !== '' ? ['account.logo' => $logo] : [];
    $only = $in->getInt('unit_id', 0);
    if ($type === 'notice' && $only > 0) {
        $idx = array_search($only, array_map('intval', $ctx['unit_ids'] ?? []), true);
        $ctx['units'] = $idx === false ? [] : [$ctx['units'][$idx]];
        $ctx['unit_expenses'] = $idx === false ? [] : [$ctx['unit_expenses'][$idx] ?? []];
    }
    $ctx['fields']['print.date'] = date('d/m/Y');
}
if (!$tpl) Auth::deny('Δεν υπάρχει πρότυπο εκτύπωσης.', 'view=templates');

$layout = Repo::layout($tpl);
$acc = Accounts::settings($account);
$dx = round((float) $acc['offset_x'] + Calc::parseNumber($in->getString('dx', '0')), 1);
$dy = round((float) $acc['offset_y'] + Calc::parseNumber($in->getString('dy', '0')), 1);
$grid = $in->getInt('grid', 0) === 1;
$draft = !$sample && !$issued && $in->getInt('draft', 1) === 1;
$bg = $tpl->background && $in->getInt('bg', $mode === 'data' ? 1 : 0) ? Settings::asset($tpl->background) : '';

Factory::getApplication()->getDocument()->setTitle($title);
$media = rtrim(Uri::root(true), '/') . '/media/com_koinoxrista/';
$templates = Repo::templates($type);
?>
<link rel="stylesheet" href="<?= $media ?>css/app.css?v=1.0.0">
<link rel="stylesheet" href="<?= $media ?>css/print.css?v=1.0.0">
<div class="kx-printbar kx-noprint">
  <a class="kx-btn kx-btn-small" href="<?= Fmt::e($back) ?>">← Πίσω</a>
  <strong class="kx-printbar-title"><?= Fmt::e($title) ?></strong>
  <form method="get" action="<?= Url::e() ?>" class="kx-printbar-form">
    <?php foreach ($in->getArray() as $k => $v): if (in_array($k, ['option', 'tmpl', 'mode', 'dx', 'dy', 'grid', 'draft', 'bg', $type . '_template', 'template_id', 'Itemid'], true) || is_array($v)) continue; ?>
      <input type="hidden" name="<?= Fmt::e($k) ?>" value="<?= Fmt::e($v) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="option" value="com_koinoxrista"><input type="hidden" name="tmpl" value="component">
    <?php if (!$sample): ?>
    <select name="<?= $type ?>_template" onchange="this.form.submit()" title="Πρότυπο">
      <?php foreach ($templates as $t): ?><option value="<?= (int) $t->id ?>"<?= (int) $t->id === (int) $tpl->id ? ' selected' : '' ?>><?= Fmt::e($t->title) ?></option><?php endforeach; ?>
    </select>
    <?php else: ?><input type="hidden" name="template_id" value="<?= (int) $tpl->id ?>"><?php endif; ?>
    <select name="mode" onchange="this.form.submit()" title="Χαρτί">
      <option value="full"<?= $mode === 'full' ? ' selected' : '' ?>>Λευκό χαρτί</option>
      <option value="data"<?= $mode === 'data' ? ' selected' : '' ?>>Προτυπωμένο (μόνο δεδομένα)</option>
      <option value="form"<?= $mode === 'form' ? ' selected' : '' ?>>Κενό έντυπο</option>
    </select>
    <label title="Μετατόπιση δεξιά (+) / αριστερά (−) σε χιλιοστά">↔ <input type="text" name="dx" value="<?= Fmt::e($in->getString('dx', '0')) ?>" size="3" inputmode="decimal"> mm</label>
    <label title="Μετατόπιση κάτω (+) / πάνω (−) σε χιλιοστά">↕ <input type="text" name="dy" value="<?= Fmt::e($in->getString('dy', '0')) ?>" size="3" inputmode="decimal"> mm</label>
    <label><input type="checkbox" name="grid" value="1"<?= $grid ? ' checked' : '' ?> onchange="this.form.submit()"> Πλέγμα</label>
    <?php if ($tpl->background): ?><label title="Το σκαναρισμένο έντυπο φαίνεται μόνο στην οθόνη, δεν τυπώνεται"><input type="hidden" name="bg" value="0"><input type="checkbox" name="bg" value="1"<?= $bg ? ' checked' : '' ?> onchange="this.form.submit()"> Φόντο εντύπου</label><?php endif; ?>
    <?php if (!$sample && !$issued): ?><label><input type="hidden" name="draft" value="0"><input type="checkbox" name="draft" value="1"<?= $draft ? ' checked' : '' ?> onchange="this.form.submit()"> «ΠΡΟΣΧΕΔΙΟ»</label><?php endif; ?>
    <button type="submit" class="kx-btn kx-btn-small">Εφαρμογή</button>
  </form>
  <button type="button" class="kx-btn kx-btn-primary" onclick="window.print()">Εκτύπωση</button>
  <p class="kx-printbar-hint">Στο παράθυρο εκτύπωσης: <strong>Περιθώρια: Κανένα</strong>, <strong>Κλίμακα: 100%</strong>, χωρίς κεφαλίδες/υποσέλιδα. Για προτυπωμένα έντυπα κάντε πρώτα μια δοκιμή σε λευκό χαρτί με «Πλέγμα» και διορθώστε τη μετατόπιση (μόνιμα στο Λογαριασμός → Εκτύπωση).<?= $acc['offset_x'] || $acc['offset_y'] ? ' Εφαρμόζεται μόνιμη μετατόπιση ' . Fmt::e($acc['offset_x']) . '/' . Fmt::e($acc['offset_y']) . ' mm.' : '' ?></p>
</div>
<div class="kx-pages">
<?php if ($type === 'notice' && empty($ctx['units'])): ?>
  <p class="kx-noprint">Δεν υπάρχουν ιδιοκτησίες για εκτύπωση.</p>
<?php else: ?>
  <?= Render::document($type, $layout, $ctx, ['mode' => $mode, 'offset_x' => $dx, 'offset_y' => $dy, 'grid' => $grid, 'background' => $bg, 'draft' => $draft]) ?>
<?php endif; ?>
</div>
<style>@media screen{body{background:#e5e7eb!important;margin:0}}</style>
