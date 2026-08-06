<?php

declare(strict_types=1);

namespace Ddns\Provider\Route53;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Provider\ProviderFactory;

final class Route53ProviderFactory implements ProviderFactory
{
    public function driver(): string
    {
        return Route53Provider::DRIVER;
    }

    public function description(): string
    {
        return 'AWS Route53 (planned)';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): string
    {
        return Route53Provider::REASON;
    }

    /**
     * Returns a real instance rather than throwing here, so the failure surfaces
     * as a per-record error at update time with the same shape as any other
     * provider failure.
     */
    public function create(ProviderConfig $config): DnsProvider
    {
        return new Route53Provider();
    }
}
