<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Webkiste\Component\LwStoreLocator\Administrator\Table\LocationTable;

/** @var \Webkiste\Component\LwStoreLocator\Administrator\View\Locations\HtmlView $this */

$user      = Factory::getApplication()->getIdentity();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$canEdit   = $user->authorise('core.edit', 'com_lwstorelocator');
$canState  = $user->authorise('core.edit.state', 'com_lwstorelocator');
$counts    = $this->geocodeCounts;
$open      = (int) ($counts[LocationTable::GEO_OPEN] ?? 0);
$failed    = (int) ($counts[LocationTable::GEO_FAILED] ?? 0);
$total     = array_sum($counts);

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('table.columns')->useScript('multiselect');
$wa->registerAndUseScript('com_lwstorelocator.geocode', 'com_lwstorelocator/geocode.js', [], ['defer' => true], ['core']);
$wa->registerAndUseStyle('com_lwstorelocator.admin', 'com_lwstorelocator/admin.css');

$geocodeUrl = Route::_('index.php?option=com_lwstorelocator&task=locations.geocode&' . Session::getFormToken() . '=1', false);

$badges = [
    LocationTable::GEO_OPEN   => ['bg-secondary', 'COM_LWSTORELOCATOR_GEO_OPEN'],
    LocationTable::GEO_EXACT  => ['bg-success', 'COM_LWSTORELOCATOR_GEO_EXACT'],
    LocationTable::GEO_APPROX => ['bg-warning text-dark', 'COM_LWSTORELOCATOR_GEO_APPROX'],
    LocationTable::GEO_FAILED => ['bg-danger', 'COM_LWSTORELOCATOR_GEO_FAILED'],
    LocationTable::GEO_MANUAL => ['bg-info text-dark', 'COM_LWSTORELOCATOR_GEO_MANUAL'],
];

Text::script('COM_LWSTORELOCATOR_GEOCODE_RUNNING');
Text::script('COM_LWSTORELOCATOR_GEOCODE_DONE');
Text::script('COM_LWSTORELOCATOR_GEOCODE_PAUSED');
Text::script('COM_LWSTORELOCATOR_GEOCODE_ERROR');
Text::script('COM_LWSTORELOCATOR_GEOCODE_STOPPED');
?>
<form action="<?php echo Route::_('index.php?option=com_lwstorelocator&view=locations'); ?>" method="post" name="adminForm" id="adminForm">

    <?php if ($total === 0) : ?>
        <div class="alert alert-info">
            <h2 class="h4"><?php echo Text::_('COM_LWSTORELOCATOR_EMPTY_TITLE'); ?></h2>
            <p><?php echo Text::_('COM_LWSTORELOCATOR_EMPTY_TEXT'); ?></p>
            <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_lwstorelocator&view=import'); ?>">
                <span class="icon-upload" aria-hidden="true"></span> <?php echo Text::_('COM_LWSTORELOCATOR_TOOLBAR_IMPORT'); ?>
            </a>
            <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_lwstorelocator&task=location.add'); ?>">
                <span class="icon-plus" aria-hidden="true"></span> <?php echo Text::_('COM_LWSTORELOCATOR_LOCATION_NEW'); ?>
            </a>
        </div>
    <?php else : ?>
        <div class="card mb-3 lwsl-geocode" id="lwsl-geocode" data-url="<?php echo $this->escape($geocodeUrl); ?>" data-open="<?php echo $open; ?>">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <div class="flex-grow-1">
                    <strong><?php echo Text::_('COM_LWSTORELOCATOR_GEOCODE_TITLE'); ?></strong>
                    <div class="small text-muted">
                        <?php foreach ($badges as $state => [$class, $label]) : ?>
                            <span class="badge <?php echo $class; ?> me-1"><?php echo Text::_($label); ?>: <?php echo (int) ($counts[$state] ?? 0); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="small text-muted mt-1"><?php echo Text::_('COM_LWSTORELOCATOR_GEOCODE_HINT'); ?></div>
                </div>
                <?php if ($canEdit) : ?>
                    <div>
                        <button type="button" class="btn btn-primary lwsl-geocode-start" <?php echo $open ? '' : 'disabled'; ?>>
                            <span class="icon-location" aria-hidden="true"></span>
                            <?php echo Text::sprintf('COM_LWSTORELOCATOR_GEOCODE_BUTTON', $open); ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary lwsl-geocode-stop" hidden><?php echo Text::_('COM_LWSTORELOCATOR_GEOCODE_STOP'); ?></button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="lwsl-geocode-progress px-3 pb-3" hidden>
                <div class="progress mb-2" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width:0%"></div>
                </div>
                <div class="lwsl-geocode-status small mb-1" aria-live="polite"></div>
                <pre class="lwsl-geocode-log small mb-0"></pre>
            </div>
        </div>

        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

        <?php if (empty($this->items)) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
            </div>
        <?php else : ?>
            <table class="table" id="locationList">
                <caption class="visually-hidden"><?php echo Text::_('COM_LWSTORELOCATOR_LOCATIONS_TITLE'); ?></caption>
                <thead>
                    <tr>
                        <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                        <th scope="col" class="w-1 text-center"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.published', $listDirn, $listOrder); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_LWSTORELOCATOR_FIELD_NAME_LABEL', 'a.name', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_LWSTORELOCATOR_FIELD_ADDRESS_LABEL'); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_LWSTORELOCATOR_FIELD_TOWN_LABEL', 'a.town', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="w-5"><?php echo HTMLHelper::_('searchtools.sort', 'COM_LWSTORELOCATOR_FIELD_COUNTRY_LABEL', 'a.country', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="w-10"><?php echo HTMLHelper::_('searchtools.sort', 'COM_LWSTORELOCATOR_FIELD_GEOCODE_STATUS_LABEL', 'a.geocode_status', $listDirn, $listOrder); ?></th>
                        <th scope="col" class="w-5 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) :
                        $badge = $badges[(int) $item->geocode_status] ?? $badges[0];
                        $coord = $item->latitude !== null ? number_format((float) $item->latitude, 5, '.', '') . ', ' . number_format((float) $item->longitude, 5, '.', '') : '';
                        ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->name); ?></td>
                            <td class="text-center"><?php echo HTMLHelper::_('jgrid.published', $item->published, $i, 'locations.', $canState, 'cb'); ?></td>
                            <th scope="row">
                                <?php if ($item->checked_out) : ?>
                                    <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, 'locations.', $canEdit); ?>
                                <?php endif; ?>
                                <?php if ($canEdit) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_lwstorelocator&task=location.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->name); ?></a>
                                <?php else : ?>
                                    <?php echo $this->escape($item->name); ?>
                                <?php endif; ?>
                                <?php if ($item->external_id !== null && $item->external_id !== '') : ?>
                                    <div class="small text-muted"><?php echo Text::_('COM_LWSTORELOCATOR_FIELD_EXTERNAL_ID_LABEL'); ?>: <?php echo $this->escape($item->external_id); ?></div>
                                <?php endif; ?>
                            </th>
                            <td class="d-none d-md-table-cell"><?php echo $this->escape($item->address); ?></td>
                            <td><?php echo $this->escape(trim($item->postal . ' ' . $item->town)); ?></td>
                            <td><?php echo $this->escape($item->country); ?></td>
                            <td>
                                <span class="badge <?php echo $badge[0]; ?>"><?php echo Text::_($badge[1]); ?></span>
                                <?php if ($coord !== '') : ?>
                                    <div class="small text-muted"><a href="https://www.openstreetmap.org/?mlat=<?php echo (float) $item->latitude; ?>&amp;mlon=<?php echo (float) $item->longitude; ?>#map=17/<?php echo (float) $item->latitude; ?>/<?php echo (float) $item->longitude; ?>" target="_blank" rel="noopener noreferrer"><?php echo $coord; ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
