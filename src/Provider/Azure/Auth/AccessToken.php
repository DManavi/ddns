<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure\Auth;

/**
 * A bearer token for the Azure management API, and when it stops being usable.
 */
final class AccessToken
{
    /**
     * Treat a token as expired slightly early, so a request is never sent with
     * one that lapses in flight or against a server whose clock runs ahead.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    private function __construct(
        private readonly string $value,
        private readonly int $expiresAt,
    ) {
    }

    public static function forSeconds(string $value, int $lifetimeSeconds, ?int $now = null): self
    {
        return new self($value, ($now ?? time()) + $lifetimeSeconds);
    }

    public static function expiringAt(string $value, int $timestamp): self
    {
        return new self($value, $timestamp);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function isUsableAt(int $now): bool
    {
        return $now < $this->expiresAt - self::EXPIRY_MARGIN_SECONDS;
    }

    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->value;
    }
}
