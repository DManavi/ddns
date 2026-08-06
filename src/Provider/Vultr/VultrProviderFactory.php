<?php

declare(strict_types=1);

namespace Ddns\Provider\Vultr;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Provider\BearerTokenProviderFactory;

final class VultrProviderFactory extends BearerTokenProviderFactory
{
    public function driver(): string
    {
        return VultrProvider::DRIVER;
    }

    public function description(): string
    {
        return 'Vultr DNS API v2';
    }

    public function create(ProviderConfig $config): DnsProvider
    {
        $baseUri = $config->option('base_uri');

        return new VultrProvider(
            $this->restClient(
                is_string($baseUri) && $baseUri !== '' ? $baseUri : VultrProvider::BASE_URI,
                $config->token(),
            ),
        );
    }
}
