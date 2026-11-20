<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Accounts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Calc;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Fmt;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Mail;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Notice;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;

/** Μήνες έκδοσης: δαπάνες, μετρήσεις θέρμανσης, καύσιμο, έκδοση, εξαγωγή, αποστολή. */
final class PeriodController extends Base
{
    public function create(): void
    {
        $this->guard();
        Auth::requireWritable();
        $b = Repo::building($this->int('building_id'));
        $bid = (int) $b->id;
        $month = max(1, min(12, $this->int('month')));
        $year = max(2000, min(2100, $this->int('year')));
        $issue = Fmt::toYmd($this->str('issue_date')) ?: Db::today();
        $due = Fmt::toYmd($this->str('due_date')) ?: date('Y-m-d', strtotime($issue . ' +' . (int) $b->due_days . ' days'));
        if (!(int) Db::value('SELECT COUNT(*) FROM #__kx_units WHERE building_id=' . $bid)) {
            $this->go('view=building&id=' . $bid . '&tab=units', 'Προσθέστε πρώτα τις ιδιοκτησίες του κτιρίου.', 'warning');
            return;
        }
        $prev = Db::one('SELECT * FROM #__kx_periods WHERE building_id=' . $bid . ' ORDER BY year DESC, month DESC, id DESC LIMIT 1');
        $pid = Db::insert('#__kx_periods', [
            'building_id' => $bid, 'year' => $year, 'month' => $month, 'title' => $this->str('title', 100) ?: Fmt::period($month, $year),
            'issue_date' => $issue, 'due_date' => $due, 'status' => 'draft', 'notes' => '', 'heating' => '{}', 'created' => Db::now(), 'modified' => Db::now(),
        ]);
        if ($prev) {
            // Μεταφορά: επαναλαμβανόμενες δαπάνες, τελικές ενδείξεις → αρχικές, τελικό απόθεμα → αρχικό.
            if ($this->int('copy_recurring', 1)) {
                foreach (Repo::expenses((int) $prev->id) as $e) {
                    if (!(int) $e->recurring) continue;
                    $row = (array) $e;
                    unset($row['id']);
                    $row['period_id'] = $pid;
                    $row['doc_no'] = '';
                    $row['doc_date'] = null;
                    $row['created'] = Db::now();
                    Db::insert('#__kx_expenses', $row);
                }
            }
            foreach (Repo::readings((int) $prev->id) as $uid => $r) {
                Db::insertPlain('#__kx_readings', ['period_id' => $pid, 'unit_id' => $uid, 'prev_value' => $r->curr_value, 'curr_value' => $r->curr_value]);
            }
            $h = json_decode((string) $prev->heating, true) ?: [];
            if ($h) {
                $f = Calc::fuel($h);
                Db::update('#__kx_periods', $pid, ['heating' => json_encode(['start_qty' => $f['end'], 'start_price' => $f['price'], 'bought_qty' => 0, 'bought_amount' => 0, 'end_qty' => $f['end']])]);
            }
        }
        Db::log('period.create', $b->title . ' — ' . Fmt::period($month, $year));
        $this->go('view=period&id=' . $pid, 'Ο μήνας δημιουργήθηκε. Καταχωρίστε τις δαπάνες.');
    }

    public function save(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p] = $this->editable($this->int('id'));
        $month = max(1, min(12, $this->int('month', (int) $p->month)));
        $year = max(2000, min(2100, $this->int('year', (int) $p->year)));
        Db::update('#__kx_periods', (int) $p->id, [
            'month' => $month, 'year' => $year, 'title' => $this->str('title', 100) ?: Fmt::period($month, $year),
            'issue_date' => Fmt::toYmd($this->str('issue_date')), 'due_date' => Fmt::toYmd($this->str('due_date')),
            'notes' => $this->text('notes', 2000), 'modified' => Db::now(),
        ]);
        $this->go('view=period&id=' . (int) $p->id . '&tab=' . $this->str('tab', 20), 'Αποθηκεύτηκε.');
    }

    public function delete(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p, $b] = Repo::period($this->int('id'));
        if ($p->status === 'issued') {
            $this->go('view=period&id=' . (int) $p->id, 'Ανοίξτε πρώτα ξανά τον μήνα (αναίρεση έκδοσης) για να τον διαγράψετε.', 'warning');
            return;
        }
        foreach (['#__kx_expenses', '#__kx_readings', '#__kx_charges'] as $t) Db::exec('DELETE FROM ' . $t . ' WHERE period_id=' . (int) $p->id);
        Db::exec('DELETE FROM #__kx_periods WHERE id=' . (int) $p->id);
        Db::log('period.delete', $b->title . ' — ' . $p->title);
        $this->go('view=building&id=' . (int) $b->id . '&tab=periods', 'Ο μήνας διαγράφηκε.');
    }

    public function saveexpense(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p, $b] = $this->editable($this->int('period_id'));
        $eid = $this->int('expense_id');
        $keys = Repo::keys((int) $b->id);
        $units = Repo::units((int) $b->id);
        $category = array_key_exists($this->str('category'), Calc::CATEGORIES) ? $this->str('category') : 'general';
        $method = array_key_exists($this->str('method'), Calc::METHODS) ? $this->str('method') : 'key';
        if (in_array($method, ['consumption'], true) && $b->heating_method === 'mills') {
            $method = 'key';
        }
        $keyId = $this->int('key_id');
        if (!in_array($keyId, array_map(static fn($k) => (int) $k->id, $keys), true)) {
            $keyId = (int) (Repo::keyOf($keys, $category === 'elevator' ? 'elevator' : ($category === 'heating' ? 'heating' : 'general'))->id ?? 0);
        }
        $unitIds = array_values(array_intersect(array_map('intval', $this->arr('unit_ids')), array_map('intval', array_keys($units))));
        if (count($unitIds) === count($units)) $unitIds = [];
        $custom = [];
        foreach ($this->arr('custom') as $uid => $v) {
            if (isset($units[(int) $uid]) && trim((string) $v) !== '') $custom[(int) $uid] = round(Calc::parseNumber($v), 2);
        }
        $amount = round($this->num('amount'), 2);
        if ($method === 'custom' && $amount == 0.0) $amount = round(array_sum($custom), 2);
        $title = $this->str('title', 255);
        if ($title === '') {
            $this->go('view=period&id=' . (int) $p->id, 'Συμπληρώστε περιγραφή δαπάνης.', 'error');
            return;
        }
        $data = [
            'title' => $title, 'category' => $category, 'method' => $method, 'key_id' => $keyId,
            'payer' => $this->str('payer') === 'owner' ? 'owner' : 'tenant', 'amount' => $amount,
            'unit_ids' => json_encode($unitIds), 'custom' => json_encode($method === 'custom' ? $custom : (object) []),
            'supplier' => $this->str('supplier'), 'doc_no' => $this->str('doc_no', 60), 'doc_date' => Fmt::toYmd($this->str('doc_date')),
            'recurring' => $this->int('recurring') ? 1 : 0,
        ];
        if ($eid > 0) {
            $this->expenseOf($eid, (int) $p->id);
            Db::update('#__kx_expenses', $eid, $data);
        } else {
            $data += ['period_id' => (int) $p->id, 'ordering' => 1 + (int) Db::value('SELECT MAX(ordering) FROM #__kx_expenses WHERE period_id=' . (int) $p->id), 'created' => Db::now()];
            Db::insert('#__kx_expenses', $data);
        }
        $this->go('view=period&id=' . (int) $p->id . '#expenses', $eid ? 'Η δαπάνη ενημερώθηκε.' : 'Η δαπάνη προστέθηκε.');
    }

    public function deleteexpense(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p] = $this->editable($this->int('period_id'));
        $e = $this->expenseOf($this->int('expense_id'), (int) $p->id);
        Db::exec('DELETE FROM #__kx_expenses WHERE id=' . (int) $e->id);
        $this->go('view=period&id=' . (int) $p->id . '#expenses', 'Η δαπάνη διαγράφηκε.');
    }

    public function moveexpense(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p] = $this->editable($this->int('period_id'));
        $ids = array_map(static fn($e) => (int) $e->id, Repo::expenses((int) $p->id));
        $id = $this->int('expense_id');
        $i = array_search($id, $ids, true);
        $j = $i === false ? false : $i + ($this->str('dir') === 'up' ? -1 : 1);
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $eid) Db::exec('UPDATE #__kx_expenses SET ordering=' . ($n + 1) . ' WHERE id=' . $eid);
        }
        $this->go('view=period&id=' . (int) $p->id . '#expenses');
    }

    public function savereadings(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p, $b] = $this->editable($this->int('id'));
        $units = Repo::units((int) $b->id);
        $prev = $this->arr('prev');
        $curr = $this->arr('curr');
        $warn = 0;
        foreach ($units as $uid => $u) {
            $a = max(0, Calc::parseNumber($prev[$uid] ?? 0));
            $c = max(0, Calc::parseNumber($curr[$uid] ?? 0));
            if ($c < $a) $warn++;
            Db::exec('REPLACE INTO #__kx_readings (period_id, unit_id, prev_value, curr_value) VALUES (' . (int) $p->id . ',' . (int) $uid . ',' . round($a, 3) . ',' . round($c, 3) . ')');
        }
        if ($warn) $this->app->enqueueMessage($warn . ' ενδείξεις είναι μικρότερες από τις προηγούμενες και μετρούν ως μηδενική κατανάλωση.', 'warning');
        $this->go('view=period&id=' . (int) $p->id . '&tab=heating', 'Οι ενδείξεις αποθηκεύτηκαν.');
    }

    /** Υπολογισμός καυσίμου και (προαιρετικά) δημιουργία/ενημέρωση της δαπάνης θέρμανσης. */
    public function saveheating(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p, $b] = $this->editable($this->int('id'));
        $h = [];
        foreach (['start_qty', 'start_price', 'bought_qty', 'bought_amount', 'end_qty'] as $k) $h[$k] = round($this->num($k), 4);
        Db::update('#__kx_periods', (int) $p->id, ['heating' => json_encode($h), 'modified' => Db::now()]);
        $f = Calc::fuel($h);
        if ($f['invalid']) {
            $this->go('view=period&id=' . (int) $p->id . '&tab=heating', 'Το τελικό απόθεμα είναι μεγαλύτερο από το διαθέσιμο (αρχικό + αγορές).', 'warning');
            return;
        }
        if ($this->int('make_expense') && $f['cost'] > 0) {
            $keys = Repo::keys((int) $b->id);
            $title = 'Κατανάλωση καυσίμου ' . Fmt::qty($f['consumed']) . ' ' . $b->fuel_unit . ' × ' . Fmt::qty($f['price'], 4) . ' €';
            $existing = Db::one('SELECT id FROM #__kx_expenses WHERE period_id=' . (int) $p->id . " AND title LIKE 'Κατανάλωση καυσίμου%' LIMIT 1");
            $data = [
                'title' => $title, 'category' => 'heating', 'method' => $b->heating_method === 'mills' ? 'key' : 'heating',
                'key_id' => (int) (Repo::keyOf($keys, 'heating')->id ?? 0), 'payer' => 'tenant', 'amount' => $f['cost'],
            ];
            if ($existing) {
                Db::update('#__kx_expenses', (int) $existing->id, $data);
            } else {
                Db::insert('#__kx_expenses', $data + ['period_id' => (int) $p->id, 'unit_ids' => '[]', 'custom' => '{}', 'ordering' => 999, 'created' => Db::now()]);
            }
            $this->go('view=period&id=' . (int) $p->id . '#expenses', 'Η δαπάνη θέρμανσης (' . Fmt::euro($f['cost']) . ') καταχωρίστηκε.');
            return;
        }
        $this->go('view=period&id=' . (int) $p->id . '&tab=heating', 'Τα στοιχεία καυσίμου αποθηκεύτηκαν.');
    }

    public function issue(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p, $b] = $this->editable($this->int('id'));
        if (!Repo::expenses((int) $p->id)) {
            $this->go('view=period&id=' . (int) $p->id, 'Δεν υπάρχουν δαπάνες για έκδοση.', 'warning');
            return;
        }
        Repo::issue((int) $p->id);
        Db::log('period.issue', $b->title . ' — ' . $p->title);
        $this->go('view=period&id=' . (int) $p->id . '&tab=print', 'Ο μήνας εκδόθηκε και κλείδωσε. Τα υπόλοιπα των ιδιοκτησιών ενημερώθηκαν.');
    }

    public function reopen(): void
    {
        $this->guard();
        Auth::requireWritable();
        [$p, $b] = Repo::period($this->int('id'));
        if (Repo::hasLaterIssued($p)) {
            $this->go('view=period&id=' . (int) $p->id, 'Υπάρχει μεταγενέστερος εκδοθείς μήνας. Ανοίξτε πρώτα εκείνον.', 'warning');
            return;
        }
        Repo::reopen((int) $p->id);
        Db::log('period.reopen', $b->title . ' — ' . $p->title);
        $this->go('view=period&id=' . (int) $p->id, 'Ο μήνας άνοιξε ξανά για αλλαγές.');
    }

    /** Εξαγωγή της κατανομής σε CSV (ανοίγει σωστά στο Excel: UTF-8 BOM, διαχωριστικό ;). */
    public function export(): void
    {
        Auth::requireFeature('export');
        [$p, $b] = Repo::period($this->input->getInt('id', 0));
        $ctx = $this->context($p, $b);
        $keys = Repo::keys((int) $b->id);
        $head = ['Α/Α', 'Ιδιοκτησία', 'Όροφος', 'Υπόχρεος'];
        $fields = ['unit.row_no', 'unit.code', 'unit.floor', 'unit.payer_name'];
        foreach ($keys as $k) {
            $head[] = 'Χιλ. ' . $k->title;
            $fields[] = 'mills.' . $k->code;
        }
        foreach (['amt.general' => 'Κοινόχρηστα', 'amt.elevator' => 'Ανελκυστήρας', 'amt.heating' => 'Θέρμανση', 'amt.special' => 'Ειδικές', 'amt.owner' => 'Ιδιοκτήτη', 'amt.current' => 'Σύνολο μήνα', 'amt.previous' => 'Προηγ. υπόλοιπο', 'amt.total' => 'Πληρωτέο'] as $f => $t) {
            $head[] = $t;
            $fields[] = $f;
        }
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$b->title . ' — ' . $ctx['fields']['period.title']], ';');
        fputcsv($out, $head, ';');
        foreach ($ctx['units'] as $row) fputcsv($out, array_map(static fn($f) => $row[$f] ?? '', $fields), ';');
        fputcsv($out, [], ';');
        fputcsv($out, ['Α/Α', 'Δαπάνη', 'Κατηγορία', 'Ποσό', 'Βαρύνει', 'Κατανομή', 'Προμηθευτής', 'Παραστατικό'], ';');
        foreach ($ctx['expenses'] as $e) fputcsv($out, [$e['exp.no'], $e['exp.title'], $e['exp.category'], $e['exp.amount'], $e['exp.payer'], $e['exp.method'], $e['exp.supplier'], $e['exp.doc']], ';');
        rewind($out);
        $csv = stream_get_contents($out);
        $name = preg_replace('~[^\pL\pN_-]+~u', '-', $b->title . '-' . $p->year . '-' . sprintf('%02d', $p->month));
        $this->app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . $name . '.csv"', true);
        $this->app->sendHeaders();
        echo $csv;
        $this->app->close();
    }

    /** Αποστολή ειδοποιητηρίων με email (σύνδεσμος ανά ιδιοκτησία). */
    public function sendnotices(): void
    {
        $this->guard();
        Auth::requireWritable();
        Auth::requireFeature('email');
        [$p, $b] = Repo::period($this->int('id'));
        if ($p->status !== 'issued') {
            $this->go('view=period&id=' . (int) $p->id . '&tab=print', 'Εκδώστε πρώτα τον μήνα.', 'warning');
            return;
        }
        $account = Auth::account();
        $intro = trim((string) Accounts::settings($account)['notice_email_text']);
        $ctx = $this->context($p, $b);
        $units = Repo::units((int) $b->id);
        $sent = 0;
        $missing = 0;
        foreach (array_values($units) as $i => $u) {
            $to = Repo::payerEmail($u);
            if ($to === '') {
                $missing++;
                continue;
            }
            $row = $ctx['units'][$i] ?? [];
            $link = Notice::link((int) $p->id, (int) $u->id);
            $html = '<p>Αγαπητέ/ή ' . Fmt::e(Repo::payerName($u)) . ',</p>'
                . ($intro !== '' ? '<p>' . nl2br(Fmt::e($intro)) . '</p>' : '')
                . '<p>Εκδόθηκαν τα κοινόχρηστα <strong>' . Fmt::e($ctx['fields']['period.title']) . '</strong> για την ιδιοκτησία <strong>' . Fmt::e($u->code) . '</strong> στο κτίριο ' . Fmt::e($b->title) . '.</p>'
                . '<p style="font-size:18px">Πληρωτέο ποσό: <strong>' . Fmt::e($row['amt.total'] ?? '') . ' €</strong>' . ($p->due_date ? '<br><small>Πληρωμή έως ' . Fmt::date($p->due_date) . '</small>' : '') . '</p>'
                . ($b->iban ? '<p>IBAN: ' . Fmt::e($b->iban) . ($b->beneficiary ? '<br>Δικαιούχος: ' . Fmt::e($b->beneficiary) : '') . '</p>' : '')
                . '<p><a href="' . Fmt::e($link) . '" style="display:inline-block;background:#1d4ed8;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Προβολή / εκτύπωση ειδοποιητηρίου</a></p>'
                . '<p style="color:#6b7280;font-size:13px">' . Fmt::e($account->name ?? '') . ($b->manager_phone ? ' · ' . Fmt::e($b->manager_phone) : '') . '</p>';
            if (Mail::send($to, 'Κοινόχρηστα ' . $ctx['fields']['period.title'] . ' — ' . $b->title . ' (' . $u->code . ')', $html, (string) ($b->manager_email ?: ($account->email ?? '')))) {
                $sent++;
            }
        }
        Db::log('period.email', $b->title . ' — ' . $p->title . ': ' . $sent);
        $this->go('view=period&id=' . (int) $p->id . '&tab=print', 'Στάλθηκαν ' . $sent . ' email.' . ($missing ? ' ' . $missing . ' ιδιοκτησίες δεν έχουν email.' : ''), $sent ? 'message' : 'warning');
    }

    // ------------------------------------------------------------------

    private function context(object $p, object $b): array
    {
        if ($p->status === 'issued' && ($snap = json_decode((string) $p->snapshot, true)) && !empty($snap['ctx'])) {
            return $snap['ctx'];
        }
        return Repo::printContext(Repo::compute($p, $b));
    }

    /** Μόνο πρόχειροι μήνες αλλάζουν. */
    private function editable(int $pid): array
    {
        [$p, $b] = Repo::period($pid);
        if ($p->status === 'issued') {
            $this->go('view=period&id=' . (int) $p->id, 'Ο μήνας έχει εκδοθεί. Για αλλαγές πατήστε «Αναίρεση έκδοσης».', 'warning');
            $this->app->close();
        }
        return [$p, $b];
    }

    private function expenseOf(int $eid, int $pid): object
    {
        $e = Db::one('SELECT * FROM #__kx_expenses WHERE id=' . $eid . ' AND period_id=' . $pid);
        if (!$e) {
            $this->go('view=period&id=' . $pid, 'Η δαπάνη δεν βρέθηκε.', 'error');
            $this->app->close();
        }
        return $e;
    }
}
