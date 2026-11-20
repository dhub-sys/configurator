<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;

/** URLs της εφαρμογής: πάντα tmpl=component (πλήρης οθόνη, χωρίς το template του site). */
final class Url
{
    public static function to(string $query = '', bool $component = true): string
    {
        $q = 'option=com_koinoxrista' . ($component ? '&tmpl=component' : '') . ($query !== '' ? '&' . ltrim($query, '&') : '');
        $itemid = (int) Settings::get('itemid');
        if ($itemid > 0) $q .= '&Itemid=' . $itemid;
        return rtrim(Uri::root(), '/') . '/index.php?' . $q;
    }

    public static function e(string $query = ''): string
    {
        return htmlspecialchars(self::to($query), ENT_QUOTES, 'UTF-8');
    }
}
