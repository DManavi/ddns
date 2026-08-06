<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * What the loader needs to know about the available drivers.
 *
 * Drivers differ in how they authenticate and in what they need configured.
 * Most take a single bearer token; Route53 uses AWS credentials that may not
 * appear in the config file at all; Azure needs a subscription and resource
 * group, neither of which is the generic `token` field. The loader consults
 * this rather than assuming every driver looks the same.
 *
 * Lives in Config, populated from the provider factories, so the config layer
 * does not have to import the provider layer.
 */
final class DriverCatalog
{
    /**
     * @param array<string, array{token: bool, options: list<string>}> $drivers
     */
    private function __construct(private readonly array $drivers)
    {
    }

    /**
     * Build from a `driver => requiresToken` map, or from a fuller definition
     * naming the options the driver cannot work without.
     *
     * @param array<string, bool|array{token?: bool, options?: list<string>}> $drivers
     */
    public static function of(array $drivers): self
    {
        $normalised = [];

        foreach ($drivers as $name => $definition) {
            $normalised[$name] = is_bool($definition)
                ? ['token' => $definition, 'options' => []]
                : ['token' => $definition['token'] ?? true, 'options' => $definition['options'] ?? []];
        }

        return new self($normalised);
    }

    /**
     * Every driver takes a bearer token and needs nothing else. Convenient for
     * tests.
     *
     * @param list<string> $names
     */
    public static function tokenBased(array $names): self
    {
        return self::of(array_fill_keys($names, true));
    }

    public function has(string $driver): bool
    {
        return array_key_exists($driver, $this->drivers);
    }

    public function requiresToken(string $driver): bool
    {
        return $this->drivers[$driver]['token'] ?? true;
    }

    /**
     * Option keys this driver cannot work without.
     *
     * @return list<string>
     */
    public function requiredOptions(string $driver): array
    {
        return $this->drivers[$driver]['options'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }
}
