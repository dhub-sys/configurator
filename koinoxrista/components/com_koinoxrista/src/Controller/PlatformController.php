<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;

/** Διαχείριση πλατφόρμας (μόνο Super Users): πακέτα, συνδρομητές, πληρωμές, ρυθμίσεις. */
final class PlatformController extends Base
{
    public function execute($task)
    {
        if ($task !== 'leave') {
            Auth::requirePlatformAdmin();
        }
        return parent::execute($task);
    }

    public function savepackage(): void
    {
        $this->guard();
        $id = $this->int('id');
        $features = [];
        foreach (array_keys(Auth::FEATURES) as $f) {
            if (!empty($this->arr('features')[$f])) $features[$f] = 1;
        }
        $data = [
            'title' => $this->str('title') ?: 'Πακέτο', 'description' => $this->text('description', 2000),
            'price_month' => max(0, round($this->num('price_month'), 2)), 'price_year' => max(0, round($this->num('price_year'), 2)),
            'max_buildings' => max(0, $this->int('max_buildings')), 'max_units' => max(0, $this->int('max_units')), 'max_users' => max(0, $this->int('max_users')),
            'trial_days' => max(0, min(365, $this->int('trial_days'))), 'features' => json_encode($features),
            'highlight' => $this->int('highlight') ? 1 : 0, 'published' => $this->int('published') ? 1 : 0, 'ordering' => $this->int('ordering'),
            'is_default' => $this->int('is_default') ? 1 : 0,
        ];
        if ($data['is_default']) Db::exec('UPDATE #__kx_packages SET is_default=0');
        if ($id > 0) {
            Db::update('#__kx_packages', $id, $data);
        } else {
            $id = Db::insert('#__kx_packages', $data + ['created' => Db::now()]);
        }
        $this->go('view=platform&tab=packages', 'Το πακέτο «' . $data['title'] . '» αποθηκεύτηκε.');
    }

    public function deletepackage(): void
    {
        $this->guard();
        $id = $this->int('id');
        if ((int) Db::value('SELECT COUNT(*) FROM #__kx_accounts WHERE package_id=' . $id)) {
            $this->go('view=platform&tab=packages', 'Το πακέτο χρησιμοποιείται από συνδρομητές· απενεργοποιήστε το αντί να το διαγράψετε.', 'warning');
            return;
        }
        Db::exec('DELETE FROM #__kx_packages WHERE id=' . $id);
        $this->go('view=platform&tab=packages', 'Το πακέτο διαγράφηκε.');
    }

    public function createaccount(): void
    {
        $this->guard();
        try {
            $email = mb_strtolower($this->str('email'));
            $existing = (int) Db::value('SELECT id FROM #__users WHERE email=' . Db::q($email));
            if ($existing && (int) Db::value('SELECT COUNT(*) FROM #__kx_account_users WHERE user_id=' . $existing)) {
                throw new \RuntimeException('Ο χρήστης ανήκει ήδη σε λογαριασμό.');
            }
            $uid = $existing ?: Accounts::createUser($this->str('name') ?: $email, $email, (string) $this->input->post->get('password', '', 'raw'));
            $aid = Accounts::createAccount($this->str('company') ?: $email, $email, $this->str('phone', 60), $uid, $this->int('package_id'));
        } catch (\Throwable $e) {
            $this->go('view=platform&tab=accounts', $e->getMessage(), 'error');
            return;
        }
        $this->go('view=platform&tab=account&id=' . $aid, 'Ο συνδρομητής δημιουργήθηκε.');
    }

    public function saveaccount(): void
    {
        $this->guard();
        $id = $this->int('id');
        if (!Db::one('SELECT id FROM #__kx_accounts WHERE id=' . $id)) {
            $this->go('view=platform&tab=accounts', 'Δεν βρέθηκε.', 'error');
            return;
        }
        $status = in_array($this->str('status'), ['trial', 'active', 'suspended'], true) ? $this->str('status') : 'active';
        Db::update('#__kx_accounts', $id, [
            'name' => $this->str('name'), 'package_id' => $this->int('package_id'), 'status' => $status,
            'billing_cycle' => $this->str('billing_cycle') === 'year' ? 'year' : 'month',
            'trial_ends' => Fmt::toYmd($this->str('trial_ends')), 'paid_until' => Fmt::toYmd($this->str('paid_until')),
            'notes' => $this->text('notes', 5000), 'modified' => Db::now(),
        ]);
        $this->go('view=platform&tab=account&id=' . $id, 'Ο συνδρομητής ενημερώθηκε.');
    }

    /** Καταχώριση πληρωμής συνδρομής: παρατείνει τη συνδρομή και ενεργοποιεί το πακέτο. */
    public function recordpayment(): void
    {
        $this->guard();
        $a = Db::one('SELECT * FROM #__kx_accounts WHERE id=' . $this->int('account_id'));
        $pkg = Db::one('SELECT * FROM #__kx_packages WHERE id=' . $this->int('package_id'));
        if (!$a || !$pkg) {
            $this->go('view=platform&tab=accounts', 'Επιλέξτε συνδρομητή και πακέτο.', 'error');
            return;
        }
        $cycle = $this->str('cycle') === 'year' ? 'year' : 'month';
        $months = max(1, $this->int('months', $cycle === 'year' ? 12 : 1));
        $today = Db::today();
        $from = Fmt::toYmd($this->str('period_from')) ?: (($a->status === 'active' && $a->paid_until && $a->paid_until >= $today) ? date('Y-m-d', strtotime($a->paid_until . ' +1 day')) : $today);
        $to = date('Y-m-d', strtotime($from . ' +' . $months . ' months -1 day'));
        $amount = $this->input->post->getString('amount', '') === '' ? ($cycle === 'year' ? (float) $pkg->price_year : (float) $pkg->price_month * $months) : round($this->num('amount'), 2);
        Db::insert('#__kx_billing', [
            'account_id' => (int) $a->id, 'package_id' => (int) $pkg->id, 'kind' => 'payment', 'cycle' => $cycle, 'period_from' => $from, 'period_to' => $to,
            'amount' => $amount, 'vat_rate' => (float) Settings::get('vat_rate'), 'method' => $this->str('method', 40), 'reference' => $this->str('reference'),
            'status' => 'paid', 'notes' => $this->text('notes', 1000), 'created' => Db::now(), 'created_by' => Auth::userId(),
        ]);
        Db::update('#__kx_accounts', (int) $a->id, ['package_id' => (int) $pkg->id, 'status' => 'active', 'billing_cycle' => $cycle, 'paid_until' => $to, 'modified' => Db::now()]);
        Db::exec('UPDATE #__kx_billing SET status=\'done\' WHERE account_id=' . (int) $a->id . ' AND kind=\'request\' AND status=\'pending\'');
        $this->go('view=platform&tab=account&id=' . (int) $a->id, 'Η πληρωμή καταχωρίστηκε. Η συνδρομή ισχύει έως ' . Fmt::date($to) . '.');
    }

    public function deletebilling(): void
    {
        $this->guard();
        $row = Db::one('SELECT * FROM #__kx_billing WHERE id=' . $this->int('id'));
        if ($row) Db::exec('DELETE FROM #__kx_billing WHERE id=' . (int) $row->id);
        $this->go('view=platform&tab=account&id=' . (int) ($row->account_id ?? 0), 'Η εγγραφή διαγράφηκε. Ελέγξτε την ημερομηνία λήξης της συνδρομής.');
    }

    /** Είσοδος σε λογαριασμό συνδρομητή για υποστήριξη. */
    public function enter(): void
    {
        $this->guard();
        $id = $this->int('id');
        if (!Db::one('SELECT id FROM #__kx_accounts WHERE id=' . $id)) {
            $this->go('view=platform&tab=accounts', 'Δεν βρέθηκε.', 'error');
            return;
        }
        Auth::act($id);
        Db::log('platform.enter', (string) $id);
        $this->go('view=dashboard');
    }

    public function leave(): void
    {
        $this->guard('get');
        Auth::act(0);
        $this->go('view=platform&tab=accounts');
    }

    public function savesettings(): void
    {
        $this->guard();
        $values = [
            'brand_name' => $this->str('brand_name') ?: 'Κοινόχρηστα', 'brand_tagline' => $this->str('brand_tagline', 255),
            'brand_color' => preg_match('~^#[0-9a-fA-F]{6}$~', $this->str('brand_color')) ? $this->str('brand_color') : '#1d4ed8',
            'signup_enabled' => $this->int('signup_enabled') ? 1 : 0, 'signup_package_id' => $this->int('signup_package_id'),
            'otp_enabled' => $this->int('otp_enabled') ? 1 : 0, 'support_email' => $this->email('support_email'), 'support_phone' => $this->str('support_phone', 60),
            'vat_rate' => max(0, min(50, $this->num('vat_rate', 24))), 'payment_info' => $this->text('payment_info', 2000),
            'terms_url' => $this->str('terms_url', 255), 'itemid' => max(0, $this->int('itemid')),
        ];
        try {
            $logo = $this->upload('brand_logo_file', 'platform', 'logo');
            if ($logo !== '') $values['brand_logo'] = $logo;
        } catch (\Throwable $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
        }
        if ($this->int('remove_logo')) $values['brand_logo'] = '';
        Settings::save($values);
        $this->go('view=platform&tab=settings', 'Οι ρυθμίσεις της πλατφόρμας αποθηκεύτηκαν.');
    }
}
