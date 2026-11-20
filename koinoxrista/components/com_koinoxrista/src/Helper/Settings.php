<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Ρυθμίσεις πλατφόρμας (του ιδιοκτήτη του SaaS), αποθηκευμένες στα params του component.
 * Διαβάζονται απευθείας από τη βάση, ώστε μια αλλαγή να φαίνεται αμέσως.
 */
final class Settings
{
    public const DEFAULTS = [
        'brand_name' => 'Κοινόχρηστα',
        'brand_tagline' => 'Διαχείριση κοινοχρήστων πολυκατοικιών',
        'brand_logo' => '',
        'brand_color' => '#1d4ed8',
        'signup_enabled' => 1,
        'signup_package_id' => 0,
        'otp_enabled' => 0,
        'support_email' => '',
        'support_phone' => '',
        'vat_rate' => 24,
        'payment_info' => '',
        'terms_url' => '',
        'itemid' => 0,
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $raw = (string) Db::value("SELECT params FROM #__extensions WHERE type='component' AND element='com_koinoxrista'");
            $params = json_decode($raw ?: '{}', true) ?: [];
            self::$cache = array_merge(self::DEFAULTS, array_intersect_key($params, self::DEFAULTS));
        }
        return self::$cache;
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function save(array $values): void
    {
        $raw = (string) Db::value("SELECT params FROM #__extensions WHERE type='component' AND element='com_koinoxrista'");
        $params = json_decode($raw ?: '{}', true) ?: [];
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $params[$k] = $v;
            }
        }
        Db::exec('UPDATE #__extensions SET params=' . Db::q((new Registry($params))->toString()) . " WHERE type='component' AND element='com_koinoxrista'");
        self::$cache = null;
    }

    /** Δημόσιο URL ενός αρχείου (σχετική διαδρομή από τη ρίζα του site ή πλήρες URL). */
    public static function asset(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, 'data:')) return $path;
        return rtrim(Uri::root(), '/') . '/' . ltrim($path, '/');
    }
}
