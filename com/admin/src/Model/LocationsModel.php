<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

class LocationsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'name', 'a.name',
                'town', 'a.town',
                'postal', 'a.postal',
                'country', 'a.country',
                'published', 'a.published',
                'geocode_status', 'a.geocode_status',
                'modified', 'a.modified',
            ];
        }

        parent::__construct($config);
    }

    protected function populateState($ordering = 'a.name', $direction = 'asc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.published');
        $id .= ':' . $this->getState('filter.geocode_status');
        $id .= ':' . $this->getState('filter.country');

        return parent::getStoreId($id);
    }

    protected function getListQuery(): QueryInterface
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('a.*')
            ->select($db->quoteName('uc.name', 'editor'))
            ->from($db->quoteName('#__lwstorelocator_locations', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'uc'), $db->quoteName('uc.id') . ' = ' . $db->quoteName('a.checked_out'));

        $published = (string) $this->getState('filter.published');

        if (is_numeric($published)) {
            $published = (int) $published;
            $query->where($db->quoteName('a.published') . ' = :published')
                ->bind(':published', $published, ParameterType::INTEGER);
        }

        $status = (string) $this->getState('filter.geocode_status');

        if (is_numeric($status)) {
            $status = (int) $status;
            $query->where($db->quoteName('a.geocode_status') . ' = :status')
                ->bind(':status', $status, ParameterType::INTEGER);
        }

        $country = (string) $this->getState('filter.country');

        if ($country !== '') {
            $query->where($db->quoteName('a.country') . ' = :country')
                ->bind(':country', $country);
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $id = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :id')
                    ->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(' ', '%', $search) . '%';
                $query->where(
                    '(' . $db->quoteName('a.name') . ' LIKE :s1'
                    . ' OR ' . $db->quoteName('a.town') . ' LIKE :s2'
                    . ' OR ' . $db->quoteName('a.postal') . ' LIKE :s3'
                    . ' OR ' . $db->quoteName('a.address') . ' LIKE :s4'
                    . ' OR ' . $db->quoteName('a.external_id') . ' LIKE :s5)'
                )
                    ->bind(':s1', $like)
                    ->bind(':s2', $like)
                    ->bind(':s3', $like)
                    ->bind(':s4', $like)
                    ->bind(':s5', $like);
            }
        }

        $ordering  = $this->state->get('list.ordering', 'a.name');
        $direction = strtoupper($this->state->get('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $query->order($db->escape($ordering) . ' ' . $direction);

        return $query;
    }

    /**
     * Counts per geocoding state (for the overview above the list).
     *
     * @return  array<int, int>
     */
    public function getGeocodeCounts(): array
    {
        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('geocode_status'), 'COUNT(*) AS ' . $db->quoteName('n')])
                ->from($db->quoteName('#__lwstorelocator_locations'))
                ->group($db->quoteName('geocode_status'))
        )->loadAssocList('geocode_status', 'n') ?: [];

        return array_map('intval', $rows);
    }

    /**
     * Countries used by the locations (for the filter).
     *
     * @return  string[]
     */
    public function getCountries(): array
    {
        $db = $this->getDatabase();

        return $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->quoteName('country'))
                ->from($db->quoteName('#__lwstorelocator_locations'))
                ->where($db->quoteName('country') . ' <> ' . $db->quote(''))
                ->order($db->quoteName('country'))
        )->loadColumn() ?: [];
    }
}
