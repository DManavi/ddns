<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider\Azure;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Azure\AzureDnsProvider;
use Ddns\Provider\Azure\AzureDnsProviderFactory;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class AzureDnsProviderFactoryTest extends TestCase
{
    private ?MockHttpClient $http = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    private function factory(): AzureDnsProviderFactory
    {
        return new AzureDnsProviderFactory($this->http(), new RequestFactory(), new StreamFactory());
    }

    public function testIsRegisteredAndAvailable(): void
    {
        $factory = $this->factory();

        self::assertSame('azuredns', $factory->driver());
        self::assertTrue($factory->isAvailable());
        self::assertNull($factory->unavailableReason());
    }

    /**
     * Azure authenticates with a service principal or a managed identity, not a
     * static bearer token in the file.
     */
    public function testDoesNotRequireAToken(): void
    {
        self::assertFalse($this->factory()->requiresToken());
    }

    /**
     * Neither is the generic `token` field, so the loader has to be told.
     */
    public function testDeclaresItsMandatoryOptions(): void
    {
        self::assertSame(['subscription_id', 'resource_group'], $this->factory()->requiredOptions());
    }

    public function testBuildsAProvider(): void
    {
        $provider = $this->factory()->create($this->config());

        self::assertInstanceOf(AzureDnsProvider::class, $provider);
        self::assertSame('azuredns', $provider->driver());
    }

    // --------------------------------------------------- auth method choice

    /**
     * A client secret selects the service principal flow.
     */
    public function testUsesTheServicePrincipalFlowWhenASecretIsGiven(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 'sp-token', 'expires_in' => 3599])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $provider = $this->factory()->create($this->config([
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
        ]));

        $provider->findRecord($this->hostname(), RecordType::A);

        self::assertStringContainsString(
            'login.microsoftonline.com/tenant-1/oauth2/v2.0/token',
            (string) $this->http()->request(0)->getUri(),
        );
        self::assertSame('Bearer sp-token', $this->http()->request(1)->getHeaderLine('Authorization'));
    }

    /**
     * No secret means the host's own managed identity, which is the
     * recommended way to run on Azure.
     */
    public function testFallsBackToManagedIdentityWithoutASecret(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 'mi-token', 'expires_on' => (string) (time() + 3600)])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $provider = $this->factory()->create($this->config());

        $provider->findRecord($this->hostname(), RecordType::A);

        $tokenRequest = $this->http()->request(0);

        self::assertStringContainsString('169.254.169.254', (string) $tokenRequest->getUri());
        self::assertSame('true', $tokenRequest->getHeaderLine('Metadata'));
        self::assertSame('Bearer mi-token', $this->http()->request(1)->getHeaderLine('Authorization'));
    }

    /**
     * `token` is the field every other driver uses, so accepting it as the
     * client secret saves an operator from a puzzling failure.
     */
    public function testAcceptsTheTokenFieldAsTheClientSecret(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 'sp-token', 'expires_in' => 3599])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $config = new ProviderConfig('azure', 'azuredns', 'secret-from-token-field', [
            'subscription_id' => 'sub-1',
            'resource_group' => 'rg-1',
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
        ]);

        $this->factory()->create($config)->findRecord($this->hostname(), RecordType::A);

        parse_str((string) $this->http()->request(0)->getBody(), $fields);

        self::assertSame('secret-from-token-field', $fields['client_secret'] ?? null);
    }

    /**
     * With several identities attached, IMDS refuses to guess, so a bare
     * client_id must not be mistaken for a service principal.
     */
    public function testAClientIdAloneStillMeansManagedIdentity(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 'mi-token', 'expires_on' => (string) (time() + 3600)])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $this->factory()
            ->create($this->config(['client_id' => 'user-assigned']))
            ->findRecord($this->hostname(), RecordType::A);

        $uri = (string) $this->http()->request(0)->getUri();

        self::assertStringContainsString('169.254.169.254', $uri);
        self::assertStringContainsString('client_id=user-assigned', $uri);
    }

    // --------------------------------------------------------- token reuse

    /**
     * The whole point of the cache: a second operation must not re-authenticate.
     */
    public function testAuthenticatesOnceAcrossSeveralOperations(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 'mi-token', 'expires_on' => (string) (time() + 3600)])
            ->queue(404, ['error' => ['code' => 'NotFound']])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $provider = $this->factory()->create($this->config());

        $provider->findRecord($this->hostname(), RecordType::A);
        $provider->findRecord($this->hostname(), RecordType::A);

        self::assertSame(3, $this->http()->requestCount(), 'One token exchange, then two lookups.');
    }

    // ------------------------------------------------------------ overrides

    public function testSupportsASovereignCloudAuthority(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 't', 'expires_in' => 3599])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $this->factory()->create($this->config([
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'authority' => 'https://login.microsoftonline.us',
        ]))->findRecord($this->hostname(), RecordType::A);

        self::assertStringStartsWith(
            'https://login.microsoftonline.us/',
            (string) $this->http()->request(0)->getUri(),
        );
    }

    public function testSupportsASovereignCloudManagementEndpoint(): void
    {
        $this->http()
            ->queue(200, ['access_token' => 't', 'expires_on' => (string) (time() + 3600)])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $this->factory()
            ->create($this->config(['endpoint' => 'https://management.usgovcloudapi.net']))
            ->findRecord($this->hostname(), RecordType::A);

        self::assertStringStartsWith(
            'https://management.usgovcloudapi.net/',
            (string) $this->http()->request(1)->getUri(),
        );
    }

    private function hostname(): Hostname
    {
        return Hostname::create('example.com', 'home');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function config(array $options = []): ProviderConfig
    {
        return new ProviderConfig('azure', 'azuredns', '', [
            'subscription_id' => 'sub-1',
            'resource_group' => 'rg-1',
            ...$options,
        ]);
    }
}
