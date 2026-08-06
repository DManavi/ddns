<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Provider\Azure\Auth\CachingTokenProvider;
use Ddns\Provider\Azure\Auth\ClientCredentialsTokenProvider;
use Ddns\Provider\Azure\Auth\ManagedIdentityTokenProvider;
use Ddns\Provider\Azure\Auth\TokenProvider;
use Ddns\Provider\Http\RestClient;
use Ddns\Provider\ProviderFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the Azure DNS driver from configuration.
 *
 * The presence of `client_secret` selects the authentication method: with one,
 * a service principal; without, the managed identity attached to the host. The
 * latter is the recommended way to run on Azure, since no secret then appears
 * in the configuration file at all.
 */
final class AzureDnsProviderFactory implements ProviderFactory
{
    /**
     * Options the configuration loader must insist on, since neither is the
     * generic `token` field it validates for other drivers.
     *
     * @var list<string>
     */
    public const REQUIRED_OPTIONS = ['subscription_id', 'resource_group'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function driver(): string
    {
        return AzureDnsProvider::DRIVER;
    }

    public function description(): string
    {
        return 'Azure DNS (management REST API)';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /**
     * Azure authenticates with a service principal or a managed identity, not
     * with a static bearer token in the config file.
     */
    public function requiresToken(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function requiredOptions(): array
    {
        return self::REQUIRED_OPTIONS;
    }

    public function create(ProviderConfig $config): DnsProvider
    {
        $tokens = new CachingTokenProvider($this->tokenProvider($config));

        $management = $this->restClient(
            $this->option($config, 'endpoint') ?? AzureDnsProvider::MANAGEMENT_ENDPOINT,
            // A callable, not a fixed header: the token rotates roughly hourly
            // and a long-running `watch` outlives any single one.
            static fn (): array => ['Authorization' => $tokens->token()->authorizationHeader()],
        );

        return new AzureDnsProvider(
            $management,
            $this->option($config, 'subscription_id') ?? '',
            $this->option($config, 'resource_group') ?? '',
        );
    }

    private function tokenProvider(ProviderConfig $config): TokenProvider
    {
        $clientId = $this->option($config, 'client_id');

        // `token` doubles as the client secret, so an operator who reaches for
        // the field every other driver uses still gets a working setup.
        $clientSecret = $this->option($config, 'client_secret')
            ?? ($config->token() === '' ? null : $config->token());

        if ($clientSecret === null) {
            return new ManagedIdentityTokenProvider(
                $this->restClient($this->option($config, 'imds_endpoint') ?? ManagedIdentityTokenProvider::IMDS_ENDPOINT, [
                    // IMDS refuses requests without this; it is what stops a
                    // confused-deputy attack via a proxied HTTP request.
                    'Metadata' => 'true',
                ]),
                // Only meaningful for a user-assigned identity.
                $clientId,
            );
        }

        return new ClientCredentialsTokenProvider(
            $this->restClient($this->option($config, 'authority') ?? ClientCredentialsTokenProvider::DEFAULT_AUTHORITY),
            $this->option($config, 'tenant_id') ?? '',
            $clientId ?? '',
            $clientSecret,
            $this->option($config, 'scope') ?? AzureDnsProvider::DEFAULT_SCOPE,
        );
    }

    /**
     * @param array<string, string>|callable(): array<string, string> $headers
     */
    private function restClient(string $baseUri, mixed $headers = []): RestClient
    {
        return new RestClient(
            $this->http,
            $this->requestFactory,
            $this->streamFactory,
            AzureDnsProvider::DRIVER,
            $baseUri,
            $headers,
        );
    }

    /**
     * A non-empty string option, or null.
     */
    private function option(ProviderConfig $config, string $key): ?string
    {
        $value = $config->option($key);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
