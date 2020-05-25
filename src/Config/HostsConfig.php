<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Config;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;

class HostsConfig implements ConfigurationInterface
{
    /**
     * @var mixed[]
     */
    private array $config = [];

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('hosts');
        $invalidEmail = function ($email) {
            return false === filter_var($email, FILTER_VALIDATE_EMAIL);
        };
        $treeBuilder->getRootNode()
            ->info('Per-host configuration. Each key or "name" attribute is hostname.'.
                ' "default" hostname sets defaults for all hosts.')
            ->requiresAtLeastOneElement()
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->children()
                    ->integerNode('maxConnections')
                        ->info('Maximum number of concurrent connections.')
                        ->treatNullLike(0)->end()
                    ->integerNode('resetAfterVerifications')
                        ->info('Send RSET, HELO, MAIL FROM commands after each specified count of verifications.')
                        ->treatNullLike(0)->end()
                    ->integerNode('closeAfterVerifications')
                        ->info('Close and reopen SMTP connection after specified count of verifications.')
                        ->treatNullLike(0)->end()
                    ->integerNode('inactiveTimeout')
                        ->info('Not implemented. Close connection after specified amout of inactivity time in seconds.')
                        ->treatNullLike(0)->end()
                    ->scalarNode('randomUser')
                        ->info('Non-existing user (part of email before "@") used to check if server is reliable.'.
                            ' If server accepts non-existing email, it is unreliable.')
                        ->cannotBeEmpty()->end()
                    ->scalarNode('fromEmail')
                        ->info('Used for "MAIL FROM" SMTP command.')
                        ->cannotBeEmpty()
                        ->validate()
                            ->ifTrue($invalidEmail)
                            ->thenInvalid('Invalid fromEmail %s')->end()
                        ->end()
                    ->scalarNode('fromHost')
                        ->info('Used in HELO command.')
                        ->cannotBeEmpty()->end()
                    ->booleanNode('unreliable')
                        ->info('If set, no connections to this host will be created'.
                            ' and all emails of this host will be marked as impossible to check by SMTP only.')
                        ->treatNullLike(false);

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

    /**
     * @return int[]
     */
    public function getMaxConnectionsPerHost(): array
    {
        return array_map(function ($item) {
            return $item['maxConnections'] ?? null;
        }, $this->configFilterKeyExists($this->config, 'maxConnections'));
    }

    /**
     * @return string[]
     */
    public function getUnreliableHosts(): array
    {
        return array_keys(
            $this->configFilterValueEquals($this->config, 'unreliable', true));
    }

    /**
     * @return array[]
     */
    public function getConnectionSettings(): array
    {
        return $this->arrayColumns($this->config, [
            'resetAfterVerifications',
            'closeAfterVerifications',
            'randomUser',
            'fromEmail',
            'fromHost',
        ]);
    }

    /**
     * @param mixed[] $config
     * @param string  $key
     *
     * @return mixed[]
     */
    private function configFilterKeyExists(array $config, string $key): array
    {
        return array_filter($config, function ($item) use ($key) {
            return is_array($item) && array_key_exists($key, $item);
        });
    }

    /**
     * @param mixed[] $config
     * @param string  $key
     * @param mixed   $value
     *
     * @return mixed[]
     */
    private function configFilterValueEquals(array $config, string $key, $value): array
    {
        return array_filter($config, function ($item) use ($key, $value) {
            return is_array($item) && array_key_exists($key, $item) && $item[$key] === $value;
        });
    }

    /**
     * @param mixed[] $array
     * @param mixed[] $columnKeys
     *
     * @return mixed[]
     */
    private function arrayColumns(array $array, array $columnKeys): array
    {
        $columnKeys = array_flip($columnKeys);

        return array_map(function ($item) use ($columnKeys) {
            return array_intersect_key($item, $columnKeys);
        }, $array);
    }
}
