<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider\Exception;

/**
 * The provider is throttling us.
 *
 * This is why {@see \Ddns\Domain\Update\DdnsUpdater} short-circuits unchanged
 * records instead of writing on every poll.
 */
final class RateLimited extends ProviderException
{
    private ?int $retryAfterSeconds = null;

    public static function for(string $driver, ?int $retryAfterSeconds = null): self
    {
        $exception = new self(
            $retryAfterSeconds === null
                ? sprintf('Provider "%s" is rate limiting requests.', $driver)
                : sprintf('Provider "%s" is rate limiting requests; retry in %ds.', $driver, $retryAfterSeconds),
            $driver,
        );

        $exception->retryAfterSeconds = $retryAfterSeconds;

        return $exception;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function suggestedHttpStatus(): int
    {
        return 429;
    }
}
