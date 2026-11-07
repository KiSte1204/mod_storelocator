<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Webkiste\Component\LwStoreLocator\Administrator\Service\Importer;

\defined('_JEXEC') or die;

/**
 * Two-step import: upload + preview (nothing is written), then apply.
 */
class ImportController extends BaseController
{
    private const STATE = 'com_lwstorelocator.import';

    /** Maximum upload size in bytes */
    private const MAX_SIZE = 20971520;

    /**
     * Upload the file and show the preview.
     */
    public function preview(): void
    {
        $this->checkToken();
        $this->authorise();
        $this->cleanup();

        $upload = $this->input->files->get('import_file', [], 'raw');
        $name   = (string) ($upload['name'] ?? '');
        $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (empty($upload['tmp_name']) || (int) ($upload['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            $this->back(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_UPLOAD'), 'error');

            return;
        }

        if (!\in_array($ext, ['csv', 'txt', 'xlsx'], true) || (int) $upload['size'] > self::MAX_SIZE) {
            $this->back(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_TYPE'), 'error');

            return;
        }

        $target = rtrim((string) $this->app->get('tmp_path'), '/\\') . '/lwstorelocator_' . bin2hex(random_bytes(12)) . '.' . $ext;

        if (!move_uploaded_file($upload['tmp_name'], $target)) {
            $this->back(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_UPLOAD'), 'error');

            return;
        }

        $options = $this->readOptions();
        $report  = $this->importer()->run($target, $options, false, basename($name));

        if ($report['error'] !== '') {
            @unlink($target);
            $this->app->setUserState(self::STATE, ['report' => $report]);
            $this->back('', '');

            return;
        }

        $this->app->setUserState(self::STATE, ['file' => $target, 'name' => basename($name), 'options' => $options, 'report' => $report]);
        $this->back('', '');
    }

    /**
     * Apply the previewed import.
     */
    public function apply(): void
    {
        $this->checkToken();
        $this->authorise();

        $state = (array) $this->app->getUserState(self::STATE, []);
        $file  = (string) ($state['file'] ?? '');

        if ($file === '' || !is_file($file) || !str_starts_with(basename($file), 'lwstorelocator_')) {
            $this->app->setUserState(self::STATE, null);
            $this->back(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_EXPIRED'), 'error');

            return;
        }

        $report = $this->importer()->run($file, (array) $state['options'], true, (string) $state['name']);
        @unlink($file);

        $this->app->setUserState(self::STATE, ['report' => $report]);

        if ($report['error'] !== '') {
            $this->back('', '');

            return;
        }

        $s = $report['stats'];
        $this->setMessage(Text::sprintf('COM_LWSTORELOCATOR_IMPORT_DONE', $s['new'], $s['updated'], $report['missing'] === 'keep' ? 0 : $s['missing']));
        $this->setRedirect(Route::_('index.php?option=com_lwstorelocator&view=import', false));
    }

    /**
     * Discard the preview.
     */
    public function cancel(): void
    {
        $this->checkToken();
        $this->cleanup();
        $this->app->setUserState(self::STATE, null);
        $this->setRedirect(Route::_('index.php?option=com_lwstorelocator&view=locations', false));
    }

    private function readOptions(): array
    {
        $params  = ComponentHelper::getParams('com_lwstorelocator');
        $missing = $this->input->getCmd('missing', 'keep');

        return [
            'missing'           => \in_array($missing, ['keep', 'unpublish', 'delete'], true) ? $missing : 'keep',
            'blank_overwrites'  => (bool) $this->input->getInt('blank_overwrites', 0),
            'skip_incomplete'   => (bool) $this->input->getInt('skip_incomplete', 0),
            'force'             => (bool) $this->input->getInt('force', 0),
            'default_published' => (int) $params->get('import_published', 1),
        ];
    }

    private function importer(): Importer
    {
        $params = ComponentHelper::getParams('com_lwstorelocator');

        return new Importer(
            Factory::getContainer()->get(DatabaseInterface::class),
            strtoupper(trim((string) $params->get('default_country', '')))
        );
    }

    private function authorise(): void
    {
        $user = $this->app->getIdentity();

        if (!$user->authorise('core.create', 'com_lwstorelocator') || !$user->authorise('core.edit', 'com_lwstorelocator')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Removes a previously uploaded file that was not applied.
     */
    private function cleanup(): void
    {
        $state = (array) $this->app->getUserState(self::STATE, []);
        $file  = (string) ($state['file'] ?? '');

        if ($file !== '' && is_file($file) && str_starts_with(basename($file), 'lwstorelocator_')) {
            @unlink($file);
        }
    }

    private function back(string $message, string $type): void
    {
        $this->setRedirect(Route::_('index.php?option=com_lwstorelocator&view=import', false), $message ?: null, $type ?: null);
    }
}
