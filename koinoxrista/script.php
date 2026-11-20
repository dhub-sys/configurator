<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

/**
 * Εγκατάσταση: δημιουργεί τα αρχικά πακέτα συνδρομής και τα έτοιμα πρότυπα εκτύπωσης
 * (μόνο αν δεν υπάρχουν ήδη — μια αναβάθμιση δεν αλλάζει τίποτα από όσα έχετε ορίσει).
 */
class Com_KoinoxristaInstallerScript
{
    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $now = Factory::getDate()->toSql();

        if ((int) $db->setQuery('SELECT COUNT(*) FROM #__kx_packages')->loadResult() === 0) {
            $all = ['designer' => 1, 'heating_meters' => 1, 'email' => 1, 'payments' => 1, 'export' => 1];
            $packages = [
                ['Δοκιμαστικό', 'Πλήρεις δυνατότητες για 30 ημέρες, έως 3 κτίρια.', 0, 0, 3, 60, 1, 30, $all, 1, 0],
                ['Basic', 'Για επαγγελματίες με λίγα κτίρια.', 15, 150, 10, 200, 1, 0, ['payments' => 1, 'export' => 1], 0, 0],
                ['Professional', 'Δικά σας πρότυπα, θέρμανση με ωρομετρητές, email ειδοποιητήρια.', 35, 350, 40, 1000, 3, 0, $all, 0, 1],
                ['Enterprise', 'Απεριόριστα κτίρια και χρήστες.', 79, 790, 0, 0, 0, 0, $all, 0, 0],
            ];
            foreach ($packages as $i => [$title, $desc, $pm, $py, $mb, $mu, $us, $trial, $features, $def, $hl]) {
                $o = (object) [
                    'title' => $title, 'description' => $desc, 'price_month' => $pm, 'price_year' => $py, 'max_buildings' => $mb, 'max_units' => $mu,
                    'max_users' => $us, 'trial_days' => $trial, 'features' => json_encode($features), 'is_default' => $def, 'highlight' => $hl,
                    'published' => 1, 'ordering' => $i + 1, 'created' => $now,
                ];
                $db->insertObject('#__kx_packages', $o);
            }
        }

        if ((int) $db->setQuery('SELECT COUNT(*) FROM #__kx_templates WHERE account_id=0')->loadResult() === 0) {
            $base = JPATH_SITE . '/components/com_koinoxrista/src/Helper/';
            foreach (['Fmt', 'Calc', 'Layouts'] as $c) {
                if (!class_exists('Koinoxrista\\Component\\Koinoxrista\\Site\\Helper\\' . $c)) {
                    require_once $base . $c . '.php';
                }
            }
            $L = 'Koinoxrista\\Component\\Koinoxrista\\Site\\Helper\\Layouts';
            $templates = [
                ['Κατάσταση κοινοχρήστων A4', 'sheet', $L::sheet()],
                ['Ειδοποιητήρια A4 (2 ανά σελίδα)', 'notice', $L::notice()],
            ];
            foreach ($templates as [$title, $tp, $layout]) {
                $o = (object) ['account_id' => 0, 'title' => $title, 'type' => $tp, 'layout' => json_encode($layout, JSON_UNESCAPED_UNICODE), 'background' => '', 'is_default' => 1, 'created' => $now, 'modified' => $now];
                $db->insertObject('#__kx_templates', $o);
            }
        }

        $dir = JPATH_ROOT . '/images/koinoxrista';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && !is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
        }
        return true;
    }
}
