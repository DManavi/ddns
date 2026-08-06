<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider;

use Ddns\Domain\Provider\Exception\ProviderException;

/**
 * Resolves a configured provider name to a ready-to-use driver instance.
 *
 * Declared here so the update orchestrator does not have to know about the
 * concrete registry or any individual driver.
 */
interface ProviderLocator
{
    /**
     * @throws ProviderException when the driver cannot be constructed
     */
    public function forProvider(string $providerName): DnsProvider;
}
