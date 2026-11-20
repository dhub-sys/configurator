<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/** Υπογεγραμμένοι σύνδεσμοι ειδοποιητηρίων για τους ενοίκους (χωρίς σύνδεση). */
final class Notice
{
    public static function token(int $pid, int $uid): string
    {
        $secret = (string) Factory::getApplication()->get('secret');
        return substr(hash_hmac('sha256', 'notice:' . $pid . ':' . $uid, $secret), 0, 40);
    }

    public static function link(int $pid, int $uid): string
    {
        return Url::to('view=notice&p=' . $pid . '&u=' . $uid . '&t=' . self::token($pid, $uid));
    }

    public static function valid(int $pid, int $uid, string $t): bool
    {
        return $pid > 0 && $uid > 0 && hash_equals(self::token($pid, $uid), $t);
    }
}
