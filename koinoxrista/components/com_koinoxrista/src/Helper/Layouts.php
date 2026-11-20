<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

/**
 * Κατάλογος πεδίων, έτοιμα πρότυπα εκτύπωσης και δείγμα δεδομένων για τον σχεδιαστή.
 * Καθαρή PHP (χωρίς Joomla).
 */
final class Layouts
{
    public const TYPES = [
        'sheet' => 'Κατάσταση κοινοχρήστων (πίνακας κτιρίου)',
        'notice' => 'Ειδοποιητήρια ανά ιδιοκτησία',
    ];

    /** Πεδία κεφαλίδας: διαθέσιμα σε όλα τα πρότυπα. */
    public static function headerFields(): array
    {
        return [
            'Διαχειριστική εταιρεία' => [
                'account.name' => 'Επωνυμία', 'account.address' => 'Διεύθυνση', 'account.phone' => 'Τηλέφωνο',
                'account.email' => 'Email', 'account.website' => 'Website', 'account.afm' => 'ΑΦΜ', 'account.contact' => 'Τηλέφωνο · email (μία γραμμή)',
            ],
            'Κτίριο' => [
                'building.title' => 'Ονομασία', 'building.address' => 'Πλήρης διεύθυνση', 'building.street' => 'Οδός & αριθμός',
                'building.area' => 'Περιοχή', 'building.city' => 'Πόλη', 'building.postcode' => 'Τ.Κ.', 'building.afm' => 'ΑΦΜ κτιρίου',
                'building.iban' => 'IBAN', 'building.bank' => 'Τράπεζα', 'building.beneficiary' => 'Δικαιούχος λογαριασμού',
                'building.units_count' => 'Πλήθος ιδιοκτησιών',
            ],
            'Διαχειριστής' => [
                'manager.name' => 'Ονοματεπώνυμο', 'manager.phone' => 'Τηλέφωνο', 'manager.mobile' => 'Κινητό', 'manager.email' => 'Email',
                'manager.address' => 'Διεύθυνση', 'manager.hours' => 'Ώρες επικοινωνίας', 'manager.contact' => 'Πλήρης γραμμή επικοινωνίας',
                'rep.name' => 'Εκπρόσωπος κτιρίου', 'rep.phone' => 'Τηλέφωνο εκπροσώπου',
            ],
            'Περίοδος' => [
                'period.title' => 'Μήνας (π.χ. Οκτώβριος 2026)', 'period.month' => 'Μήνας', 'period.year' => 'Έτος',
                'period.issue_date' => 'Ημερομηνία έκδοσης', 'period.due_date' => 'Πληρωμή έως', 'period.notes' => 'Ανακοινώσεις',
                'print.date' => 'Ημερομηνία εκτύπωσης', 'page.no' => 'Αριθμός σελίδας', 'page.count' => 'Σύνολο σελίδων',
            ],
            'Σύνολα κτιρίου' => [
                'totals.general' => 'Σύνολο κοινοχρήστων', 'totals.elevator' => 'Σύνολο ανελκυστήρα', 'totals.heating' => 'Σύνολο θέρμανσης',
                'totals.special' => 'Σύνολο ειδικών', 'totals.owner' => 'Σύνολο ιδιοκτητών', 'totals.current' => 'Γενικό σύνολο μήνα',
                'totals.expenses' => 'Σύνολο δαπανών', 'totals.mills.general' => 'Σύνολο χιλ. γενικών', 'totals.mills.elevator' => 'Σύνολο χιλ. ανελκυστήρα',
                'totals.mills.heating' => 'Σύνολο χιλ. θέρμανσης',
            ],
            'Θέρμανση' => [
                'heating.start' => 'Αρχικό απόθεμα', 'heating.bought' => 'Αγορές (ποσότητα)', 'heating.end' => 'Τελικό απόθεμα',
                'heating.consumed' => 'Κατανάλωση', 'heating.price' => 'Μέση τιμή', 'heating.cost' => 'Κόστος καυσίμου',
                'heating.fixed_pct' => 'Ποσοστό παγίου %', 'heating.unit' => 'Μονάδα (lt, m³…)',
            ],
        ];
    }

    /** Πεδία γραμμής ιδιοκτησίας (στήλες πίνακα κτιρίου ή πεδία ειδοποιητηρίου). */
    public static function unitFields(array $keys = []): array
    {
        $f = [
            'unit.row_no' => 'Α/Α', 'unit.code' => 'Κωδικός ιδιοκτησίας', 'unit.floor' => 'Όροφος', 'unit.label' => 'Ιδιοκτησία & όροφος',
            'unit.kind' => 'Είδος', 'unit.area' => 'Τ.μ.', 'unit.payer_name' => 'Ονοματεπώνυμο υπόχρεου', 'unit.owner_name' => 'Ιδιοκτήτης',
            'unit.tenant_name' => 'Ένοικος', 'unit.consumption' => 'Κατανάλωση θέρμανσης', 'unit.heat_coef' => 'Συντελεστής θέρμανσης',
            'unit.mills_line' => 'Χιλιοστά (μία γραμμή)',
            'amt.general' => 'Κοινόχρηστα', 'amt.elevator' => 'Ανελκυστήρας', 'amt.heating' => 'Θέρμανση', 'amt.special' => 'Ειδικές δαπάνες',
            'amt.owner' => 'Δαπάνες ιδιοκτήτη', 'amt.tenant' => 'Σύνολο ενοίκου', 'amt.current' => 'Σύνολο μήνα',
            'amt.previous' => 'Προηγούμενο υπόλοιπο', 'amt.total' => 'Πληρωτέο ποσό',
        ];
        $keys = $keys ?: ['general' => 'Γενικά', 'elevator' => 'Ανελκυστήρας', 'heating' => 'Θέρμανση'];
        foreach ($keys as $code => $title) {
            $f['mills.' . $code] = 'Χιλιοστά: ' . $title;
        }
        return $f;
    }

    public static function expenseFields(): array
    {
        return [
            'exp.no' => 'Α/Α', 'exp.title' => 'Περιγραφή', 'exp.category' => 'Κατηγορία', 'exp.amount' => 'Ποσό',
            'exp.supplier' => 'Προμηθευτής', 'exp.doc' => 'Παραστατικό', 'exp.method' => 'Τρόπος κατανομής', 'exp.payer' => 'Βαρύνει',
        ];
    }

    public static function unitExpenseFields(): array
    {
        return ['uexp.title' => 'Περιγραφή δαπάνης', 'uexp.category' => 'Κατηγορία', 'uexp.amount' => 'Ποσό δαπάνης', 'uexp.share' => 'Μερίδιο ιδιοκτησίας'];
    }

    public static function images(): array
    {
        return ['account.logo' => 'Λογότυπο διαχειριστικής εταιρείας'];
    }

    // ------------------------------------------------------------------ έτοιμα πρότυπα

    private static function t(string $text, float $x, float $y, array $o = []): array
    {
        return array_merge(['type' => 'text', 'text' => $text, 'x' => $x, 'y' => $y, 'layer' => 'form', 'size' => 8], $o);
    }

    private static function f(string $field, float $x, float $y, float $w, array $o = []): array
    {
        return array_merge(['type' => 'field', 'field' => $field, 'x' => $x, 'y' => $y, 'w' => $w, 'layer' => 'data', 'size' => 9], $o);
    }

    private static function line(float $x, float $y, float $w, float $h = 0, array $o = []): array
    {
        return array_merge(['type' => 'line', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'layer' => 'form', 'stroke' => 0.3], $o);
    }

    /** Κατάσταση κοινοχρήστων A4 κάθετη: 26 ιδιοκτησίες ανά σελίδα. */
    public static function sheet(): array
    {
        $cols = [
            ['field' => 'unit.code', 'title' => 'Διαμ.', 'w' => 12, 'align' => 'left'],
            ['field' => 'unit.floor', 'title' => 'Όροφ.', 'w' => 10, 'align' => 'center'],
            ['field' => 'unit.payer_name', 'title' => 'Ονοματεπώνυμο', 'w' => 40, 'align' => 'left'],
            ['field' => 'mills.general', 'title' => "Χιλ.\nΚοιν.", 'w' => 13, 'align' => 'right'],
            ['field' => 'mills.elevator', 'title' => "Χιλ.\nΑνελκ.", 'w' => 13, 'align' => 'right'],
            ['field' => 'mills.heating', 'title' => "Χιλ.\nΘέρμ.", 'w' => 13, 'align' => 'right'],
            ['field' => 'amt.general', 'title' => 'Κοινόχρ.', 'w' => 15, 'align' => 'right'],
            ['field' => 'amt.elevator', 'title' => 'Ανελκ.', 'w' => 14, 'align' => 'right'],
            ['field' => 'amt.heating', 'title' => 'Θέρμανση', 'w' => 15, 'align' => 'right'],
            ['field' => 'amt.special', 'title' => 'Ειδικές', 'w' => 14, 'align' => 'right'],
            ['field' => 'amt.owner', 'title' => "Ιδιο-\nκτήτη", 'w' => 14, 'align' => 'right'],
            ['field' => 'amt.current', 'title' => 'Σύνολο', 'w' => 17, 'align' => 'right', 'bold' => true],
        ];
        $el = [
            ['type' => 'image', 'field' => 'account.logo', 'x' => 10, 'y' => 9, 'w' => 34, 'h' => 15, 'layer' => 'form'],
            self::f('account.name', 47, 10, 75, ['layer' => 'form', 'size' => 10, 'bold' => true]),
            self::f('account.contact', 47, 15.5, 75, ['layer' => 'form', 'size' => 7.5]),
            self::f('account.address', 47, 19.5, 75, ['layer' => 'form', 'size' => 7.5]),
            self::t('ΚΑΤΑΣΤΑΣΗ ΚΟΙΝΟΧΡΗΣΤΩΝ', 120, 9.5, ['w' => 80, 'align' => 'right', 'size' => 14, 'bold' => true]),
            self::f('period.title', 120, 16.5, 80, ['align' => 'right', 'size' => 12, 'bold' => true]),
            self::line(10, 27, 190, 0, ['stroke' => 0.5]),
            self::t('Κτίριο:', 10, 30), self::f('building.title', 30, 29.7, 100, ['bold' => true]),
            self::t('Διεύθυνση:', 10, 35), self::f('building.address', 30, 34.7, 100),
            self::t('Ημ. έκδοσης:', 140, 30), self::f('period.issue_date', 170, 29.7, 30, ['align' => 'right']),
            self::t('Πληρωμή έως:', 140, 35), self::f('period.due_date', 170, 34.7, 30, ['align' => 'right', 'bold' => true]),
            [
                'type' => 'table', 'source' => 'units', 'x' => 10, 'y' => 51, 'row_h' => 6, 'rows' => 26, 'size' => 8,
                'header_h' => 8, 'header_size' => 7, 'header_fill' => '#ececec', 'grid' => true, 'stroke' => 0.25, 'columns' => $cols,
            ],
            ['type' => 'box', 'x' => 10, 'y' => 207, 'w' => 190, 'h' => 6.5, 'layer' => 'form', 'stroke' => 0.4, 'fill' => '#f3f3f3'],
            self::t('ΣΥΝΟΛΑ', 12, 208.2, ['bold' => true]),
            self::f('totals.mills.general', 72, 208.1, 13, ['align' => 'right', 'size' => 8]),
            self::f('totals.mills.elevator', 85, 208.1, 13, ['align' => 'right', 'size' => 8]),
            self::f('totals.mills.heating', 98, 208.1, 13, ['align' => 'right', 'size' => 8]),
            self::f('totals.general', 111, 208.1, 15, ['align' => 'right', 'size' => 8]),
            self::f('totals.elevator', 126, 208.1, 14, ['align' => 'right', 'size' => 8]),
            self::f('totals.heating', 140, 208.1, 15, ['align' => 'right', 'size' => 8]),
            self::f('totals.special', 155, 208.1, 14, ['align' => 'right', 'size' => 8]),
            self::f('totals.owner', 169, 208.1, 14, ['align' => 'right', 'size' => 8]),
            self::f('totals.current', 183, 208.1, 17, ['align' => 'right', 'size' => 8, 'bold' => true]),
            self::t('ΑΝΑΛΥΣΗ ΔΑΠΑΝΩΝ', 10, 217, ['bold' => true, 'size' => 9]),
            [
                'type' => 'table', 'source' => 'expenses', 'x' => 10, 'y' => 227, 'row_h' => 5, 'rows' => 10, 'size' => 7.5,
                'header_h' => 5, 'header_size' => 7, 'header_fill' => '#ececec', 'grid' => true, 'stroke' => 0.2,
                'columns' => [
                    ['field' => 'exp.no', 'title' => 'Α/Α', 'w' => 8, 'align' => 'center'],
                    ['field' => 'exp.title', 'title' => 'Περιγραφή', 'w' => 66, 'align' => 'left'],
                    ['field' => 'exp.category', 'title' => 'Κατηγορία', 'w' => 26, 'align' => 'left'],
                    ['field' => 'exp.amount', 'title' => 'Ποσό €', 'w' => 18, 'align' => 'right'],
                ],
            ],
            self::t('Σύνολο δαπανών:', 74, 278, ['w' => 34, 'align' => 'right', 'bold' => true]),
            self::f('totals.expenses', 110, 277.7, 18, ['align' => 'right', 'bold' => true, 'size' => 8.5]),
            ['type' => 'box', 'x' => 132, 'y' => 216, 'w' => 68, 'h' => 36, 'layer' => 'form', 'stroke' => 0.25],
            self::t('ΑΝΑΚΟΙΝΩΣΕΙΣ', 134, 217.5, ['bold' => true]),
            self::f('period.notes', 134, 222, 64, ['h' => 29, 'wrap' => true, 'size' => 7.5]),
            self::t('ΔΙΑΧΕΙΡΙΣΤΗΣ', 132, 255, ['bold' => true]),
            self::f('manager.name', 132, 259.5, 68, ['size' => 8, 'bold' => true]),
            self::f('manager.phone', 132, 263.5, 68, ['size' => 7.5, 'prefix' => 'Τηλ. ', 'hide_empty' => true]),
            self::f('manager.email', 132, 267.2, 68, ['size' => 7.5]),
            self::f('manager.hours', 132, 270.9, 68, ['size' => 7.5]),
            self::f('building.iban', 132, 275, 68, ['size' => 7.5, 'prefix' => 'IBAN: ', 'hide_empty' => true]),
            self::line(10, 286, 190),
            self::f('print.date', 10, 287.5, 60, ['size' => 7, 'prefix' => 'Εκτύπωση: ', 'layer' => 'form']),
            self::f('page.no', 150, 287.5, 50, ['size' => 7, 'align' => 'right', 'prefix' => 'Σελίδα ', 'layer' => 'form']),
        ];
        return ['orientation' => 'portrait', 'font' => 'Arial', 'offset_x' => 0, 'offset_y' => 0, 'per_page' => 1, 'elements' => self::ids($el)];
    }

    /** Ειδοποιητήρια: 2 ανά σελίδα A4, με γραμμή κοπής. */
    public static function notice(): array
    {
        $amounts = [
            ['Κοινόχρηστα', 'amt.general'], ['Ανελκυστήρας', 'amt.elevator'], ['Θέρμανση', 'amt.heating'],
            ['Ειδικές δαπάνες', 'amt.special'], ['Δαπάνες ιδιοκτήτη', 'amt.owner'], ['Σύνολο μήνα', 'amt.current'],
            ['Προηγούμενο υπόλοιπο', 'amt.previous'],
        ];
        $el = [
            ['type' => 'image', 'field' => 'account.logo', 'x' => 10, 'y' => 8, 'w' => 30, 'h' => 13, 'layer' => 'form'],
            self::f('account.name', 43, 8.5, 75, ['layer' => 'form', 'size' => 10, 'bold' => true]),
            self::f('account.contact', 43, 13.5, 75, ['layer' => 'form', 'size' => 7.5]),
            self::t('ΕΙΔΟΠΟΙΗΤΗΡΙΟ ΚΟΙΝΟΧΡΗΣΤΩΝ', 115, 8, ['w' => 85, 'align' => 'right', 'size' => 12, 'bold' => true]),
            self::f('period.title', 115, 14, 85, ['align' => 'right', 'size' => 11, 'bold' => true]),
            self::line(10, 23, 190, 0, ['stroke' => 0.5]),
            self::t('Κτίριο:', 10, 27), self::f('building.title', 33, 26.7, 72, ['bold' => true]),
            self::t('Διεύθυνση:', 10, 32), self::f('building.address', 33, 31.8, 72, ['size' => 8]),
            self::t('Ιδιοκτησία:', 10, 37), self::f('unit.label', 33, 36.7, 72, ['bold' => true]),
            self::t('Υπόχρεος:', 10, 42), self::f('unit.payer_name', 33, 41.7, 72, ['bold' => true]),
            self::t('Χιλιοστά:', 10, 47), self::f('unit.mills_line', 33, 46.9, 72, ['size' => 7.5]),
            [
                'type' => 'table', 'source' => 'unit_expenses', 'x' => 10, 'y' => 59, 'row_h' => 4.6, 'rows' => 10, 'size' => 7.5,
                'header_h' => 5, 'header_size' => 7, 'header_fill' => '#ececec', 'grid' => true, 'stroke' => 0.2, 'paginate' => false,
                'columns' => [
                    ['field' => 'uexp.title', 'title' => 'Ανάλυση δαπανών', 'w' => 54, 'align' => 'left'],
                    ['field' => 'uexp.amount', 'title' => 'Δαπάνη €', 'w' => 20, 'align' => 'right'],
                    ['field' => 'uexp.share', 'title' => 'Αναλογεί €', 'w' => 21, 'align' => 'right'],
                ],
            ],
            ['type' => 'box', 'x' => 112, 'y' => 26, 'w' => 88, 'h' => 48, 'layer' => 'form', 'stroke' => 0.3],
        ];
        $y = 27.5;
        foreach ($amounts as [$label, $field]) {
            $bold = $field === 'amt.current';
            $el[] = self::t($label, 114, $y + 0.3, ['bold' => $bold]);
            $el[] = self::f($field, 160, $y, 38, ['align' => 'right', 'bold' => $bold]);
            $y += 5.2;
        }
        $el[] = self::line(112, 64.5, 88, 0, ['stroke' => 0.4]);
        $el[] = ['type' => 'box', 'x' => 112, 'y' => 65, 'w' => 88, 'h' => 9, 'layer' => 'form', 'stroke' => 0.01, 'fill' => '#ececec'];
        $el[] = self::t('ΠΛΗΡΩΤΕΟ ΠΟΣΟ €', 114, 67.3, ['bold' => true, 'size' => 9.5]);
        $el[] = self::f('amt.total', 150, 66.6, 48, ['align' => 'right', 'bold' => true, 'size' => 12]);
        $el[] = self::t('Πληρωμή έως:', 112, 78);
        $el[] = self::f('period.due_date', 135, 77.7, 40, ['bold' => true]);
        $el[] = self::f('building.iban', 112, 83, 88, ['size' => 8, 'prefix' => 'IBAN: ', 'hide_empty' => true]);
        $el[] = self::f('building.beneficiary', 112, 87.5, 88, ['size' => 7.5, 'prefix' => 'Δικαιούχος: ', 'hide_empty' => true]);
        $el[] = self::f('period.notes', 112, 93, 88, ['h' => 17, 'wrap' => true, 'size' => 7.5]);
        $el[] = self::line(10, 113, 190, 0, ['stroke' => 0.2]);
        $el[] = self::f('manager.contact', 10, 114.5, 190, ['size' => 7.5, 'prefix' => 'Διαχειριστής: ', 'hide_empty' => true]);
        $el[] = self::line(0, 148.5, 210, 0, ['stroke' => 0.2, 'dashed' => true, 'color' => '#888']);
        return ['orientation' => 'portrait', 'font' => 'Arial', 'offset_x' => 0, 'offset_y' => 0, 'per_page' => 2, 'pitch' => 148.5, 'elements' => self::ids($el)];
    }

    private static function ids(array $el): array
    {
        foreach ($el as $i => &$e) {
            $e['id'] = 'e' . ($i + 1);
        }
        return $el;
    }

    /** Καθαρίζει ένα layout που ήρθε από τον σχεδιαστή (τιμές, τύποι, όρια). */
    public static function sanitize($layout): array
    {
        if (!is_array($layout)) {
            return ['orientation' => 'portrait', 'elements' => []];
        }
        $num = static fn($v, $min, $max) => max($min, min($max, round((float) $v, 2)));
        $str = static fn($v, $len = 300) => mb_substr(trim((string) $v), 0, $len);
        $out = [
            'orientation' => ($layout['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
            'font' => preg_replace('~[^A-Za-z0-9 \'-]~', '', (string) ($layout['font'] ?? 'Arial')) ?: 'Arial',
            'offset_x' => $num($layout['offset_x'] ?? 0, -30, 30),
            'offset_y' => $num($layout['offset_y'] ?? 0, -30, 30),
            'per_page' => (int) $num($layout['per_page'] ?? 1, 1, 4),
            'pitch' => $num($layout['pitch'] ?? 148.5, 20, 297),
            'bg_opacity' => $num($layout['bg_opacity'] ?? 0.45, 0, 1),
            'elements' => [],
        ];
        $types = ['field', 'text', 'line', 'box', 'image', 'table'];
        foreach (array_slice((array) ($layout['elements'] ?? []), 0, 400) as $i => $e) {
            if (!is_array($e) || !in_array($e['type'] ?? '', $types, true)) continue;
            $c = [
                'id' => preg_replace('~[^a-z0-9_-]~i', '', (string) ($e['id'] ?? '')) ?: 'e' . ($i + 1),
                'type' => $e['type'],
                'x' => $num($e['x'] ?? 0, -20, 320), 'y' => $num($e['y'] ?? 0, -20, 320),
                'w' => $num($e['w'] ?? 0, 0, 320), 'h' => $num($e['h'] ?? 0, 0, 320),
                'layer' => ($e['layer'] ?? 'data') === 'form' ? 'form' : 'data',
                'size' => $num($e['size'] ?? 9, 4, 48),
            ];
            foreach (['bold', 'italic', 'wrap', 'hide_empty', 'upper', 'dashed', 'grid'] as $flag) {
                if (!empty($e[$flag])) $c[$flag] = true;
            }
            foreach (['color', 'fill', 'header_fill'] as $col) {
                if (!empty($e[$col]) && preg_match('~^#[0-9a-fA-F]{3,8}$~', (string) $e[$col])) $c[$col] = $e[$col];
            }
            $c['align'] = in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'left';
            if (isset($e['stroke'])) $c['stroke'] = $num($e['stroke'], 0.01, 3);
            if (isset($e['radius'])) $c['radius'] = $num($e['radius'], 0, 20);
            if ($c['type'] === 'text') $c['text'] = $str($e['text'] ?? '', 2000);
            if (in_array($c['type'], ['field', 'image'], true)) {
                $c['field'] = preg_replace('~[^a-z0-9_.]~i', '', (string) ($e['field'] ?? ''));
                $c['prefix'] = mb_substr((string) ($e['prefix'] ?? ''), 0, 80);
                $c['suffix'] = mb_substr((string) ($e['suffix'] ?? ''), 0, 80);
            }
            if ($c['type'] === 'table') {
                $c['source'] = in_array($e['source'] ?? '', ['units', 'expenses', 'unit_expenses'], true) ? $e['source'] : 'units';
                $c['row_h'] = $num($e['row_h'] ?? 6, 2, 50);
                $c['rows'] = (int) $num($e['rows'] ?? 20, 1, 200);
                $c['header_h'] = $num($e['header_h'] ?? 0, 0, 40);
                $c['header_size'] = $num($e['header_size'] ?? $c['size'], 4, 30);
                $c['paginate'] = !isset($e['paginate']) || !empty($e['paginate']);
                $c['columns'] = [];
                foreach (array_slice((array) ($e['columns'] ?? []), 0, 40) as $col) {
                    if (!is_array($col)) continue;
                    $c['columns'][] = [
                        'field' => preg_replace('~[^a-z0-9_.]~i', '', (string) ($col['field'] ?? '')),
                        'title' => $str($col['title'] ?? '', 80),
                        'w' => $num($col['w'] ?? 10, 1, 300),
                        'align' => in_array($col['align'] ?? '', ['left', 'center', 'right'], true) ? $col['align'] : 'left',
                        'bold' => !empty($col['bold']),
                    ];
                }
            }
            $out['elements'][] = $c;
        }
        return $out;
    }

    /** Δείγμα δεδομένων για τον σχεδιαστή και την προεπισκόπηση προτύπων. */
    public static function sample(): array
    {
        $names = ['Παπαδόπουλος Γεώργιος', 'Ιωάννου Μαρία', 'Κωνσταντίνου Νίκος', 'Γεωργίου Ελένη', 'Δημητρίου Αλέξανδρος', 'Νικολάου Σοφία', 'Αθανασίου Πέτρος', 'Βασιλείου Κατερίνα', 'Χριστοδούλου Άννα', 'Μιχαηλίδης Σπύρος'];
        $floors = ['Ισόγ.', 'Α΄', 'Α΄', 'Β΄', 'Β΄', 'Γ΄', 'Γ΄', 'Δ΄', 'Δ΄', 'Ε΄'];
        $units = [];
        $mills = [72, 95, 98, 100, 102, 104, 106, 108, 110, 105];
        foreach ($names as $i => $n) {
            $g = $mills[$i] * 4.2;
            $units[] = [
                'unit.row_no' => (string) ($i + 1), 'unit.code' => ($i ? chr(64 + (int) ceil($i / 2)) . (2 - $i % 2) : 'Ι1'), 'unit.floor' => $floors[$i],
                'unit.label' => ($i ? chr(64 + (int) ceil($i / 2)) . (2 - $i % 2) : 'Ι1') . ' · ' . $floors[$i] . ' όροφος',
                'unit.payer_name' => $n, 'unit.owner_name' => $n, 'unit.tenant_name' => '', 'unit.kind' => 'Διαμέρισμα', 'unit.area' => (string) (60 + $i * 5),
                'mills.general' => Fmt::mills($mills[$i]), 'mills.elevator' => Fmt::mills($i ? $mills[$i] + 3 : 0, true), 'mills.heating' => Fmt::mills($mills[$i] - 1),
                'unit.mills_line' => 'Κοιν. ' . Fmt::mills($mills[$i]) . ' · Ανελκ. ' . Fmt::mills($i ? $mills[$i] + 3 : 0) . ' · Θέρμ. ' . Fmt::mills($mills[$i] - 1),
                'unit.consumption' => (string) (40 + $i * 7),
                'amt.general' => Fmt::money($g / 10), 'amt.elevator' => Fmt::money($i ? $mills[$i] * 0.08 : 0, true), 'amt.heating' => Fmt::money($mills[$i] * 0.9),
                'amt.special' => $i === 3 ? Fmt::money(25) : '', 'amt.owner' => Fmt::money($mills[$i] * 0.1), 'amt.tenant' => Fmt::money($g / 10 + $mills[$i]),
                'amt.current' => Fmt::money($g / 10 + $mills[$i] * 1.08), 'amt.previous' => $i === 4 ? Fmt::money(56.4) : Fmt::money(0),
                'amt.total' => Fmt::money($g / 10 + $mills[$i] * 1.08 + ($i === 4 ? 56.4 : 0)),
            ];
        }
        $exp = [
            ['ΔΕΗ κοινοχρήστων χώρων', 'Κοινόχρηστα', 86.4], ['ΕΥΔΑΠ', 'Κοινόχρηστα', 42.1], ['Καθαρισμός κλιμακοστασίου', 'Κοινόχρηστα', 120],
            ['Συντήρηση ανελκυστήρα', 'Ανελκυστήρας', 80], ['Πετρέλαιο θέρμανσης', 'Θέρμανση', 910.5], ['Αμοιβή διαχείρισης', 'Κοινόχρηστα', 60],
        ];
        $expenses = [];
        foreach ($exp as $i => [$t, $c, $a]) {
            $expenses[] = ['exp.no' => (string) ($i + 1), 'exp.title' => $t, 'exp.category' => $c, 'exp.amount' => Fmt::money($a), 'exp.supplier' => '', 'exp.doc' => '', 'exp.method' => 'Με χιλιοστά', 'exp.payer' => 'Ένοικος'];
        }
        $uexp = [];
        foreach ($units as $i => $u) {
            $uexp[$i] = array_map(static fn($e) => ['uexp.title' => $e['exp.title'], 'uexp.category' => $e['exp.category'], 'uexp.amount' => $e['exp.amount'], 'uexp.share' => Fmt::money(Calc::parseNumber($e['exp.amount']) * ($mills[$i] / 1000))], $expenses);
        }
        $fields = [
            'account.name' => 'Διαχειρίσεις Αττικής', 'account.address' => 'Λεωφ. Κηφισίας 100, 11526 Αθήνα', 'account.phone' => '210 1234567',
            'account.email' => 'info@example.gr', 'account.website' => 'www.example.gr', 'account.afm' => '099999999', 'account.contact' => '210 1234567 · info@example.gr',
            'building.title' => 'Πολυκατοικία Αγίου Νικολάου 12', 'building.address' => 'Αγίου Νικολάου 12, Παγκράτι, 11635 Αθήνα', 'building.street' => 'Αγίου Νικολάου 12',
            'building.area' => 'Παγκράτι', 'building.city' => 'Αθήνα', 'building.postcode' => '11635', 'building.afm' => '', 'building.iban' => 'GR16 0110 1250 0000 0001 2300 695',
            'building.bank' => 'Εθνική Τράπεζα', 'building.beneficiary' => 'Πολυκατοικία Αγ. Νικολάου 12', 'building.units_count' => '10',
            'manager.name' => 'Κώστας Παπαδάκης', 'manager.phone' => '210 1234567', 'manager.mobile' => '690 0000000', 'manager.email' => 'diaxeirisi@example.gr',
            'manager.address' => 'Λεωφ. Κηφισίας 100', 'manager.hours' => 'Δευτ.–Παρ. 09:00–17:00', 'manager.contact' => 'Κώστας Παπαδάκης · 210 1234567 · diaxeirisi@example.gr',
            'rep.name' => 'Ιωάννου Μαρία', 'rep.phone' => '697 1111111',
            'period.title' => 'Οκτώβριος 2026', 'period.month' => 'Οκτώβριος', 'period.year' => '2026', 'period.issue_date' => '05/11/2026', 'period.due_date' => '15/11/2026',
            'period.notes' => "Η γενική συνέλευση θα γίνει την Πέμπτη 20/11 στις 19:00 στην είσοδο.\nΠαρακαλούμε για την έγκαιρη εξόφληση.",
            'print.date' => date('d/m/Y'),
            'totals.general' => '420,00', 'totals.elevator' => '80,00', 'totals.heating' => '910,50', 'totals.special' => '25,00', 'totals.owner' => '100,00',
            'totals.current' => '1.535,50', 'totals.expenses' => '1.299,00', 'totals.mills.general' => '1.000', 'totals.mills.elevator' => '1.000', 'totals.mills.heating' => '1.000',
            'heating.start' => '500', 'heating.bought' => '1.000', 'heating.end' => '300', 'heating.consumed' => '1.200', 'heating.price' => '1,2000', 'heating.cost' => '1.440,00',
            'heating.fixed_pct' => '30', 'heating.unit' => 'lt',
        ];
        $logo = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="50"><rect x="1" y="1" width="118" height="48" rx="6" fill="none" stroke="#1e3a8a" stroke-width="2"/><text x="60" y="31" font-family="Arial" font-size="15" text-anchor="middle" fill="#1e3a8a">ΛΟΓΟΤΥΠΟ</text></svg>');
        return ['fields' => $fields, 'units' => $units, 'expenses' => $expenses, 'unit_expenses' => $uexp, 'images' => ['account.logo' => $logo]];
    }
}
