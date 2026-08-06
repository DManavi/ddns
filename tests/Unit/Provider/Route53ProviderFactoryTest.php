<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider;

use Aws\Credentials\Credentials;
use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\Exception\ProviderNotImplemented;
use Ddns\Provider\Route53\Route53Provider;
use Ddns\Provider\Route53\Route53ProviderFactory;
use PHPUnit\Framework\TestCase;

final class Route53ProviderFactoryTest extends TestCase
{
    public function testIsRegisteredAndAvailable(): void
    {
        $factory = new Route53ProviderFactory();

        self::assertSame('route53', $factory->driver());
        self::assertTrue($factory->isAvailable(), 'aws/aws-sdk-php should be installed.');
        self::assertNull($factory->unavailableReason());
    }

    /**
     * Credentials can come entirely from the AWS chain, so the config file need
     * not carry a token at all.
     */
    public function testDoesNotRequireAToken(): void
    {
        self::assertFalse((new Route53ProviderFactory())->requiresToken());
    }

    public function testBuildsAProvider(): void
    {
        $provider = (new Route53ProviderFactory())->create($this->config());

        self::assertInstanceOf(Route53Provider::class, $provider);
        self::assertSame('route53', $provider->driver());
    }

    public function testPinsTheApiVersionRatherThanTrackingLatest(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config());

        self::assertSame('2013-04-01', $this->argument($arguments, 'version'));
    }

    /**
     * Route53 is global, but the SDK still demands a region.
     */
    public function testDefaultsToTheGlobalEndpointRegion(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config());

        self::assertSame('us-east-1', $this->argument($arguments, 'region'));
    }

    public function testRegionCanBeOverriddenForOtherPartitions(): void
    {
        $arguments = (new Route53ProviderFactory())
            ->clientArguments($this->config(['region' => 'us-gov-west-1']));

        self::assertSame('us-gov-west-1', $this->argument($arguments, 'region'));
    }

    /**
     * The headline case for running on AWS: no static keys anywhere, an
     * instance or task role supplies them at runtime.
     */
    public function testLeavesCredentialsToTheDefaultChainWhenNoneAreConfigured(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config());

        self::assertArrayNotHasKey('credentials', $arguments);
    }

    public function testUsesExplicitCredentialsWhenBothArePresent(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'super-secret',
        ]));

        $credentials = $this->credentials($arguments);

        self::assertSame('AKIAIOSFODNN7EXAMPLE', $credentials->getAccessKeyId());
        self::assertSame('super-secret', $credentials->getSecretKey());
        self::assertNull($credentials->getSecurityToken());
    }

    public function testAcceptsTheLongerAwsKeyNames(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config([
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'super-secret',
        ]));

        self::assertInstanceOf(Credentials::class, $this->argument($arguments, 'credentials'));
    }

    /**
     * A half-configured credential pair would fail confusingly at call time, so
     * it falls through to the chain instead.
     */
    public function testIgnoresAKeyWithoutASecret(): void
    {
        $arguments = (new Route53ProviderFactory())
            ->clientArguments($this->config(['key' => 'AKIAIOSFODNN7EXAMPLE']));

        self::assertArrayNotHasKey('credentials', $arguments);
    }

    /**
     * Temporary STS credentials need a session token alongside the pair; the
     * existing `token` field carries it.
     */
    public function testUsesTheTokenAsTheSessionToken(): void
    {
        $config = new ProviderConfig('aws', 'route53', 'session-token-value', [
            'key' => 'ASIAIOSFODNN7EXAMPLE',
            'secret' => 'super-secret',
        ]);

        $credentials = $this->credentials((new Route53ProviderFactory())->clientArguments($config));

        self::assertSame('session-token-value', $credentials->getSecurityToken());
    }

    public function testAnExplicitSessionTokenWinsOverTheTokenField(): void
    {
        $config = new ProviderConfig('aws', 'route53', 'from-token', [
            'key' => 'ASIAIOSFODNN7EXAMPLE',
            'secret' => 'super-secret',
            'session_token' => 'from-session-token',
        ]);

        $credentials = $this->credentials((new Route53ProviderFactory())->clientArguments($config));

        self::assertSame('from-session-token', $credentials->getSecurityToken());
    }

    public function testPassesAProfileThroughToTheSdk(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config(['profile' => 'personal']));

        self::assertSame('personal', $this->argument($arguments, 'profile'));
    }

    /**
     * The SDK rejects a profile alongside explicit credentials.
     */
    public function testDropsTheProfileWhenExplicitCredentialsAreGiven(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config([
            'profile' => 'personal',
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'super-secret',
        ]));

        self::assertArrayNotHasKey('profile', $arguments);
        self::assertArrayHasKey('credentials', $arguments);
    }

    public function testSupportsACustomEndpoint(): void
    {
        $arguments = (new Route53ProviderFactory())
            ->clientArguments($this->config(['endpoint' => 'https://localstack.test:4566']));

        self::assertSame('https://localstack.test:4566', $this->argument($arguments, 'endpoint'));
    }

    public function testBlankOptionsAreTreatedAsAbsent(): void
    {
        $arguments = (new Route53ProviderFactory())->clientArguments($this->config([
            'region' => '   ',
            'profile' => '',
        ]));

        self::assertSame('us-east-1', $this->argument($arguments, 'region'));
        self::assertArrayNotHasKey('profile', $arguments);
    }

    /**
     * If the SDK were stripped from a slimmed-down build, this should fail with
     * an actionable error rather than a fatal missing-class error.
     */
    public function testReportsAClearErrorWhenTheSdkIsAbsent(): void
    {
        $factory = new Route53ProviderFactory(sdkAvailable: false);

        self::assertFalse($factory->isAvailable());
        self::assertNotNull($factory->unavailableReason());
        self::assertStringContainsString('composer require aws/aws-sdk-php', (string) $factory->unavailableReason());

        $this->expectException(ProviderNotImplemented::class);

        $factory->create($this->config());
    }

    public function testAMissingSdkSuggests501(): void
    {
        self::assertSame(501, ProviderNotImplemented::for('route53', 'reason')->suggestedHttpStatus());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function config(array $options = []): ProviderConfig
    {
        return new ProviderConfig('aws', 'route53', '', $options);
    }

    /**
     * A client argument, failing the test rather than tripping over a
     * missing key.
     *
     * @param array<string, mixed> $arguments
     */
    private function argument(array $arguments, string $key): mixed
    {
        self::assertArrayHasKey($key, $arguments, sprintf('No "%s" client argument was built.', $key));

        return $arguments[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function credentials(array $arguments): Credentials
    {
        $credentials = $this->argument($arguments, 'credentials');

        self::assertInstanceOf(Credentials::class, $credentials);

        return $credentials;
    }
}
