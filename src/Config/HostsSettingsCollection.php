<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\Config;

use InvalidArgumentException;

class HostsSettingsCollection
{
    /**
     * @var HostSettings[]
     */
    private array $settings;

    /**
     * HostsSettingsCollection constructor.
     *
     * @param HostSettings   $default
     * @param HostSettings[] $settings
     */
    public function __construct(HostSettings $default, array $settings)
    {
        if (!$default->isDefault()) {
            throw new InvalidArgumentException('Malformed default HostSettings object.');
        }
        $this->settings = [
            HostSettings::HOSTNAME_DEFAULT => $default,
        ];
        foreach ($settings as $item) {
            if (!$item instanceof HostSettings) {
                throw new InvalidArgumentException('Malformed HostSettings object.');
            }
            if ($item->isDefault()) {
                continue;
            }
            $this->settings[$item->getHostname()] = $item;
        }
    }

    public function findForHostname(string $hostname): HostSettings
    {
        return $this->settings[mb_strtolower($hostname)] ?? $this->settings[HostSettings::HOSTNAME_DEFAULT];
    }

    /**
     * @return HostSettings[]
     */
    public function getAll(): array
    {
        return $this->settings;
    }
}
