<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Service;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Webkiste\Component\LwStoreLocator\Administrator\Table\LocationTable;

\defined('_JEXEC') or die;

/**
 * Geocodes locations with OpenStreetMap Nominatim.
 *
 * The search runs in stages from exact to coarse (street + postcode + town, free text,
 * street + town, postcode + town, postcode, town). Nominatim's usage policy is respected:
 * at most one request per second and an identifying User-Agent.
 */
class Geocoder
{
    public const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /** Seconds between two requests (Nominatim allows at most one per second) */
    private const PAUSE = 1.1;

    private static float $lastCall = 0.0;

    /** @var callable|null  Test hook: fn(array $params): array|false|null */
    private $lookup;

    /**
     * @param   DatabaseInterface  $db              Database
     * @param   string             $contactEmail    Contact address sent to Nominatim (recommended by the usage policy)
     * @param   string             $defaultCountry  ISO code used when a location has no country
     * @param   callable|null      $lookup          Replaces the HTTP request (for tests)
     */
    public function __construct(
        private DatabaseInterface $db,
        private string $contactEmail = '',
        private string $defaultCountry = '',
        ?callable $lookup = null
    ) {
        $this->lookup = $lookup;
    }

    /**
     * Number of locations that still need coordinates.
     */
    public function pendingCount(): int
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__lwstorelocator_locations'))
            ->where($this->db->quoteName('geocode_status') . ' = ' . LocationTable::GEO_OPEN);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Geocodes open locations until $limit is reached or $maxSeconds have passed.
     *
     * @return  array{processed:int, exact:int, approx:int, failed:int, remaining:int, aborted:bool, log:string[]}
     */
    public function run(int $limit, float $maxSeconds = 20.0): array
    {
        $start  = microtime(true);
        $result = ['processed' => 0, 'exact' => 0, 'approx' => 0, 'failed' => 0, 'remaining' => 0, 'aborted' => false, 'log' => []];

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'name', 'address', 'postal', 'town', 'country']))
            ->from($this->db->quoteName('#__lwstorelocator_locations'))
            ->where($this->db->quoteName('geocode_status') . ' = ' . LocationTable::GEO_OPEN)
            ->order($this->db->quoteName('id'));

        $locations = $this->db->setQuery($query, 0, max(1, $limit))->loadObjectList() ?: [];

        foreach ($locations as $loc) {
            // Leave enough time for the slowest case (all stages) of the next location
            if ($result['processed'] > 0 && (microtime(true) - $start) > $maxSeconds) {
                break;
            }

            $hit = $this->geocode((string) $loc->address, (string) $loc->postal, (string) $loc->town, (string) $loc->country);

            if ($hit === false) {
                // Technical problem (network, HTTP 429/5xx): keep the location open and stop the batch
                $result['aborted'] = true;
                $result['log'][]   = ['error', (int) $loc->id, (string) $loc->name, ''];
                break;
            }

            $result['processed']++;

            if ($hit === null) {
                $this->save((int) $loc->id, null, null, LocationTable::GEO_FAILED, '');
                $result['failed']++;
                $result['log'][] = ['failed', (int) $loc->id, (string) $loc->name, ''];

                continue;
            }

            $status = $hit['exact'] ? LocationTable::GEO_EXACT : LocationTable::GEO_APPROX;
            $this->save((int) $loc->id, $hit['lat'], $hit['lon'], $status, $hit['stage']);
            $result[$hit['exact'] ? 'exact' : 'approx']++;
            $result['log'][] = [$hit['exact'] ? 'exact' : 'approx', (int) $loc->id, (string) $loc->name, $hit['stage']];
        }

        $result['remaining'] = $this->pendingCount();

        return $result;
    }

    /**
     * Search stages for an address (public for tests).
     *
     * @return  array<string, array{params: array<string, string>, exact: bool}>
     */
    public function stages(string $street, string $postal, string $town, string $country): array
    {
        // Unknown country names are ignored rather than sent as a wrong filter
        $code   = Countries::toCode($country);
        $code   = ($code === '') ? $this->defaultCountry : (string) $code;
        $filter = $code !== '' ? ['countrycodes' => strtolower($code)] : [];

        // "Lahr/Schw." -> "Lahr", "Linsengericht 1" -> "Linsengericht", "Str.- " -> "Str.-"
        $street    = trim(preg_replace('/\s+/u', ' ', str_replace('- ', '-', $street)));
        $postal    = trim($postal);
        $town      = trim(preg_replace('/\s+\d+$/u', '', preg_replace('#\s*/.*$#u', '', trim($town))));
        $hasStreet = $street !== '' && !preg_match('/^(postbus|postfach|p\.?\s*o\.?\s*box|bp\s)/iu', $street);

        $stages = [];

        if ($hasStreet) {
            $stages['street_postal_town'] = ['params' => array_filter(['street' => $street, 'postalcode' => $postal, 'city' => $town]) + $filter, 'exact' => true];
            $stages['free_text']          = ['params' => ['q' => trim($street . ', ' . trim($postal . ' ' . $town), ', ')] + $filter, 'exact' => true];

            if ($town !== '') {
                $stages['street_town'] = ['params' => ['street' => $street, 'city' => $town] + $filter, 'exact' => true];
            }
        }

        if ($postal !== '' && $town !== '') {
            $stages['postal_town'] = ['params' => ['postalcode' => $postal, 'city' => $town] + $filter, 'exact' => false];
        }

        if ($postal !== '') {
            $stages['postal'] = ['params' => ['postalcode' => $postal] + $filter, 'exact' => false];
        }

        if ($town !== '') {
            $stages['town'] = ['params' => ['city' => $town] + $filter, 'exact' => false];
        }

        return $stages;
    }

    /**
     * Geocodes one address.
     *
     * @return  array{lat: float, lon: float, exact: bool, stage: string}|null|false
     *          Hit, null = nothing found, false = technical error
     */
    public function geocode(string $street, string $postal, string $town, string $country)
    {
        foreach ($this->stages($street, $postal, $town, $country) as $stage => $def) {
            $hit = $this->lookup($def['params']);

            if ($hit === false) {
                return false;
            }

            if ($hit !== null) {
                return ['lat' => $hit['lat'], 'lon' => $hit['lon'], 'exact' => $def['exact'], 'stage' => $stage];
            }
        }

        return null;
    }

    /**
     * One Nominatim request.
     *
     * @return  array{lat: float, lon: float}|null|false
     */
    private function lookup(array $params)
    {
        if ($this->lookup) {
            return ($this->lookup)($params);
        }

        $wait = self::PAUSE - (microtime(true) - self::$lastCall);

        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }

        self::$lastCall = microtime(true);

        $params += ['format' => 'jsonv2', 'limit' => 1];

        if ($this->contactEmail !== '') {
            $params['email'] = $this->contactEmail;
        }

        $site    = rtrim(Uri::root(), '/');
        $headers = ['User-Agent' => 'LightweightStoreLocator/2.0 (Joomla; ' . $site . ')', 'Accept' => 'application/json'];

        try {
            $response = HttpFactory::getHttp()->get(self::ENDPOINT . '?' . http_build_query($params), $headers, 15);
        } catch (\Throwable $e) {
            return false;
        }

        if ((int) $response->getStatusCode() !== 200) {
            return false;
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!\is_array($data)) {
            return false;
        }

        if (isset($data[0]['lat'], $data[0]['lon'])) {
            return ['lat' => (float) $data[0]['lat'], 'lon' => (float) $data[0]['lon']];
        }

        return null;
    }

    private function save(int $id, ?float $lat, ?float $lon, int $status, string $info): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__lwstorelocator_locations'))
            ->set($this->db->quoteName('geocode_status') . ' = :status')
            ->set($this->db->quoteName('geocode_info') . ' = :info')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $status, ParameterType::INTEGER)
            ->bind(':info', $info)
            ->bind(':id', $id, ParameterType::INTEGER);

        if ($lat === null) {
            $query->set($this->db->quoteName('latitude') . ' = NULL')
                ->set($this->db->quoteName('longitude') . ' = NULL');
        } else {
            $latStr = number_format($lat, 7, '.', '');
            $lonStr = number_format($lon, 7, '.', '');
            $query->set($this->db->quoteName('latitude') . ' = :lat')
                ->set($this->db->quoteName('longitude') . ' = :lon')
                ->bind(':lat', $latStr)
                ->bind(':lon', $lonStr);
        }

        $this->db->setQuery($query)->execute();
    }
}
