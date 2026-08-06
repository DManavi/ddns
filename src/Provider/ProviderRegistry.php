<?php

declare(strict_types=1);

namespace Ddns\Provider;

use Ddns\Config\Configuration;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\ProviderException;
use Ddns\Domain\Provider\ProviderLocator;

/**
 * Maps configured provider names onto driver instances.
 *
 * Instances are memoised per provider name so per-account state built up during
 * a run - Cloudflare's resolved zone IDs, for example - survives across the
 * hosts that share that account.
 */
final class ProviderRegistry implements ProviderLocator
{
    /** @var array<string, ProviderFactory> */
    private array $factories = [];

    /** @var array<string, DnsProvider> */
    private array $instances = [];

    public function __construct(
        private readonly Configuration $configuration,
        ProviderFactory ...$factories,
    ) {
        foreach ($factories as $factory) {
            $this->factories[$factory->driver()] = $factory;
        }
    }

    /**
     * Every registered driver identifier, including unavailable ones.
     *
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @return list<ProviderFactory>
     */
    public function factories(): array
    {
        return array_values($this->factories);
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->factories[$driver]);
    }

    public function forProvider(string $providerName): DnsProvider
    {
        if (isset($this->instances[$providerName])) {
            return $this->instances[$providerName];
        }

        $config = $this->configuration->provider($providerName);
        $factory = $this->factories[$config->driver()] ?? null;

        if ($factory === null) {
            throw new ProviderException(
                sprintf(
                    'Provider "%s" uses driver "%s", which is not registered. Registered drivers: %s.',
                    $providerName,
                    $config->driver(),
                    implode(', ', $this->drivers()),
                ),
                $config->driver(),
            );
        }

        return $this->instances[$providerName] = $factory->create($config);
    }
}
