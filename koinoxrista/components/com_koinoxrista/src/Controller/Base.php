<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Calc;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

/** Κοινά των controllers: CSRF, ανακατεύθυνση με μήνυμα, ανάγνωση πεδίων. */
abstract class Base extends BaseController
{
    protected function guard(string $method = 'post'): void
    {
        if (!Session::checkToken($method)) {
            $this->app->enqueueMessage('Η φόρμα έληξε. Δοκιμάστε ξανά.', 'warning');
            $this->app->redirect(Url::to('view=dashboard'));
            $this->app->close();
        }
    }

    protected function go(string $query, string $message = '', string $type = 'message'): void
    {
        if ($message !== '') {
            $this->app->enqueueMessage($message, $type);
        }
        $this->app->redirect(Url::to($query));
    }

    protected function str(string $name, int $max = 190): string
    {
        $v = trim((string) $this->input->post->getString($name, ''));
        return mb_substr($v, 0, $max);
    }

    protected function text(string $name, int $max = 5000): string
    {
        $v = (string) $this->input->post->get($name, '', 'raw');
        $v = trim(strip_tags(str_replace("\r\n", "\n", $v)));
        return mb_substr($v, 0, $max);
    }

    protected function int(string $name, int $default = 0): int
    {
        return $this->input->post->getInt($name, $default);
    }

    protected function num(string $name, float $default = 0): float
    {
        $raw = $this->input->post->getString($name, '');
        return $raw === '' ? $default : Calc::parseNumber($raw);
    }

    protected function arr(string $name): array
    {
        $v = $this->input->post->get($name, [], 'array');
        return is_array($v) ? $v : [];
    }

    protected function email(string $name): string
    {
        $v = $this->str($name);
        return $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }

    /**
     * Ανέβασμα εικόνας στο images/koinoxrista/<φάκελος>/ — μόνο PNG/JPG/WEBP/GIF.
     * Επιστρέφει τη σχετική διαδρομή ή '' (χωρίς αρχείο). Σε σφάλμα πετάει εξαίρεση με μήνυμα.
     */
    protected function upload(string $field, string $folder, string $name): string
    {
        $file = $this->input->files->get($field, [], 'raw');
        if (!is_array($file) || empty($file['tmp_name']) || (int) ($file['error'] ?? 4) === 4) {
            return '';
        }
        if ((int) $file['error'] !== 0 || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Το αρχείο δεν ανέβηκε (σφάλμα ' . (int) $file['error'] . ').');
        }
        if ((int) $file['size'] > 5 * 1024 * 1024) {
            throw new \RuntimeException('Το αρχείο ξεπερνά τα 5 MB.');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? '';
        if ($ext === '') {
            throw new \RuntimeException('Επιτρέπονται μόνο εικόνες PNG, JPG, WEBP ή GIF.');
        }
        $folder = preg_replace('~[^a-z0-9_/-]~i', '', $folder);
        $dir = JPATH_ROOT . '/images/koinoxrista/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Ο φάκελος images/koinoxrista δεν είναι εγγράψιμος.');
        }
        $base = preg_replace('~[^a-z0-9_-]~i', '', $name) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        foreach (glob($dir . '/' . preg_replace('~[^a-z0-9_-]~i', '', $name) . '-*') ?: [] as $old) {
            @unlink($old);
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $base . '.' . $ext)) {
            throw new \RuntimeException('Το αρχείο δεν αποθηκεύτηκε.');
        }
        return 'images/koinoxrista/' . $folder . '/' . $base . '.' . $ext;
    }
}
