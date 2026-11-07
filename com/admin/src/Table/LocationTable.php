<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Table;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;
use Webkiste\Component\LwStoreLocator\Administrator\Service\Countries;

\defined('_JEXEC') or die;

/**
 * Table of the locations.
 */
class LocationTable extends Table
{
    /** Geocoding states */
    public const GEO_OPEN   = 0;
    public const GEO_EXACT  = 1;
    public const GEO_APPROX = 2;
    public const GEO_FAILED = 3;
    public const GEO_MANUAL = 4;

    /**
     * Allow NULL for coordinates and external ID.
     *
     * @var  boolean
     */
    protected $_supportNullValue = true;

    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__lwstorelocator_locations', 'id', $db);
    }

    public function check()
    {
        try {
            parent::check();
        } catch (\Exception $e) {
            $this->setError($e->getMessage());

            return false;
        }

        foreach (['name', 'address', 'postal', 'town', 'phone', 'email', 'website'] as $field) {
            $this->$field = trim(preg_replace('/\s+/u', ' ', (string) $this->$field));
        }

        if ($this->name === '') {
            $this->setError(Text::_('COM_LWSTORELOCATOR_ERROR_NAME_REQUIRED'));

            return false;
        }

        $this->external_id = trim((string) $this->external_id);
        $this->external_id = $this->external_id === '' ? null : $this->external_id;
        $this->country     = Countries::toCode((string) $this->country) ?? '';

        // Coordinates: both or none, within the valid range
        $lat = $this->latitude === null || $this->latitude === '' ? null : (float) str_replace(',', '.', (string) $this->latitude);
        $lng = $this->longitude === null || $this->longitude === '' ? null : (float) str_replace(',', '.', (string) $this->longitude);

        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            $lat = $lng = null;
        }

        $this->latitude  = $lat;
        $this->longitude = $lng;

        if ($lat === null && \in_array((int) $this->geocode_status, [self::GEO_EXACT, self::GEO_APPROX, self::GEO_MANUAL], true)) {
            $this->geocode_status = self::GEO_OPEN;
            $this->geocode_info   = '';
        }

        return true;
    }

    public function store($updateNulls = true)
    {
        $now = Factory::getDate()->toSql();

        if (!(int) $this->id) {
            $this->created = $this->created ?: $now;
        }

        $this->modified = $now;

        return parent::store($updateNulls);
    }
}
