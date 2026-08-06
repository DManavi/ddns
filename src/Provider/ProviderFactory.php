<?php

declare(strict_types=1);

namespace Ddns\Provider;

use Ddns\Config\ConfigField;
use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;

/**
 * Builds a driver instance from its configuration.
 *
 * Adding a provider means writing one {@see DnsProvider} and one factory, then
 * registering the factory in the container.
 */
interface ProviderFactory
{
    /**
     * The identifier used as `driver:` in the config file.
     */
    public function driver(): string;

    /**
     * A short human-readable label for `ddns providers:list`.
     */
    public function description(): string;

    /**
     * Whether this driver can actually be used.
     *
     * Drivers that are registered but not yet functional return false and
     * explain themselves via {@see self::unavailableReason()}, so they remain
     * discoverable instead of silently missing.
     */
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;

    /**
     * Whether this driver needs a `token` in its configuration.
     *
     * False for drivers backed by a cloud credential chain, where the
     * credentials may be supplied entirely at runtime by an instance profile,
     * a task role or the environment.
     */
    public function requiresToken(): bool;

    /**
     * Option keys this driver cannot work without, so the configuration loader
     * can report a missing one up front rather than letting it fail at
     * update time.
     *
     * @return list<string>
     */
    public function requiredOptions(): array;

    /**
     * The values this driver needs, in the order they make sense to ask for.
     *
     * Consumed by the `config:init` wizard, so a driver describes itself rather
     * than the wizard carrying a table that goes stale when a driver is added.
     * Required fields must line up with {@see self::requiresToken()} and
     * {@see self::requiredOptions()}, which the loader enforces.
     *
     * @return list<ConfigField>
     */
    public function configFields(): array;

    public function create(ProviderConfig $config): DnsProvider;
}
