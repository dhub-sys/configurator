<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    protected $default_view = 'app';

    public function display($cachable = false, $urlparams = [])
    {
        $view = $this->input->getCmd('view', 'app');
        $known = ['app', 'otp', 'signup', 'dashboard', 'buildings', 'building', 'period', 'print', 'payments', 'templates', 'template', 'account', 'platform', 'help', 'notice', 'receipt'];
        $this->input->set('view', in_array($view, $known, true) ? $view : 'app');
        return parent::display(false, $urlparams);
    }
}
