<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  mod_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Module\LwStoreLocator\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Webkiste\Module\LwStoreLocator\Site\Helper\StoreLocatorHelper;

\defined('_JEXEC') or die;

class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    protected function getLayoutData(): array
    {
        $data   = parent::getLayoutData();
        $params = $data['params'];
        $app    = $this->getApplication();
        $mode   = $params->get('display_mode', 'full') === 'compact' ? 'compact' : 'full';

        $targetId  = (int) $params->get('target_itemid', 0);
        $privacyId = (int) $params->get('privacy_itemid', 0);
        $langCode  = strtolower(substr((string) $app->getLanguage()->getTag(), 0, 2));

        $locations = [];

        if ($mode === 'full') {
            $locations = $this->getHelperFactory()->getHelper('StoreLocatorHelper')->getLocations();
        }

        // Countries for the town search: setting, otherwise the countries of the locations
        $countries = array_filter(array_map(
            static fn ($c) => strtolower(trim($c)),
            explode(',', (string) $params->get('search_countries', ''))
        ), static fn ($c) => preg_match('/^[a-z]{2}$/', $c));

        if (!$countries) {
            $countries = array_values(array_unique(array_filter(array_map(static fn ($l) => strtolower($l['country']), $locations))));
        }

        $radius = (int) $params->get('default_radius', 25);

        $data['lwsl'] = [
            'id'         => 'lwsl-' . (int) $data['module']->id,
            'mode'       => $mode,
            'targetUrl'  => $targetId ? Route::_('index.php?Itemid=' . $targetId, false) : '',
            'privacyUrl' => $privacyId ? Route::_('index.php?Itemid=' . $privacyId, false) : '',
            'radius'     => \in_array($radius, [10, 25, 50, 100, 200], true) ? $radius : 25,
            'height'     => max(250, min(1200, (int) $params->get('map_height', 520))),
            'consent'    => (bool) $params->get('require_consent', 1),
            'consentText' => trim((string) $params->get('consent_text', '')),
            'countryFilter' => (bool) $params->get('country_filter', 0),
            'colors'     => [
                'accent'    => StoreLocatorHelper::color((string) $params->get('accent_color', ''), '#0066cc'),
                'secondary' => StoreLocatorHelper::color((string) $params->get('secondary_color', ''), '#222222'),
                'marker'    => StoreLocatorHelper::color((string) $params->get('marker_color', ''), '#d9534f'),
            ],
            'config'     => [
                'lang'        => $langCode,
                'leaflet'     => Uri::root(true) . '/media/mod_lwstorelocator/leaflet/',
                'tiles'       => (string) $params->get('tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
                'attribution' => (string) $params->get('tile_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'),
                'countries'   => array_values($countries),
                'directions'  => (string) $params->get('directions', 'google'),
                'showPhone'   => (bool) $params->get('show_phone', 1),
                'showWebsite' => (bool) $params->get('show_website', 1),
                'consent'     => (bool) $params->get('require_consent', 1),
                'targetUrl'   => $targetId ? Route::_('index.php?Itemid=' . $targetId, false) : '',
                'text'        => $this->texts(),
            ],
            'locations'  => $locations,
        ];

        $wa = $app->getDocument()->getWebAssetManager();
        $wa->registerAndUseStyle('mod_lwstorelocator', 'mod_lwstorelocator/storelocator.css');
        $wa->registerAndUseScript('mod_lwstorelocator', 'mod_lwstorelocator/storelocator.js', [], ['defer' => true]);

        return $data;
    }

    /**
     * Texts for the JavaScript part.
     */
    private function texts(): array
    {
        $keys = [
            'myLocation'      => 'MOD_LWSTORELOCATOR_MY_LOCATION',
            'locating'        => 'MOD_LWSTORELOCATOR_LOCATING',
            'fetching'        => 'MOD_LWSTORELOCATOR_FETCHING_LOCATION',
            'searching'       => 'MOD_LWSTORELOCATOR_SEARCHING',
            'notFound'        => 'MOD_LWSTORELOCATOR_NOT_FOUND',
            'searchError'     => 'MOD_LWSTORELOCATOR_SEARCH_ERROR',
            'geoDenied'       => 'MOD_LWSTORELOCATOR_GEO_DENIED',
            'geoUnavailable'  => 'MOD_LWSTORELOCATOR_GEO_UNAVAILABLE',
            'geoTimeout'      => 'MOD_LWSTORELOCATOR_GEO_TIMEOUT',
            'geoNotSupported' => 'MOD_LWSTORELOCATOR_GEO_NOT_SUPPORTED',
            'centerMsg'       => 'MOD_LWSTORELOCATOR_CENTER_MSG',
            'noResults'       => 'MOD_LWSTORELOCATOR_NO_RESULTS',
            'initialHint'     => 'MOD_LWSTORELOCATOR_INITIAL_HINT',
            'website'         => 'MOD_LWSTORELOCATOR_WEBSITE',
            'directions'      => 'MOD_LWSTORELOCATOR_DIRECTIONS',
            'route'           => 'MOD_LWSTORELOCATOR_ROUTE',
            'consentRequired' => 'MOD_LWSTORELOCATOR_CONSENT_REQUIRED',
            'mapError'        => 'MOD_LWSTORELOCATOR_MAP_ERROR',
            'phone'           => 'MOD_LWSTORELOCATOR_PHONE',
            'allCountries'    => 'MOD_LWSTORELOCATOR_ALL_COUNTRIES',
        ];

        return array_map(static fn ($key) => Text::_($key), $keys);
    }
}
