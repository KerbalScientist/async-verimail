<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Verifier\Config;

use InvalidArgumentException;

class HostSettings
{
    const HOSTNAME_DEFAULT = 'default';

    private string $hostname;
    private int $maxConnections = 1;
    private int $maxReconnects = 10;
    private int $resetAfterVerifications = 1;
    private int $closeAfterVerifications = 0;
    private float $inactiveTimeout = 10;
    /* @noinspection SpellCheckingInspection */
    private string $randomUser = 'mo4rahpheix8ti7ohT0eoku0oisien6ohKaenuutareiCei3ad9Ibedoogh6quie';
    private string $fromEmail = 'test@example.com';
    private string $fromHost = 'localhost';
    private bool $unreliable = false;

    /**
     * HostSettings constructor.
     *
     * @param string            $hostname
     * @param mixed[]           $settings
     * @param HostSettings|null $default
     */
    public function __construct(
        string $hostname = self::HOSTNAME_DEFAULT,
        array $settings = [],
        ?self $default = null
    ) {
        if (array_key_exists('hostname', $settings)) {
            throw new InvalidArgumentException("Unknown parameter 'hostname'.");
        }
        if ($default) {
            $settings = array_merge($default->toArray(), $settings);
            unset($settings['hostname']);
        }
        foreach ($settings as $name => $value) {
            if (!property_exists($this, $name)) {
                throw new InvalidArgumentException("Unknown parameter '$name'.");
            }
            $this->$name = $value;
        }
        $this->hostname = $hostname;
    }

    /**
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return int
     */
    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    /**
     * @return int
     */
    public function getMaxReconnects(): int
    {
        return $this->maxReconnects;
    }

    /**
     * @return int
     */
    public function getResetAfterVerifications(): int
    {
        return $this->resetAfterVerifications;
    }

    /**
     * @return int
     */
    public function getCloseAfterVerifications(): int
    {
        return $this->closeAfterVerifications;
    }

    /**
     * @return float
     */
    public function getInactiveTimeout()
    {
        return $this->inactiveTimeout;
    }

    /**
     * @return string
     */
    public function getRandomUser(): string
    {
        return $this->randomUser;
    }

    /**
     * @return string
     */
    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    /**
     * @return string
     */
    public function getFromHost(): string
    {
        return $this->fromHost;
    }

    /**
     * @return bool
     */
    public function isUnreliable(): bool
    {
        return $this->unreliable;
    }

    public function isDefault(): bool
    {
        return self::HOSTNAME_DEFAULT === $this->hostname;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
