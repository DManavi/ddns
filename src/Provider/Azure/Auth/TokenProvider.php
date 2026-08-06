<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure\Auth;

use Ddns\Domain\Provider\Exception\ProviderException;

/**
 * Supplies a bearer token for the Azure management API.
 *
 * Two implementations: a service principal exchanging a client secret, and a
 * managed identity asking the instance metadata service. Which one is in use is
 * decided once, in the factory, from configuration.
 */
interface TokenProvider
{
    /**
     * @throws ProviderException when a token cannot be obtained
     */
    public function token(): AccessToken;
}
