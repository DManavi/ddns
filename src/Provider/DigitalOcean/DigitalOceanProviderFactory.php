<?php

declare(strict_types=1);

namespace Ddns\Provider\DigitalOcean;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Provider\BearerTokenProviderFactory;

final class DigitalOceanProviderFactory extends BearerTokenProviderFactory
{
    public function driver(): string
    {
        return DigitalOceanProvider::DRIVER;
    }

    public function description(): string
    {
        return 'DigitalOcean Domain Records API';
    }

    public function create(ProviderConfig $config): DnsProvider
    {
        $baseUri = $config->option('base_uri');

        return new DigitalOceanProvider(
            $this->restClient(
                is_string($baseUri) && $baseUri !== '' ? $baseUri : DigitalOceanProvider::BASE_URI,
                $config->token(),
            ),
        );
    }
}
