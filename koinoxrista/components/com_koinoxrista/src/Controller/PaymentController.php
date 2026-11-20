<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;

/** Εισπράξεις από ιδιοκτησίες, με αύξουσα αρίθμηση αποδείξεων ανά κτίριο. */
final class PaymentController extends Base
{
    public function save(): void
    {
        $this->guard();
        Auth::requireWritable();
        Auth::requireFeature('payments');
        $b = Repo::building($this->int('building_id'));
        $uid = $this->int('unit_id');
        if (!(int) Db::value('SELECT COUNT(*) FROM #__kx_units WHERE id=' . $uid . ' AND building_id=' . (int) $b->id)) {
            $this->go('view=payments&building_id=' . (int) $b->id, 'Επιλέξτε ιδιοκτησία.', 'error');
            return;
        }
        $amount = round($this->num('amount'), 2);
        if ($amount == 0.0) {
            $this->go('view=payments&building_id=' . (int) $b->id . '&unit_id=' . $uid, 'Συμπληρώστε ποσό.', 'error');
            return;
        }
        $method = array_key_exists($this->str('method'), Repo::PAY_METHODS) ? $this->str('method') : 'cash';
        $no = 1 + (int) Db::value('SELECT MAX(receipt_no) FROM #__kx_payments WHERE building_id=' . (int) $b->id);
        $id = Db::insert('#__kx_payments', [
            'building_id' => (int) $b->id, 'unit_id' => $uid, 'pay_date' => Fmt::toYmd($this->str('pay_date')) ?: Db::today(), 'amount' => $amount,
            'method' => $method, 'receipt_no' => $no, 'notes' => $this->str('notes', 255), 'created' => Db::now(), 'created_by' => Auth::userId(),
        ]);
        Db::log('payment.save', $b->title . ': ' . Fmt::euro($amount));
        $this->go('view=payments&building_id=' . (int) $b->id . '&unit_id=' . $uid . '&receipt=' . $id, 'Η είσπραξη ' . Fmt::euro($amount) . ' καταχωρίστηκε (απόδειξη ' . $no . ').');
    }

    public function delete(): void
    {
        $this->guard();
        Auth::requireWritable();
        $p = Db::one('SELECT * FROM #__kx_payments WHERE id=' . $this->int('payment_id'));
        if (!$p) {
            $this->go('view=payments', 'Δεν βρέθηκε.', 'error');
            return;
        }
        $b = Repo::building((int) $p->building_id);
        Db::exec('DELETE FROM #__kx_payments WHERE id=' . (int) $p->id);
        Db::log('payment.delete', $b->title . ': ' . Fmt::euro($p->amount));
        $this->go('view=payments&building_id=' . (int) $b->id . '&unit_id=' . (int) $p->unit_id, 'Η είσπραξη διαγράφηκε.');
    }
}
