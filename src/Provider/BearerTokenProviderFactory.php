<?php

declare(strict_types=1);

namespace Ddns\Provider;

use Ddns\Provider\Http\RestClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shared plumbing for factories that build a bearer-token REST driver.
 */
abstract class BearerTokenProviderFactory implements ProviderFactory
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function requiresToken(): bool
    {
        return true;
    }

    protected function restClient(string $baseUri, string $token): RestClient
    {
        return new RestClient(
            $this->http,
            $this->requestFactory,
            $this->streamFactory,
            $this->driver(),
            $baseUri,
            ['Authorization' => 'Bearer ' . $token],
        );
    }
}
