<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * What the loader needs to know about the available drivers.
 *
 * Drivers differ in how they authenticate: most take a single bearer token,
 * but Route53 uses AWS credentials that may not appear in the config file at
 * all - an EC2 instance profile, an ECS task role or IRSA supplies them at
 * runtime. The loader consults this rather than assuming every driver has a
 * `token`.
 *
 * Lives in Config, populated from the provider factories, so the config layer
 * does not have to import the provider layer.
 */
final class DriverCatalog
{
    /**
     * @param array<string, bool> $drivers driver name => whether `token` is required
     */
    private function __construct(private readonly array $drivers)
    {
    }

    /**
     * @param array<string, bool> $drivers
     */
    public static function of(array $drivers): self
    {
        return new self($drivers);
    }

    /**
     * Every driver takes a bearer token. Convenient for tests.
     *
     * @param list<string> $names
     */
    public static function tokenBased(array $names): self
    {
        return new self(array_fill_keys($names, true));
    }

    public function has(string $driver): bool
    {
        return array_key_exists($driver, $this->drivers);
    }

    public function requiresToken(string $driver): bool
    {
        return $this->drivers[$driver] ?? true;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }
}
