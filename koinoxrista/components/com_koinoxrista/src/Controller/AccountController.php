<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\User\UserHelper;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Mail;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;

/** Στοιχεία συνδρομητή, ρυθμίσεις εκτύπωσης, ομάδα χρηστών και αιτήματα πακέτου. */
final class AccountController extends Base
{
    public function saveprofile(): void
    {
        $this->guard();
        $a = Auth::requireOwner();
        $data = [
            'name' => $this->str('name') ?: $a->name, 'afm' => $this->str('afm', 20), 'doy' => $this->str('doy', 100), 'address' => $this->str('address', 255),
            'phone' => $this->str('phone', 60), 'email' => $this->email('email'), 'website' => $this->str('website'), 'modified' => Db::now(),
        ];
        try {
            $logo = $this->upload('logo', 'a' . (int) $a->id, 'logo');
            if ($logo !== '') $data['logo'] = $logo;
        } catch (\Throwable $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
        }
        if ($this->int('remove_logo')) $data['logo'] = '';
        Db::update('#__kx_accounts', (int) $a->id, $data);
        $this->go('view=account', 'Τα στοιχεία αποθηκεύτηκαν.');
    }

    public function savesettings(): void
    {
        $this->guard();
        $a = Auth::requireOwner();
        $s = Accounts::settings($a);
        $s['offset_x'] = max(-20, min(20, round($this->num('offset_x'), 1)));
        $s['offset_y'] = max(-20, min(20, round($this->num('offset_y'), 1)));
        $s['notice_email_text'] = $this->text('notice_email_text', 1000);
        $s['receipt_footer'] = $this->text('receipt_footer', 500);
        Db::update('#__kx_accounts', (int) $a->id, ['settings' => json_encode($s, JSON_UNESCAPED_UNICODE), 'modified' => Db::now()]);
        $this->go('view=account&tab=print', 'Οι ρυθμίσεις αποθηκεύτηκαν.');
    }

    public function adduser(): void
    {
        $this->guard();
        $a = Auth::requireOwner();
        Auth::requireWritable();
        if (!Auth::within('users')) {
            $this->go('view=account&tab=team', 'Φτάσατε το όριο χρηστών του πακέτου σας.', 'warning');
            return;
        }
        $name = $this->str('name');
        $email = mb_strtolower($this->str('email'));
        $password = (string) $this->input->post->get('password', '', 'raw') ?: Accounts::randomPassword();
        try {
            $uid = Accounts::createUser($name ?: $email, $email, $password);
        } catch (\Throwable $e) {
            $this->go('view=account&tab=team', $e->getMessage(), 'error');
            return;
        }
        Db::insert('#__kx_account_users', ['account_id' => (int) $a->id, 'user_id' => $uid, 'role' => 'staff', 'created' => Db::now()]);
        Mail::send($email, 'Πρόσβαση στο ' . Settings::get('brand_name'), '<p>Ο λογαριασμός ' . Fmt::e($a->name) . ' σας έδωσε πρόσβαση.</p><p>Σύνδεση: <a href="' . Fmt::e(\Koinoxrista\Component\Koinoxrista\Site\Helper\Url::to('view=app')) . '">' . Fmt::e(\Koinoxrista\Component\Koinoxrista\Site\Helper\Url::to('view=app')) . '</a><br>Όνομα χρήστη: ' . Fmt::e($email) . '<br>Κωδικός: ' . Fmt::e($password) . '</p><p>Αλλάξτε τον κωδικό σας μετά την πρώτη σύνδεση.</p>');
        Db::log('user.add', $email);
        $this->go('view=account&tab=team', 'Ο χρήστης προστέθηκε και ενημερώθηκε με email.');
    }

    public function removeuser(): void
    {
        $this->guard();
        $a = Auth::requireOwner();
        $uid = $this->int('user_id');
        $m = Db::one('SELECT * FROM #__kx_account_users WHERE account_id=' . (int) $a->id . ' AND user_id=' . $uid);
        if (!$m || $m->role === 'owner') {
            $this->go('view=account&tab=team', 'Ο υπεύθυνος του λογαριασμού δεν αφαιρείται.', 'warning');
            return;
        }
        Db::exec('DELETE FROM #__kx_account_users WHERE id=' . (int) $m->id);
        Db::exec('UPDATE #__users SET block=1 WHERE id=' . $uid . ' AND id NOT IN (SELECT user_id FROM #__user_usergroup_map WHERE group_id=8)');
        Db::log('user.remove', (string) $uid);
        $this->go('view=account&tab=team', 'Ο χρήστης αφαιρέθηκε.');
    }

    public function password(): void
    {
        $this->guard();
        $uid = Auth::userId();
        $current = (string) $this->input->post->get('current', '', 'raw');
        $new = (string) $this->input->post->get('new', '', 'raw');
        $hash = (string) Db::value('SELECT password FROM #__users WHERE id=' . $uid);
        if (!UserHelper::verifyPassword($current, $hash, $uid)) {
            $this->go('view=account&tab=me', 'Ο τρέχων κωδικός δεν είναι σωστός.', 'error');
            return;
        }
        if (mb_strlen($new) < 8 || $new !== (string) $this->input->post->get('new2', '', 'raw')) {
            $this->go('view=account&tab=me', 'Ο νέος κωδικός πρέπει να έχει 8+ χαρακτήρες και να ταιριάζει με την επιβεβαίωση.', 'error');
            return;
        }
        Db::exec('UPDATE #__users SET password=' . Db::q(UserHelper::hashPassword($new)) . ', requireReset=0 WHERE id=' . $uid);
        $this->go('view=account&tab=me', 'Ο κωδικός άλλαξε.');
    }

    /** Αίτημα αλλαγής/ανανέωσης πακέτου: καταγράφεται και ειδοποιείται ο διαχειριστής πλατφόρμας. */
    public function requestpackage(): void
    {
        $this->guard();
        $a = Auth::requireOwner();
        $pkg = Db::one('SELECT * FROM #__kx_packages WHERE published=1 AND id=' . $this->int('package_id'));
        if (!$pkg) {
            $this->go('view=account&tab=billing', 'Επιλέξτε πακέτο.', 'error');
            return;
        }
        $cycle = $this->str('cycle') === 'year' ? 'year' : 'month';
        $amount = $cycle === 'year' ? (float) $pkg->price_year : (float) $pkg->price_month;
        Db::exec('UPDATE #__kx_billing SET status=\'cancelled\' WHERE account_id=' . (int) $a->id . ' AND kind=\'request\' AND status=\'pending\'');
        Db::insert('#__kx_billing', [
            'account_id' => (int) $a->id, 'package_id' => (int) $pkg->id, 'kind' => 'request', 'cycle' => $cycle, 'amount' => $amount,
            'vat_rate' => (float) Settings::get('vat_rate'), 'status' => 'pending', 'notes' => $this->text('notes', 1000), 'created' => Db::now(), 'created_by' => Auth::userId(),
        ]);
        $support = (string) Settings::get('support_email');
        if ($support !== '') {
            Mail::send($support, 'Αίτημα πακέτου: ' . $a->name, '<p><strong>' . Fmt::e($a->name) . '</strong> ζητά το πακέτο <strong>' . Fmt::e($pkg->title) . '</strong> (' . ($cycle === 'year' ? 'ετήσια' : 'μηνιαία') . ' χρέωση, ' . Fmt::euro($amount) . ' + ΦΠΑ).</p>');
        }
        Db::log('billing.request', $pkg->title);
        $this->go('view=account&tab=billing', 'Το αίτημα καταχωρίστηκε. Θα ενεργοποιηθεί μόλις επιβεβαιωθεί η πληρωμή.');
    }
}
