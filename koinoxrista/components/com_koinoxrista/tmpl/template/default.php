<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Layouts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

$account = Auth::isPlatformAdmin() ? Auth::account() : Auth::requireAccount();
$t = Repo::template($this->in->getInt('id', 0));
if (!$t) Auth::deny('Το πρότυπο δεν βρέθηκε.', 'view=templates');
$own = (int) $t->account_id > 0;
$editable = $own ? Auth::feature('designer') : (Auth::isPlatformAdmin() && !Auth::acting());

// Στήλες χιλιοστών από όλα τα κτίρια του λογαριασμού (για τα πεδία mills.<κωδικός>).
$keys = ['general' => 'Γενικά', 'elevator' => 'Ανελκυστήρας', 'heating' => 'Θέρμανση'];
foreach (Db::all('SELECT DISTINCT k.code, k.title FROM #__kx_keys k JOIN #__kx_buildings b ON b.id=k.building_id WHERE b.account_id=' . (int) ($account->id ?? 0) . " AND k.kind='custom'") as $k) {
    $keys[$k->code] = $k->title;
}
$sample = Layouts::sample();
$logo = Settings::asset((string) ($account->logo ?? ''));
if ($logo !== '') $sample['images']['account.logo'] = $logo;
$catalog = [
    'header' => Layouts::headerFields(),
    'unit' => Layouts::unitFields($keys),
    'expense' => Layouts::expenseFields(),
    'unit_expense' => Layouts::unitExpenseFields(),
    'images' => Layouts::images(),
];
$data = [
    'type' => $t->type, 'layout' => Repo::layout($t), 'catalog' => $catalog, 'sample' => $sample, 'editable' => $editable,
    'background' => $t->background ? Settings::asset($t->background) : '',
];
$media = rtrim(Uri::root(true), '/') . '/media/com_koinoxrista/';
$actions = Ui::btn('Δοκιμαστική εκτύπωση', Url::to('view=print&sample=1&template_id=' . (int) $t->id), 'secondary', 'target="_blank"')
    . ($editable ? '' : Ui::postButton('template.copy', ['id' => $t->id], 'Δημιουργία αντιγράφου για επεξεργασία', 'primary'));
echo Ui::open('templates', $t->title, $actions, Fmt::e(Layouts::TYPES[$t->type] ?? '') . ($own ? '' : ' · πρότυπο συστήματος' . ($editable ? ' (επεξεργασία ως διαχειριστής πλατφόρμας)' : ' · μόνο προβολή')));
?>
<link rel="stylesheet" href="<?= $media ?>css/print.css?v=1.0.1">
<script type="application/json" id="kx-designer-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?= Ui::form('template.save', 'id="kx-designer-form" class="kx-designer-form"', true) ?>
  <input type="hidden" name="id" value="<?= (int) $t->id ?>">
  <input type="hidden" name="layout" value="">
  <div class="kx-designer-top">
    <label class="kx-field kx-grow"><span>Όνομα προτύπου</span><input type="text" name="title" value="<?= Fmt::e($t->title) ?>" <?= $editable ? '' : 'readonly' ?>></label>
    <?php if ($editable): ?>
    <label class="kx-field"><span>Φόντο: σάρωση κενού εντύπου (PNG/JPG)</span><input type="file" name="background" accept="image/png,image/jpeg,image/webp"></label>
    <?php if ($t->background): ?><?= Ui::check('remove_background', false, 'Αφαίρεση φόντου') ?><?php endif; ?>
    <button type="submit" class="kx-btn kx-btn-primary">Αποθήκευση</button>
    <?php endif; ?>
  </div>
</form>
<div class="kx-designer" id="kx-designer">
  <aside class="kx-designer-tools">
    <div class="kx-dz-group" data-only-edit>
      <h3>Προσθήκη</h3>
      <div class="kx-dz-add">
        <button type="button" class="kx-btn kx-btn-small" data-add="field">Πεδίο</button>
        <button type="button" class="kx-btn kx-btn-small" data-add="text">Κείμενο</button>
        <button type="button" class="kx-btn kx-btn-small" data-add="table">Πίνακας</button>
        <button type="button" class="kx-btn kx-btn-small" data-add="line">Γραμμή</button>
        <button type="button" class="kx-btn kx-btn-small" data-add="box">Πλαίσιο</button>
        <button type="button" class="kx-btn kx-btn-small" data-add="image">Λογότυπο</button>
      </div>
    </div>
    <div class="kx-dz-group">
      <h3>Σελίδα</h3>
      <div class="kx-dz-props" data-page-props></div>
    </div>
    <div class="kx-dz-group">
      <h3>Εμφάνιση</h3>
      <label class="kx-check"><input type="checkbox" data-view="form" checked><span>Επίπεδο «Έντυπο»</span></label>
      <label class="kx-check"><input type="checkbox" data-view="data" checked><span>Επίπεδο «Δεδομένα»</span></label>
      <label class="kx-check"><input type="checkbox" data-view="bg" checked><span>Φόντο εντύπου</span></label>
      <label class="kx-check"><input type="checkbox" data-view="grid"><span>Πλέγμα 10 mm</span></label>
      <label class="kx-field"><span>Μεγέθυνση</span><input type="range" min="2" max="6" step="0.25" value="3.5" data-zoom></label>
    </div>
    <div class="kx-dz-group">
      <h3>Στοιχεία</h3>
      <ol class="kx-dz-list" data-list></ol>
    </div>
  </aside>
  <div class="kx-designer-stage" data-stage>
    <div class="kx-dz-paper" data-paper></div>
  </div>
  <aside class="kx-designer-props">
    <h3>Ιδιότητες</h3>
    <div data-props><p class="kx-muted">Επιλέξτε ένα στοιχείο στη σελίδα. Μετακίνηση με σύρσιμο ή με τα βελάκια (Shift = 5 mm, Alt = 0,1 mm). Αλλαγή πλάτους από τη δεξιά λαβή.</p></div>
  </aside>
</div>
<script src="<?= $media ?>js/designer.js?v=1.0.1" defer></script>
<?php echo Ui::close();
