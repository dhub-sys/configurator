<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

/**
 * Πρόσβαση στα δεδομένα του συνδρομητή. Κάθε ανάγνωση κτιρίου/μήνα ελέγχει ότι ανήκει
 * στον τρέχοντα λογαριασμό, ώστε ένας πελάτης να μη βλέπει ποτέ δεδομένα άλλου.
 */
final class Repo
{
    public const HEATING_TYPES = [
        'none' => 'Χωρίς κεντρική θέρμανση', 'oil' => 'Κεντρική με πετρέλαιο', 'gas' => 'Κεντρική με φυσικό αέριο',
        'heatpump' => 'Κεντρική με αντλία θερμότητας', 'other' => 'Άλλο',
    ];
    public const HEATING_METHODS = [
        'mills' => 'Μόνο με χιλιοστά θέρμανσης',
        'hours' => 'Πάγιο με χιλιοστά + κατανάλωση με ωρομετρητές',
        'units' => 'Πάγιο με χιλιοστά + κατανάλωση με θερμιδομετρητές',
    ];
    public const UNIT_KINDS = ['Διαμέρισμα', 'Κατάστημα', 'Γραφείο', 'Αποθήκη', 'Θέση στάθμευσης', 'Μεζονέτα', 'Άλλο'];
    public const PAY_METHODS = ['cash' => 'Μετρητά', 'bank' => 'Κατάθεση / έμβασμα', 'card' => 'Κάρτα', 'other' => 'Άλλο'];

    // ------------------------------------------------------------------ κτίρια

    public static function buildings(bool $archived = false): array
    {
        $aid = Auth::accountId();
        return Db::all('SELECT b.*, (SELECT COUNT(*) FROM #__kx_units u WHERE u.building_id=b.id) AS units_count,
                (SELECT CONCAT(p.year, LPAD(p.month,2,\'0\'), \':\', p.status, \':\', p.id) FROM #__kx_periods p WHERE p.building_id=b.id ORDER BY p.year DESC, p.month DESC, p.id DESC LIMIT 1) AS last_period
            FROM #__kx_buildings b WHERE b.account_id=' . $aid . ' AND b.archived=' . ($archived ? 1 : 0) . ' ORDER BY b.title');
    }

    public static function building(int $id): object
    {
        $b = $id > 0 ? Db::one('SELECT * FROM #__kx_buildings WHERE id=' . $id . ' AND account_id=' . Auth::accountId()) : null;
        if (!$b) Auth::deny('Το κτίριο δεν βρέθηκε.', 'view=buildings');
        return $b;
    }

    public static function keys(int $bid): array
    {
        return Db::all('SELECT * FROM #__kx_keys WHERE building_id=' . $bid . ' ORDER BY ordering, id');
    }

    public static function ensureKeys(int $bid): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM #__kx_keys WHERE building_id=' . $bid) > 0) return;
        foreach ([['general', 'Γενικά', 'general'], ['elevator', 'Ανελκυστήρας', 'elevator'], ['heating', 'Θέρμανση', 'heating']] as $i => [$code, $title, $kind]) {
            Db::insert('#__kx_keys', ['building_id' => $bid, 'code' => $code, 'title' => $title, 'kind' => $kind, 'ordering' => $i + 1]);
        }
    }

    public static function keyOf(array $keys, string $kind): ?object
    {
        foreach ($keys as $k) if ($k->kind === $kind) return $k;
        return null;
    }

    /** Ιδιοκτησίες με ->mills = [key_id => float]. */
    public static function units(int $bid): array
    {
        $units = Db::all('SELECT * FROM #__kx_units WHERE building_id=' . $bid . ' ORDER BY ordering, id');
        if (!$units) return [];
        $map = [];
        foreach ($units as $u) {
            $u->mills = [];
            $map[(int) $u->id] = $u;
        }
        foreach (Db::all('SELECT * FROM #__kx_unit_mills WHERE unit_id IN (' . Db::ids(array_keys($map)) . ')') as $m) {
            if (isset($map[(int) $m->unit_id])) $map[(int) $m->unit_id]->mills[(int) $m->key_id] = (float) $m->mills;
        }
        return $map;
    }

    public static function payerName(object $u): string
    {
        if ($u->payer === 'owner') return (string) ($u->owner_name ?: $u->tenant_name);
        return (string) ($u->tenant_name ?: $u->owner_name);
    }

    public static function payerEmail(object $u): string
    {
        if ($u->payer === 'owner') return (string) ($u->owner_email ?: $u->tenant_email);
        return (string) ($u->tenant_email ?: $u->owner_email);
    }

    // ------------------------------------------------------------------ μήνες (εκδόσεις)

    public static function periods(int $bid): array
    {
        return Db::all('SELECT p.*, (SELECT SUM(e.amount) FROM #__kx_expenses e WHERE e.period_id=p.id) AS expenses_total
            FROM #__kx_periods p WHERE p.building_id=' . $bid . ' ORDER BY p.year DESC, p.month DESC, p.id DESC');
    }

    /** [period, building] — ελεγμένα ως προς τον λογαριασμό. */
    public static function period(int $id): array
    {
        $p = $id > 0 ? Db::one('SELECT * FROM #__kx_periods WHERE id=' . $id) : null;
        if (!$p) Auth::deny('Ο μήνας δεν βρέθηκε.', 'view=buildings');
        return [$p, self::building((int) $p->building_id)];
    }

    public static function expenses(int $pid): array
    {
        return Db::all('SELECT * FROM #__kx_expenses WHERE period_id=' . $pid . ' ORDER BY ordering, id');
    }

    public static function readings(int $pid): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM #__kx_readings WHERE period_id=' . $pid) as $r) $out[(int) $r->unit_id] = $r;
        return $out;
    }

    /** Ο αμέσως προηγούμενος μήνας του κτιρίου. */
    public static function previousPeriod(object $p): ?object
    {
        $ym = (int) $p->year * 100 + (int) $p->month;
        return Db::one('SELECT * FROM #__kx_periods WHERE building_id=' . (int) $p->building_id . ' AND id<>' . (int) $p->id
            . ' AND (year*100+month<' . $ym . ' OR (year*100+month=' . $ym . ' AND id<' . (int) $p->id . ')) ORDER BY year DESC, month DESC, id DESC LIMIT 1');
    }

    // ------------------------------------------------------------------ υπόλοιπα

    /** Υπόλοιπο κάθε ιδιοκτησίας ΠΡΙΝ από τον μήνα $p (σε λεπτά). */
    public static function balancesBefore(object $p, array $units): array
    {
        $ym = (int) $p->year * 100 + (int) $p->month;
        $until = $p->issue_date ?: Db::today();
        $bid = (int) $p->building_id;
        $out = [];
        foreach ($units as $u) $out[(int) $u->id] = Calc::cents($u->opening_balance);
        foreach (Db::all('SELECT c.unit_id, SUM(c.total) AS s FROM #__kx_charges c JOIN #__kx_periods p ON p.id=c.period_id
                WHERE p.building_id=' . $bid . ' AND p.status=\'issued\' AND p.id<>' . (int) $p->id . ' AND (p.year*100+p.month<' . $ym . ' OR (p.year*100+p.month=' . $ym . ' AND p.id<' . (int) $p->id . '))
                GROUP BY c.unit_id') as $r) {
            if (isset($out[(int) $r->unit_id])) $out[(int) $r->unit_id] += Calc::cents($r->s);
        }
        foreach (Db::all('SELECT unit_id, SUM(amount) AS s FROM #__kx_payments WHERE building_id=' . $bid . ' AND pay_date<=' . Db::q($until) . ' GROUP BY unit_id') as $r) {
            if (isset($out[(int) $r->unit_id])) $out[(int) $r->unit_id] -= Calc::cents($r->s);
        }
        return $out;
    }

    /** Τρέχον υπόλοιπο κάθε ιδιοκτησίας: έναρξη + εκδοθέντες μήνες − εισπράξεις (σε λεπτά). */
    public static function balancesNow(int $bid, array $units): array
    {
        $out = [];
        foreach ($units as $u) $out[(int) $u->id] = ['opening' => Calc::cents($u->opening_balance), 'charged' => 0, 'paid' => 0, 'balance' => 0];
        foreach (Db::all('SELECT c.unit_id, SUM(c.total) AS s FROM #__kx_charges c JOIN #__kx_periods p ON p.id=c.period_id WHERE p.building_id=' . $bid . ' AND p.status=\'issued\' GROUP BY c.unit_id') as $r) {
            if (isset($out[(int) $r->unit_id])) $out[(int) $r->unit_id]['charged'] = Calc::cents($r->s);
        }
        foreach (Db::all('SELECT unit_id, SUM(amount) AS s FROM #__kx_payments WHERE building_id=' . $bid . ' GROUP BY unit_id') as $r) {
            if (isset($out[(int) $r->unit_id])) $out[(int) $r->unit_id]['paid'] = Calc::cents($r->s);
        }
        foreach ($out as &$o) $o['balance'] = $o['opening'] + $o['charged'] - $o['paid'];
        return $out;
    }

    /** Καρτέλα ιδιοκτησίας: χρεώσεις μηνών και εισπράξεις με προοδευτικό υπόλοιπο. */
    public static function ledger(object $unit): array
    {
        $rows = [];
        foreach (Db::all('SELECT p.id, p.title, p.year, p.month, p.issue_date, c.total FROM #__kx_charges c JOIN #__kx_periods p ON p.id=c.period_id WHERE p.status=\'issued\' AND c.unit_id=' . (int) $unit->id) as $c) {
            $rows[] = ['date' => $c->issue_date ?: sprintf('%04d-%02d-01', $c->year, $c->month), 'text' => 'Κοινόχρηστα ' . ($c->title ?: Fmt::period((int) $c->month, (int) $c->year)), 'debit' => Calc::cents($c->total), 'credit' => 0, 'payment_id' => 0];
        }
        foreach (Db::all('SELECT * FROM #__kx_payments WHERE unit_id=' . (int) $unit->id) as $p) {
            $rows[] = ['date' => $p->pay_date, 'text' => 'Είσπραξη' . ($p->receipt_no ? ' (απόδ. ' . $p->receipt_no . ')' : '') . ($p->notes ? ' — ' . $p->notes : ''), 'debit' => 0, 'credit' => Calc::cents($p->amount), 'payment_id' => (int) $p->id];
        }
        usort($rows, static fn($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['credit'] <=> $b['credit']));
        $bal = Calc::cents($unit->opening_balance);
        array_unshift($rows, ['date' => '', 'text' => 'Υπόλοιπο έναρξης', 'debit' => 0, 'credit' => 0, 'payment_id' => 0, 'balance' => $bal]);
        for ($i = 1; $i < count($rows); $i++) {
            $bal += $rows[$i]['debit'] - $rows[$i]['credit'];
            $rows[$i]['balance'] = $bal;
        }
        return $rows;
    }

    // ------------------------------------------------------------------ υπολογισμός μήνα

    /**
     * Όλα τα δεδομένα ενός μήνα και η κατανομή του.
     * ['period','building','keys','units','expenses','readings','calc','previous','fuel']
     */
    public static function compute(object $p, object $b): array
    {
        $keys = self::keys((int) $b->id);
        $units = self::units((int) $b->id);
        $expenses = self::expenses((int) $p->id);
        $readings = self::readings((int) $p->id);
        $heatKey = self::keyOf($keys, 'heating');

        $cu = [];
        foreach ($units as $u) {
            $r = $readings[(int) $u->id] ?? null;
            $cu[] = [
                'id' => (int) $u->id, 'code' => $u->code, 'mills' => $u->mills, 'area' => (float) $u->area,
                'heat_coef' => (float) $u->heat_coef, 'consumption' => $r ? max(0, (float) $r->curr_value - (float) $r->prev_value) : 0,
            ];
        }
        $ce = [];
        foreach ($expenses as $e) {
            $ce[] = [
                'id' => (int) $e->id, 'title' => $e->title, 'amount' => (float) $e->amount, 'category' => $e->category, 'method' => $e->method,
                'key_id' => (int) $e->key_id, 'payer' => $e->payer, 'unit_ids' => json_decode((string) $e->unit_ids, true) ?: [],
                'custom' => json_decode((string) $e->custom, true) ?: [],
            ];
        }
        $calc = Calc::compute($cu, $ce, [
            'heating_key' => (int) ($heatKey->id ?? 0),
            'heating_mode' => $b->heating_method,
            'heating_fixed_pct' => (float) $b->heating_fixed_pct,
        ]);
        $heating = json_decode((string) $p->heating, true) ?: [];
        return [
            'period' => $p, 'building' => $b, 'keys' => $keys, 'units' => $units, 'expenses' => $expenses, 'readings' => $readings,
            'calc' => $calc, 'previous' => self::balancesBefore($p, $units), 'heating' => $heating, 'fuel' => Calc::fuel($heating),
        ];
    }

    /** Τα δεδομένα εκτύπωσης (όλα σε μορφοποιημένα strings). */
    public static function printContext(array $d, ?object $account = null): array
    {
        $account = $account ?? Auth::account();
        $b = $d['building'];
        $p = $d['period'];
        $calc = $d['calc'];
        $keys = $d['keys'];
        $m = static fn(int $c, bool $blank = true) => Fmt::moneyCents($c, $blank);
        $managerLine = implode(' · ', array_filter([trim((string) $b->manager_name), trim((string) ($b->manager_phone ?: $b->manager_mobile)), trim((string) $b->manager_email)]));
        $fields = [
            'account.name' => (string) ($account->name ?? ''), 'account.address' => (string) ($account->address ?? ''),
            'account.phone' => (string) ($account->phone ?? ''), 'account.email' => (string) ($account->email ?? ''),
            'account.website' => (string) ($account->website ?? ''), 'account.afm' => (string) ($account->afm ?? ''),
            'account.contact' => implode(' · ', array_filter([(string) ($account->phone ?? ''), (string) ($account->email ?? '')])),
            'building.title' => (string) $b->title, 'building.address' => Fmt::address($b), 'building.street' => trim($b->street . ' ' . $b->street_no),
            'building.area' => (string) $b->area, 'building.city' => (string) $b->city, 'building.postcode' => (string) $b->postcode,
            'building.afm' => (string) $b->afm, 'building.iban' => (string) $b->iban, 'building.bank' => (string) $b->bank,
            'building.beneficiary' => (string) $b->beneficiary, 'building.units_count' => (string) count($d['units']),
            'manager.name' => (string) $b->manager_name, 'manager.phone' => (string) $b->manager_phone, 'manager.mobile' => (string) $b->manager_mobile,
            'manager.email' => (string) $b->manager_email, 'manager.address' => (string) $b->manager_address, 'manager.hours' => (string) $b->manager_hours,
            'manager.contact' => $managerLine, 'rep.name' => (string) $b->rep_name, 'rep.phone' => (string) $b->rep_phone,
            'period.title' => (string) ($p->title ?: Fmt::period((int) $p->month, (int) $p->year)), 'period.month' => Fmt::MONTHS[(int) $p->month] ?? '',
            'period.year' => (string) $p->year, 'period.issue_date' => Fmt::date($p->issue_date), 'period.due_date' => Fmt::date($p->due_date),
            'period.notes' => (string) $p->notes, 'print.date' => date('d/m/Y'),
            'totals.general' => $m($calc['totals']['general'], false), 'totals.elevator' => $m($calc['totals']['elevator'], false),
            'totals.heating' => $m($calc['totals']['heating'], false), 'totals.special' => $m($calc['totals']['special'], false),
            'totals.owner' => $m($calc['totals']['owner'], false), 'totals.current' => $m($calc['totals']['total'], false),
            'totals.expenses' => Fmt::money(array_sum(array_map(static fn($e) => (float) $e->amount, $d['expenses']))),
            'heating.start' => Fmt::qty($d['fuel']['start']), 'heating.bought' => Fmt::qty($d['fuel']['bought']), 'heating.end' => Fmt::qty($d['fuel']['end']),
            'heating.consumed' => Fmt::qty($d['fuel']['consumed']), 'heating.price' => Fmt::qty($d['fuel']['price'], 4), 'heating.cost' => Fmt::money($d['fuel']['cost'], true),
            'heating.fixed_pct' => Fmt::qty($b->heating_fixed_pct), 'heating.unit' => (string) $b->fuel_unit,
        ];
        foreach ($keys as $k) {
            $fields['totals.mills.' . $k->code] = Fmt::mills(array_sum(array_map(static fn($u) => (float) ($u->mills[(int) $k->id] ?? 0), $d['units'])));
        }
        $rows = [];
        $uexp = [];
        $i = 0;
        foreach ($d['units'] as $u) {
            $c = $calc['units'][(int) $u->id] ?? ['general' => 0, 'elevator' => 0, 'heating' => 0, 'special' => 0, 'owner' => 0, 'tenant' => 0, 'total' => 0, 'expenses' => []];
            $prev = (int) ($d['previous'][(int) $u->id] ?? 0);
            $r = $d['readings'][(int) $u->id] ?? null;
            $millsLine = [];
            $row = [
                'unit.row_no' => (string) (++$i), 'unit.code' => (string) $u->code, 'unit.floor' => (string) $u->floor,
                'unit.label' => trim($u->code . ($u->floor !== '' ? ' · ' . $u->floor . (preg_match('~όροφ~iu', $u->floor) ? '' : ' όροφος') : '')),
                'unit.kind' => (string) $u->kind, 'unit.area' => Fmt::qty($u->area), 'unit.payer_name' => self::payerName($u),
                'unit.owner_name' => (string) $u->owner_name, 'unit.tenant_name' => (string) $u->tenant_name,
                'unit.consumption' => $r ? Fmt::qty(max(0, (float) $r->curr_value - (float) $r->prev_value)) : '',
                'unit.heat_coef' => Fmt::qty($u->heat_coef, 4),
                'amt.general' => $m($c['general']), 'amt.elevator' => $m($c['elevator']), 'amt.heating' => $m($c['heating']),
                'amt.special' => $m($c['special']), 'amt.owner' => $m($c['owner']), 'amt.tenant' => $m($c['tenant'], false),
                'amt.current' => $m($c['total'], false), 'amt.previous' => $m($prev, false), 'amt.total' => $m($c['total'] + $prev, false),
            ];
            foreach ($keys as $k) {
                $v = (float) ($u->mills[(int) $k->id] ?? 0);
                $row['mills.' . $k->code] = Fmt::mills($v, true);
                $millsLine[] = $k->title . ' ' . Fmt::mills($v);
            }
            $row['unit.mills_line'] = implode(' · ', $millsLine);
            $rows[] = $row;
            $list = [];
            foreach ($d['expenses'] as $e) {
                $share = (int) ($c['expenses'][(int) $e->id] ?? 0);
                if ($share === 0) continue;
                $list[] = ['uexp.title' => (string) $e->title, 'uexp.category' => Calc::CATEGORIES[$e->category] ?? '', 'uexp.amount' => Fmt::money($e->amount), 'uexp.share' => $m($share, false)];
            }
            $uexp[] = $list;
        }
        $exp = [];
        foreach (array_values($d['expenses']) as $n => $e) {
            $exp[] = [
                'exp.no' => (string) ($n + 1), 'exp.title' => (string) $e->title, 'exp.category' => Calc::CATEGORIES[$e->category] ?? '',
                'exp.amount' => Fmt::money($e->amount), 'exp.supplier' => (string) $e->supplier,
                'exp.doc' => trim($e->doc_no . ($e->doc_date ? ' / ' . Fmt::date($e->doc_date) : '')),
                'exp.method' => Calc::METHODS[$e->method] ?? '', 'exp.payer' => $e->payer === 'owner' ? 'Ιδιοκτήτης' : 'Ένοικος',
            ];
        }
        return ['fields' => $fields, 'units' => $rows, 'expenses' => $exp, 'unit_expenses' => $uexp, 'unit_ids' => array_map('intval', array_keys($d['units']))];
    }

    // ------------------------------------------------------------------ έκδοση / κλείδωμα

    public static function issue(int $pid): void
    {
        [$p, $b] = self::period($pid);
        if (!$p->issue_date) {
            Db::exec('UPDATE #__kx_periods SET issue_date=' . Db::q(Db::today()) . ' WHERE id=' . $pid);
            $p->issue_date = Db::today();
        }
        $d = self::compute($p, $b);
        $ctx = self::printContext($d);
        $db = Db::db();
        $db->transactionStart();
        try {
            Db::exec('DELETE FROM #__kx_charges WHERE period_id=' . $pid);
            foreach ($d['units'] as $u) {
                $c = $d['calc']['units'][(int) $u->id];
                Db::insertPlain('#__kx_charges', [
                    'period_id' => $pid, 'unit_id' => (int) $u->id, 'tenant' => $c['tenant'] / 100, 'owner' => $c['owner'] / 100,
                    'total' => $c['total'] / 100, 'previous' => ((int) ($d['previous'][(int) $u->id] ?? 0)) / 100,
                ]);
            }
            $snapshot = ['ctx' => $ctx, 'totals' => $d['calc']['totals'], 'warnings' => $d['calc']['warnings'], 'issued' => Db::now()];
            Db::update('#__kx_periods', $pid, ['status' => 'issued', 'issued_at' => Db::now(), 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
    }

    public static function reopen(int $pid): void
    {
        self::period($pid);
        Db::exec('DELETE FROM #__kx_charges WHERE period_id=' . $pid);
        Db::exec('UPDATE #__kx_periods SET status=\'draft\', snapshot=NULL, issued_at=NULL WHERE id=' . $pid);
    }

    /** Υπάρχει μεταγενέστερος εκδοθείς μήνας; (τότε δεν ανοίγει ξανά, για να μην αλλάξουν τα υπόλοιπα) */
    public static function hasLaterIssued(object $p): bool
    {
        $ym = (int) $p->year * 100 + (int) $p->month;
        return (int) Db::value('SELECT COUNT(*) FROM #__kx_periods WHERE building_id=' . (int) $p->building_id . ' AND status=\'issued\' AND id<>' . (int) $p->id
            . ' AND (year*100+month>' . $ym . ' OR (year*100+month=' . $ym . ' AND id>' . (int) $p->id . '))') > 0;
    }

    // ------------------------------------------------------------------ πρότυπα εκτύπωσης

    public static function templates(?string $type = null): array
    {
        return Db::all('SELECT id, account_id, title, type, background, is_default, modified FROM #__kx_templates WHERE (account_id=0 OR account_id=' . Auth::accountId() . ')'
            . ($type ? ' AND type=' . Db::q($type) : '') . ' ORDER BY account_id DESC, is_default DESC, title');
    }

    public static function template(int $id, bool $forEdit = false): ?object
    {
        $aid = Auth::accountId();
        $t = Db::one('SELECT * FROM #__kx_templates WHERE id=' . $id . ' AND (account_id=' . $aid . ($forEdit && !Auth::isPlatformAdmin() ? '' : ' OR account_id=0') . ')');
        return $t ?: null;
    }

    /** Το προεπιλεγμένο πρότυπο ενός τύπου: του λογαριασμού, αλλιώς του συστήματος. */
    public static function defaultTemplate(string $type, int $accountId = -1): ?object
    {
        $aid = $accountId >= 0 ? $accountId : Auth::accountId();
        return Db::one('SELECT * FROM #__kx_templates WHERE type=' . Db::q($type) . ' AND (account_id=0 OR account_id=' . $aid . ') ORDER BY (account_id=' . $aid . ' AND is_default=1) DESC, account_id=0 DESC, is_default DESC, id LIMIT 1');
    }

    public static function layout(object $t): array
    {
        $l = json_decode((string) $t->layout, true);
        if (!is_array($l) || empty($l['elements'])) {
            $l = $t->type === 'notice' ? Layouts::notice() : Layouts::sheet();
        }
        return Layouts::sanitize($l);
    }
}
