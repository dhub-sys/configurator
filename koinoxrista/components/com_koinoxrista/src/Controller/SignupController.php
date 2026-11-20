<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Mail;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;

/** Δημόσια εγγραφή νέου συνδρομητή (διαχειριστικής εταιρείας / επαγγελματία διαχειριστή). */
final class SignupController extends Base
{
    public function register(): void
    {
        $this->guard();
        if (!(int) Settings::get('signup_enabled')) {
            $this->go('view=app', 'Οι εγγραφές είναι κλειστές.', 'warning');
            return;
        }
        // Παγίδα για bots: κρυφό πεδίο που πρέπει να μείνει κενό.
        if ($this->str('website_hp') !== '') {
            $this->go('view=app');
            return;
        }
        $company = $this->str('company');
        $name = $this->str('name');
        $email = mb_strtolower($this->str('email'));
        $phone = $this->str('phone', 60);
        $password = (string) $this->input->post->get('password', '', 'raw');
        $this->app->setUserState('kx.signup', compact('company', 'name', 'email', 'phone'));
        if ($company === '' || $name === '' || $email === '') {
            $this->go('view=signup', 'Συμπληρώστε επωνυμία, ονοματεπώνυμο και email.', 'error');
            return;
        }
        if ($password !== (string) $this->input->post->get('password2', '', 'raw')) {
            $this->go('view=signup', 'Οι κωδικοί δεν ταιριάζουν.', 'error');
            return;
        }
        if (!$this->input->post->getInt('terms', 0)) {
            $this->go('view=signup', 'Πρέπει να αποδεχθείτε τους όρους χρήσης.', 'error');
            return;
        }
        $db = Db::db();
        try {
            $uid = Accounts::createUser($name, $email, $password);
            $aid = Accounts::createAccount($company, $email, $phone, $uid, $this->input->post->getInt('package_id', 0));
        } catch (\Throwable $e) {
            $this->go('view=signup', $e->getMessage(), 'error');
            return;
        }
        $this->app->setUserState('kx.signup', null);
        Auth::login($uid);
        Db::log('signup', 'Νέος λογαριασμός: ' . $company);
        $support = (string) Settings::get('support_email');
        if ($support !== '') {
            Mail::send($support, 'Νέα εγγραφή: ' . $company, '<p><strong>' . Fmt::e($company) . '</strong><br>' . Fmt::e($name) . '<br>' . Fmt::e($email) . '<br>' . Fmt::e($phone) . '</p>');
        }
        $this->go('view=dashboard', 'Καλώς ήρθατε! Ξεκινήστε προσθέτοντας το πρώτο σας κτίριο.');
    }
}
