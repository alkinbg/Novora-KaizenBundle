<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder('novora_kaizen');
        $builder->getRootNode()
            ->children()
                ->scalarNode('log_path')->defaultValue('%kernel.logs_dir%/%kernel.environment%.log')->cannotBeEmpty()->end()
                ->scalarNode('storage_dir')->defaultValue('%kernel.project_dir%/var/kaizen')->cannotBeEmpty()->end()
                ->booleanNode('correlation_enabled')->defaultFalse()->end()
                ->integerNode('max_bytes')->defaultValue(2_097_152)->min(1024)->max(16_777_216)->end()
            ->end();

        return $builder;
    }
}
