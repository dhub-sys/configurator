<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

/**
 * Κατανομή δαπανών. Καθαρή PHP, χωρίς εξαρτήσεις από το Joomla, ώστε να ελέγχεται με tests.
 *
 * Όλα τα ποσά υπολογίζονται σε λεπτά (int). Κάθε δαπάνη μοιράζεται με τη μέθοδο του
 * «μεγαλύτερου υπολοίπου», οπότε το άθροισμα των μεριδίων ισούται πάντα ακριβώς με το ποσό
 * της δαπάνης, χωρίς διαφορές στρογγυλοποίησης.
 *
 * Μονάδα (unit):  ['id'=>int, 'code'=>string, 'mills'=>[key_id=>float], 'area'=>float,
 *                  'heat_coef'=>float, 'consumption'=>float]
 * Δαπάνη:         ['id'=>int, 'title'=>string, 'amount'=>float, 'category'=>general|elevator|heating|special,
 *                  'method'=>key|equal|area|heating|consumption|custom, 'key_id'=>int,
 *                  'payer'=>tenant|owner, 'unit_ids'=>int[], 'custom'=>[unit_id=>float]]
 * Επιλογές:       ['heating_key'=>int, 'heating_mode'=>mills|hours|units, 'heating_fixed_pct'=>float]
 */
final class Calc
{
    public const CATEGORIES = [
        'general' => 'Κοινόχρηστα',
        'elevator' => 'Ανελκυστήρας',
        'heating' => 'Θέρμανση',
        'special' => 'Ειδικές δαπάνες',
    ];

    public const METHODS = [
        'key' => 'Με χιλιοστά',
        'equal' => 'Ισόποσα ανά ιδιοκτησία',
        'area' => 'Με τετραγωνικά μέτρα',
        'heating' => 'Θέρμανση: πάγιο + κατανάλωση',
        'consumption' => 'Μόνο με κατανάλωση (ώρες/μονάδες)',
        'custom' => 'Συγκεκριμένα ποσά ανά ιδιοκτησία',
    ];

    public const PAYERS = [
        'tenant' => 'Ένοικος (χρήστης)',
        'owner' => 'Ιδιοκτήτης',
    ];

    /** Στήλες αποτελέσματος ανά ιδιοκτησία, με τη σειρά που εμφανίζονται. */
    public const COLUMNS = ['general', 'elevator', 'heating', 'special', 'owner'];

    public static function cents($value): int
    {
        if (is_string($value)) {
            $value = self::parseNumber($value);
        }
        return (int) round(((float) $value) * 100);
    }

    /** Δέχεται «1.234,56», «1234,56» και «1234.56». */
    public static function parseNumber($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = trim(str_replace(["\xc2\xa0", ' ', '€'], '', (string) $value));
        if ($s === '') {
            return 0.0;
        }
        if (strpos($s, ',') !== false) {
            // Ελληνική μορφή: η τελεία είναι διαχωριστικό χιλιάδων.
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        return is_numeric($s) ? (float) $s : 0.0;
    }

    /**
     * Μοιράζει $cents λεπτά ανάλογα με τα βάρη. Επιστρέφει [id => λεπτά] μόνο για τα θετικά βάρη.
     * Μέθοδος μεγαλύτερου υπολοίπου· σε ισοπαλία προηγείται η σειρά των βαρών.
     */
    public static function allocate(int $cents, array $weights): array
    {
        $weights = array_filter($weights, static fn($w) => (float) $w > 0);
        if (!$weights || $cents === 0) {
            return array_fill_keys(array_keys($weights), 0);
        }
        $sign = $cents < 0 ? -1 : 1;
        $abs = abs($cents);
        $sum = array_sum(array_map('floatval', $weights));
        $out = [];
        $rema = [];
        $given = 0;
        $order = 0;
        foreach ($weights as $id => $w) {
            $exact = $abs * ((float) $w) / $sum;
            $floor = (int) floor($exact + 1e-9);
            $out[$id] = $floor;
            $given += $floor;
            $rema[] = [$id, $exact - $floor, $order++];
        }
        usort($rema, static fn($a, $b) => ($b[1] <=> $a[1]) ?: ($a[2] <=> $b[2]));
        $left = $abs - $given;
        for ($i = 0; $left > 0; $i = ($i + 1) % count($rema), $left--) {
            $out[$rema[$i][0]]++;
        }
        if ($sign < 0) {
            foreach ($out as $id => $v) {
                $out[$id] = -$v;
            }
        }
        return $out;
    }

    public static function compute(array $units, array $expenses, array $opts = []): array
    {
        $heatKey = (int) ($opts['heating_key'] ?? 0);
        $heatMode = (string) ($opts['heating_mode'] ?? 'mills');
        $fixedPct = max(0.0, min(100.0, (float) ($opts['heating_fixed_pct'] ?? 30)));

        $blank = array_fill_keys(array_merge(self::COLUMNS, ['tenant', 'total']), 0);
        $result = ['units' => [], 'expenses' => [], 'totals' => $blank, 'warnings' => []];
        $byId = [];
        foreach ($units as $u) {
            $id = (int) $u['id'];
            $byId[$id] = $u;
            $result['units'][$id] = $blank + ['expenses' => []];
        }

        foreach ($expenses as $e) {
            $eid = (int) ($e['id'] ?? 0);
            $title = (string) ($e['title'] ?? '');
            $amount = self::cents($e['amount'] ?? 0);
            $method = (string) ($e['method'] ?? 'key');
            $category = isset(self::CATEGORIES[$e['category'] ?? '']) ? (string) $e['category'] : 'general';
            $payer = ($e['payer'] ?? 'tenant') === 'owner' ? 'owner' : 'tenant';
            $keyId = (int) ($e['key_id'] ?? 0);
            $subset = array_values(array_filter(array_map('intval', (array) ($e['unit_ids'] ?? []))));
            $eligible = $subset ? array_intersect_key($byId, array_flip($subset)) : $byId;

            $shares = [];
            switch ($method) {
                case 'equal':
                    $shares = self::allocate($amount, array_map(static fn() => 1, $eligible));
                    break;
                case 'area':
                    $shares = self::allocate($amount, array_map(static fn($u) => (float) ($u['area'] ?? 0), $eligible));
                    break;
                case 'consumption':
                    $w = self::consumptionWeights($eligible);
                    if (array_sum($w) <= 0) {
                        $result['warnings'][] = "«{$title}»: δεν υπάρχουν καταναλώσεις· η δαπάνη μοιράστηκε με τα χιλιοστά θέρμανσης.";
                        $w = self::millWeights($eligible, $keyId ?: $heatKey);
                    }
                    $shares = self::allocate($amount, $w);
                    break;
                case 'heating':
                    $key = $keyId ?: $heatKey;
                    $mills = self::millWeights($eligible, $key);
                    $cons = self::consumptionWeights($eligible);
                    if ($heatMode === 'mills' || $fixedPct >= 100) {
                        $shares = self::allocate($amount, $mills);
                    } elseif (array_sum($cons) <= 0) {
                        $result['warnings'][] = "«{$title}»: δεν υπάρχουν μετρήσεις κατανάλωσης· η δαπάνη μοιράστηκε ολόκληρη με τα χιλιοστά θέρμανσης.";
                        $shares = self::allocate($amount, $mills);
                    } else {
                        $fixed = (int) round($amount * $fixedPct / 100);
                        $a = self::allocate($fixed, $mills);
                        $b = self::allocate($amount - $fixed, $cons);
                        $shares = $a;
                        foreach ($b as $id => $v) {
                            $shares[$id] = ($shares[$id] ?? 0) + $v;
                        }
                        if ($fixed !== 0 && array_sum($mills) <= 0) {
                            $result['warnings'][] = "«{$title}»: δεν υπάρχουν χιλιοστά θέρμανσης για το πάγιο μέρος.";
                        }
                    }
                    break;
                case 'custom':
                    foreach ((array) ($e['custom'] ?? []) as $uid => $v) {
                        $uid = (int) $uid;
                        $c = self::cents($v);
                        if (isset($byId[$uid]) && $c !== 0) {
                            $shares[$uid] = $c;
                        }
                    }
                    if (array_sum($shares) !== $amount) {
                        $result['warnings'][] = sprintf('«%s»: τα συγκεκριμένα ποσά αθροίζουν %s αντί για %s.', $title, Fmt::money(array_sum($shares) / 100), Fmt::money($amount / 100));
                    }
                    break;
                case 'key':
                default:
                    $shares = self::allocate($amount, self::millWeights($eligible, $keyId));
                    break;
            }

            $allocated = array_sum($shares);
            if ($amount !== 0 && $allocated === 0 && $method !== 'custom') {
                $result['warnings'][] = "«{$title}»: δεν κατανεμήθηκε (καμία ιδιοκτησία με χιλιοστά/βάρος στη συγκεκριμένη κλείδα).";
            }
            $result['expenses'][$eid] = ['amount' => $amount, 'allocated' => $allocated, 'category' => $category, 'payer' => $payer];
            $column = $payer === 'owner' ? 'owner' : $category;
            foreach ($shares as $uid => $c) {
                if (!isset($result['units'][$uid])) {
                    continue;
                }
                $row = &$result['units'][$uid];
                $row['expenses'][$eid] = ($row['expenses'][$eid] ?? 0) + $c;
                $row[$column] += $c;
                if ($payer === 'tenant') {
                    $row['tenant'] += $c;
                }
                $row['total'] += $c;
                unset($row);
                $result['totals'][$column] += $c;
                if ($payer === 'tenant') {
                    $result['totals']['tenant'] += $c;
                }
                $result['totals']['total'] += $c;
            }
        }
        return $result;
    }

    private static function millWeights(array $units, int $keyId): array
    {
        $w = [];
        foreach ($units as $id => $u) {
            $w[$id] = (float) ($u['mills'][$keyId] ?? 0);
        }
        return $w;
    }

    private static function consumptionWeights(array $units): array
    {
        $w = [];
        foreach ($units as $id => $u) {
            $coef = isset($u['heat_coef']) && $u['heat_coef'] !== '' ? (float) $u['heat_coef'] : 1.0;
            $w[$id] = max(0.0, (float) ($u['consumption'] ?? 0)) * $coef;
        }
        return $w;
    }

    /**
     * Υπολογισμός καυσίμου: (αρχικό απόθεμα + αγορές − τελικό απόθεμα) × μέση τιμή.
     * Η μέση τιμή σταθμίζει την τιμή του αρχικού αποθέματος με τις αγορές της περιόδου.
     */
    public static function fuel(array $h): array
    {
        $start = max(0.0, self::parseNumber($h['start_qty'] ?? 0));
        $startPrice = max(0.0, self::parseNumber($h['start_price'] ?? 0));
        $bought = max(0.0, self::parseNumber($h['bought_qty'] ?? 0));
        $boughtAmount = max(0.0, self::parseNumber($h['bought_amount'] ?? 0));
        $end = max(0.0, self::parseNumber($h['end_qty'] ?? 0));
        $available = $start + $bought;
        $consumed = max(0.0, $available - $end);
        $avg = $available > 0 ? ($start * $startPrice + $boughtAmount) / $available : 0.0;
        return [
            'start' => $start,
            'bought' => $bought,
            'end' => $end,
            'consumed' => round($consumed, 3),
            'price' => round($avg, 4),
            'cost' => round($consumed * $avg, 2),
            'invalid' => $end > $available,
        ];
    }
}
