<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Calc;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;

/** Κτίρια, κλείδες κατανομής (στήλες χιλιοστών) και ιδιοκτησίες. */
final class BuildingController extends Base
{
    private const FIELDS = [
        'title' => 190, 'street' => 190, 'street_no' => 20, 'area' => 120, 'city' => 120, 'postcode' => 10, 'floors' => 20,
        'afm' => 20, 'iban' => 40, 'bank' => 120, 'beneficiary' => 190,
        'manager_name' => 190, 'manager_phone' => 60, 'manager_mobile' => 60, 'manager_email' => 190, 'manager_address' => 255, 'manager_hours' => 190,
        'rep_name' => 190, 'rep_phone' => 60, 'fuel_unit' => 10,
    ];

    public function save(): void
    {
        $this->guard();
        $account = Auth::requireWritable();
        $id = $this->int('id');
        $data = [];
        foreach (self::FIELDS as $f => $max) {
            $data[$f] = $this->str($f, $max);
        }
        $data['iban'] = strtoupper(preg_replace('~\s+~', ' ', $data['iban']));
        $data['heating_type'] = array_key_exists($this->str('heating_type'), Repo::HEATING_TYPES) ? $this->str('heating_type') : 'none';
        $method = $this->str('heating_method');
        if (!array_key_exists($method, Repo::HEATING_METHODS) || ($method !== 'mills' && !Auth::feature('heating_meters'))) {
            $method = 'mills';
        }
        $data['heating_method'] = $method;
        $data['heating_fixed_pct'] = max(0, min(100, $this->num('heating_fixed_pct', 30)));
        $data['due_days'] = max(0, min(90, $this->int('due_days', 10)));
        $data['notes'] = $this->text('notes');
        $data['modified'] = Db::now();
        if ($data['title'] === '') {
            $data['title'] = trim($data['street'] . ' ' . $data['street_no']) ?: 'Νέο κτίριο';
        }
        if ($id > 0) {
            Repo::building($id);
            Db::update('#__kx_buildings', $id, $data);
            Db::log('building.save', $data['title']);
            $this->go('view=building&id=' . $id . '&tab=' . $this->str('tab', 20), 'Τα στοιχεία του κτιρίου αποθηκεύτηκαν.');
            return;
        }
        if (!Auth::within('buildings')) {
            $this->go('view=account&tab=billing', 'Φτάσατε το όριο κτιρίων του πακέτου σας. Αναβαθμίστε για να προσθέσετε κι άλλα.', 'warning');
            return;
        }
        $data += ['account_id' => (int) $account->id, 'archived' => 0, 'created' => Db::now()];
        $id = Db::insert('#__kx_buildings', $data);
        Repo::ensureKeys($id);
        Db::log('building.create', $data['title']);
        $this->go('view=building&id=' . $id . '&tab=units', 'Το κτίριο δημιουργήθηκε. Προσθέστε τώρα τις ιδιοκτησίες και τα χιλιοστά.');
    }

    public function archive(): void
    {
        $this->guard();
        Auth::requireWritable();
        $b = Repo::building($this->int('id'));
        $to = (int) !$b->archived;
        Db::exec('UPDATE #__kx_buildings SET archived=' . $to . ' WHERE id=' . (int) $b->id);
        Db::log('building.archive', $b->title);
        $this->go('view=buildings', $to ? 'Το κτίριο αρχειοθετήθηκε.' : 'Το κτίριο επανήλθε.');
    }

    public function delete(): void
    {
        $this->guard();
        Auth::requireWritable();
        Auth::requireOwner();
        $b = Repo::building($this->int('id'));
        if ($this->str('confirm_title') !== $b->title) {
            $this->go('view=building&id=' . (int) $b->id . '&tab=info', 'Για διαγραφή πληκτρολογήστε ακριβώς την ονομασία του κτιρίου.', 'warning');
            return;
        }
        $bid = (int) $b->id;
        $pids = Db::ids(Db::column('SELECT id FROM #__kx_periods WHERE building_id=' . $bid));
        $uids = Db::ids(Db::column('SELECT id FROM #__kx_units WHERE building_id=' . $bid));
        foreach ([
            'DELETE FROM #__kx_expenses WHERE period_id IN (' . $pids . ')', 'DELETE FROM #__kx_readings WHERE period_id IN (' . $pids . ')',
            'DELETE FROM #__kx_charges WHERE period_id IN (' . $pids . ')', 'DELETE FROM #__kx_periods WHERE building_id=' . $bid,
            'DELETE FROM #__kx_unit_mills WHERE unit_id IN (' . $uids . ')', 'DELETE FROM #__kx_units WHERE building_id=' . $bid,
            'DELETE FROM #__kx_payments WHERE building_id=' . $bid, 'DELETE FROM #__kx_keys WHERE building_id=' . $bid,
            'DELETE FROM #__kx_buildings WHERE id=' . $bid,
        ] as $sql) {
            Db::exec($sql);
        }
        Db::log('building.delete', $b->title);
        $this->go('view=buildings', 'Το κτίριο διαγράφηκε οριστικά.');
    }

    /** Κλείδες κατανομής: μετονομασία, νέες στήλες, διαγραφή στηλών «custom». */
    public function savekeys(): void
    {
        $this->guard();
        Auth::requireWritable();
        $b = Repo::building($this->int('id'));
        $bid = (int) $b->id;
        $keys = [];
        foreach (Repo::keys($bid) as $k) $keys[(int) $k->id] = $k;
        $titles = $this->arr('key_title');
        $order = 0;
        foreach ($titles as $kid => $title) {
            $kid = (int) $kid;
            if (!isset($keys[$kid])) continue;
            $title = mb_substr(trim((string) $title), 0, 100);
            if (!empty($this->arr('key_delete')[$kid]) && $keys[$kid]->kind === 'custom') {
                $used = (int) Db::value('SELECT COUNT(*) FROM #__kx_expenses e JOIN #__kx_periods p ON p.id=e.period_id WHERE p.building_id=' . $bid . ' AND e.key_id=' . $kid);
                if ($used) {
                    $this->app->enqueueMessage('Η στήλη «' . $keys[$kid]->title . '» χρησιμοποιείται σε δαπάνες και δεν διαγράφηκε.', 'warning');
                } else {
                    Db::exec('DELETE FROM #__kx_unit_mills WHERE key_id=' . $kid);
                    Db::exec('DELETE FROM #__kx_keys WHERE id=' . $kid);
                    continue;
                }
            }
            Db::update('#__kx_keys', $kid, ['title' => $title !== '' ? $title : $keys[$kid]->title, 'ordering' => ++$order]);
        }
        $new = mb_substr($this->str('new_key'), 0, 100);
        if ($new !== '') {
            $n = 1;
            $codes = array_map(static fn($k) => $k->code, $keys);
            while (in_array('k' . $n, $codes, true)) $n++;
            Db::insert('#__kx_keys', ['building_id' => $bid, 'code' => 'k' . $n, 'title' => $new, 'kind' => 'custom', 'ordering' => 100]);
        }
        $this->go('view=building&id=' . $bid . '&tab=units', 'Οι στήλες χιλιοστών ενημερώθηκαν.');
    }

    /** Αποθήκευση όλου του πίνακα ιδιοκτησιών (επεξεργασία σαν λογιστικό φύλλο). */
    public function saveunits(): void
    {
        $this->guard();
        Auth::requireWritable();
        $b = Repo::building($this->int('id'));
        $bid = (int) $b->id;
        $existing = Repo::units($bid);
        $keys = Repo::keys($bid);
        $rows = $this->arr('u');
        $newCount = 0;
        foreach ($rows as $key => $r) {
            if (!is_array($r) || !empty($r['_delete'])) continue;
            if (!isset($existing[(int) $key]) && trim(implode('', array_map(static fn($v) => is_array($v) ? implode('', $v) : (string) $v, $r))) !== '') $newCount++;
        }
        if ($newCount && !Auth::within('units', $newCount)) {
            $this->go('view=building&id=' . $bid . '&tab=units', 'Οι νέες ιδιοκτησίες ξεπερνούν το όριο του πακέτου σας.', 'warning');
            return;
        }
        $saved = 0;
        $order = 0;
        foreach ($rows as $key => $r) {
            if (!is_array($r)) continue;
            $uid = isset($existing[(int) $key]) ? (int) $key : 0;
            if (!empty($r['_delete'])) {
                if ($uid) $this->deleteUnit($uid, $bid);
                continue;
            }
            $data = $this->unitData($r);
            if (!$uid && $data['code'] === '' && $data['owner_name'] === '' && $data['tenant_name'] === '') continue;
            $data['ordering'] = ++$order;
            if ($uid) {
                Db::update('#__kx_units', $uid, $data);
            } else {
                $uid = Db::insert('#__kx_units', $data + ['building_id' => $bid, 'created' => Db::now()]);
            }
            $this->saveMills($uid, $keys, (array) ($r['mills'] ?? []));
            $saved++;
        }
        Db::log('units.save', $b->title . ': ' . $saved . ' ιδιοκτησίες');
        $this->go('view=building&id=' . $bid . '&tab=units', 'Αποθηκεύτηκαν ' . $saved . ' ιδιοκτησίες.');
    }

    /** Επικόλληση από Excel: μία γραμμή ανά ιδιοκτησία, στήλες χωρισμένες με Tab ή ;. */
    public function import(): void
    {
        $this->guard();
        Auth::requireWritable();
        $b = Repo::building($this->int('id'));
        $bid = (int) $b->id;
        $keys = Repo::keys($bid);
        $lines = preg_split('~\r?\n~', (string) $this->input->post->get('paste', '', 'raw'));
        $cols = ['code', 'floor', 'owner_name', 'tenant_name', 'area'];
        $parsed = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ';');
            $cells = array_map('trim', $cells);
            // Παράλειψη γραμμής επικεφαλίδων.
            if (!$parsed && isset($cells[0]) && preg_match('~^(διαμ|κωδ|α/α|code)~iu', $cells[0]) && !preg_match('~\d~', $cells[5] ?? '')) continue;
            $r = [];
            foreach ($cols as $i => $c) $r[$c] = $cells[$i] ?? '';
            $r['mills'] = [];
            foreach (array_values($keys) as $i => $k) $r['mills'][(int) $k->id] = $cells[5 + $i] ?? '';
            $parsed[] = $r;
        }
        if (!$parsed) {
            $this->go('view=building&id=' . $bid . '&tab=import', 'Δεν βρέθηκαν γραμμές για εισαγωγή.', 'warning');
            return;
        }
        if (!Auth::within('units', count($parsed))) {
            $this->go('view=building&id=' . $bid . '&tab=import', 'Οι ιδιοκτησίες ξεπερνούν το όριο του πακέτου σας.', 'warning');
            return;
        }
        $order = (int) Db::value('SELECT MAX(ordering) FROM #__kx_units WHERE building_id=' . $bid);
        foreach ($parsed as $r) {
            $data = $this->unitData($r + ['payer' => 'tenant', 'kind' => 'Διαμέρισμα', 'heat_coef' => '1']);
            $data['ordering'] = ++$order;
            $uid = Db::insert('#__kx_units', $data + ['building_id' => $bid, 'created' => Db::now()]);
            $this->saveMills($uid, $keys, $r['mills']);
        }
        Db::log('units.import', $b->title . ': ' . count($parsed));
        $this->go('view=building&id=' . $bid . '&tab=units', 'Εισήχθησαν ' . count($parsed) . ' ιδιοκτησίες. Ελέγξτε τα σύνολα χιλιοστών.');
    }

    private function unitData(array $r): array
    {
        $s = static fn($k, $max = 190) => mb_substr(trim((string) ($r[$k] ?? '')), 0, $max);
        $kind = $s('kind', 30);
        $email = static fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
        return [
            'code' => $s('code', 30), 'floor' => $s('floor', 20), 'kind' => in_array($kind, Repo::UNIT_KINDS, true) ? $kind : 'Διαμέρισμα',
            'area' => max(0, Calc::parseNumber($r['area'] ?? 0)),
            'owner_name' => $s('owner_name'), 'owner_phone' => $s('owner_phone', 60), 'owner_email' => $email($s('owner_email')), 'owner_afm' => $s('owner_afm', 20),
            'tenant_name' => $s('tenant_name'), 'tenant_phone' => $s('tenant_phone', 60), 'tenant_email' => $email($s('tenant_email')),
            'payer' => ($r['payer'] ?? '') === 'owner' ? 'owner' : 'tenant',
            'heat_coef' => ($r['heat_coef'] ?? '') === '' ? 1 : max(0, min(100, Calc::parseNumber($r['heat_coef']))),
            'opening_balance' => Calc::parseNumber($r['opening_balance'] ?? 0),
            'notes' => $s('notes', 255),
        ];
    }

    private function saveMills(int $uid, array $keys, array $mills): void
    {
        foreach ($keys as $k) {
            $v = max(0, round(Calc::parseNumber($mills[(int) $k->id] ?? 0), 3));
            Db::exec('REPLACE INTO #__kx_unit_mills (unit_id, key_id, mills) VALUES (' . $uid . ',' . (int) $k->id . ',' . $v . ')');
        }
    }

    private function deleteUnit(int $uid, int $bid): void
    {
        $has = (int) Db::value('SELECT COUNT(*) FROM #__kx_charges WHERE unit_id=' . $uid) + (int) Db::value('SELECT COUNT(*) FROM #__kx_payments WHERE unit_id=' . $uid);
        if ($has) {
            $this->app->enqueueMessage('Μια ιδιοκτησία έχει εκδοθέντες μήνες ή εισπράξεις και δεν διαγράφηκε.', 'warning');
            return;
        }
        Db::exec('DELETE FROM #__kx_unit_mills WHERE unit_id=' . $uid);
        Db::exec('DELETE FROM #__kx_readings WHERE unit_id=' . $uid);
        Db::exec('DELETE FROM #__kx_units WHERE id=' . $uid . ' AND building_id=' . $bid);
    }
}
