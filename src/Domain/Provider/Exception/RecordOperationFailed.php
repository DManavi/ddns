<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider\Exception;

/**
 * A record operation was refused or returned something we could not parse.
 */
final class RecordOperationFailed extends ProviderException
{
    public static function for(string $driver, string $operation, string $detail): self
    {
        return new self(
            sprintf('Provider "%s" failed to %s: %s', $driver, $operation, $detail),
            $driver,
        );
    }

    public static function unexpectedResponse(string $driver, string $operation): self
    {
        return self::for($driver, $operation, 'the API returned an unexpected response body.');
    }
}
