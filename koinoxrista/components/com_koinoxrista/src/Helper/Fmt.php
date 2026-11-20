<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

/** Μορφοποίηση αριθμών, ημερομηνιών και κειμένου (καθαρή PHP). */
final class Fmt
{
    public const MONTHS = [1 => 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];
    public const MONTHS_GEN = [1 => 'Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function money($v, bool $blankZero = false): string
    {
        $v = (float) $v;
        if ($blankZero && abs($v) < 0.005) {
            return '';
        }
        return number_format($v, 2, ',', '.');
    }

    public static function moneyCents(int $c, bool $blankZero = false): string
    {
        return self::money($c / 100, $blankZero);
    }

    public static function euro($v): string
    {
        return self::money($v) . ' €';
    }

    /** Χιλιοστά: έως 3 δεκαδικά, χωρίς άσκοπα μηδενικά. */
    public static function mills($v, bool $blankZero = false): string
    {
        $v = (float) $v;
        if ($blankZero && abs($v) < 0.0005) {
            return '';
        }
        $s = number_format($v, 3, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');
        return $s === '-0' ? '0' : $s;
    }

    public static function qty($v, int $dec = 2): string
    {
        $s = number_format((float) $v, $dec, ',', '.');
        return $dec > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    public static function date(?string $ymd): string
    {
        if (!$ymd || $ymd === '0000-00-00' || strlen($ymd) < 10) {
            return '';
        }
        return substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }

    /** «dd/mm/yyyy» ή «yyyy-mm-dd» → «yyyy-mm-dd» (ή null). */
    public static function toYmd(?string $s): ?string
    {
        $s = trim((string) $s);
        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $s;
        }
        if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    public static function period(int $month, int $year): string
    {
        return (self::MONTHS[$month] ?? '') . ' ' . $year;
    }

    /** Κεφαλαία χωρίς τόνους (για επικεφαλίδες εντύπων). */
    public static function upper(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        return strtr($s, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'ΐ' => 'Ϊ', 'ΰ' => 'Ϋ']);
    }

    public static function address(object|array $b): string
    {
        $b = (object) $b;
        $line = trim(trim((string) ($b->street ?? '')) . ' ' . trim((string) ($b->street_no ?? '')));
        $parts = array_filter([$line, trim((string) ($b->area ?? '')), trim(trim((string) ($b->postcode ?? '')) . ' ' . trim((string) ($b->city ?? '')))]);
        return implode(', ', $parts);
    }
}
