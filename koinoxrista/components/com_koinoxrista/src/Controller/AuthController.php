<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\User\UserHelper;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Mail;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

/** Σύνδεση με τα στοιχεία του Joomla χρήστη και προαιρετικός κωδικός μίας χρήσης στο email. */
final class AuthController extends Base
{
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 300;

    public function login(): void
    {
        $this->guard();
        $s = $this->app->getSession();
        if ((int) $s->get('kx.lock_until', 0) > time()) {
            $this->go('view=app', 'Πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε λίγα λεπτά.', 'warning');
            return;
        }
        $login = trim($this->input->post->getString('username', ''));
        $password = (string) $this->input->post->get('password', '', 'raw');
        $user = $login === '' ? null : Db::one('SELECT id, name, email, password, block, requireReset FROM #__users WHERE username=' . Db::q($login) . ' OR email=' . Db::q($login) . ' ORDER BY username=' . Db::q($login) . ' DESC LIMIT 1');
        $ok = $user && !(int) $user->block && $password !== '' && UserHelper::verifyPassword($password, (string) $user->password, (int) $user->id) && Auth::allowed((int) $user->id);
        if (!$ok) {
            $fails = (int) $s->get('kx.fails', 0) + 1;
            $s->set('kx.fails', $fails);
            if ($fails >= self::MAX_FAILS) {
                $s->set('kx.lock_until', time() + self::LOCK_SECONDS);
                $s->set('kx.fails', 0);
            }
            $this->go('view=app', 'Λάθος στοιχεία σύνδεσης.', 'error');
            return;
        }
        $s->set('kx.fails', 0);
        $this->app->getSession()->fork();
        if ((int) Settings::get('otp_enabled')) {
            $s->set('kx.pending_user', (int) $user->id);
            $this->sendOtp((int) $user->id, (string) $user->email, (string) $user->name);
            $this->go('view=otp');
            return;
        }
        $this->finish((int) $user->id);
    }

    public function verify(): void
    {
        $this->guard();
        $s = $this->app->getSession();
        $uid = (int) $s->get('kx.pending_user', 0);
        $otp = (array) $s->get('kx.otp', []);
        $code = preg_replace('~\D~', '', $this->input->post->getString('code', ''));
        if (!$uid || !$otp || ($otp['uid'] ?? 0) !== $uid) {
            $this->go('view=app', 'Η σύνδεση έληξε. Συνδεθείτε ξανά.', 'warning');
            return;
        }
        if (time() > (int) $otp['expires'] || (int) $otp['tries'] >= 5) {
            $s->clear('kx.otp');
            $s->clear('kx.pending_user');
            $this->go('view=app', 'Ο κωδικός έληξε. Συνδεθείτε ξανά.', 'warning');
            return;
        }
        if (!password_verify($code, (string) $otp['hash'])) {
            $otp['tries'] = (int) $otp['tries'] + 1;
            $s->set('kx.otp', $otp);
            $this->go('view=otp', 'Λάθος κωδικός.', 'error');
            return;
        }
        $s->clear('kx.pending_user');
        $this->finish($uid);
    }

    public function resend(): void
    {
        $this->guard();
        $s = $this->app->getSession();
        $uid = (int) $s->get('kx.pending_user', 0);
        $otp = (array) $s->get('kx.otp', []);
        if (!$uid) {
            $this->go('view=app');
            return;
        }
        if (time() - (int) ($otp['sent'] ?? 0) < 60) {
            $this->go('view=otp', 'Περιμένετε ένα λεπτό πριν ζητήσετε νέο κωδικό.', 'warning');
            return;
        }
        $u = Db::one('SELECT id, name, email FROM #__users WHERE id=' . $uid);
        $this->sendOtp($uid, (string) $u->email, (string) $u->name);
        $this->go('view=otp', 'Στάλθηκε νέος κωδικός.');
    }

    public function logout(): void
    {
        Auth::logout();
        $this->go('view=app', 'Αποσυνδεθήκατε.');
    }

    private function sendOtp(int $uid, string $email, string $name): void
    {
        $code = (string) random_int(100000, 999999);
        $this->app->getSession()->set('kx.otp', ['uid' => $uid, 'hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + 300, 'tries' => 0, 'sent' => time()]);
        Mail::send($email, 'Κωδικός σύνδεσης: ' . $code, '<p>Γεια σας ' . Fmt::e($name) . ',</p><p>Ο κωδικός σύνδεσης είναι:</p><p style="font-size:26px;font-weight:700;letter-spacing:4px">' . $code . '</p><p>Ισχύει για 5 λεπτά.</p>');
    }

    private function finish(int $uid): void
    {
        Auth::login($uid);
        $return = (string) $this->app->getSession()->get('kx.return', '');
        $this->app->getSession()->clear('kx.return');
        if ($return !== '' && str_starts_with($return, rtrim(\Joomla\CMS\Uri\Uri::root(), '/')) && str_contains($return, 'option=com_koinoxrista')) {
            $this->app->redirect($return);
            return;
        }
        $this->go(Auth::account() ? 'view=dashboard' : 'view=platform');
    }
}
