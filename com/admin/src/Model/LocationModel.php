<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Webkiste\Component\LwStoreLocator\Administrator\Table\LocationTable;

\defined('_JEXEC') or die;

class LocationModel extends AdminModel
{
    /**
     * @var  string
     */
    public $typeAlias = 'com_lwstorelocator.location';

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_lwstorelocator.location', 'location', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_lwstorelocator.edit.location.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        $this->preprocessData('com_lwstorelocator.location', $data);

        return $data;
    }

    public function getTable($name = 'Location', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    /**
     * Keeps the geocoding state consistent:
     * - coordinates entered or changed by hand -> "set manually"
     * - address changed, coordinates untouched -> coordinates are reset and geocoded again
     */
    public function save($data)
    {
        $id  = (int) ($data['id'] ?? 0);
        $old = null;

        if ($id) {
            $table = $this->getTable();

            if ($table->load($id)) {
                $old = $table;
            }
        }

        $num = static fn ($v) => ($v === null || trim((string) $v) === '') ? null : round((float) str_replace(',', '.', (string) $v), 7);
        $lat = $num($data['latitude'] ?? null);
        $lng = $num($data['longitude'] ?? null);

        $coordsChanged = $old === null
            ? ($lat !== null && $lng !== null)
            : ($lat !== $num($old->latitude) || $lng !== $num($old->longitude));

        $addressChanged = $old !== null && (
            $this->norm($data['address'] ?? '') !== $this->norm($old->address)
            || $this->norm($data['postal'] ?? '') !== $this->norm($old->postal)
            || $this->norm($data['town'] ?? '') !== $this->norm($old->town)
            || strtoupper(trim((string) ($data['country'] ?? ''))) !== strtoupper((string) $old->country)
        );

        if ($coordsChanged && $lat !== null && $lng !== null) {
            $data['geocode_status'] = LocationTable::GEO_MANUAL;
            $data['geocode_info']   = 'manual';
        } elseif ($old === null || $addressChanged || $lat === null || $lng === null) {
            $data['latitude']       = null;
            $data['longitude']      = null;
            $data['geocode_status'] = LocationTable::GEO_OPEN;
            $data['geocode_info']   = '';
        }

        return parent::save($data);
    }

    private function norm($value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $value), 'UTF-8');
    }
}
