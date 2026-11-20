<?php
namespace Koinoxrista\Component\Koinoxrista\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;

/** Το administrator απλώς ανοίγει την εφαρμογή (frontend, πλήρης οθόνη). */
class DisplayController extends BaseController
{
    protected $default_view = 'launcher';

    public function display($cachable = false, $urlparams = [])
    {
        $this->app->redirect(Uri::root() . 'index.php?option=com_koinoxrista&view=app&tmpl=component');
        return $this;
    }
}
