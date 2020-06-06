<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App;

use LogicException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;

class EnvConfig implements ConfigurationInterface
{
    /**
     * @var string[]
     */
    private array $factorySettersMap = [
        'DB_EMAIL_TABLE_NAME' => 'setDbEmailTableName',
        'MAX_CONCURRENT' => 'setMaxConcurrentVerifications',
        'CONNECT_TIMEOUT' => 'setMxConnectTimeout',
        'DB_USER' => 'setDbUser',
        'DB_PASSWORD' => 'setDbPassword',
        'DB_HOST' => 'setDbHost',
        'DB_PORT' => 'setDbPort',
        'DB_SCHEMA' => 'setDbSchema',
    ];

    /**
     * @var callable[]
     */
    private array $typeCastMap = [
        'MAX_CONCURRENT' => 'intval',
        'CONNECT_TIMEOUT' => 'floatval',
    ];

    /**
     * @var mixed[]
     */
    private array $config = [];

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('env');
        $rootNode = $treeBuilder->getRootNode();
        if (!$rootNode instanceof ArrayNodeDefinition) {
            // Calm down PHPStan.
            throw new LogicException('Malformed root node.');
        }
        $rootNode
            ->ignoreExtraKeys()
            ->children()
                  ->scalarNode('DB_EMAIL_TABLE_NAME')->end()
                  ->scalarNode('DB_USER')->end()
                  ->scalarNode('DB_PASSWORD')->end()
                  ->scalarNode('DB_HOST')->end()
                  ->scalarNode('DB_PORT')->end()
                  ->scalarNode('DB_SCHEMA')->end()
                  ->scalarNode('MAX_CONCURRENT')
                      ->validate()
                          ->ifTrue(fn ($val) => !is_numeric($val))
                          ->thenInvalid('Environment variable MAX_CONCURRENT should be numeric.')->end()
                      ->end()
                  ->scalarNode('CONNECT_TIMEOUT')
                      ->validate()
                          ->ifTrue(fn ($val) => !is_numeric($val))
                          ->thenInvalid('Environment variable CONNECT_TIMEOUT should be numeric.')->end()
                      ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * @param mixed[] $config
     */
    public function loadArray(array $config): void
    {
        $processor = new Processor();
        $this->config = $processor->processConfiguration($this, [$this->config, $config]);
    }

    public function configureFactory(ServiceFactory $factory): void
    {
        foreach ($this->factorySettersMap as $key => $setterName) {
            if (!isset($_SERVER[$key])) {
                continue;
            }
            if (isset($this->typeCastMap[$key])) {
                $factory->$setterName(($this->typeCastMap[$key])($_SERVER[$key]));

                continue;
            }
            $factory->$setterName($_SERVER[$key]);
        }
    }
}
