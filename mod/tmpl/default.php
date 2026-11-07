<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  mod_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @var \Joomla\Registry\Registry $params
 * @var array                     $lwsl
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$e      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$id     = $lwsl['id'];
$c      = $lwsl['colors'];
$style  = '--lwsl-accent:' . $c['accent'] . ';--lwsl-secondary:' . $c['secondary'] . ';--lwsl-marker:' . $c['marker'] . ';--lwsl-map-height:' . (int) $lwsl['height'] . 'px';
$suffix = trim((string) $params->get('moduleclass_sfx', ''));
$json   = static fn ($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<?php if ($lwsl['mode'] === 'compact') : ?>
    <div class="lwsl lwsl--compact<?php echo $suffix !== '' ? ' ' . $e($suffix) : ''; ?>" id="<?php echo $id; ?>" data-lwsl="compact" style="<?php echo $style; ?>">
        <form class="lwsl-quick" action="<?php echo $e($lwsl['targetUrl'] ?: '#'); ?>" method="get">
            <label class="lwsl-label" for="<?php echo $id; ?>-q"><?php echo Text::_('MOD_LWSTORELOCATOR_LABEL_SEARCH'); ?></label>
            <div class="lwsl-quick-row">
                <input class="lwsl-input" type="text" name="sl_zip" id="<?php echo $id; ?>-q" placeholder="<?php echo $e(Text::_('MOD_LWSTORELOCATOR_PLACEHOLDER')); ?>" autocomplete="postal-code">
                <button class="lwsl-btn lwsl-btn--accent" type="submit" aria-label="<?php echo $e(Text::_('MOD_LWSTORELOCATOR_BTN_SEARCH')); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>
                </button>
            </div>
            <button class="lwsl-btn lwsl-btn--secondary lwsl-btn--block lwsl-geo" type="button"><?php echo Text::_('MOD_LWSTORELOCATOR_BTN_MY_LOCATION'); ?></button>
        </form>
        <script type="application/json" class="lwsl-config"><?php echo $json($lwsl['config']); ?></script>
    </div>
<?php else : ?>
    <div class="lwsl lwsl--full<?php echo $suffix !== '' ? ' ' . $e($suffix) : ''; ?>" id="<?php echo $id; ?>" data-lwsl="full" style="<?php echo $style; ?>">
        <div class="lwsl-bar">
            <div class="lwsl-bar-row">
                <div class="lwsl-search">
                    <label class="lwsl-label" for="<?php echo $id; ?>-q"><?php echo Text::_('MOD_LWSTORELOCATOR_LABEL_SEARCH'); ?></label>
                    <div class="lwsl-search-row">
                        <input class="lwsl-input lwsl-q" type="text" id="<?php echo $id; ?>-q" placeholder="<?php echo $e(Text::_('MOD_LWSTORELOCATOR_PLACEHOLDER')); ?>" autocomplete="postal-code">
                        <button class="lwsl-btn lwsl-btn--accent lwsl-search-btn" type="button"><?php echo Text::_('MOD_LWSTORELOCATOR_BTN_SEARCH'); ?></button>
                    </div>
                </div>
                <div class="lwsl-options">
                    <div>
                        <label class="lwsl-label" for="<?php echo $id; ?>-radius"><?php echo Text::_('MOD_LWSTORELOCATOR_LABEL_RADIUS'); ?></label>
                        <select class="lwsl-input lwsl-radius" id="<?php echo $id; ?>-radius">
                            <?php foreach ([10, 25, 50, 100, 200] as $r) : ?>
                                <option value="<?php echo $r; ?>"<?php echo $r === $lwsl['radius'] ? ' selected' : ''; ?>><?php echo $r; ?> km</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($lwsl['countryFilter']) : ?>
                        <div>
                            <label class="lwsl-label" for="<?php echo $id; ?>-country"><?php echo Text::_('MOD_LWSTORELOCATOR_LABEL_COUNTRY'); ?></label>
                            <select class="lwsl-input lwsl-country" id="<?php echo $id; ?>-country"></select>
                        </div>
                    <?php endif; ?>
                    <button class="lwsl-btn lwsl-btn--secondary lwsl-geo" type="button"><?php echo Text::_('MOD_LWSTORELOCATOR_BTN_MY_LOCATION'); ?></button>
                </div>
            </div>
            <div class="lwsl-status-row">
                <span class="lwsl-status" aria-live="polite"><?php echo Text::_('MOD_LWSTORELOCATOR_INITIAL_HINT'); ?></span>
                <button class="lwsl-link lwsl-reset" type="button"><?php echo Text::_('MOD_LWSTORELOCATOR_BTN_RESET'); ?></button>
            </div>
        </div>

        <div class="lwsl-grid">
            <div class="lwsl-panel">
                <h3 class="lwsl-list-title"><?php echo Text::_('MOD_LWSTORELOCATOR_LIST_TITLE'); ?> (<span class="lwsl-count">0</span>)</h3>
                <div class="lwsl-list">
                    <p class="lwsl-hint"><?php echo Text::_('MOD_LWSTORELOCATOR_INITIAL_HINT'); ?></p>
                </div>
            </div>

            <div class="lwsl-map-wrap">
                <div class="lwsl-map" hidden></div>

                <?php if ($lwsl['consent']) : ?>
                    <div class="lwsl-consent">
                        <div class="lwsl-consent-box">
                            <svg class="lwsl-consent-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2-6-2z"/><path d="M9 4v14M15 6v14"/></svg>
                            <h3><?php echo Text::_('MOD_LWSTORELOCATOR_CONSENT_TITLE'); ?></h3>
                            <div class="lwsl-consent-text">
                                <?php echo $lwsl['consentText'] !== '' ? $lwsl['consentText'] : '<p>' . $e(Text::_('MOD_LWSTORELOCATOR_CONSENT_TEXT')) . '</p>'; ?>
                            </div>
                            <label class="lwsl-remember">
                                <input type="checkbox" class="lwsl-consent-remember">
                                <?php echo Text::_('MOD_LWSTORELOCATOR_CONSENT_REMEMBER'); ?>
                            </label>
                            <div>
                                <button class="lwsl-btn lwsl-btn--accent lwsl-consent-btn" type="button"><?php echo Text::_('MOD_LWSTORELOCATOR_CONSENT_BUTTON'); ?></button>
                            </div>
                            <?php if ($lwsl['privacyUrl'] !== '') : ?>
                                <p class="lwsl-privacy"><a href="<?php echo $e($lwsl['privacyUrl']); ?>"><?php echo Text::_('MOD_LWSTORELOCATOR_PRIVACY'); ?></a></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="lwsl-revoke-wrap" hidden>
                        <button class="lwsl-link lwsl-revoke" type="button"><?php echo Text::_('MOD_LWSTORELOCATOR_CONSENT_REVOKE'); ?></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script type="application/json" class="lwsl-config"><?php echo $json($lwsl['config']); ?></script>
        <script type="application/json" class="lwsl-data"><?php echo $json($lwsl['locations']); ?></script>
    </div>
<?php endif; ?>
