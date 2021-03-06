<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier\Config;

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
                    ->floatNode('inactiveTimeout')
                        ->info('Close connection after specified amount of inactivity time in seconds.')
                        ->treatNullLike(0.0)->end()
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

    public function getSettings(): HostsSettingsCollection
    {
        $objects = [];
        $default = new HostSettings(
            HostSettings::HOSTNAME_DEFAULT,
            $this->config[HostSettings::HOSTNAME_DEFAULT] ?? []
        );
        foreach ($this->config as $hostname => $item) {
            $objects[$hostname] = new HostSettings($hostname, $item, $default);
        }

        return new HostsSettingsCollection($default, $objects);
    }
}
