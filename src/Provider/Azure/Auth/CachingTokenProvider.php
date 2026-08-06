<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure\Auth;

/**
 * Holds a token until shortly before it expires.
 *
 * Not an optimisation. Azure tokens last about an hour, so without this a
 * `watch` loop polling every 60 seconds would perform a full token exchange
 * on every cycle - the same waste the updater's `unchanged` short-circuit
 * avoids for record writes.
 */
final class CachingTokenProvider implements TokenProvider
{
    private ?AccessToken $cached = null;

    /**
     * @param callable(): int $clock injectable so expiry is testable without sleeping
     */
    public function __construct(
        private readonly TokenProvider $delegate,
        private readonly mixed $clock = null,
    ) {
    }

    public function token(): AccessToken
    {
        $now = $this->now();

        if ($this->cached !== null && $this->cached->isUsableAt($now)) {
            return $this->cached;
        }

        return $this->cached = $this->delegate->token();
    }

    /**
     * Drop the cached token so the next call fetches a fresh one.
     */
    public function forget(): void
    {
        $this->cached = null;
    }

    private function now(): int
    {
        return is_callable($this->clock) ? (int) ($this->clock)() : time();
    }
}
