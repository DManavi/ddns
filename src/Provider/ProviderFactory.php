<?php

declare(strict_types=1);

namespace Ddns\Provider;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;

/**
 * Builds a driver instance from its configuration.
 *
 * Adding a provider means writing one {@see DnsProvider} and one factory, then
 * registering the factory in the container.
 */
interface ProviderFactory
{
    /**
     * The identifier used as `driver:` in the config file.
     */
    public function driver(): string;

    /**
     * A short human-readable label for `ddns providers:list`.
     */
    public function description(): string;

    /**
     * Whether this driver can actually be used.
     *
     * Drivers that are registered but not yet functional return false and
     * explain themselves via {@see self::unavailableReason()}, so they remain
     * discoverable instead of silently missing.
     */
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;

    public function create(ProviderConfig $config): DnsProvider;
}
