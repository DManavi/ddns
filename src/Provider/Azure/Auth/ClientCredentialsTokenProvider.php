<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure\Auth;

use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Provider\Azure\AzureDnsProvider;
use Ddns\Provider\Http\RestClient;

/**
 * Service principal authentication: the OAuth2 client credentials grant.
 *
 * @see https://learn.microsoft.com/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
final class ClientCredentialsTokenProvider implements TokenProvider
{
    public const DEFAULT_AUTHORITY = 'https://login.microsoftonline.com';

    public function __construct(
        private readonly RestClient $client,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $scope = AzureDnsProvider::DEFAULT_SCOPE,
    ) {
    }

    public function token(): AccessToken
    {
        $response = $this->client->postForm(
            sprintf('/%s/oauth2/v2.0/token', rawurlencode($this->tenantId)),
            [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => $this->scope,
            ],
        );

        if (!$response->isSuccessful()) {
            throw AuthenticationFailed::for(AzureDnsProvider::DRIVER, $this->explain($response->json()));
        }

        $token = $response->get('access_token');

        if (!is_string($token) || $token === '') {
            throw AuthenticationFailed::for(
                AzureDnsProvider::DRIVER,
                'The token endpoint returned no access_token.',
            );
        }

        $lifetime = $response->get('expires_in');

        // Entra ID always sends expires_in; an hour is its usual value and a
        // safe fallback if a sovereign cloud ever omits it.
        return AccessToken::forSeconds($token, is_int($lifetime) ? $lifetime : 3600);
    }

    /**
     * Turn an OAuth2 error into a message that names the likely culprit.
     *
     * Three values can be wrong and the raw errors are famously opaque, so it
     * is worth saying which one to check.
     *
     * @param array<array-key, mixed> $body
     */
    private function explain(array $body): string
    {
        $code = $body['error'] ?? null;
        $description = $body['error_description'] ?? null;
        $detail = is_string($description) ? $description : '';

        $hint = match ($code) {
            'invalid_client' => 'Check "client_secret" - it is wrong or expired.',
            'unauthorized_client' => 'Check "client_id" - the application is not authorised for this tenant.',
            'invalid_request' => 'Check "tenant_id" and "client_id".',
            'invalid_scope' => 'Check "scope"; it should normally be left unset.',
            default => 'Check "tenant_id", "client_id" and "client_secret".',
        };

        // The description carries the AADSTS code operators search for, but it
        // is multi-line and long, so only its first line is kept.
        $firstLine = strtok($detail, "\r\n");

        return trim($hint . ' ' . ($firstLine === false ? '' : $firstLine));
    }
}
