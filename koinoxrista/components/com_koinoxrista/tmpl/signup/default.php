<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

if (!(int) Settings::get('signup_enabled')) {
    echo Ui::bare('<h2>Οι εγγραφές είναι κλειστές</h2><p><a href="' . Url::e('view=app') . '">Σύνδεση</a></p>');
    return;
}
$old = (array) Factory::getApplication()->getUserState('kx.signup', []);
$packages = Db::all('SELECT * FROM #__kx_packages WHERE published=1 ORDER BY ordering, id');
$chosen = $this->in->getInt('package', (int) Settings::get('signup_package_id'));
$terms = trim((string) Settings::get('terms_url'));
$h = '<h2>Δημιουργία λογαριασμού</h2><p class="kx-muted">Για διαχειριστικές εταιρείες και επαγγελματίες διαχειριστές πολυκατοικιών.</p>'
    . Ui::form('signup.register', 'class="kx-auth-form"')
    . '<div class="kx-hp" aria-hidden="true"><input type="text" name="website_hp" tabindex="-1" autocomplete="off"></div>'
    . Ui::input('company', $old['company'] ?? '', 'Επωνυμία (εταιρεία ή ονοματεπώνυμο)', ['required' => true])
    . Ui::input('name', $old['name'] ?? '', 'Ονοματεπώνυμο υπευθύνου', ['required' => true, 'autocomplete' => 'name'])
    . Ui::input('email', $old['email'] ?? '', 'Email (θα είναι και το όνομα χρήστη)', ['type' => 'email', 'required' => true, 'autocomplete' => 'email'])
    . Ui::input('phone', $old['phone'] ?? '', 'Τηλέφωνο', ['type' => 'tel', 'autocomplete' => 'tel'])
    . Ui::input('password', '', 'Κωδικός (8+ χαρακτήρες)', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'min' => 8])
    . Ui::input('password2', '', 'Επιβεβαίωση κωδικού', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']);
if (count($packages) > 1) {
    $opts = [];
    foreach ($packages as $p) {
        $opts[$p->id] = $p->title . ((int) $p->trial_days ? ' — δωρεάν ' . (int) $p->trial_days . ' ημέρες' : ((float) $p->price_month > 0 ? ' — ' . Fmt::euro($p->price_month) . '/μήνα' : ''));
    }
    $h .= Ui::select('package_id', $opts, $chosen ?: (int) ($packages[0]->id ?? 0), 'Πακέτο');
}
$h .= '<label class="kx-check"><input type="checkbox" name="terms" value="1" required><span>Αποδέχομαι τους ' . ($terms !== '' ? '<a href="' . Fmt::e($terms) . '" target="_blank" rel="noopener">όρους χρήσης</a>' : 'όρους χρήσης') . '</span></label>'
    . '<button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Δημιουργία λογαριασμού</button></form>'
    . '<p class="kx-auth-alt">Έχετε ήδη λογαριασμό; <a href="' . Url::e('view=app') . '">Σύνδεση</a></p>';
echo Ui::bare($h);
