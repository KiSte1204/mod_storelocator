<?php

/**
 * @package     Webkiste.LwStoreLocator
 * @subpackage  plg_task_lwstorelocator
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Webkiste\Plugin\Task\LwStoreLocator\Extension\LwStoreLocator;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container) {
                $config = (array) PluginHelper::getPlugin('task', 'lwstorelocator');

                // Joomla 5.x/6.x accept the configuration as first argument, older versions expect the dispatcher
                $first  = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0]->getName();
                $plugin = $first === 'config'
                    ? new LwStoreLocator($config)
                    : new LwStoreLocator($container->get(DispatcherInterface::class), $config);

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
