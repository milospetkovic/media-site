<?php

declare(strict_types=1);

namespace AppBundle\DependencyInjection;

use Netgen\Bundle\RemoteMediaBundle\NetgenRemoteMediaBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

use function file_get_contents;
use function in_array;

final class AppExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');
        $loader->load('layouts/services.yml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $prependConfigs = [
            'layouts/blocks.yml' => 'netgen_layouts',
            'layouts/components.yml' => 'netgen_layouts',
            'layouts/block_view.yml' => 'netgen_layouts',
            'layouts/item_view.yml' => 'netgen_layouts',
        ];

        $activatedBundles = $container->getParameter('kernel.bundles');
        if (in_array(NetgenRemoteMediaBundle::class, $activatedBundles, true)) {
            $prependConfigs['remote_media.yml'] = 'netgen_remote_media';
        }

        foreach ($prependConfigs as $configFile => $prependConfig) {
            $configFile = __DIR__ . '/../Resources/config/' . $configFile;
            $config = Yaml::parse(file_get_contents($configFile));
            $container->prependExtensionConfig($prependConfig, $config);
            $container->addResource(new FileResource($configFile));
        }

        $configFile = __DIR__ . '/../Resources/config/content_view.yml';
        $config = Yaml::parse(file_get_contents($configFile));
        $container->prependExtensionConfig('ezpublish', ['system' => $config]);
        $container->addResource(new FileResource($configFile));

        $configFile = __DIR__ . '/../Resources/config/component_view.yml';
        $config = Yaml::parse(file_get_contents($configFile));
        $container->prependExtensionConfig('ezpublish', ['system' => $config]);
        $container->addResource(new FileResource($configFile));
    }
}
