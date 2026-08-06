<?php

declare(strict_types=1);

namespace Ddns\Config;

use Ddns\Config\Exception\ConfigurationError;

/**
 * The fully validated configuration, and the application's only source of truth.
 *
 * There is no database: everything the server knows comes from here.
 */
final class Configuration
{
    /**
     * @param array<string, ProviderConfig> $providers keyed by provider name
     * @param array<string, HostConfig>     $hosts     keyed by host name
     */
    public function __construct(
        private readonly ServerConfig $server,
        private readonly array $providers,
        private readonly array $hosts,
    ) {
    }

    public function server(): ServerConfig
    {
        return $this->server;
    }

    /**
     * @return array<string, ProviderConfig>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, HostConfig>
     */
    public function hosts(): array
    {
        return $this->hosts;
    }

    /**
     * @return list<string>
     */
    public function hostNames(): array
    {
        return array_keys($this->hosts);
    }

    public function hasHost(string $name): bool
    {
        return isset($this->hosts[$name]);
    }

    public function findHost(string $name): ?HostConfig
    {
        return $this->hosts[$name] ?? null;
    }

    /**
     * @throws ConfigurationError when no such host is configured
     */
    public function host(string $name): HostConfig
    {
        return $this->hosts[$name] ?? throw ConfigurationError::unknownHost($name);
    }

    /**
     * @throws ConfigurationError when no such provider is configured
     */
    public function provider(string $name): ProviderConfig
    {
        return $this->providers[$name]
            ?? throw new ConfigurationError(sprintf('No provider named "%s" is configured.', $name));
    }

    /**
     * The provider account backing a given host.
     *
     * @throws ConfigurationError
     */
    public function providerForHost(HostConfig $host): ProviderConfig
    {
        return $this->provider($host->providerName());
    }
}
