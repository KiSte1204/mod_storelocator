<?php
/**
 * Automated Geocoding Cronjob for Joomla Store Locator
 * Synchronizes missing Lat/Lng coordinates via OpenStreetMap Nominatim API
 */

// ------------------------------------------------------------------
// 1. Sicherheitsschlüssel (Token) definieren
// ------------------------------------------------------------------
$cronToken = 'll_locate_2026';

$isCli = (php_sapi_name() === 'cli');
$providedToken = $_GET['token'] ?? '';

if (!$isCli && $providedToken !== $cronToken) {
    http_response_code(403);
    die('Zugriff verweigert: Ungültiger Token.');
}

// ------------------------------------------------------------------
// 2. Joomla Framework Initialisierung
// ------------------------------------------------------------------
define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

// Datenbankverbindung abwärtskompatibel aufbauen
$db = Factory::getDbo();

// ------------------------------------------------------------------
// 3. Laufzeit- & Batch-Einstellungen (Timeout-Schutz!)
// ------------------------------------------------------------------
// Im Browser (HTTP) max. 15 Adressen (~20 Sek.), im CLI-Modus bis zu 300
$batchLimit = $isCli ? 300 : 15;

// Bedingung für ungeocodete Standorte (schließt 0, NULL, Leerstring und -99/FAILED aus)
$whereClause = '(' . $db->quoteName('latitude') . ' IS NULL OR ' . 
               $db->quoteName('latitude') . ' = "" OR ' . 
               $db->quoteName('latitude') . ' = "0" OR ' . 
               $db->quoteName('latitude') . ' = "0.00000000")';

// ------------------------------------------------------------------
// 4. Offene Datensätze zählen & Laden
// ------------------------------------------------------------------
$countQuery = $db->getQuery(true)
    ->select('COUNT(*)')
    ->from($db->quoteName('sl_locations'))
    ->where($whereClause);

$db->setQuery($countQuery);
$totalRemaining = (int)$db->loadResult();

$query = $db->getQuery(true)
    ->select(['id', 'name', 'address', 'postal', 'town', 'country'])
    ->from($db->quoteName('sl_locations'))
    ->where($whereClause)
    ->setLimit($batchLimit);

$db->setQuery($query);
$locations = $db->loadObjectList() ?: [];

// Konsole-Styling für den Browser
if (!$isCli) {
    echo '<div style="background: #1e1e1e; color: #ffffff; padding: 15px; font-family: monospace; border-radius: 5px; line-height: 1.5; margin: 20px;">';
}

echo "[" . date('Y-m-d H:i:s') . "] Geocoding gestartet. Offen gesamt: " . $totalRemaining . " | Dieser Durchgang: " . count($locations) . " Adressen.<br>\n";

if (empty($locations)) {
    echo "[" . date('Y-m-d H:i:s') . "] <span style='color:#5cb85c;'>✅ Alle Standorte wurden verarbeitet!</span><br>\n";
    if (!$isCli) { echo '</div>'; }
    exit;
}

// ------------------------------------------------------------------
// 5. Adressen einzeln geocodieren & speichern
// ------------------------------------------------------------------
$successCount = 0;
$failCount = 0;

foreach ($locations as $loc) {
    $addressParts = array_filter([$loc->address, $loc->postal, $loc->town, $loc->country]);
    $fullAddress = implode(', ', $addressParts);

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: JoomlaStoreLocatorCron/1.0 (Contact: info@laserliner.com)\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($fullAddress);

    $response = @file_get_contents($url, false, $context);
    $data = json_decode($response, true);

    if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
        $lat = (float)$data[0]['lat'];
        $lng = (float)$data[0]['lon'];

        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('sl_locations'))
            ->set($db->quoteName('latitude') . ' = ' . $db->quote((string)$lat))
            ->set($db->quoteName('longitude') . ' = ' . $db->quote((string)$lng))
            ->where($db->quoteName('id') . ' = ' . (int)$loc->id);

        $db->setQuery($updateQuery);
        $db->execute();

        $successCount++;
        echo "  <span style='color:#5cb85c;'>[OK]</span> ID {$loc->id}: " . htmlspecialchars($loc->name, ENT_QUOTES) . " (" . htmlspecialchars($loc->town, ENT_QUOTES) . ") -> Lat: $lat, Lng: $lng<br>\n";
    } else {
        $failCount++;
        
        // Auf '-99.00000000' setzen (liegt im gültigen DECIMAL-Bereich von MySQL, signalisiert 'Fehlgeschlagen')
        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('sl_locations'))
            ->set($db->quoteName('latitude') . ' = "-99.00000000"')
            ->set($db->quoteName('longitude') . ' = "-99.00000000"')
            ->where($db->quoteName('id') . ' = ' . (int)$loc->id);

        $db->setQuery($updateQuery);
        $db->execute();

        echo "  <span style='color:#d9534f;'>[FAIL]</span> ID {$loc->id}: " . htmlspecialchars($loc->name, ENT_QUOTES) . " (" . htmlspecialchars($fullAddress, ENT_QUOTES) . ") -> Nicht aufgelöst (als -99 markiert).<br>\n";
    }

    // Ausgabe direkt an den Browser streamen
    if (!$isCli) {
        @ob_flush();
        @flush();
    }

    // 1,2 Sekunden Pause gemäß Nominatim Fair-Use-Policy
    usleep(1200000);
}

$stillLeft = $totalRemaining - count($locations);
echo "[" . date('Y-m-d H:i:s') . "] Batch beendet. OK: $successCount, Fehler: $failCount. Verbleibend: " . max(0, $stillLeft) . "<br>\n";

if (!$isCli) {
    echo '</div>';
    
    // Auto-Reload für den nächsten Batch
    if ($stillLeft > 0) {
        echo '<p style="color: #d9534f; font-weight: bold; font-family: sans-serif; margin: 20px;">';
        echo '⏳ Nächster Batch startet automatisch in 3 Sekunden... (Browserfenster offen lassen)';
        echo '</p>';
        echo '<script>setTimeout(function(){ location.reload(); }, 3000);</script>';
    }
}