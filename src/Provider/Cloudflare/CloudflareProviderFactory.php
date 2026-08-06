<?php

declare(strict_types=1);

namespace Ddns\Provider\Cloudflare;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Provider\BearerTokenProviderFactory;

final class CloudflareProviderFactory extends BearerTokenProviderFactory
{
    public function driver(): string
    {
        return CloudflareProvider::DRIVER;
    }

    public function description(): string
    {
        return 'Cloudflare DNS API v4';
    }

    public function create(ProviderConfig $config): DnsProvider
    {
        $baseUri = $config->option('base_uri');
        $zoneId = $config->option('zone_id');

        return new CloudflareProvider(
            $this->restClient(
                is_string($baseUri) && $baseUri !== '' ? $baseUri : CloudflareProvider::BASE_URI,
                $config->token(),
            ),
            // A token scoped to a single zone cannot list zones, so an
            // explicitly configured zone_id skips the lookup entirely.
            is_string($zoneId) && $zoneId !== '' ? $zoneId : null,
        );
    }
}
