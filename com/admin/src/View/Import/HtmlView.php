<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\View\Import;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    /** @var array|null  Report of the last preview or import */
    protected $report;

    /** @var bool  A previewed file is waiting to be applied */
    protected $pending = false;

    public function display($tpl = null)
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.create', 'com_lwstorelocator') || !$user->authorise('core.edit', 'com_lwstorelocator')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $state         = (array) $app->getUserState('com_lwstorelocator.import', []);
        $this->report  = $state['report'] ?? null;
        $this->pending = !empty($state['file']) && is_file((string) $state['file']);

        // Results and errors are shown once
        if ($this->report && !$this->pending) {
            $app->setUserState('com_lwstorelocator.import', null);
        }

        ToolbarHelper::title(Text::_('COM_LWSTORELOCATOR_IMPORT_TITLE'), 'upload');
        $this->getDocument()->getToolbar()->linkButton('back', 'COM_LWSTORELOCATOR_TOOLBAR_BACK_TO_LIST')
            ->url(Route::_('index.php?option=com_lwstorelocator&view=locations'))
            ->icon('icon-arrow-left');

        parent::display($tpl);
    }
}
