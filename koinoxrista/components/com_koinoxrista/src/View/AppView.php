<?php
namespace Koinoxrista\Component\Koinoxrista\Site\View;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView;

/**
 * Βάση των οθονών: τα templates χρησιμοποιούν απευθείας τους helpers. Το $this->in
 * είναι το input του αιτήματος.
 */
abstract class AppView extends HtmlView
{
    public $in;

    public function display($tpl = null)
    {
        $this->in = Factory::getApplication()->getInput();
        return parent::display($tpl);
    }
}
