<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  plg_task_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Plugin\Task\LwStoreLocator\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Webkiste\Component\LwStoreLocator\Administrator\Service\Geocoder;
use Webkiste\Component\LwStoreLocator\Administrator\Service\Importer;

\defined('_JEXEC') or die;

/**
 * Scheduled tasks for Lightweight Store Locator:
 * - import a CSV/XLSX file from the server (e.g. a nightly CRM export)
 * - geocode open locations
 */
final class LwStoreLocator extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    protected const TASKS_MAP = [
        'lwstorelocator.import' => [
            'langConstPrefix' => 'PLG_TASK_LWSTORELOCATOR_IMPORT',
            'form'            => 'import',
            'method'          => 'import',
        ],
        'lwstorelocator.geocode' => [
            'langConstPrefix' => 'PLG_TASK_LWSTORELOCATOR_GEOCODE',
            'form'            => 'geocode',
            'method'          => 'geocode',
        ],
    ];

    /**
     * @var  boolean
     */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Imports a file from the server.
     */
    protected function import(ExecuteTaskEvent $event): int
    {
        if (!class_exists(Importer::class)) {
            $this->logTask('Lightweight Store Locator component is not installed.', 'error');

            return Status::KNOCKOUT;
        }

        $params   = $event->getArgument('params');
        $path     = trim(str_replace('\\', '/', (string) ($params->file ?? '')));
        $relative = ltrim($path, '/');

        if ($relative === '' || !preg_match('/\.(csv|txt|xlsx)$/i', $relative)) {
            $this->logTask('No CSV/XLSX file configured.', 'error');

            return Status::KNOCKOUT;
        }

        // Absolute path (e.g. a folder outside the web root) or relative to the Joomla folder
        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path);
        $file       = realpath($isAbsolute ? $path : JPATH_ROOT . '/' . $relative);

        if ($file === false || !is_file($file) || !is_readable($file)) {
            $this->logTask('File not found or not readable: ' . $path, 'error');

            return Status::KNOCKOUT;
        }

        $component = ComponentHelper::getParams('com_lwstorelocator');
        $importer  = new Importer($this->getDatabase(), strtoupper(trim((string) $component->get('default_country', ''))));
        $missing   = (string) ($params->missing ?? 'keep');

        $report = $importer->run($file, [
            'missing'           => \in_array($missing, ['keep', 'unpublish', 'delete'], true) ? $missing : 'keep',
            'blank_overwrites'  => (bool) ($params->blank_overwrites ?? 0),
            'skip_incomplete'   => (bool) ($params->skip_incomplete ?? 0),
            'force'             => false,
            'default_published' => (int) $component->get('import_published', 1),
        ], true, basename($file));

        if ($report['error'] !== '') {
            $this->logTask('Import failed: ' . $report['error'], 'error');

            return Status::KNOCKOUT;
        }

        $s = $report['stats'];
        $this->logTask(sprintf(
            'Import of %s: %d new, %d updated, %d unchanged, %d not in file (%s), %d skipped rows, %d notes.',
            basename($file),
            $s['new'],
            $s['updated'],
            $s['unchanged'],
            $s['missing'],
            $report['missing'],
            $s['skipped'],
            \count($report['warnings'])
        ));

        foreach (\array_slice($report['warnings'], 0, 50) as $warning) {
            $this->logTask($warning, 'warning');
        }

        return Status::OK;
    }

    /**
     * Geocodes open locations.
     */
    protected function geocode(ExecuteTaskEvent $event): int
    {
        if (!class_exists(Geocoder::class)) {
            $this->logTask('Lightweight Store Locator component is not installed.', 'error');

            return Status::KNOCKOUT;
        }

        $params    = $event->getArgument('params');
        $limit     = max(1, min(500, (int) ($params->limit ?? 50)));
        $seconds   = max(10, min(600, (int) ($params->seconds ?? 50)));
        $component = ComponentHelper::getParams('com_lwstorelocator');

        @set_time_limit($seconds + 60);

        $geocoder = new Geocoder(
            $this->getDatabase(),
            trim((string) $component->get('contact_email', '')),
            strtoupper(trim((string) $component->get('default_country', '')))
        );

        $result = $geocoder->run($limit, (float) $seconds);

        $this->logTask(sprintf(
            'Geocoding: %d processed (%d exact, %d approximate, %d not found), %d still open.%s',
            $result['processed'],
            $result['exact'],
            $result['approx'],
            $result['failed'],
            $result['remaining'],
            $result['aborted'] ? ' Nominatim was not reachable – the rest will be processed on the next run.' : ''
        ));

        return Status::OK;
    }
}
