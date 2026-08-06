<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider\Exception;

/**
 * A driver is registered and discoverable but not yet usable.
 *
 * Registering a provider this way rather than leaving a TODO keeps the
 * extension seam honest: `ddns providers:list` shows the driver together with
 * the reason it cannot be selected.
 */
final class ProviderNotImplemented extends ProviderException
{
    public static function for(string $driver, string $reason): self
    {
        return new self(
            sprintf('Provider "%s" is not implemented yet: %s', $driver, $reason),
            $driver,
        );
    }

    public function suggestedHttpStatus(): int
    {
        return 501;
    }
}
