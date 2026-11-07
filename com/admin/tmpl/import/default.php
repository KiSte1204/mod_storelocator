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
use Joomla\CMS\Uri\Uri;
use Webkiste\Component\LwStoreLocator\Administrator\Service\Importer;

/** @var \Webkiste\Component\LwStoreLocator\Administrator\View\Import\HtmlView $this */

$this->getDocument()->getWebAssetManager()->registerAndUseStyle('com_lwstorelocator.admin', 'com_lwstorelocator/admin.css');

$report    = $this->report;
$action    = Route::_('index.php?option=com_lwstorelocator&view=import');
$sampleUrl = Uri::root(true) . '/media/com_lwstorelocator/sample/sample-locations.csv';
$typeText  = [
    'new'       => ['bg-success', 'COM_LWSTORELOCATOR_IMPORT_TYPE_NEW'],
    'update'    => ['bg-info text-dark', 'COM_LWSTORELOCATOR_IMPORT_TYPE_UPDATE'],
    'unpublish' => ['bg-warning text-dark', 'COM_LWSTORELOCATOR_IMPORT_TYPE_UNPUBLISH'],
    'delete'    => ['bg-danger', 'COM_LWSTORELOCATOR_IMPORT_TYPE_DELETE'],
];
?>

<?php if ($report) : ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h4">
                <?php echo Text::_($report['apply'] ? 'COM_LWSTORELOCATOR_IMPORT_RESULT' : 'COM_LWSTORELOCATOR_IMPORT_PREVIEW'); ?>:
                <?php echo $this->escape($report['file']); ?>
            </h2>

            <?php if ($report['error'] !== '') : ?>
                <div class="alert alert-danger"><?php echo $this->escape($report['error']); ?></div>
            <?php else : ?>
                <?php if (!$report['apply']) : ?>
                    <div class="alert alert-info"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_PREVIEW_HINT'); ?></div>
                <?php endif; ?>

                <?php $s = $report['stats']; ?>
                <ul class="list-inline">
                    <li class="list-inline-item"><span class="badge bg-success"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_TYPE_NEW'); ?>: <?php echo (int) $s['new']; ?></span></li>
                    <li class="list-inline-item"><span class="badge bg-info text-dark"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_TYPE_UPDATE'); ?>: <?php echo (int) $s['updated']; ?></span></li>
                    <li class="list-inline-item"><span class="badge bg-secondary"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_UNCHANGED'); ?>: <?php echo (int) $s['unchanged']; ?></span></li>
                    <li class="list-inline-item"><span class="badge bg-warning text-dark"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_REGEOCODE'); ?>: <?php echo (int) $s['regeocode']; ?></span></li>
                    <li class="list-inline-item"><span class="badge bg-light text-dark"><?php echo Text::sprintf('COM_LWSTORELOCATOR_IMPORT_MISSING', (int) $s['missing'], Text::_('COM_LWSTORELOCATOR_IMPORT_MISSING_' . strtoupper($report['missing']))); ?></span></li>
                    <li class="list-inline-item"><span class="badge bg-light text-dark"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_SKIPPED'); ?>: <?php echo (int) $s['skipped']; ?></span></li>
                </ul>

                <p class="small text-muted">
                    <?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_COLUMNS'); ?>:
                    <?php foreach ($report['columns'] as $field => $heading) : ?>
                        <code><?php echo $this->escape($heading); ?></code> → <?php echo $this->escape($field); ?>&nbsp;
                    <?php endforeach; ?>
                </p>

                <?php if ($report['changes']) : ?>
                    <details <?php echo \count($report['changes']) <= 50 ? 'open' : ''; ?>>
                        <summary><?php echo Text::sprintf('COM_LWSTORELOCATOR_IMPORT_CHANGES', \count($report['changes'])); ?></summary>
                        <table class="table table-sm lwsl-import-table mt-2">
                            <tbody>
                                <?php foreach ($report['changes'] as $c) : ?>
                                    <tr>
                                        <td class="w-10"><span class="badge <?php echo $typeText[$c['type']][0]; ?>"><?php echo Text::_($typeText[$c['type']][1]); ?></span></td>
                                        <td>
                                            <strong><?php echo $this->escape($c['name']); ?></strong>
                                            <span class="text-muted"><?php echo $this->escape($c['place']); ?></span>
                                            <?php if ($c['details']) : ?>
                                                <ul class="lwsl-import-details">
                                                    <?php foreach ($c['details'] as $d) : ?>
                                                        <li><?php echo $this->escape($d); ?></li>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($c['regeocode'])) : ?>
                                                        <li><em><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_REGEOCODE_NOTE'); ?></em></li>
                                                    <?php endif; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($report['warnings'])) : ?>
                <details class="mt-3">
                    <summary><?php echo Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARNINGS', \count($report['warnings'])); ?></summary>
                    <ul class="small mt-2">
                        <?php foreach ($report['warnings'] as $w) : ?>
                            <li><?php echo $this->escape($w); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if ($this->pending) : ?>
                <form action="<?php echo $action; ?>" method="post" class="mt-3 d-flex gap-2">
                    <button type="submit" name="task" value="import.apply" class="btn btn-success">
                        <span class="icon-check" aria-hidden="true"></span> <?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_APPLY'); ?>
                    </button>
                    <button type="submit" name="task" value="import.cancel" class="btn btn-secondary"><?php echo Text::_('JCANCEL'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            <?php elseif ($report['apply'] && $report['error'] === '' && ($report['stats']['new'] + $report['stats']['regeocode']) > 0) : ?>
                <a class="btn btn-primary mt-3" href="<?php echo Route::_('index.php?option=com_lwstorelocator&view=locations'); ?>">
                    <?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_GO_GEOCODE'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!$this->pending) : ?>
    <div class="row">
        <div class="col-lg-7">
            <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" class="card">
                <div class="card-body">
                    <h2 class="h4"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_UPLOAD'); ?></h2>

                    <div class="mb-3">
                        <label class="form-label" for="import_file"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_FILE'); ?></label>
                        <input class="form-control" type="file" id="import_file" name="import_file" accept=".csv,.txt,.xlsx" required>
                        <div class="form-text"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_FILE_DESC'); ?></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="missing"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_MISSING_LABEL'); ?></label>
                        <select class="form-select" id="missing" name="missing">
                            <option value="keep"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_MISSING_KEEP'); ?></option>
                            <option value="unpublish"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_MISSING_UNPUBLISH'); ?></option>
                            <option value="delete"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_MISSING_DELETE'); ?></option>
                        </select>
                        <div class="form-text"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_MISSING_DESC'); ?></div>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="blank_overwrites" name="blank_overwrites" value="1">
                        <label class="form-check-label" for="blank_overwrites"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_BLANK'); ?></label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="skip_incomplete" name="skip_incomplete" value="1">
                        <label class="form-check-label" for="skip_incomplete"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_SKIP_INCOMPLETE'); ?></label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="force" name="force" value="1">
                        <label class="form-check-label" for="force"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_FORCE'); ?></label>
                    </div>

                    <button type="submit" name="task" value="import.preview" class="btn btn-primary">
                        <span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_PREVIEW_BUTTON'); ?>
                    </button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card lwsl-sample">
                <div class="card-body">
                    <h2 class="h4"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_FORMAT'); ?></h2>
                    <p><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_FORMAT_DESC'); ?></p>
                    <ul class="small">
                        <?php foreach (Importer::COLUMNS as $field => $aliases) : ?>
                            <li><code><?php echo $field; ?></code> – <?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_COL_' . strtoupper($field)); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="small"><?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_FORMAT_ALIASES'); ?></p>
                    <a class="btn btn-outline-primary" href="<?php echo $this->escape($sampleUrl); ?>" download>
                        <span class="icon-download" aria-hidden="true"></span> <?php echo Text::_('COM_LWSTORELOCATOR_IMPORT_SAMPLE'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
