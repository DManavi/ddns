<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Ddns\Provider\Azure\Auth\AccessToken;
use Ddns\Provider\Azure\Auth\TokenProvider;

/**
 * Returns a fixed token and counts how often it was asked for.
 *
 * Exists so the caching decorator can be tested on the property that matters:
 * how many times it actually reaches for a new token.
 */
final class CountingTokenProvider implements TokenProvider
{
    public int $calls = 0;

    public function __construct(private readonly AccessToken $token)
    {
    }

    public function token(): AccessToken
    {
        ++$this->calls;

        return $this->token;
    }
}
