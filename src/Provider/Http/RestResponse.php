<?php

declare(strict_types=1);

namespace Ddns\Provider\Http;

use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Domain\Provider\Exception\RateLimited;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Psr\Http\Message\ResponseInterface;

/**
 * A decoded provider API response, plus the error mapping every driver shares.
 */
final class RestResponse
{
    /**
     * @param array<array-key, mixed> $body
     */
    private function __construct(
        private readonly string $driver,
        private readonly int $status,
        private readonly array $body,
        private readonly string $rawBody,
        private readonly ?int $retryAfter,
    ) {
    }

    public static function fromPsrResponse(string $driver, ResponseInterface $response): self
    {
        $raw = (string) $response->getBody();
        $decoded = [];

        if (trim($raw) !== '') {
            $parsed = json_decode($raw, true);

            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        $retryAfterHeader = $response->getHeaderLine('Retry-After');
        $retryAfter = preg_match('/^\d+$/', $retryAfterHeader) === 1 ? (int) $retryAfterHeader : null;

        return new self($driver, $response->getStatusCode(), $decoded, $raw, $retryAfter);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        return $this->body;
    }

    /**
     * The value at a dotted path, or null when absent.
     */
    public function get(string $path): mixed
    {
        $current = $this->body;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * The list at a dotted path, or an empty list when absent or not a list.
     *
     * @return list<array<array-key, mixed>>
     */
    public function listOf(string $path): array
    {
        $value = $this->get($path);

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @throws AuthenticationFailed
     * @throws RateLimited
     * @throws RecordOperationFailed
     */
    public function assertSuccessful(string $operation): self
    {
        if ($this->isSuccessful()) {
            return $this;
        }

        throw match (true) {
            $this->status === 401, $this->status === 403 => AuthenticationFailed::for(
                $this->driver,
                $this->errorDetail(),
            ),
            $this->status === 429 => RateLimited::for($this->driver, $this->retryAfter),
            default => RecordOperationFailed::for(
                $this->driver,
                $operation,
                sprintf('HTTP %d - %s', $this->status, $this->errorDetail()),
            ),
        };
    }

    /**
     * Pull a useful message out of whichever error shape the provider used.
     */
    public function errorDetail(): string
    {
        foreach (['message', 'error', 'detail', 'errors.0.message', 'errors.0.detail'] as $path) {
            $value = $this->get($path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $fallback = trim($this->rawBody);

        if ($fallback === '') {
            return 'the API returned no error detail.';
        }

        return mb_strimwidth($fallback, 0, 300, '...');
    }
}
