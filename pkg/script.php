<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  pkg_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * Installation script of the package.
 */
class Pkg_LwstorelocatorInstallerScript
{
    private string $minimumPhp = '8.1.0';

    private string $minimumJoomla = '5.0.0';

    /**
     * Checks the requirements before anything is installed.
     */
    public function preflight($type, $parent): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $this->loadLanguage();

        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_LWSTORELOCATOR_ERROR_PHP', $this->minimumPhp), 'error');

            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_LWSTORELOCATOR_ERROR_JOOMLA', $this->minimumJoomla), 'error');

            return false;
        }

        return true;
    }

    /**
     * Enables the scheduled-task plugin on a new installation and shows the next steps.
     */
    public function postflight($type, $parent): void
    {
        if (!\in_array($type, ['install', 'discover_install'], true)) {
            return;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('lwstorelocator'));
        $db->setQuery($query)->execute();

        $this->loadLanguage();
        Factory::getApplication()->enqueueMessage(Text::_('PKG_LWSTORELOCATOR_INSTALLED'), 'message');
    }

    private function loadLanguage(): void
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('pkg_lwstorelocator', JPATH_ADMINISTRATOR)
            || $lang->load('pkg_lwstorelocator', __DIR__);
    }
}
