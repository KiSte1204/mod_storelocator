<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\View\Locations;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    /** @var array */
    protected $items;

    /** @var \Joomla\CMS\Pagination\Pagination */
    protected $pagination;

    /** @var \Joomla\Registry\Registry */
    protected $state;

    /** @var \Joomla\CMS\Form\Form */
    public $filterForm;

    /** @var array */
    public $activeFilters;

    /** @var array<int, int> */
    protected $geocodeCounts = [];

    public function display($tpl = null)
    {
        $model               = $this->getModel();
        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->geocodeCounts = $model->getGeocodeCounts();

        if ($this->filterForm && ($field = $this->filterForm->getField('country', 'filter'))) {
            foreach ($model->getCountries() as $code) {
                $field->addOption($code, ['value' => $code]);
            }
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $user    = Factory::getApplication()->getIdentity();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_LWSTORELOCATOR_LOCATIONS_TITLE'), 'map-marker-alt');

        if ($user->authorise('core.create', 'com_lwstorelocator')) {
            $toolbar->addNew('location.add');
        }

        if ($user->authorise('core.edit.state', 'com_lwstorelocator')) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('icon-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $child = $dropdown->getChildToolbar();
            $child->publish('locations.publish')->listCheck(true);
            $child->unpublish('locations.unpublish')->listCheck(true);
            $child->checkin('locations.checkin')->listCheck(true);

            if ($user->authorise('core.edit', 'com_lwstorelocator')) {
                $child->standardButton('refresh', 'COM_LWSTORELOCATOR_TOOLBAR_RESET_GEOCODE', 'locations.resetGeocode')
                    ->listCheck(true);
            }
        }

        if ($user->authorise('core.delete', 'com_lwstorelocator')) {
            $toolbar->delete('locations.delete')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        if ($user->authorise('core.create', 'com_lwstorelocator') && $user->authorise('core.edit', 'com_lwstorelocator')) {
            $toolbar->linkButton('upload', 'COM_LWSTORELOCATOR_TOOLBAR_IMPORT')
                ->url(Route::_('index.php?option=com_lwstorelocator&view=import'))
                ->icon('icon-upload');
        }

        $toolbar->linkButton('download', 'COM_LWSTORELOCATOR_TOOLBAR_EXPORT')
            ->url(Route::_('index.php?option=com_lwstorelocator&task=locations.export&' . Session::getFormToken() . '=1'))
            ->icon('icon-download');

        if ($user->authorise('core.admin', 'com_lwstorelocator') || $user->authorise('core.options', 'com_lwstorelocator')) {
            $toolbar->preferences('com_lwstorelocator');
        }
    }
}
