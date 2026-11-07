<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  mod_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Module\LwStoreLocator\Site\Helper;

use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;

\defined('_JEXEC') or die;

class StoreLocatorHelper implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Published locations with coordinates. The email address is deliberately not loaded,
     * because all data ends up in the page source.
     *
     * @return  array<int, array>
     */
    public function getLocations(): array
    {
        $db = $this->getDatabase();

        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'name', 'address', 'postal', 'town', 'country', 'latitude', 'longitude', 'phone', 'website']))
                ->from($db->quoteName('#__lwstorelocator_locations'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('latitude') . ' IS NOT NULL')
                ->where($db->quoteName('longitude') . ' IS NOT NULL')
                ->order($db->quoteName('name'));

            $rows = $db->setQuery($query)->loadAssocList() ?: [];
        } catch (\RuntimeException $e) {
            // Component not installed (yet)
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'address' => (string) $r['address'],
            'postal'  => (string) $r['postal'],
            'town'    => (string) $r['town'],
            'country' => (string) $r['country'],
            'lat'     => round((float) $r['latitude'], 6),
            'lng'     => round((float) $r['longitude'], 6),
            'phone'   => (string) $r['phone'],
            'website' => (string) $r['website'],
        ], $rows);
    }

    /**
     * Valid colour or the default.
     */
    public static function color(string $value, string $default): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : $default;
    }
}
