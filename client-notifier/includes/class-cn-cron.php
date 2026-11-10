<?php
if (!defined('ABSPATH')) exit;

final class CN_Cron {
    const REMINDERS_HOOK = 'cn_check_renewals';
    const QUEUE_HOOK = 'cn_process_queue';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
        add_action(self::REMINDERS_HOOK, [__CLASS__, 'queue_renewal_reminders']);
        add_action(self::QUEUE_HOOK, [__CLASS__, 'process_queue']);
        // Re-create the events if something removed them.
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function schedules($s) {
        $s['cn_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Κάθε 5 λεπτά (Client Notifier)'];
        return $s;
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::REMINDERS_HOOK)) wp_schedule_event(time() + 60, 'hourly', self::REMINDERS_HOOK);
        if (!wp_next_scheduled(self::QUEUE_HOOK)) wp_schedule_event(time() + 120, 'cn_five_minutes', self::QUEUE_HOOK);
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::REMINDERS_HOOK);
        wp_clear_scheduled_hook(self::QUEUE_HOOK);
    }

    public static function process_queue() {
        CN_Mailer::process_queue();
    }

    /**
     * For each active service with automatic reminders, queues the reminder whose threshold
     * has been reached most recently. Each (service, expiry date, threshold) is queued once,
     * thanks to the unique ref, so the hourly run never duplicates and a missed day catches up
     * with a single message instead of several.
     */
    public static function queue_renewal_reminders() {
        global $wpdb;
        $offsets = CN_Settings::reminder_days();
        if (!$offsets) return 0;
        $today = CN_Util::today();
        $from = CN_Util::add_days($today, min($offsets));
        $to = CN_Util::add_days($today, max($offsets));
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT s.id FROM ' . CN_DB::t('services') . ' s JOIN ' . CN_DB::t('clients') . " c ON c.id = s.client_id
             WHERE s.status = 'active' AND s.auto_remind = 1 AND c.status = 'active'
               AND s.expiry_date IS NOT NULL AND s.expiry_date BETWEEN %s AND %s", $from, $to));
        $queued = 0;
        foreach ($ids as $id) {
            $service = CN_DB::service($id);
            $days = CN_Util::days_until($service->expiry_date);
            $reached = array_filter($offsets, function ($o) use ($days) { return $o >= $days; });
            if (!$reached) continue;
            $offset = min($reached);
            $ref = sprintf('renewal:%d:%s:%d', $service->id, $service->expiry_date, $offset);
            if (CN_Mailer::queue_renewal($service, $ref)) $queued++;
        }
        return $queued;
    }
}
