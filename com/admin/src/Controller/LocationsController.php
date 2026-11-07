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
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Webkiste\Component\LwStoreLocator\Administrator\Service\Geocoder;
use Webkiste\Component\LwStoreLocator\Administrator\Table\LocationTable;

\defined('_JEXEC') or die;

class LocationsController extends AdminController
{
    /**
     * @var  string
     */
    protected $text_prefix = 'COM_LWSTORELOCATOR_LOCATIONS';

    public function getModel($name = 'Location', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Geocodes the next batch of locations (AJAX, returns JSON).
     */
    public function geocode(): void
    {
        $app = $this->app;

        if (!Session::checkToken('get') && !Session::checkToken()) {
            $this->sendJson(null, Text::_('JINVALID_TOKEN'), true);
        }

        if (!$app->getIdentity()->authorise('core.edit', 'com_lwstorelocator')) {
            $this->sendJson(null, Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), true);
        }

        $params = ComponentHelper::getParams('com_lwstorelocator');
        $limit  = max(1, min(50, (int) $params->get('geocode_batch', 5)));

        @set_time_limit(90);

        $geocoder = new Geocoder(
            Factory::getContainer()->get(DatabaseInterface::class),
            trim((string) $params->get('contact_email', '')),
            strtoupper(trim((string) $params->get('default_country', '')))
        );

        $result = $geocoder->run($limit, 20.0);

        // Translate the log for the progress window
        $result['log'] = array_map(static function (array $entry): string {
            [$type, $id, $name, $stage] = $entry;
            $stageText = $stage !== '' ? Text::_('COM_LWSTORELOCATOR_GEOCODE_STAGE_' . strtoupper($stage)) : '';

            return Text::sprintf('COM_LWSTORELOCATOR_GEOCODE_LOG_' . strtoupper($type), $id, $name, $stageText);
        }, $result['log']);

        $this->sendJson($result);
    }

    /**
     * Resets the coordinates of the selected locations, so they are geocoded again.
     */
    public function resetGeocode(): void
    {
        $this->checkToken();

        $ids = array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array')));

        if (!$this->app->getIdentity()->authorise('core.edit', 'com_lwstorelocator')) {
            throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), 403);
        }

        if ($ids) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $open  = LocationTable::GEO_OPEN;
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__lwstorelocator_locations'))
                ->set($db->quoteName('latitude') . ' = NULL')
                ->set($db->quoteName('longitude') . ' = NULL')
                ->set($db->quoteName('geocode_status') . ' = :open')
                ->set($db->quoteName('geocode_info') . ' = ' . $db->quote(''))
                ->whereIn($db->quoteName('id'), $ids)
                ->bind(':open', $open, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            $this->setMessage(Text::plural('COM_LWSTORELOCATOR_N_ITEMS_GEOCODE_RESET', \count($ids)));
        }

        $this->setRedirect(Route::_('index.php?option=com_lwstorelocator&view=locations', false));
    }

    /**
     * Downloads all locations as CSV (same format as the import).
     */
    public function export(): void
    {
        $this->checkToken('get');

        if (!$this->app->getIdentity()->authorise('core.manage', 'com_lwstorelocator')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['external_id', 'name', 'address', 'postal', 'town', 'country', 'phone', 'email', 'website', 'latitude', 'longitude', 'published']))
                ->from($db->quoteName('#__lwstorelocator_locations'))
                ->order($db->quoteName('name'))
        )->loadAssocList() ?: [];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['external_id', 'name', 'address', 'postal', 'town', 'country', 'phone', 'email', 'website', 'latitude', 'longitude', 'published'], ';', '"', '');

        foreach ($rows as $row) {
            // Protect against formula injection in spreadsheet programs
            $row = array_map(static fn ($v) => \is_string($v) && preg_match('/^[=+\-@]/', $v) && !is_numeric($v) ? "'" . $v : $v, $row);
            fputcsv($out, array_values($row), ';', '"', '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $this->app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="store-locations-' . date('Y-m-d') . '.csv"', true);
        $this->app->sendHeaders();
        echo $csv;
        $this->app->close();
    }

    private function sendJson($data, string $message = '', bool $error = false): void
    {
        $this->app->mimeType = 'application/json';
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo new JsonResponse($data, $message, $error);
        $this->app->close();
    }
}
