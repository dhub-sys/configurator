<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Layouts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

if (!Auth::isPlatformAdmin()) Auth::requireAccount();
$canDesign = Auth::feature('designer');
$admin = Auth::isPlatformAdmin() && !Auth::acting();
echo Ui::open('templates', 'Πρότυπα εκτύπωσης', '', 'Ορίστε πού ακριβώς τυπώνεται κάθε πεδίο, ώστε να «πέφτει» στα προτυπωμένα σας έντυπα A4.');
?>
<section class="kx-card kx-howto">
  <h2>Πώς ταιριάζω την εκτύπωση στα προτυπωμένα μου έντυπα</h2>
  <ol>
    <li>Πατήστε <strong>Αντίγραφο</strong> σε ένα πρότυπο συστήματος (ή <strong>Νέο πρότυπο</strong>).</li>
    <li>Στον σχεδιαστή ανεβάστε μια <strong>σάρωση του κενού εντύπου</strong> ως φόντο (A4, 300 dpi). Το φόντο φαίνεται μόνο στην οθόνη.</li>
    <li>Σύρετε κάθε πεδίο πάνω στη θέση του στο έντυπο. Ό,τι είναι ήδη τυπωμένο στο χαρτί (τίτλοι, γραμμές) το βάζετε στο επίπεδο <strong>«Έντυπο»</strong>· δεν τυπώνεται στο προτυπωμένο χαρτί.</li>
    <li>Κάντε <strong>δοκιμαστική εκτύπωση</strong> σε λευκό χαρτί, βάλτε το πάνω στο έντυπο κόντρα στο φως και διορθώστε τη μετατόπιση του εκτυπωτή (Λογαριασμός → Εκτύπωση).</li>
  </ol>
  <?php if (!$canDesign): ?><p class="kx-alert kx-alert-notice">Τα δικά σας πρότυπα περιλαμβάνονται σε ανώτερο πακέτο. <a href="<?= Url::e('view=account&tab=billing') ?>">Δείτε τα πακέτα</a></p><?php endif; ?>
</section>
<?php foreach (Layouts::TYPES as $type => $label): $list = Repo::templates($type); ?>
<section class="kx-card">
  <div class="kx-card-head"><h2><?= Fmt::e($label) ?></h2>
    <?php if ($canDesign): ?><?= Ui::postButton('template.create', ['type' => $type], '+ Νέο πρότυπο', 'small') ?><?php endif; ?></div>
  <div class="kx-table-wrap"><table class="kx-table">
    <thead><tr><th>Πρότυπο</th><th>Προέλευση</th><th>Φόντο</th><th></th></tr></thead><tbody>
    <?php foreach ($list as $t): $own = (int) $t->account_id > 0; $editable = $own ? $canDesign : $admin; ?>
      <tr>
        <td><strong><?= Fmt::e($t->title) ?></strong> <?= (int) $t->is_default && $own ? Ui::badge('Προεπιλογή', 'success') : '' ?></td>
        <td><?= $own ? 'Δικό μου' : 'Συστήματος' ?></td>
        <td><?= $t->background ? '✓' : '—' ?></td>
        <td class="kx-right kx-nowrap">
          <a class="kx-btn kx-btn-small" target="_blank" href="<?= Url::e('view=print&sample=1&template_id=' . (int) $t->id) ?>">Δοκιμή</a>
          <a class="kx-btn kx-btn-small" href="<?= Url::e('view=template&id=' . (int) $t->id) ?>"><?= $editable ? 'Σχεδιαστής' : 'Προβολή' ?></a>
          <?php if ($canDesign): ?><?= Ui::postButton('template.copy', ['id' => $t->id], 'Αντίγραφο', 'small') ?><?php endif; ?>
          <?php if ($own && $canDesign && !(int) $t->is_default): ?><?= Ui::postButton('template.setdefault', ['id' => $t->id], 'Προεπιλογή', 'small') ?><?php endif; ?>
          <?php if ($editable): ?><?= Ui::postButton('template.delete', ['id' => $t->id], '✕', 'small', 'Διαγραφή του προτύπου «' . $t->title . '»;') ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endforeach;
echo Ui::close();
