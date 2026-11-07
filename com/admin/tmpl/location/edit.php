<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Webkiste\Component\LwStoreLocator\Administrator\Table\LocationTable;

/** @var \Webkiste\Component\LwStoreLocator\Administrator\View\Location\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')->useScript('form.validate');

$states = [
    LocationTable::GEO_OPEN   => 'COM_LWSTORELOCATOR_GEO_OPEN',
    LocationTable::GEO_EXACT  => 'COM_LWSTORELOCATOR_GEO_EXACT',
    LocationTable::GEO_APPROX => 'COM_LWSTORELOCATOR_GEO_APPROX',
    LocationTable::GEO_FAILED => 'COM_LWSTORELOCATOR_GEO_FAILED',
    LocationTable::GEO_MANUAL => 'COM_LWSTORELOCATOR_GEO_MANUAL',
];
$status = (int) ($this->item->geocode_status ?? 0);
?>
<form action="<?php echo Route::_('index.php?option=com_lwstorelocator&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="location-form" class="form-validate">

    <div class="row">
        <div class="col-lg-8">
            <fieldset class="options-form">
                <legend><?php echo Text::_('COM_LWSTORELOCATOR_FIELDSET_LOCATION'); ?></legend>
                <?php echo $this->form->renderFieldset('location'); ?>
            </fieldset>
            <fieldset class="options-form">
                <legend><?php echo Text::_('COM_LWSTORELOCATOR_FIELDSET_CONTACT'); ?></legend>
                <?php echo $this->form->renderFieldset('contact'); ?>
            </fieldset>
        </div>
        <div class="col-lg-4">
            <fieldset class="options-form">
                <legend><?php echo Text::_('COM_LWSTORELOCATOR_FIELDSET_COORDINATES'); ?></legend>
                <p>
                    <?php echo Text::_('COM_LWSTORELOCATOR_FIELD_GEOCODE_STATUS_LABEL'); ?>:
                    <strong><?php echo Text::_($states[$status] ?? $states[0]); ?></strong>
                </p>
                <p class="small text-muted"><?php echo Text::_('COM_LWSTORELOCATOR_COORDINATES_HINT'); ?></p>
                <?php echo $this->form->renderFieldset('coordinates'); ?>
            </fieldset>
            <fieldset class="options-form">
                <legend><?php echo Text::_('JDETAILS'); ?></legend>
                <?php echo $this->form->renderFieldset('details'); ?>
            </fieldset>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
