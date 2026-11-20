<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** Λεπτό περιτύλιγμα της βάσης. Οι τιμές περνούν πάντα από quote()/cast. */
final class Db
{
    public static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    public static function q($v): string
    {
        return self::db()->quote((string) $v);
    }

    /** Λίστα ακεραίων για IN (...) — ποτέ κενή. */
    public static function ids(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids ? implode(',', $ids) : '0';
    }

    public static function all(string $sql): array
    {
        return self::db()->setQuery($sql)->loadObjectList() ?: [];
    }

    public static function one(string $sql): ?object
    {
        return self::db()->setQuery($sql)->loadObject() ?: null;
    }

    public static function value(string $sql)
    {
        return self::db()->setQuery($sql)->loadResult();
    }

    public static function column(string $sql): array
    {
        return self::db()->setQuery($sql)->loadColumn() ?: [];
    }

    public static function exec(string $sql): void
    {
        self::db()->setQuery($sql)->execute();
    }

    public static function insert(string $table, array $data): int
    {
        $o = (object) $data;
        self::db()->insertObject($table, $o, 'id');
        return (int) ($o->id ?? self::db()->insertid());
    }

    /** Εισαγωγή σε πίνακα χωρίς στήλη id. */
    public static function insertPlain(string $table, array $data): void
    {
        $o = (object) $data;
        self::db()->insertObject($table, $o);
    }

    public static function update(string $table, int $id, array $data): void
    {
        $data['id'] = $id;
        $o = (object) $data;
        self::db()->updateObject($table, $o, 'id', true);
    }

    public static function now(): string
    {
        return Factory::getDate()->toSql();
    }

    public static function today(): string
    {
        return Factory::getDate('now', Factory::getApplication()->get('offset', 'UTC'))->format('Y-m-d', true);
    }

    public static function log(string $action, string $message = ''): void
    {
        try {
            self::insert('#__kx_log', [
                'account_id' => Auth::accountId(), 'user_id' => Auth::userId(),
                'action' => mb_substr($action, 0, 60), 'message' => mb_substr($message, 0, 500), 'created' => self::now(),
            ]);
        } catch (\Throwable $e) {
            // Το ιστορικό δεν πρέπει ποτέ να εμποδίζει μια ενέργεια.
        }
    }
}
