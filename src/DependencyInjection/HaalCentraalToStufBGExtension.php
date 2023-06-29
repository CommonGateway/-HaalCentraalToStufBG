<?php

namespace CommonGateway\HaalCentraalToStufBGBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Every symfony bundle need and extension to load services
 *
 * @author  Conduction.nl <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class HaalCentraalToStufBGExtension extends Extension
{


    /**
     * The load function is triggered by symfony and loads the bundles services files.
     *
     * @param array            $configs   The configuration
     * @param ContainerBuilder $container The container
     *
     * @return void
     *
     * @SuppressWarnings("unused") Required by symfony
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../../Resources/config')
        );
        $loader->load('services.yaml');

    }//end load()


}//end class
