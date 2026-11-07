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
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Webkiste\Component\LwStoreLocator\Administrator\Table\LocationTable;

\defined('_JEXEC') or die;

/**
 * Imports locations from a CSV or XLSX file.
 *
 * - Columns are recognised by their heading (several languages and common export formats,
 *   e.g. "Account-ID", "Straße (Postanschrift)", "PLZ", "City", "Zip").
 * - Rows are matched by the external ID; without an ID column by name + postcode + street.
 * - Coordinates are kept; they are only reset when the address really changes.
 * - Locations missing in the file can be kept, unpublished or deleted. A safety limit
 *   stops the import if suspiciously many locations would be removed (truncated export).
 * - A preview (dry run) shows all changes before anything is written.
 */
class Importer
{
    /** Fields and the headings that are recognised for them (normalised with Importer::headerKey()) */
    public const COLUMNS = [
        'external_id' => ['externalid', 'extid', 'accountid', 'sfid', 'salesforceid', 'customerid', 'kundenid', 'storeid', 'locationid', 'dealerid', 'haendlerid', 'shopid', 'reference', 'referenz', 'uid', 'guid'],
        'name'        => ['name', 'accountname', 'company', 'companyname', 'firma', 'firmenname', 'haendler', 'dealer', 'dealername', 'store', 'storename', 'shop', 'shopname', 'title', 'titel', 'location', 'standort', 'nom', 'naam', 'nome', 'nombre'],
        'address'     => ['address', 'street', 'streetaddress', 'address1', 'addressline1', 'strasse', 'str', 'adresse', 'anschrift', 'strasseundhausnummer', 'rue', 'straat', 'via', 'calle', 'direccion', 'indirizzo'],
        'postal'      => ['postal', 'postalcode', 'postcode', 'zip', 'zipcode', 'plz', 'postleitzahl', 'codepostal', 'cp', 'cap', 'codigopostal'],
        'town'        => ['town', 'city', 'stadt', 'ort', 'locality', 'ville', 'plaats', 'citta', 'ciudad', 'gemeinde'],
        'country'     => ['country', 'countrycode', 'land', 'laendercode', 'pays', 'paese', 'pais', 'staat'],
        'phone'       => ['phone', 'phonenumber', 'telephone', 'telefon', 'telefonnummer', 'tel', 'telefono', 'telephone', 'telefoon'],
        'email'       => ['email', 'mail', 'emailaddress', 'emailadresse', 'courriel'],
        'website'     => ['website', 'web', 'url', 'homepage', 'internet', 'webseite', 'internetseite', 'siteweb', 'sitio'],
        'latitude'    => ['latitude', 'lat', 'breitengrad', 'breite'],
        'longitude'   => ['longitude', 'lng', 'lon', 'long', 'laengengrad', 'laenge'],
        'published'   => ['published', 'active', 'aktiv', 'enabled', 'veroeffentlicht', 'visible', 'sichtbar', 'state'],
    ];

    /** Fields compared and written by the import */
    private const DATA_FIELDS = ['name', 'address', 'postal', 'town', 'country', 'phone', 'email', 'website'];

    /** A change in one of these fields requires new coordinates */
    private const GEO_FIELDS = ['address', 'postal', 'town', 'country'];

    /** Maximum number of change lines kept in the report */
    private const MAX_LOG = 1000;

    private const TABLE = '#__lwstorelocator_locations';

    public function __construct(private DatabaseInterface $db, private string $defaultCountry = '')
    {
    }

    /**
     * Runs the import.
     *
     * @param   string  $file     CSV or XLSX file
     * @param   array   $options  missing (keep|unpublish|delete), blank_overwrites (bool), force (bool),
     *                            skip_incomplete (bool), default_published (int), max_delete_ratio (float)
     * @param   bool    $apply    false = preview only
     */
    public function run(string $file, array $options, bool $apply, ?string $originalName = null): array
    {
        $options += [
            'missing'           => 'keep',
            'blank_overwrites'  => false,
            'force'             => false,
            'skip_incomplete'   => false,
            'default_published' => 1,
            'max_delete_ratio'  => 0.25,
        ];

        if (!\in_array($options['missing'], ['keep', 'unpublish', 'delete'], true)) {
            $options['missing'] = 'keep';
        }

        $report = [
            'apply'    => $apply,
            'file'     => $originalName ?? basename($file),
            'missing'  => $options['missing'],
            'columns'  => [],
            'rows'     => 0,
            'valid'    => 0,
            'stats'    => ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'regeocode' => 0, 'linked' => 0, 'missing' => 0, 'skipped' => 0],
            'changes'  => [],
            'warnings' => [],
            'error'    => '',
        ];

        try {
            [$header, $rows] = self::readTable($file);
            $map               = self::mapColumns($header);
            $report['columns'] = array_map(static fn ($i) => (string) $header[$i], $map);
            $report['rows']    = \count($rows);

            if (!isset($map['name'])) {
                throw new \RuntimeException(Text::sprintf('COM_LWSTORELOCATOR_IMPORT_ERROR_NO_NAME', implode(' | ', array_filter($header))));
            }

            if (!isset($map['address']) && !isset($map['postal']) && !isset($map['town']) && !isset($map['latitude'])) {
                throw new \RuntimeException(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_NO_ADDRESS'));
            }

            $items = $this->normalise($rows, $map, $options, $report);
            $report['valid']           = \count($items);
            $report['stats']['skipped'] = $report['rows'] - \count($items);

            if (!$items) {
                throw new \RuntimeException(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_NO_ROWS'));
            }

            $this->sync($items, $map, $options, $apply, $report);
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * @return  array{0: string[], 1: array<int, array{line: int, cells: string[]}>}
     */
    public static function readTable(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException(Text::sprintf('COM_LWSTORELOCATOR_IMPORT_ERROR_FILE', basename($file)));
        }

        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $rows = $ext === 'xlsx' ? self::readXlsx($file) : self::readCsv($file);

        // Skip empty lines before the heading
        while ($rows && !array_filter($rows[0], static fn ($c) => trim((string) $c) !== '')) {
            array_shift($rows);
        }

        if (!$rows) {
            throw new \RuntimeException(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_EMPTY'));
        }

        $header = array_map(static fn ($h) => trim((string) $h), array_shift($rows));
        $data   = [];

        foreach ($rows as $n => $cells) {
            if (!array_filter($cells, static fn ($c) => trim((string) $c) !== '')) {
                continue;
            }

            $data[] = ['line' => $n + 2, 'cells' => array_map(static fn ($c) => (string) $c, $cells)];
        }

        return [$header, $data];
    }

    public static function readCsv(string $file): array
    {
        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new \RuntimeException(Text::sprintf('COM_LWSTORELOCATOR_IMPORT_ERROR_FILE', basename($file)));
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        // Delimiter: the one that occurs most often in the first line
        $first = (string) strtok($raw, "\n");
        $delim = ';';
        $best  = -1;

        foreach ([';', ',', "\t", '|'] as $d) {
            $n = substr_count($first, $d);

            if ($n > $best) {
                $best  = $n;
                $delim = $d;
            }
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);

        $rows = [];

        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null]) {
                $rows[] = [];
                continue;
            }

            $rows[] = $r;
        }

        fclose($fh);

        return $rows;
    }

    public static function readXlsx(string $file): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_ZIP'));
        }

        $zip = new \ZipArchive();

        if ($zip->open($file) !== true) {
            throw new \RuntimeException(Text::sprintf('COM_LWSTORELOCATOR_IMPORT_ERROR_FILE', basename($file)));
        }

        $shared = [];
        $ssXml  = $zip->getFromName('xl/sharedStrings.xml');

        if ($ssXml !== false) {
            $ss = simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NONET);

            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $t = '';

                    foreach ($si->r as $r) {
                        $t .= (string) $r->t;
                    }

                    $shared[] = $t;
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException(Text::_('COM_LWSTORELOCATOR_IMPORT_ERROR_EMPTY'));
        }

        $sheet = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
        $rows  = [];

        foreach ($sheet->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $c) {
                $col = 0;

                foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper((string) $c['r']))) as $ch) {
                    $col = $col * 26 + (\ord($ch) - 64);
                }

                $type = (string) $c['t'];

                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string) $c->is->t : '';

                    if ($val === '' && isset($c->is->r)) {
                        foreach ($c->is->r as $r) {
                            $val .= (string) $r->t;
                        }
                    }
                } else {
                    $val = (string) $c->v;
                }

                $cells[max(0, $col - 1)] = $val;
            }

            $line = [];

            if ($cells) {
                for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
            }

            $rows[] = $line;
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Columns and normalisation
    // ------------------------------------------------------------------

    /**
     * Heading -> comparable key: lower case, without text in brackets, umlauts transliterated, only a-z0-9.
     */
    public static function headerKey(string $heading): string
    {
        return self::translit(preg_replace('/\([^)]*\)|\[[^\]]*\]/u', '', $heading));
    }

    private static function translit(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        }

        return preg_replace('/[^a-z0-9]/', '', $s);
    }

    /**
     * @return  array<string, int>  field => column index
     */
    public static function mapColumns(array $header): array
    {
        $map = [];

        foreach ($header as $i => $heading) {
            $key = self::headerKey((string) $heading);

            if ($key === '') {
                continue;
            }

            foreach (self::COLUMNS as $field => $aliases) {
                if (!isset($map[$field]) && \in_array($key, $aliases, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    public static function normalisePostal(string $postal, string $country): string
    {
        $postal = trim(preg_replace('/\s+/u', ' ', $postal));

        if ($country === 'DE' && preg_match('/^\d{1,5}$/', $postal)) {
            // Spreadsheets drop the leading zero: 1067 -> 01067
            return str_pad($postal, 5, '0', STR_PAD_LEFT);
        }

        if ($country === 'NL' && preg_match('/^(\d{4})\s?([A-Za-z]{2})$/', $postal, $m)) {
            return $m[1] . ' ' . strtoupper($m[2]);
        }

        if (\in_array($country, ['GB', 'IE', 'CA'], true)) {
            return strtoupper($postal);
        }

        return $postal;
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function key(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $s), 'UTF-8');
    }

    private static function parsePublished(string $value): ?int
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        if ($value === '') {
            return null;
        }

        if (\in_array($value, ['1', 'yes', 'y', 'ja', 'j', 'true', 'x', 'active', 'aktiv', 'published', 'oui', 'si', 'sì'], true)) {
            return 1;
        }

        if (\in_array($value, ['0', 'no', 'n', 'nein', 'false', 'inactive', 'inaktiv', 'unpublished', 'non', '-'], true)) {
            return 0;
        }

        return null;
    }

    private static function parseCoordinate(string $value, float $limit): ?float
    {
        $value = trim(str_replace(',', '.', $value));

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $f = (float) $value;

        return abs($f) <= $limit ? $f : null;
    }

    private function normalise(array $rows, array $map, array $options, array &$report): array
    {
        $items = [];
        $seen  = [];

        foreach ($rows as $row) {
            $cell = static fn (string $field): string => isset($map[$field]) ? self::clean((string) ($row['cells'][$map[$field]] ?? '')) : '';
            $line = $row['line'];
            $name = $cell('name');

            if ($name === '') {
                $report['warnings'][] = Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARN_NO_NAME', $line);
                continue;
            }

            $countryRaw = $cell('country');
            $country    = Countries::toCode($countryRaw);

            if ($country === null) {
                $report['warnings'][] = Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARN_COUNTRY', $line, $name, $countryRaw);
                $country = '';
            }

            if ($country === '' && !isset($map['country'])) {
                $country = $this->defaultCountry;
            } elseif ($country === '' && $countryRaw === '') {
                $country = $this->defaultCountry;
            }

            $item = [
                'line'        => $line,
                'external_id' => $cell('external_id'),
                'name'        => $name,
                'address'     => $cell('address'),
                'postal'      => self::normalisePostal($cell('postal'), $country),
                'town'        => $cell('town'),
                'country'     => $country,
                'phone'       => $cell('phone'),
                'email'       => $cell('email'),
                'website'     => $cell('website'),
                'latitude'    => self::parseCoordinate($cell('latitude'), 90),
                'longitude'   => self::parseCoordinate($cell('longitude'), 180),
                'published'   => isset($map['published']) ? self::parsePublished($cell('published')) : null,
            ];

            if ($item['latitude'] === null || $item['longitude'] === null || ($item['latitude'] == 0.0 && $item['longitude'] == 0.0)) {
                $item['latitude'] = $item['longitude'] = null;
            }

            $missingParts = [];

            foreach (['address' => 'COM_LWSTORELOCATOR_FIELD_ADDRESS_LABEL', 'postal' => 'COM_LWSTORELOCATOR_FIELD_POSTAL_LABEL', 'town' => 'COM_LWSTORELOCATOR_FIELD_TOWN_LABEL'] as $field => $label) {
                if ($item[$field] === '') {
                    $missingParts[] = Text::_($label);
                }
            }

            if ($item['latitude'] === null && $item['postal'] === '' && $item['town'] === '') {
                $report['warnings'][] = Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARN_NO_PLACE', $line, $name);
                continue;
            }

            if ($missingParts && $options['skip_incomplete'] && $item['latitude'] === null) {
                $report['warnings'][] = Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARN_INCOMPLETE_SKIPPED', $line, $name, implode(', ', $missingParts));
                continue;
            }

            if ($missingParts) {
                $report['warnings'][] = Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARN_INCOMPLETE', $line, $name, implode(', ', $missingParts));
            }

            $dupKey = $item['external_id'] !== ''
                ? 'id:' . $item['external_id']
                : 'n:' . self::key($name) . '|' . self::key($item['postal']) . '|' . self::key($item['address']);

            if (isset($seen[$dupKey])) {
                $report['warnings'][] = Text::sprintf('COM_LWSTORELOCATOR_IMPORT_WARN_DUPLICATE', $line, $name, $seen[$dupKey]);
                continue;
            }

            $seen[$dupKey] = $line;
            $items[]       = $item;
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // Synchronisation
    // ------------------------------------------------------------------

    private function sync(array $items, array $map, array $options, bool $apply, array &$report): void
    {
        $db       = $this->db;
        $existing = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName(self::TABLE)))->loadAssocList() ?: [];

        $byExt  = [];
        $byKey  = [];
        $byName = [];

        foreach ($existing as $row) {
            if ((string) $row['external_id'] !== '') {
                $byExt[(string) $row['external_id']] = $row;
            }

            $byKey[self::key((string) $row['name']) . '|' . self::key((string) $row['postal']) . '|' . self::key((string) $row['address'])][] = $row;
            $byName[self::key((string) $row['name']) . '|' . self::key((string) $row['postal'])][] = $row;
        }

        // Only fields that exist in the file are compared and written
        $fields  = array_values(array_filter(self::DATA_FIELDS, static fn ($f) => isset($map[$f])));
        $matched = [];
        $now     = Factory::getDate()->toSql();
        $plan    = [];

        foreach ($items as $it) {
            $hit = null;

            if ($it['external_id'] !== '' && isset($byExt[$it['external_id']])) {
                $hit = $byExt[$it['external_id']];
            } else {
                // Rows without (or with a new) external ID: find them by name + postcode + street
                $candidates = $byKey[self::key($it['name']) . '|' . self::key($it['postal']) . '|' . self::key($it['address'])]
                    ?? $byName[self::key($it['name']) . '|' . self::key($it['postal'])]
                    ?? [];

                foreach ($candidates as $c) {
                    $free = !isset($matched[$c['id']]) && ($it['external_id'] === '' || (string) $c['external_id'] === '');

                    if ($free) {
                        $hit = $c;
                        break;
                    }
                }

                if ($hit && $it['external_id'] !== '') {
                    $report['stats']['linked']++;
                }
            }

            $place = trim($it['country'] . ' ' . $it['postal'] . ' ' . $it['town']);

            if ($hit === null) {
                $report['stats']['new']++;
                $this->log($report, 'new', $it['name'], $place, []);

                $plan[] = ['insert', $it];
                continue;
            }

            $matched[$hit['id']] = true;
            $set                 = [];
            $diff                = [];

            foreach ($fields as $f) {
                $old = (string) ($hit[$f] ?? '');
                $new = (string) $it[$f];

                if ($new === '' && !$options['blank_overwrites']) {
                    continue;
                }

                if ($old !== $new) {
                    $set[$f] = $new;
                    $diff[]  = $f . ': "' . $old . '" → "' . $new . '"';
                }
            }

            if ($it['external_id'] !== '' && (string) $hit['external_id'] !== $it['external_id']) {
                $set['external_id'] = $it['external_id'];
            }

            if ($it['published'] !== null && (int) $hit['published'] !== $it['published']) {
                $set['published'] = $it['published'];
                $diff[]           = 'published: ' . (int) $hit['published'] . ' → ' . $it['published'];
            }

            $geoChanged = false;

            foreach (self::GEO_FIELDS as $f) {
                if (\array_key_exists($f, $set) && self::key((string) $hit[$f]) !== self::key((string) $set[$f])) {
                    $geoChanged = true;
                }
            }

            if ($it['latitude'] !== null) {
                if (abs((float) $hit['latitude'] - $it['latitude']) > 0.0000001 || abs((float) $hit['longitude'] - $it['longitude']) > 0.0000001 || $hit['latitude'] === null) {
                    $set['latitude']       = $it['latitude'];
                    $set['longitude']      = $it['longitude'];
                    $set['geocode_status'] = LocationTable::GEO_MANUAL;
                    $set['geocode_info']   = 'import';
                    $diff[]                = 'coordinates: ' . $it['latitude'] . ', ' . $it['longitude'];
                }
            } elseif ($geoChanged) {
                $set['latitude']       = null;
                $set['longitude']      = null;
                $set['geocode_status'] = LocationTable::GEO_OPEN;
                $set['geocode_info']   = '';
                $report['stats']['regeocode']++;
            }

            if ($diff) {
                $report['stats']['updated']++;
                $this->log($report, 'update', $it['name'], $place, $diff, $geoChanged && $it['latitude'] === null);
            } else {
                $report['stats']['unchanged']++;
            }

            if ($set) {
                $set['modified'] = $now;
                $plan[]          = ['update', (int) $hit['id'], $set];
            }
        }

        // Locations that are not in the file (any more)
        $missing = array_values(array_filter($existing, static fn ($row) => !isset($matched[$row['id']])));
        $report['stats']['missing'] = \count($missing);

        if ($missing && $options['missing'] !== 'keep') {
            $limit = max(10, (int) ceil($options['max_delete_ratio'] * \count($existing)));

            if (\count($missing) > $limit && !$options['force']) {
                throw new \RuntimeException(Text::sprintf('COM_LWSTORELOCATOR_IMPORT_ERROR_SAFETY', \count($missing), \count($existing), $limit));
            }

            foreach ($missing as $row) {
                if ($options['missing'] === 'unpublish' && (int) $row['published'] === 0) {
                    continue;
                }

                $this->log($report, $options['missing'], (string) $row['name'], trim($row['country'] . ' ' . $row['postal'] . ' ' . $row['town']), []);
                $plan[] = [$options['missing'], (int) $row['id']];
            }
        }

        if (!$apply) {
            return;
        }

        $db->transactionStart();

        try {
            foreach ($plan as $step) {
                switch ($step[0]) {
                    case 'insert':
                        $this->insert($step[1], (int) $options['default_published'], $now);
                        break;

                    case 'update':
                        $this->update($step[1], $step[2]);
                        break;

                    case 'unpublish':
                        $this->update($step[1], ['published' => 0, 'modified' => $now]);
                        break;

                    case 'delete':
                        $id    = $step[1];
                        $query = $db->getQuery(true)
                            ->delete($db->quoteName(self::TABLE))
                            ->where($db->quoteName('id') . ' = :id')
                            ->bind(':id', $id, ParameterType::INTEGER);
                        $db->setQuery($query)->execute();
                        break;
                }
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
    }

    private function insert(array $it, int $defaultPublished, string $now): void
    {
        $hasCoords = $it['latitude'] !== null;
        $row       = (object) [
            'external_id'    => $it['external_id'] !== '' ? $it['external_id'] : null,
            'name'           => mb_substr($it['name'], 0, 255),
            'address'        => mb_substr($it['address'], 0, 255),
            'postal'         => mb_substr($it['postal'], 0, 20),
            'town'           => mb_substr($it['town'], 0, 100),
            'country'        => $it['country'],
            'phone'          => mb_substr($it['phone'], 0, 50),
            'email'          => mb_substr($it['email'], 0, 255),
            'website'        => mb_substr($it['website'], 0, 255),
            'latitude'       => $it['latitude'],
            'longitude'      => $it['longitude'],
            'geocode_status' => $hasCoords ? LocationTable::GEO_MANUAL : LocationTable::GEO_OPEN,
            'geocode_info'   => $hasCoords ? 'import' : '',
            'published'      => $it['published'] ?? $defaultPublished,
            'created'        => $now,
            'modified'       => $now,
        ];

        $this->db->insertObject(self::TABLE, $row, 'id');
    }

    private function update(int $id, array $set): void
    {
        $limits = ['name' => 255, 'address' => 255, 'postal' => 20, 'town' => 100, 'phone' => 50, 'email' => 255, 'website' => 255];

        foreach ($limits as $f => $len) {
            if (isset($set[$f])) {
                $set[$f] = mb_substr((string) $set[$f], 0, $len);
            }
        }

        $row = (object) (['id' => $id] + $set);
        $this->db->updateObject(self::TABLE, $row, 'id', true);
    }

    private function log(array &$report, string $type, string $name, string $place, array $details, bool $regeocode = false): void
    {
        if (\count($report['changes']) >= self::MAX_LOG) {
            return;
        }

        $report['changes'][] = ['type' => $type, 'name' => $name, 'place' => $place, 'details' => $details, 'regeocode' => $regeocode];
    }
}
