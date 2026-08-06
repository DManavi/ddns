<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure\Auth;

use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Provider\Azure\AzureDnsProvider;
use Ddns\Provider\Http\RestClient;

/**
 * Managed identity authentication via the instance metadata service.
 *
 * This is the Azure counterpart of an EC2 instance profile or IRSA: on an Azure
 * VM, App Service or container with an identity attached, credentials never
 * appear in the configuration file at all.
 *
 * @see https://learn.microsoft.com/entra/identity/managed-identities-azure-resources/how-to-use-vm-token
 */
final class ManagedIdentityTokenProvider implements TokenProvider
{
    public const IMDS_ENDPOINT = 'http://169.254.169.254';
    public const IMDS_PATH = '/metadata/identity/oauth2/token';
    public const IMDS_API_VERSION = '2018-02-01';

    /**
     * The management API audience. Note the trailing slash: IMDS takes a
     * `resource` audience rather than an OAuth2 `.default` scope.
     */
    public const RESOURCE = 'https://management.azure.com/';

    public function __construct(
        private readonly RestClient $client,
        /**
         * Set only for a user-assigned identity. A VM may have several attached,
         * in which case IMDS refuses to guess and the client ID is required.
         */
        private readonly ?string $clientId = null,
        private readonly string $resource = self::RESOURCE,
    ) {
    }

    public function token(): AccessToken
    {
        $query = [
            'api-version' => self::IMDS_API_VERSION,
            'resource' => $this->resource,
        ];

        if ($this->clientId !== null && $this->clientId !== '') {
            $query['client_id'] = $this->clientId;
        }

        $response = $this->client->get(self::IMDS_PATH, $query);

        if (!$response->isSuccessful()) {
            throw AuthenticationFailed::for(AzureDnsProvider::DRIVER, sprintf(
                'The instance metadata service returned HTTP %d. This host may have no managed identity '
                . 'attached, or may not be running on Azure at all - set "client_secret" to use a service '
                . 'principal instead. (%s)',
                $response->status(),
                $response->errorDetail(),
            ));
        }

        $token = $response->get('access_token');

        if (!is_string($token) || $token === '') {
            throw AuthenticationFailed::for(
                AzureDnsProvider::DRIVER,
                'The instance metadata service returned no access_token.',
            );
        }

        // IMDS reports absolute expiry as a Unix timestamp, and as a string
        // rather than a number.
        $expiresOn = $response->get('expires_on');

        if (is_int($expiresOn)) {
            return AccessToken::expiringAt($token, $expiresOn);
        }

        if (is_string($expiresOn) && preg_match('/^\d+$/', $expiresOn) === 1) {
            return AccessToken::expiringAt($token, (int) $expiresOn);
        }

        $lifetime = $response->get('expires_in');

        if (is_int($lifetime)) {
            return AccessToken::forSeconds($token, $lifetime);
        }

        if (is_string($lifetime) && preg_match('/^\d+$/', $lifetime) === 1) {
            return AccessToken::forSeconds($token, (int) $lifetime);
        }

        return AccessToken::forSeconds($token, 3600);
    }
}
