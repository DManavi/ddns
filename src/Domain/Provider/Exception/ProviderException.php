<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider\Exception;

/**
 * Base class for every failure originating from a DNS provider.
 *
 * Each subclass carries a suggested HTTP status so the web layer can translate
 * failures without knowing anything about individual providers.
 */
class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $driver = 'unknown',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * The HTTP status that best represents this failure to an API client.
     */
    public function suggestedHttpStatus(): int
    {
        return 502;
    }
}
