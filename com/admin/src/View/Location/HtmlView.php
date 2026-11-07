<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\View\Location;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    /** @var \Joomla\CMS\Form\Form */
    protected $form;

    /** @var object */
    protected $item;

    public function display($tpl = null)
    {
        $model      = $this->getModel();
        $this->form = $model->getForm();
        $this->item = $model->getItem();

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $isNew   = (int) $this->item->id === 0;
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_($isNew ? 'COM_LWSTORELOCATOR_LOCATION_NEW' : 'COM_LWSTORELOCATOR_LOCATION_EDIT'), 'map-marker-alt');

        $toolbar->apply('location.apply');
        $toolbar->save('location.save');
        $toolbar->save2new('location.save2new');
        $toolbar->cancel('location.cancel', $isNew ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
    }
}
