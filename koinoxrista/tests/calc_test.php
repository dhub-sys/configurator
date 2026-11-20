<?php
// php tests/calc_test.php  — tests του μηχανισμού κατανομής (χωρίς Joomla).
define('_JEXEC', 1);
require __DIR__ . '/../components/com_koinoxrista/src/Helper/Fmt.php';
require __DIR__ . '/../components/com_koinoxrista/src/Helper/Calc.php';

use Koinoxrista\Component\Koinoxrista\Site\Helper\Calc;

$fail = 0;
function check(string $name, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $fail++;
        echo "FAIL $name\n     got:  " . json_encode($got) . "\n     want: " . json_encode($want) . "\n";
    }
}

// allocate: το άθροισμα ισούται πάντα με το ποσό.
check('allocate 100.00 / 3', Calc::allocate(10000, [1 => 1, 2 => 1, 3 => 1]), [1 => 3334, 2 => 3333, 3 => 3333]);
check('allocate negative', Calc::allocate(-100, [1 => 1, 2 => 1, 3 => 1]), [1 => -34, 2 => -33, 3 => -33]);
check('allocate zero weights skipped', Calc::allocate(1000, [1 => 0, 2 => 5]), [2 => 1000]);
check('allocate no weights', Calc::allocate(1000, [1 => 0]), []);
for ($i = 0; $i < 200; $i++) {
    $w = [];
    for ($j = 1; $j <= rand(1, 30); $j++) $w[$j] = rand(0, 300) / 3;
    $c = rand(-100000, 1000000);
    $r = Calc::allocate($c, $w);
    if (array_sum($w) > 0 && array_sum($r) !== $c) { check("allocate random #$i sum", array_sum($r), $c); }
}
echo "ok   allocate random sums\n";

check('parse 1.234,56', Calc::parseNumber('1.234,56'), 1234.56);
check('parse 1234.56', Calc::parseNumber('1234.56'), 1234.56);
check('parse 12,5 €', Calc::parseNumber('12,5 €'), 12.5);
check('cents 0.1+0.2', Calc::cents(0.1 + 0.2), 30);

// Πολυκατοικία 4 διαμερισμάτων. Κλείδες: 1 γενικά, 2 ανελκυστήρας, 3 θέρμανση.
$units = [
    ['id' => 1, 'code' => 'Ισ', 'mills' => [1 => 150, 2 => 0, 3 => 160], 'area' => 60, 'heat_coef' => 1.0, 'consumption' => 100],
    ['id' => 2, 'code' => 'Α1', 'mills' => [1 => 250, 2 => 200, 3 => 240], 'area' => 90, 'heat_coef' => 1.1, 'consumption' => 0],
    ['id' => 3, 'code' => 'Β1', 'mills' => [1 => 280, 2 => 350, 3 => 280], 'area' => 95, 'heat_coef' => 1.0, 'consumption' => 200],
    ['id' => 4, 'code' => 'Γ1', 'mills' => [1 => 320, 2 => 450, 3 => 320], 'area' => 110, 'heat_coef' => 0.9, 'consumption' => 300],
];
$expenses = [
    ['id' => 10, 'title' => 'ΔΕΗ κοινοχρήστων', 'amount' => '123,45', 'category' => 'general', 'method' => 'key', 'key_id' => 1, 'payer' => 'tenant'],
    ['id' => 11, 'title' => 'Συντήρηση ανελκυστήρα', 'amount' => 80, 'category' => 'elevator', 'method' => 'key', 'key_id' => 2, 'payer' => 'tenant'],
    ['id' => 12, 'title' => 'Πετρέλαιο', 'amount' => 1000, 'category' => 'heating', 'method' => 'heating', 'key_id' => 3, 'payer' => 'tenant'],
    ['id' => 13, 'title' => 'Καθαρισμός', 'amount' => 40, 'category' => 'general', 'method' => 'equal', 'payer' => 'tenant'],
    ['id' => 14, 'title' => 'Αποθεματικό', 'amount' => 100, 'category' => 'general', 'method' => 'key', 'key_id' => 1, 'payer' => 'owner'],
    ['id' => 15, 'title' => 'Τζάμι εισόδου Β1', 'amount' => 25, 'category' => 'special', 'method' => 'custom', 'custom' => [3 => 25], 'payer' => 'tenant'],
    ['id' => 16, 'title' => 'Λαμπτήρες ορόφων Α-Γ', 'amount' => 10, 'category' => 'special', 'method' => 'equal', 'unit_ids' => [2, 3, 4], 'payer' => 'tenant'],
];
$r = Calc::compute($units, $expenses, ['heating_key' => 3, 'heating_mode' => 'hours', 'heating_fixed_pct' => 30]);

check('no warnings', $r['warnings'], []);
check('total = sum of expenses', $r['totals']['total'], 12345 + 8000 + 100000 + 4000 + 10000 + 2500 + 1000);
foreach ($r['expenses'] as $id => $x) check("expense $id fully allocated", $x['allocated'], $x['amount']);
check('ground floor pays no elevator', $r['units'][1]['elevator'], 0);
check('Β1 special = 25 + 3.33/3.34', $r['units'][3]['special'], 2500 + 333);
check('owner column', [$r['units'][1]['owner'], $r['units'][4]['owner']], [1500, 3200]);
// Θέρμανση: πάγιο 300€ με χιλιοστά θέρμανσης (σύνολο 1000), μεταβλητό 700€ με ώρες × συντελεστή.
// βάρη: 100, 0, 200, 270 → σύνολο 570.
$fixedA1 = 300 * 240 / 1000; // 72€ — το Α1 δεν άναψε, πληρώνει μόνο πάγιο
check('heating A1 fixed only', $r['units'][2]['heating'], (int) round($fixedA1 * 100));
check('heating total', $r['totals']['heating'], 100000);
$sumUnits = 0;
foreach ($r['units'] as $u) {
    $sumUnits += $u['total'];
    if ($u['total'] !== $u['general'] + $u['elevator'] + $u['heating'] + $u['special'] + $u['owner']) check('row sums', false, true);
}
check('sum of rows = total', $sumUnits, $r['totals']['total']);

// Χωρίς καταναλώσεις: προειδοποίηση και κατανομή με χιλιοστά.
$u2 = array_map(static fn($u) => ['consumption' => 0] + $u, $units);
foreach ($u2 as &$u) $u['consumption'] = 0; unset($u);
$r2 = Calc::compute($u2, [$expenses[2]], ['heating_key' => 3, 'heating_mode' => 'hours', 'heating_fixed_pct' => 30]);
check('heating fallback warns', count($r2['warnings']), 1);
check('heating fallback by mills', $r2['units'][4]['heating'], 32000);

// Custom amounts που δεν ταιριάζουν → προειδοποίηση.
$r3 = Calc::compute($units, [['id' => 1, 'title' => 'x', 'amount' => 30, 'method' => 'custom', 'custom' => [1 => 10, 2 => 10]]], []);
check('custom mismatch warns', count($r3['warnings']), 1);

// Καύσιμο: 500 lt × 1,10 + αγορά 1000 lt / 1.250 € − τελικό 300 lt.
$f = Calc::fuel(['start_qty' => 500, 'start_price' => '1,10', 'bought_qty' => 1000, 'bought_amount' => '1.250,00', 'end_qty' => 300]);
check('fuel consumed', $f['consumed'], 1200.0);
check('fuel price', $f['price'], 1.2);
check('fuel cost', $f['cost'], 1440.0);

echo $fail ? "\n$fail test(s) failed\n" : "\nall tests passed\n";
exit($fail ? 1 : 0);
