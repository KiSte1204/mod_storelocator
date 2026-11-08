<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Service;

use Joomla\CMS\Factory;
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

    /** Reason of the last technical error (exception message or HTTP status) */
    private string $lastError = '';

    /**
     * @param   DatabaseInterface  $db              Database
     * @param   string             $contactEmail    Contact address sent to Nominatim (recommended by the usage policy)
     * @param   string             $defaultCountry  ISO code used when a location has no country
     * @param   callable|null      $lookup          Replaces the HTTP request (for tests)
     * @param   string             $stateFile       File that keeps the time of the last request and a
     *                                              Retry-After block across PHP requests ('' = off)
     */
    public function __construct(
        private DatabaseInterface $db,
        private string $contactEmail = '',
        private string $defaultCountry = '',
        ?callable $lookup = null,
        private string $stateFile = ''
    ) {
        $this->lookup = $lookup;
    }

    /**
     * Default location of the shared throttle state (Joomla's tmp folder).
     */
    public static function defaultStateFile(): string
    {
        $tmp = (string) Factory::getApplication()->get('tmp_path', JPATH_ROOT . '/tmp');

        return is_dir($tmp) && is_writable($tmp) ? rtrim($tmp, '/\\') . '/lwstorelocator_nominatim.json' : '';
    }

    /**
     * Open locations with their search stages, so the browser can geocode them itself
     * when the server is throttled by Nominatim (shared hosting IPs often are).
     *
     * @return  array<int, array{id:int, name:string, stages: array<int, array{key:string, params:array, exact:bool}>}>
     */
    public function plan(int $limit): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'name', 'address', 'postal', 'town', 'country']))
            ->from($this->db->quoteName('#__lwstorelocator_locations'))
            ->where($this->db->quoteName('geocode_status') . ' = ' . LocationTable::GEO_OPEN)
            ->order($this->db->quoteName('id'));

        $plan = [];

        foreach ($this->db->setQuery($query, 0, max(1, $limit))->loadObjectList() ?: [] as $loc) {
            $stages = [];

            foreach ($this->stages((string) $loc->address, (string) $loc->postal, (string) $loc->town, (string) $loc->country) as $key => $def) {
                $stages[] = ['key' => $key, 'params' => $def['params'], 'exact' => $def['exact']];
            }

            $plan[] = ['id' => (int) $loc->id, 'name' => (string) $loc->name, 'stages' => $stages];
        }

        return $plan;
    }

    /**
     * Stores a result found by the browser.
     *
     * @return  string  Log type: exact, approx or failed ('' = location unknown or not open)
     */
    public function store(int $id, ?float $lat, ?float $lon, string $stage, bool $exact): string
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__lwstorelocator_locations'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('geocode_status') . ' = ' . LocationTable::GEO_OPEN)
            ->bind(':id', $id, ParameterType::INTEGER);

        if (!(int) $this->db->setQuery($query)->loadResult()) {
            return '';
        }

        $valid = $lat !== null && $lon !== null && abs($lat) <= 90 && abs($lon) <= 180
            && array_key_exists($stage, ['street_postal_town' => 1, 'free_text' => 1, 'street_town' => 1, 'postal_town' => 1, 'postal' => 1, 'town' => 1]);

        if (!$valid) {
            $this->save($id, null, null, LocationTable::GEO_FAILED, '');

            return 'failed';
        }

        $this->save($id, $lat, $lon, $exact ? LocationTable::GEO_EXACT : LocationTable::GEO_APPROX, $stage);

        return $exact ? 'exact' : 'approx';
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
        $result = ['processed' => 0, 'exact' => 0, 'approx' => 0, 'failed' => 0, 'remaining' => 0, 'aborted' => false, 'reason' => '', 'log' => []];

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
                $result['reason']  = $this->lastError;
                $result['log'][]   = ['error', (int) $loc->id, (string) $loc->name, $this->lastError];
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

        $state = $this->readState();
        $now   = microtime(true);

        // Nominatim asked us to back off (HTTP 429 with Retry-After): do not hammer it
        if ($state['until'] > $now) {
            $this->lastError = 'HTTP 429, ' . (int) ceil($state['until'] - $now) . ' s';

            return false;
        }

        $wait = self::PAUSE - ($now - max(self::$lastCall, $state['last']));

        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }

        self::$lastCall = microtime(true);
        $this->writeState(self::$lastCall, 0.0);

        $params += ['format' => 'jsonv2', 'limit' => 1];

        if ($this->contactEmail !== '') {
            $params['email'] = $this->contactEmail;
        }

        $site    = rtrim(Uri::root(), '/');
        $headers = ['User-Agent' => 'LightweightStoreLocator/2.0 (Joomla; ' . $site . ')', 'Accept' => 'application/json'];

        try {
            $response = HttpFactory::getHttp()->get(self::ENDPOINT . '?' . http_build_query($params), $headers, 15);
        } catch (\Throwable $e) {
            $this->lastError = trim($e->getMessage()) ?: \get_class($e);

            return false;
        }

        $status = (int) $response->getStatusCode();

        if ($status !== 200) {
            $this->lastError = 'HTTP ' . $status;

            if ($status === 429 || $status === 503) {
                $retry = method_exists($response, 'getHeaderLine') ? (int) $response->getHeaderLine('Retry-After') : 0;
                $this->writeState(self::$lastCall, microtime(true) + max(60, min(3600, $retry ?: 300)));
            }

            return false;
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!\is_array($data)) {
            $this->lastError = 'Invalid response';

            return false;
        }

        if (isset($data[0]['lat'], $data[0]['lon'])) {
            return ['lat' => (float) $data[0]['lat'], 'lon' => (float) $data[0]['lon']];
        }

        return null;
    }

    /**
     * @return  array{last: float, until: float}
     */
    private function readState(): array
    {
        $state = ['last' => 0.0, 'until' => 0.0];

        if ($this->stateFile !== '' && is_file($this->stateFile)) {
            $data = json_decode((string) @file_get_contents($this->stateFile), true);

            if (\is_array($data)) {
                $state['last']  = (float) ($data['last'] ?? 0);
                $state['until'] = (float) ($data['until'] ?? 0);
            }
        }

        return $state;
    }

    private function writeState(float $last, float $until): void
    {
        if ($this->stateFile !== '') {
            @file_put_contents($this->stateFile, json_encode(['last' => $last, 'until' => $until]), LOCK_EX);
        }
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
