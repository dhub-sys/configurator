<?php
\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Ui;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

$form = Ui::form('auth.login', 'class="kx-auth-form"')
    . '<label class="kx-field"><span>Email ή όνομα χρήστη</span><input type="text" name="username" autocomplete="username" required autofocus></label>'
    . '<label class="kx-field"><span>Κωδικός</span><input type="password" name="password" autocomplete="current-password" required></label>'
    . '<button type="submit" class="kx-btn kx-btn-primary kx-btn-block">Σύνδεση</button></form>';
if ((int) Settings::get('signup_enabled')) {
    $form .= '<p class="kx-auth-alt">Δεν έχετε λογαριασμό; <a href="' . Url::e('view=signup') . '">Δημιουργία λογαριασμού</a></p>';
}
echo Ui::bare('<h2>Σύνδεση</h2>' . $form);
