<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * Credentials and settings for one configured provider account.
 *
 * Several hosts can share a provider entry, and the same driver can appear more
 * than once under different names (a personal and a work DigitalOcean token,
 * for instance).
 */
final class ProviderConfig
{
    /**
     * @param string               $name    the key used in the config file
     * @param string               $driver  the driver identifier, e.g. `vultr`
     * @param array<string, mixed> $options driver-specific extras
     */
    public function __construct(
        private readonly string $name,
        private readonly string $driver,
        private readonly string $token,
        private readonly array $options = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * The API credential. Never log or serialise this value.
     */
    public function token(): string
    {
        return $this->token;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * A representation safe to print in CLI tables and API responses.
     *
     * @return array{name: string, driver: string, token: string}
     */
    public function toRedactedArray(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'token' => Redactor::redact($this->token),
        ];
    }
}
