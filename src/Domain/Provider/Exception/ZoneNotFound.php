<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider\Exception;

/**
 * The configured zone does not exist on the provider account.
 */
final class ZoneNotFound extends ProviderException
{
    public static function for(string $driver, string $zone): self
    {
        return new self(
            sprintf('Zone "%s" was not found on provider "%s".', $zone, $driver),
            $driver,
        );
    }

    public function suggestedHttpStatus(): int
    {
        return 404;
    }
}
