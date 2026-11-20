<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Factory;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Settings;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Url;

/**
 * Πύλη της εφαρμογής: δημόσιες είναι μόνο οι οθόνες σύνδεσης/εγγραφής και τα ειδοποιητήρια
 * με υπογεγραμμένο σύνδεσμο. Όλα τα υπόλοιπα απαιτούν σύνδεση.
 */
final class Dispatcher extends ComponentDispatcher
{
    private const PUBLIC_VIEWS = ['app', 'otp', 'signup', 'notice'];
    private const PUBLIC_TASKS = ['auth.login', 'auth.verify', 'auth.resend', 'auth.logout', 'signup.register'];

    public function dispatch(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $view = $input->getCmd('view', 'app');
        $task = $input->getCmd('task', '');
        $uid = Auth::userId();

        // Μπλοκαρισμένος ή διαγραμμένος χρήστης χάνει την πρόσβαση αμέσως.
        if ($uid && !Auth::allowed($uid)) {
            Auth::logout();
            $uid = 0;
        }

        $public = $task !== '' ? in_array($task, self::PUBLIC_TASKS, true) : in_array($view, self::PUBLIC_VIEWS, true);
        if (!$uid && !$public) {
            if ($task === '') {
                $app->getSession()->set('kx.return', (string) \Joomla\CMS\Uri\Uri::getInstance()->toString());
            }
            $app->enqueueMessage('Συνδεθείτε για να συνεχίσετε.', 'notice');
            $app->redirect(Url::to('view=app'));
            return;
        }
        if ($uid && $task === '' && in_array($view, ['app', 'otp', 'signup'], true)) {
            $app->redirect(Url::to(Auth::account() ? 'view=dashboard' : 'view=platform'));
            return;
        }

        parent::dispatch();

        $doc = $app->getDocument();
        if ($doc && method_exists($doc, 'setTitle') && !in_array($view, ['print', 'notice'], true)) {
            $doc->setTitle((string) Settings::get('brand_name'));
        }
    }
}
