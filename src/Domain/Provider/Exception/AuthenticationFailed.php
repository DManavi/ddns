<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider\Exception;

/**
 * The provider rejected our credentials.
 *
 * Surfaced as 502 rather than 401 on purpose: the API client authenticated
 * with us correctly, it is the server's own upstream credential that is wrong.
 */
final class AuthenticationFailed extends ProviderException
{
    public static function for(string $driver, string $detail = ''): self
    {
        return new self(
            trim(sprintf('Provider "%s" rejected the configured API credentials. %s', $driver, $detail)),
            $driver,
        );
    }
}
