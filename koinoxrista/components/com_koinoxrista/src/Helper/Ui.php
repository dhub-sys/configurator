<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/** Κέλυφος της εφαρμογής (sidebar, κεφαλίδα, μηνύματα) και μικρά βοηθήματα φορμών. */
final class Ui
{
    private const ICONS = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'buildings' => '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M2 21h20"/>',
        'templates' => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M9 12h7M9 16h5"/><path d="M3 7v14"/>',
        'payments' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6.5 15h4"/>',
        'account' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21h-4v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1-2.8-2.8.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3v-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.8-2.8.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.8 2.8-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21v4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'billing' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'platform' => '<path d="M12 2 3 7l9 5 9-5z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.3 2.3 0 1 1 3.7 1.8c-.9.6-1.5 1-1.5 2.2"/><path d="M12 17h.01"/>',
        'logout' => '<path d="M9 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4"/><path d="M16 16l4-4-4-4M20 12H9"/>',
    ];

    public static function icon(string $name): string
    {
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . (self::ICONS[$name] ?? '') . '</svg>';
    }

    public static function assets(): string
    {
        $base = rtrim(Uri::root(true), '/') . '/media/com_koinoxrista/';
        $v = '1.0.0';
        return '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geom:wght@300..900&display=swap">'
            . '<link rel="stylesheet" href="' . $base . 'css/app.css?v=' . $v . '">'
            . '<script src="' . $base . 'js/app.js?v=' . $v . '" defer></script>'
            . '<style>:root{--kx-brand:' . self::color((string) Settings::get('brand_color')) . '}</style>';
    }

    public static function color(string $c): string
    {
        return preg_match('~^#[0-9a-fA-F]{6}$~', $c) ? $c : '#1d4ed8';
    }

    /** Μηνύματα του Joomla μέσα στο κέλυφος της εφαρμογής. */
    public static function flash(): string
    {
        $app = Factory::getApplication();
        $out = '';
        foreach ($app->getMessageQueue(true) as $m) {
            $type = in_array($m['type'] ?? '', ['error', 'warning', 'notice', 'message', 'success'], true) ? $m['type'] : 'message';
            $out .= '<div class="kx-alert kx-alert-' . $type . '" role="alert">' . $m['message'] . '<button type="button" class="kx-alert-x" aria-label="Κλείσιμο">×</button></div>';
        }
        return $out;
    }

    public static function brandBlock(): string
    {
        $logo = Settings::asset((string) Settings::get('brand_logo'));
        $name = (string) Settings::get('brand_name');
        return $logo !== ''
            ? '<img src="' . Fmt::e($logo) . '" alt="' . Fmt::e($name) . '">'
            : '<span class="kx-mark">' . Fmt::e(mb_substr($name, 0, 1)) . '</span><strong>' . Fmt::e($name) . '</strong>';
    }

    /** Αρχή σελίδας εφαρμογής: sidebar + κεφαλίδα. */
    public static function open(string $active, string $title, string $actions = '', string $subtitle = ''): string
    {
        $user = Auth::user();
        $account = Auth::account();
        $admin = Auth::isPlatformAdmin();
        $nav = [];
        if ($account) {
            $nav['dashboard'] = ['Επισκόπηση', 'view=dashboard'];
            $nav['buildings'] = ['Κτίρια', 'view=buildings'];
            if (Auth::feature('payments')) $nav['payments'] = ['Εισπράξεις', 'view=payments'];
            $nav['templates'] = ['Πρότυπα εκτύπωσης', 'view=templates'];
            $nav['account'] = ['Λογαριασμός', 'view=account'];
            $nav['billing'] = ['Συνδρομή', 'view=account&tab=billing'];
        }
        if ($admin) $nav['platform'] = ['Πλατφόρμα', 'view=platform'];
        $nav['help'] = ['Οδηγός', 'view=help'];

        $h = self::assets() . '<div class="kx-app">' . '<aside class="kx-side"><div class="kx-brand">' . self::brandBlock() . '</div>';
        if ($account) {
            $h .= '<div class="kx-side-account"><small>Λογαριασμός</small><strong>' . Fmt::e($account->name) . '</strong></div>';
        }
        $h .= '<nav class="kx-nav">';
        foreach ($nav as $key => [$label, $q]) {
            $icon = $key === 'billing' ? 'billing' : $key;
            $h .= '<a class="kx-nav-link' . ($key === $active ? ' is-active' : '') . '" href="' . Url::e($q) . '">' . self::icon($icon) . '<span>' . Fmt::e($label) . '</span></a>';
        }
        $h .= '</nav><div class="kx-side-user"><span class="kx-avatar">' . Fmt::e(self::initials((string) ($user->name ?? '?'))) . '</span><span class="kx-side-user-t"><strong>' . Fmt::e($user->name ?? '') . '</strong><small>' . Fmt::e($user->email ?? '') . '</small></span>'
            . '<a class="kx-logout" title="Αποσύνδεση" href="' . Url::e('task=auth.logout&' . Session::getFormToken() . '=1') . '">' . self::icon('logout') . '</a></div></aside>';
        $h .= '<main class="kx-main">';
        if (Auth::acting()) {
            $h .= '<div class="kx-acting">Βλέπετε τον λογαριασμό <strong>' . Fmt::e($account->name ?? '') . '</strong> ως διαχειριστής πλατφόρμας. <a href="' . Url::e('task=platform.leave&' . Session::getFormToken() . '=1') . '">Επιστροφή στην πλατφόρμα</a></div>';
        }
        if ($account) {
            $st = Auth::state($account);
            if (!$st['ok']) {
                $h .= '<div class="kx-alert kx-alert-warning"><strong>' . Fmt::e($st['label']) . '.</strong> Μπορείτε να δείτε και να εκτυπώσετε τα δεδομένα σας, αλλά οι αλλαγές είναι κλειδωμένες. <a href="' . Url::e('view=account&tab=billing') . '">Ανανέωση συνδρομής</a></div>';
            } elseif ($st['code'] === 'trial' && $st['until']) {
                $days = (int) floor((strtotime($st['until']) - strtotime(Db::today())) / 86400);
                if ($days <= 7) {
                    $h .= '<div class="kx-alert kx-alert-notice">Η δοκιμαστική περίοδος λήγει σε ' . max(0, $days) . ' ημέρες (' . Fmt::date($st['until']) . '). <a href="' . Url::e('view=account&tab=billing') . '">Επιλογή πακέτου</a></div>';
                }
            }
        }
        $h .= '<header class="kx-head"><div><h1>' . Fmt::e($title) . '</h1>' . ($subtitle !== '' ? '<p class="kx-sub">' . $subtitle . '</p>' : '') . '</div>'
            . ($actions !== '' ? '<div class="kx-head-actions">' . $actions . '</div>' : '') . '</header>';
        return $h . self::flash();
    }

    public static function close(): string
    {
        return '</main></div>';
    }

    public static function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
            if (mb_strlen($out) >= 2) break;
        }
        return $out ?: '?';
    }

    // ---------------------------------------------------------------- φόρμες

    public static function token(): string
    {
        return HTMLHelper::_('form.token');
    }

    /** Αρχή φόρμας POST προς task. */
    public static function form(string $task, string $extra = '', bool $files = false): string
    {
        return '<form method="post" action="' . Url::e() . '"' . ($files ? ' enctype="multipart/form-data"' : '') . ' ' . $extra . '>'
            . '<input type="hidden" name="task" value="' . Fmt::e($task) . '">' . self::token();
    }

    public static function input(string $name, $value, string $label, array $o = []): string
    {
        $type = $o['type'] ?? 'text';
        $attrs = '';
        foreach (['placeholder', 'step', 'min', 'max', 'maxlength', 'pattern', 'autocomplete', 'inputmode'] as $a) {
            if (isset($o[$a])) $attrs .= ' ' . $a . '="' . Fmt::e($o[$a]) . '"';
        }
        if (!empty($o['required'])) $attrs .= ' required';
        $help = !empty($o['help']) ? '<small class="kx-help">' . $o['help'] . '</small>' : '';
        $cls = 'kx-field' . (!empty($o['wide']) ? ' kx-span' : '') . (!empty($o['class']) ? ' ' . $o['class'] : '');
        if ($type === 'textarea') {
            return '<label class="' . $cls . '"><span>' . Fmt::e($label) . '</span><textarea name="' . Fmt::e($name) . '" rows="' . (int) ($o['rows'] ?? 3) . '"' . $attrs . '>' . Fmt::e($value) . '</textarea>' . $help . '</label>';
        }
        return '<label class="' . $cls . '"><span>' . Fmt::e($label) . '</span><input type="' . Fmt::e($type) . '" name="' . Fmt::e($name) . '" value="' . Fmt::e($value) . '"' . $attrs . '>' . $help . '</label>';
    }

    public static function select(string $name, array $options, $value, string $label = '', array $o = []): string
    {
        $s = '<select name="' . Fmt::e($name) . '"' . (!empty($o['attrs']) ? ' ' . $o['attrs'] : '') . '>';
        foreach ($options as $k => $v) {
            $s .= '<option value="' . Fmt::e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . Fmt::e($v) . '</option>';
        }
        $s .= '</select>';
        if ($label === '') return $s;
        $help = !empty($o['help']) ? '<small class="kx-help">' . $o['help'] . '</small>' : '';
        return '<label class="kx-field' . (!empty($o['wide']) ? ' kx-span' : '') . '"><span>' . Fmt::e($label) . '</span>' . $s . $help . '</label>';
    }

    public static function check(string $name, bool $checked, string $label, string $value = '1'): string
    {
        return '<label class="kx-check"><input type="checkbox" name="' . Fmt::e($name) . '" value="' . Fmt::e($value) . '"' . ($checked ? ' checked' : '') . '><span>' . Fmt::e($label) . '</span></label>';
    }

    public static function btn(string $label, string $href, string $kind = 'secondary', string $extra = ''): string
    {
        return '<a class="kx-btn kx-btn-' . $kind . '" href="' . Fmt::e($href) . '" ' . $extra . '>' . Fmt::e($label) . '</a>';
    }

    /** Κουμπί που κάνει POST ένα task (για διαγραφές κ.λπ.), με προαιρετική επιβεβαίωση. */
    public static function postButton(string $task, array $fields, string $label, string $kind = 'secondary', string $confirm = ''): string
    {
        $h = '<form method="post" action="' . Url::e() . '" class="kx-inline"' . ($confirm !== '' ? ' data-confirm="' . Fmt::e($confirm) . '"' : '') . '>'
            . '<input type="hidden" name="task" value="' . Fmt::e($task) . '">' . self::token();
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . Fmt::e($k) . '" value="' . Fmt::e($v) . '">';
        return $h . '<button type="submit" class="kx-btn kx-btn-' . $kind . '">' . Fmt::e($label) . '</button></form>';
    }

    public static function badge(string $text, string $kind = 'neutral'): string
    {
        return '<span class="kx-badge kx-badge-' . $kind . '">' . Fmt::e($text) . '</span>';
    }

    public static function empty(string $title, string $text, string $action = ''): string
    {
        return '<div class="kx-empty"><h3>' . Fmt::e($title) . '</h3><p>' . $text . '</p>' . $action . '</div>';
    }

    public static function meter(string $label, int $used, int $max): string
    {
        $pct = $max > 0 ? min(100, (int) round($used * 100 / $max)) : 0;
        return '<div class="kx-meter"><div class="kx-meter-top"><span>' . Fmt::e($label) . '</span><strong>' . $used . ' / ' . ($max > 0 ? $max : '∞') . '</strong></div>'
            . '<div class="kx-meter-bar"><i style="width:' . ($max > 0 ? $pct : 4) . '%"' . ($pct >= 90 ? ' class="is-full"' : '') . '></i></div></div>';
    }

    /** Απλή σελίδα χωρίς sidebar (login, εγγραφή). */
    public static function bare(string $content): string
    {
        return self::assets() . '<div class="kx-auth"><div class="kx-auth-card"><div class="kx-auth-brand">' . self::brandBlock() . '</div>'
            . '<p class="kx-auth-tag">' . Fmt::e((string) Settings::get('brand_tagline')) . '</p>' . self::flash() . $content . '</div></div>';
    }
}
