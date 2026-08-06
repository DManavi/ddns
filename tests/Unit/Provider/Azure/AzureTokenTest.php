<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider\Azure;

use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Provider\Azure\Auth\AccessToken;
use Ddns\Provider\Azure\Auth\CachingTokenProvider;
use Ddns\Provider\Azure\Auth\ClientCredentialsTokenProvider;
use Ddns\Provider\Azure\Auth\ManagedIdentityTokenProvider;
use Ddns\Tests\Support\CountingTokenProvider;
use Ddns\Tests\Support\Fixtures;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AzureTokenTest extends TestCase
{
    private ?MockHttpClient $http = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    // ----------------------------------------------------------- AccessToken

    public function testATokenIsUsableWellBeforeExpiry(): void
    {
        $token = AccessToken::forSeconds('abc', 3600, now: 1_000);

        self::assertTrue($token->isUsableAt(1_000));
        self::assertSame('Bearer abc', $token->authorizationHeader());
    }

    /**
     * Expiring a token slightly early stops one lapsing mid-request, or against
     * a server whose clock runs ahead of ours.
     */
    public function testATokenIsConsideredExpiredShortlyBeforeItActuallyExpires(): void
    {
        $token = AccessToken::forSeconds('abc', 3600, now: 0);

        self::assertTrue($token->isUsableAt(3_500), 'still comfortably valid');
        self::assertFalse($token->isUsableAt(3_570), 'inside the safety margin');
        self::assertFalse($token->isUsableAt(3_600), 'expired');
    }

    public function testATokenCanBeBuiltFromAnAbsoluteExpiry(): void
    {
        $token = AccessToken::expiringAt('abc', 5_000);

        self::assertSame(5_000, $token->expiresAt());
        self::assertTrue($token->isUsableAt(4_000));
    }

    // --------------------------------------------------- client credentials

    public function testExchangesAClientSecretForAToken(): void
    {
        $this->http()->queue(200, ['access_token' => 'sp-token', 'expires_in' => 3599]);

        $token = $this->clientCredentials()->token();

        self::assertSame('sp-token', $token->value());
    }

    public function testPostsTheClientCredentialsGrantAsAForm(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_in' => 3599]);

        $this->clientCredentials()->token();

        $request = $this->http()->request(0);
        parse_str((string) $request->getBody(), $fields);

        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('client_credentials', $fields['grant_type'] ?? null);
        self::assertSame('client-1', $fields['client_id'] ?? null);
        self::assertSame('secret-1', $fields['client_secret'] ?? null);
        self::assertSame('https://management.azure.com/.default', $fields['scope'] ?? null);
    }

    /**
     * Three values can be wrong and Entra ID's errors are opaque, so the
     * message should say which one to look at.
     */
    #[DataProvider('oauthErrors')]
    public function testExplainsWhichCredentialIsLikelyWrong(string $code, string $expected): void
    {
        $this->http()->queue(401, [
            'error' => $code,
            'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: abc",
        ]);

        try {
            $this->clientCredentials()->token();
            self::fail('Expected an AuthenticationFailed exception.');
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oauthErrors(): iterable
    {
        yield 'bad secret' => ['invalid_client', 'client_secret'];
        yield 'app not in tenant' => ['unauthorized_client', 'client_id'];
        yield 'malformed request' => ['invalid_request', 'tenant_id'];
        yield 'unknown code' => ['something_else', 'tenant_id'];
    }

    /**
     * The AADSTS code is what operators search for, so it must survive - but
     * only its first line, since the description is long and multi-line.
     */
    public function testKeepsTheAadstsCodeButNotTheWholeDescription(): void
    {
        $this->http()->queue(401, [
            'error' => 'invalid_client',
            'error_description' => "AADSTS7000215: Invalid client secret.\r\nTrace ID: abc\r\nTimestamp: x",
        ]);

        try {
            $this->clientCredentials()->token();
            self::fail('Expected an AuthenticationFailed exception.');
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString('AADSTS7000215', $e->getMessage());
            self::assertStringNotContainsString('Timestamp', $e->getMessage());
        }
    }

    public function testRejectsATokenResponseWithNoToken(): void
    {
        $this->http()->queue(200, ['token_type' => 'Bearer']);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('no access_token');

        $this->clientCredentials()->token();
    }

    public function testFallsBackToAnHourWhenNoLifetimeIsGiven(): void
    {
        $this->http()->queue(200, ['access_token' => 't']);

        $token = $this->clientCredentials()->token();

        self::assertGreaterThan(time() + 3_000, $token->expiresAt());
    }

    // ------------------------------------------------------ managed identity

    public function testFetchesATokenFromTheMetadataService(): void
    {
        $this->http()->queue(200, ['access_token' => 'mi-token', 'expires_on' => (string) (time() + 3600)]);

        self::assertSame('mi-token', $this->managedIdentity()->token()->value());
    }

    /**
     * IMDS rejects requests without this header; it is what prevents a
     * confused-deputy attack through a proxied request.
     */
    public function testSendsTheMandatoryMetadataHeader(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_on' => (string) (time() + 3600)]);

        $this->managedIdentity()->token();

        $request = $this->http()->request(0);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('true', $request->getHeaderLine('Metadata'));
        self::assertStringStartsWith(
            'http://169.254.169.254/metadata/identity/oauth2/token?',
            (string) $request->getUri(),
        );
    }

    public function testAsksForTheManagementAudience(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_on' => (string) (time() + 3600)]);

        $this->managedIdentity()->token();

        $uri = (string) $this->http()->request(0)->getUri();

        self::assertStringContainsString('api-version=2018-02-01', $uri);
        self::assertStringContainsString('resource=' . rawurlencode('https://management.azure.com/'), $uri);
    }

    /**
     * A VM may carry several identities, in which case IMDS refuses to guess.
     */
    public function testIdentifiesAUserAssignedIdentityByClientId(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_on' => (string) (time() + 3600)]);

        $this->managedIdentity('user-assigned-id')->token();

        self::assertStringContainsString('client_id=user-assigned-id', (string) $this->http()->request(0)->getUri());
    }

    public function testOmitsClientIdForASystemAssignedIdentity(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_on' => (string) (time() + 3600)]);

        $this->managedIdentity()->token();

        self::assertStringNotContainsString('client_id', (string) $this->http()->request(0)->getUri());
    }

    /**
     * IMDS reports absolute expiry, as a string rather than a number.
     */
    public function testParsesTheStringExpiryTimestamp(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_on' => '2000000000']);

        self::assertSame(2_000_000_000, $this->managedIdentity()->token()->expiresAt());
    }

    public function testFallsBackToExpiresInWhenExpiresOnIsAbsent(): void
    {
        $this->http()->queue(200, ['access_token' => 't', 'expires_in' => '3599']);

        $token = $this->managedIdentity()->token();

        self::assertGreaterThan(time() + 3_000, $token->expiresAt());
    }

    /**
     * Off Azure this endpoint simply is not there, and the message should say
     * what to do about it rather than reporting a bare HTTP status.
     */
    public function testExplainsThatTheHostMayNotBeOnAzure(): void
    {
        $this->http()->queue(400, ['error' => 'invalid_request']);

        try {
            $this->managedIdentity()->token();
            self::fail('Expected an AuthenticationFailed exception.');
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString('managed identity', $e->getMessage());
            self::assertStringContainsString('client_secret', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- cache

    /**
     * The point of the cache: a `watch` loop must not exchange a token on every
     * poll.
     */
    public function testFetchesOnceAndThenReusesTheToken(): void
    {
        $delegate = $this->countingProvider(AccessToken::forSeconds('t', 3600, now: 1_000));
        $cache = new CachingTokenProvider($delegate, static fn (): int => 1_000);

        $cache->token();
        $cache->token();
        $cache->token();

        self::assertSame(1, $delegate->calls);
    }

    public function testFetchesAgainOnceTheTokenNearsExpiry(): void
    {
        $delegate = $this->countingProvider(AccessToken::forSeconds('t', 3600, now: 0));
        $now = 0;
        $cache = new CachingTokenProvider($delegate, static function () use (&$now): int {
            return $now;
        });

        $cache->token();
        $now = 3_590;
        $cache->token();

        self::assertSame(2, $delegate->calls);
    }

    public function testForgetForcesAFreshToken(): void
    {
        $delegate = $this->countingProvider(AccessToken::forSeconds('t', 3600, now: 1_000));
        $cache = new CachingTokenProvider($delegate, static fn (): int => 1_000);

        $cache->token();
        $cache->forget();
        $cache->token();

        self::assertSame(2, $delegate->calls);
    }

    public function testDefaultsToTheRealClock(): void
    {
        $delegate = $this->countingProvider(AccessToken::forSeconds('t', 3600));
        $cache = new CachingTokenProvider($delegate);

        $cache->token();
        $cache->token();

        self::assertSame(1, $delegate->calls);
    }

    // -------------------------------------------------------------- helpers

    private function clientCredentials(): ClientCredentialsTokenProvider
    {
        return new ClientCredentialsTokenProvider(
            Fixtures::restClientWithHeaders($this->http(), 'azuredns', ClientCredentialsTokenProvider::DEFAULT_AUTHORITY, []),
            'tenant-1',
            'client-1',
            'secret-1',
        );
    }

    private function managedIdentity(?string $clientId = null): ManagedIdentityTokenProvider
    {
        return new ManagedIdentityTokenProvider(
            Fixtures::restClientWithHeaders(
                $this->http(),
                'azuredns',
                ManagedIdentityTokenProvider::IMDS_ENDPOINT,
                ['Metadata' => 'true'],
            ),
            $clientId,
        );
    }

    private function countingProvider(AccessToken $token): CountingTokenProvider
    {
        return new CountingTokenProvider($token);
    }
}
