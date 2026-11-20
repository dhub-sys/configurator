<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * Ποιος είναι συνδεδεμένος και σε ποιον συνδρομητή (λογαριασμό) ανήκει.
 *
 * Η εφαρμογή έχει δική της σύνδεση (session kx.*) πάνω στους χρήστες του Joomla.
 * - Διαχειριστής πλατφόρμας: Super User του Joomla. Βλέπει πακέτα, συνδρομητές, ρυθμίσεις
 *   και μπορεί να «μπει» σε έναν λογαριασμό για υποστήριξη.
 * - Χρήστης συνδρομητή: owner ή staff σε έναν λογαριασμό (#__kx_account_users).
 */
final class Auth
{
    public const FEATURES = [
        'designer' => 'Δικά μου πρότυπα εκτύπωσης (σχεδιαστής)',
        'heating_meters' => 'Θέρμανση με ωρομετρητές / θερμιδομετρητές',
        'email' => 'Αποστολή ειδοποιητηρίων με email',
        'payments' => 'Εισπράξεις, υπόλοιπα & αποδείξεις',
        'export' => 'Εξαγωγή σε Excel (CSV)',
    ];

    private static array $cache = [];

    private static function session()
    {
        return Factory::getApplication()->getSession();
    }

    public static function userId(): int
    {
        $s = self::session();
        return ($s->get('kx.verified', false) ? (int) $s->get('kx.user_id', 0) : 0);
    }

    public static function login(int $userId): void
    {
        $s = self::session();
        $s->set('kx.user_id', $userId);
        $s->set('kx.verified', true);
        $s->clear('kx.acting_account');
        $s->clear('kx.otp');
        self::$cache = [];
    }

    public static function logout(): void
    {
        $s = self::session();
        foreach (['kx.user_id', 'kx.verified', 'kx.acting_account', 'kx.otp', 'kx.pending_user'] as $k) {
            $s->clear($k);
        }
        self::$cache = [];
    }

    public static function user(): ?object
    {
        $uid = self::userId();
        if (!$uid) return null;
        return self::$cache['user'] ??= Db::one('SELECT id, name, username, email, block FROM #__users WHERE id=' . $uid);
    }

    public static function isPlatformAdmin(?int $uid = null): bool
    {
        $uid = $uid ?? self::userId();
        if (!$uid) return false;
        $key = 'admin' . $uid;
        if (!isset(self::$cache[$key])) {
            $u = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($uid);
            self::$cache[$key] = (bool) $u->authorise('core.admin');
        }
        return self::$cache[$key];
    }

    /** Επιτρέπεται η είσοδος; (μη μπλοκαρισμένος και είτε admin πλατφόρμας είτε μέλος λογαριασμού) */
    public static function allowed(int $uid): bool
    {
        $u = Db::one('SELECT id, block FROM #__users WHERE id=' . $uid);
        if (!$u || (int) $u->block) return false;
        return self::isPlatformAdmin($uid) || (int) Db::value('SELECT account_id FROM #__kx_account_users WHERE user_id=' . $uid) > 0;
    }

    public static function membership(): ?object
    {
        $uid = self::userId();
        if (!$uid) return null;
        if (!isset(self::$cache['member'])) {
            self::$cache['member'] = Db::one('SELECT * FROM #__kx_account_users WHERE user_id=' . $uid);
            if (!self::$cache['member'] && self::isPlatformAdmin($uid)) {
                self::$cache['member'] = self::provisionAdminAccount($uid);
            }
        }
        return self::$cache['member'];
    }

    /**
     * Ο διαχειριστής πλατφόρμας αποκτά αυτόματα δικό του λογαριασμό διαχείρισης, ώστε να
     * χρησιμοποιεί και ο ίδιος την εφαρμογή (κτίρια, μήνες, εκτυπώσεις). Είναι «εσωτερικός»:
     * χωρίς λήξη, με το πακέτο με τις περισσότερες δυνατότητες, και δεν μετράει στα έσοδα.
     */
    private static function provisionAdminAccount(int $uid): ?object
    {
        $pkg = Db::one('SELECT * FROM #__kx_packages ORDER BY (max_buildings=0) DESC, (max_units=0) DESC, LENGTH(features) DESC, price_month DESC, id LIMIT 1');
        $user = Db::one('SELECT name, email FROM #__users WHERE id=' . $uid);
        $site = trim((string) Factory::getApplication()->get('sitename', ''));
        $aid = Db::insert('#__kx_accounts', [
            'name' => $site !== '' ? $site : (string) ($user->name ?? 'Ο λογαριασμός μου'), 'email' => (string) ($user->email ?? ''),
            'package_id' => (int) ($pkg->id ?? 0), 'billing_cycle' => 'month', 'status' => 'active', 'trial_ends' => null, 'paid_until' => null,
            'settings' => json_encode(['internal' => 1]), 'notes' => 'Λογαριασμός διαχειριστή πλατφόρμας', 'created' => Db::now(), 'modified' => Db::now(),
        ]);
        Db::insert('#__kx_account_users', ['account_id' => $aid, 'user_id' => $uid, 'role' => 'owner', 'created' => Db::now()]);
        return Db::one('SELECT * FROM #__kx_account_users WHERE user_id=' . $uid);
    }

    /** Εσωτερικός λογαριασμός (του διαχειριστή πλατφόρμας): εκτός στατιστικών εσόδων. */
    public static function isInternal(object $account): bool
    {
        return !empty((json_decode((string) ($account->settings ?? ''), true) ?: [])['internal']);
    }

    public static function accountId(): int
    {
        if (self::isPlatformAdmin()) {
            $acting = (int) self::session()->get('kx.acting_account', 0);
            if ($acting) return $acting;
        }
        return (int) (self::membership()->account_id ?? 0);
    }

    public static function acting(): bool
    {
        return self::isPlatformAdmin() && (int) self::session()->get('kx.acting_account', 0) > 0;
    }

    public static function act(int $accountId): void
    {
        self::session()->set('kx.acting_account', $accountId);
        self::$cache = [];
    }

    public static function account(): ?object
    {
        $id = self::accountId();
        if (!$id) return null;
        return self::$cache['account' . $id] ??= Db::one('SELECT * FROM #__kx_accounts WHERE id=' . $id);
    }

    public static function isOwner(): bool
    {
        return self::acting() || (self::membership()->role ?? '') === 'owner';
    }

    public static function package(?object $account = null): ?object
    {
        $account = $account ?? self::account();
        if (!$account) return null;
        return self::$cache['pkg' . $account->package_id] ??= Db::one('SELECT * FROM #__kx_packages WHERE id=' . (int) $account->package_id);
    }

    public static function feature(string $feature, ?object $account = null): bool
    {
        $pkg = self::package($account);
        if (!$pkg) return false;
        $f = json_decode((string) $pkg->features, true) ?: [];
        return !empty($f[$feature]);
    }

    /**
     * Κατάσταση συνδρομής: ['ok'=>bool, 'code'=>trial|active|expired|suspended, 'label'=>..., 'until'=>Y-m-d|null]
     */
    public static function state(?object $account = null): array
    {
        $a = $account ?? self::account();
        if (!$a) return ['ok' => false, 'code' => 'none', 'label' => 'Χωρίς λογαριασμό', 'until' => null];
        $today = Db::today();
        if ($a->status === 'suspended') {
            return ['ok' => false, 'code' => 'suspended', 'label' => 'Σε αναστολή', 'until' => null];
        }
        if ($a->status === 'trial') {
            $ok = !$a->trial_ends || $a->trial_ends >= $today;
            return ['ok' => $ok, 'code' => $ok ? 'trial' : 'expired', 'label' => $ok ? 'Δοκιμαστική περίοδος' : 'Η δοκιμή έληξε', 'until' => $a->trial_ends];
        }
        $ok = !$a->paid_until || $a->paid_until >= $today;
        return ['ok' => $ok, 'code' => $ok ? 'active' : 'expired', 'label' => $ok ? 'Ενεργή συνδρομή' : 'Η συνδρομή έληξε', 'until' => $a->paid_until];
    }

    /** Χρήση πόρων έναντι των ορίων του πακέτου. */
    public static function usage(?object $account = null): array
    {
        $a = $account ?? self::account();
        $id = (int) ($a->id ?? 0);
        $pkg = self::package($a);
        return [
            'buildings' => [(int) Db::value('SELECT COUNT(*) FROM #__kx_buildings WHERE archived=0 AND account_id=' . $id), (int) ($pkg->max_buildings ?? 0)],
            'units' => [(int) Db::value('SELECT COUNT(*) FROM #__kx_units u JOIN #__kx_buildings b ON b.id=u.building_id WHERE b.archived=0 AND b.account_id=' . $id), (int) ($pkg->max_units ?? 0)],
            'users' => [(int) Db::value('SELECT COUNT(*) FROM #__kx_account_users WHERE account_id=' . $id), (int) ($pkg->max_users ?? 0)],
        ];
    }

    /** Ελέγχει αν χωράει +$add στο όριο. 0 = απεριόριστο. */
    public static function within(string $what, int $add = 1): bool
    {
        [$used, $max] = self::usage()[$what];
        return $max <= 0 || $used + $add <= $max;
    }

    // ---------------------------------------------------------------- φρουροί για controllers

    public static function deny(string $message, string $redirect = 'view=dashboard'): void
    {
        $app = Factory::getApplication();
        $app->enqueueMessage($message, 'error');
        $app->redirect(Url::to($redirect));
        $app->close();
    }

    public static function requireAccount(): object
    {
        $a = self::account();
        if (!$a) self::deny('Δεν υπάρχει συνδεδεμένος λογαριασμός.', self::isPlatformAdmin() ? 'view=platform' : 'view=app');
        return $a;
    }

    /** Για κάθε ενέργεια που αλλάζει δεδομένα του συνδρομητή. */
    public static function requireWritable(): object
    {
        $a = self::requireAccount();
        $st = self::state($a);
        if (!$st['ok'] && !self::acting()) {
            self::deny($st['label'] . ': οι αλλαγές είναι κλειδωμένες. Ανανεώστε τη συνδρομή σας για να συνεχίσετε.', 'view=account&tab=billing');
        }
        return $a;
    }

    public static function requireOwner(): object
    {
        $a = self::requireAccount();
        if (!self::isOwner()) self::deny('Η ενέργεια επιτρέπεται μόνο στον υπεύθυνο του λογαριασμού.');
        return $a;
    }

    public static function requireFeature(string $feature): void
    {
        if (!self::feature($feature)) {
            self::deny('Η λειτουργία «' . (self::FEATURES[$feature] ?? $feature) . '» δεν περιλαμβάνεται στο πακέτο σας.', 'view=account&tab=billing');
        }
    }

    public static function requirePlatformAdmin(): void
    {
        if (!self::isPlatformAdmin()) self::deny('Δεν έχετε πρόσβαση.');
    }
}
