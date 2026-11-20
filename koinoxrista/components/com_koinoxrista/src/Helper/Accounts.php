<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;

/** Δημιουργία συνδρομητών και χρηστών (Joomla users + σύνδεση με λογαριασμό). */
final class Accounts
{
    /** Δημιουργεί Joomla χρήστη· επιστρέφει id ή πετάει εξαίρεση με κατανοητό μήνυμα. */
    public static function createUser(string $name, string $email, string $password): int
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Μη έγκυρο email.');
        }
        if (mb_strlen($password) < 8) {
            throw new \RuntimeException('Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.');
        }
        if ((int) Db::value('SELECT COUNT(*) FROM #__users WHERE email=' . Db::q($email) . ' OR username=' . Db::q($email)) > 0) {
            throw new \RuntimeException('Υπάρχει ήδη χρήστης με αυτό το email.');
        }
        $group = (int) ComponentHelper::getParams('com_users')->get('new_usertype', 2) ?: 2;
        $user = new User();
        $data = ['name' => $name, 'username' => $email, 'email' => $email, 'password' => $password, 'password2' => $password, 'groups' => [$group], 'block' => 0, 'sendEmail' => 0];
        if (!$user->bind($data) || !$user->save()) {
            throw new \RuntimeException('Ο χρήστης δεν δημιουργήθηκε: ' . implode(' ', array_map('strval', (array) $user->getErrors())));
        }
        return (int) $user->id;
    }

    /** Νέος λογαριασμός συνδρομητή με το πακέτο εγγραφής (δοκιμαστική περίοδος αν ορίζεται). */
    public static function createAccount(string $name, string $email, string $phone, int $ownerUserId, int $packageId = 0): int
    {
        $pkg = null;
        if ($packageId > 0) $pkg = Db::one('SELECT * FROM #__kx_packages WHERE id=' . $packageId);
        if (!$pkg && (int) Settings::get('signup_package_id') > 0) $pkg = Db::one('SELECT * FROM #__kx_packages WHERE id=' . (int) Settings::get('signup_package_id'));
        if (!$pkg) $pkg = Db::one('SELECT * FROM #__kx_packages WHERE published=1 ORDER BY is_default DESC, ordering LIMIT 1');
        $trial = (int) ($pkg->trial_days ?? 0);
        $today = Db::today();
        $id = Db::insert('#__kx_accounts', [
            'name' => $name, 'email' => $email, 'phone' => $phone, 'package_id' => (int) ($pkg->id ?? 0), 'billing_cycle' => 'month',
            'status' => $trial > 0 ? 'trial' : 'active', 'trial_ends' => $trial > 0 ? date('Y-m-d', strtotime($today . ' +' . $trial . ' days')) : null,
            'paid_until' => null, 'settings' => '{}', 'notes' => '', 'created' => Db::now(), 'modified' => Db::now(),
        ]);
        Db::insert('#__kx_account_users', ['account_id' => $id, 'user_id' => $ownerUserId, 'role' => 'owner', 'created' => Db::now()]);
        return $id;
    }

    public static function settings(?object $account): array
    {
        $s = json_decode((string) ($account->settings ?? ''), true) ?: [];
        return $s + ['offset_x' => 0, 'offset_y' => 0, 'notice_email_text' => '', 'receipt_footer' => ''];
    }

    public static function randomPassword(): string
    {
        return UserHelper::genRandomPassword(12);
    }
}
