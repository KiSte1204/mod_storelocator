<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  com_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Component\LwStoreLocator\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Converts country names (several languages) and ISO codes to ISO 3166-1 alpha-2 codes.
 */
abstract class Countries
{
    /**
     * Country names => ISO code. Keys are normalised with Countries::key().
     */
    private const NAMES = [
        'AT' => ['austria', 'oesterreich', 'osterreich', 'autriche', 'oostenrijk', 'austria'],
        'BE' => ['belgium', 'belgien', 'belgique', 'belgie', 'belgio', 'belgica'],
        'BG' => ['bulgaria', 'bulgarien', 'bulgarie'],
        'CA' => ['canada', 'kanada'],
        'CH' => ['switzerland', 'schweiz', 'suisse', 'svizzera', 'zwitserland', 'suiza'],
        'CZ' => ['czechrepublic', 'czechia', 'tschechien', 'tschechischerepublik', 'republiquetcheque', 'tsjechie', 'chequia'],
        'DE' => ['germany', 'deutschland', 'allemagne', 'duitsland', 'germania', 'alemania', 'brd'],
        'DK' => ['denmark', 'daenemark', 'danemark', 'denemarken', 'danimarca', 'dinamarca'],
        'EE' => ['estonia', 'estland', 'estonie'],
        'ES' => ['spain', 'spanien', 'espagne', 'spanje', 'spagna', 'espana'],
        'FI' => ['finland', 'finnland', 'finlande', 'finlandia'],
        'FR' => ['france', 'frankreich', 'frankrijk', 'francia'],
        'GB' => ['unitedkingdom', 'greatbritain', 'england', 'scotland', 'wales', 'uk', 'grossbritannien', 'vereinigteskoenigreich', 'royaumeuni', 'verenigdkoninkrijk', 'reinounido', 'regnounito'],
        'GR' => ['greece', 'griechenland', 'grece', 'griekenland', 'grecia'],
        'HR' => ['croatia', 'kroatien', 'croatie', 'kroatie', 'croazia', 'croacia'],
        'HU' => ['hungary', 'ungarn', 'hongrie', 'hongarije', 'ungheria', 'hungria'],
        'IE' => ['ireland', 'irland', 'irlande', 'ierland', 'irlanda'],
        'IT' => ['italy', 'italien', 'italie', 'italie', 'italia'],
        'LI' => ['liechtenstein'],
        'LT' => ['lithuania', 'litauen', 'lituanie'],
        'LU' => ['luxembourg', 'luxemburg', 'lussemburgo', 'luxemburgo'],
        'LV' => ['latvia', 'lettland', 'lettonie'],
        'NL' => ['netherlands', 'niederlande', 'holland', 'paysbas', 'nederland', 'paesibassi', 'paisesbajos', 'thenetherlands'],
        'NO' => ['norway', 'norwegen', 'norvege', 'noorwegen', 'norvegia', 'noruega'],
        'PL' => ['poland', 'polen', 'pologne', 'polonia'],
        'PT' => ['portugal', 'portogallo'],
        'RO' => ['romania', 'rumaenien', 'roumanie', 'roemenie', 'rumania'],
        'RS' => ['serbia', 'serbien', 'serbie'],
        'SE' => ['sweden', 'schweden', 'suede', 'zweden', 'svezia', 'suecia'],
        'SI' => ['slovenia', 'slowenien', 'slovenie', 'eslovenia'],
        'SK' => ['slovakia', 'slowakei', 'slovaquie', 'slowakije', 'slovacchia', 'eslovaquia'],
        'TR' => ['turkey', 'tuerkei', 'turkei', 'turquie', 'turkije', 'turchia', 'turquia', 'turkiye'],
        'UA' => ['ukraine', 'oekraine', 'ucraina', 'ucrania'],
        'US' => ['unitedstates', 'usa', 'unitedstatesofamerica', 'vereinigtestaaten', 'etatsunis', 'verenigdestaten', 'statiuniti', 'estadosunidos'],
    ];

    /** @var array<string, string>|null */
    private static ?array $lookup = null;

    /**
     * ISO code for a country name or code, null if unknown. Empty input returns ''.
     */
    public static function toCode(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]{2}$/', $value)) {
            $code = strtoupper($value);

            return $code === 'UK' ? 'GB' : $code;
        }

        if (self::$lookup === null) {
            self::$lookup = [];

            foreach (self::NAMES as $code => $names) {
                foreach ($names as $name) {
                    self::$lookup[$name] = $code;
                }
            }
        }

        return self::$lookup[self::key($value)] ?? null;
    }

    /**
     * Lower case, umlauts transliterated, only a-z.
     */
    public static function key(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        if (class_exists('Normalizer')) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        }

        return preg_replace('/[^a-z]/', '', $value);
    }
}
