<?php

declare(strict_types=1);

namespace Ddns\Provider\Route53;

use Aws\Credentials\Credentials;
use Aws\Route53\Route53Client;
use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\ProviderNotImplemented;
use Ddns\Provider\ProviderFactory;

/**
 * Builds the Route53 driver from configuration.
 *
 * Credentials are optional on purpose. When none are given the SDK's default
 * provider chain runs, which picks up environment variables, a shared
 * credentials file, an EC2 instance profile, an ECS task role or IRSA - the
 * usual way to run on AWS without putting long-lived keys in a file.
 */
final class Route53ProviderFactory implements ProviderFactory
{
    /** Pinned rather than 'latest', so an SDK upgrade cannot change behaviour. */
    private const API_VERSION = '2013-04-01';

    /**
     * @param bool|null $sdkAvailable overrides detection of aws-sdk-php; null
     *                                autodetects, which is what production uses
     */
    public function __construct(private readonly ?bool $sdkAvailable = null)
    {
    }

    public function driver(): string
    {
        return Route53Provider::DRIVER;
    }

    public function description(): string
    {
        return 'AWS Route53 (aws-sdk-php)';
    }

    /**
     * The SDK is a hard dependency, but it is large enough that someone may
     * strip it from a slimmed-down build, so this degrades rather than fatals.
     */
    public function isAvailable(): bool
    {
        return $this->sdkAvailable ?? class_exists(Route53Client::class);
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable()
            ? null
            : 'aws/aws-sdk-php is not installed. Run: composer require aws/aws-sdk-php';
    }

    /**
     * Route53 authenticates with AWS credentials, which may be supplied
     * entirely outside the config file.
     */
    public function requiresToken(): bool
    {
        return false;
    }

    public function create(ProviderConfig $config): DnsProvider
    {
        if (!$this->isAvailable()) {
            throw ProviderNotImplemented::for(Route53Provider::DRIVER, (string) $this->unavailableReason());
        }

        return new Route53Provider(
            new Route53Client($this->clientArguments($config)),
            $this->option($config, 'zone_id'),
            $config->option('private_zone') === true,
        );
    }

    /**
     * Map provider configuration onto AWS SDK client arguments.
     *
     * Public because this mapping - which credentials win, which region is
     * used, when the default chain is left to run - is the part of the factory
     * worth asserting on directly.
     *
     * @return array<string, mixed>
     */
    public function clientArguments(ProviderConfig $config): array
    {
        $arguments = [
            'version' => self::API_VERSION,
            // Route53 is a global service, but the SDK still requires a region.
            // Overridable for the GovCloud and China partitions.
            'region' => $this->option($config, 'region') ?? Route53Provider::DEFAULT_REGION,
        ];

        $credentials = $this->credentials($config);

        if ($credentials !== null) {
            $arguments['credentials'] = $credentials;
        }

        $profile = $this->option($config, 'profile');

        // The SDK rejects `profile` alongside explicit credentials, so explicit
        // credentials win and the profile is ignored.
        if ($profile !== null && $credentials === null) {
            $arguments['profile'] = $profile;
        }

        $endpoint = $this->option($config, 'endpoint');

        if ($endpoint !== null) {
            $arguments['endpoint'] = $endpoint;
        }

        return $arguments;
    }

    /**
     * Static credentials, or null to fall through to the SDK's default chain.
     */
    private function credentials(ProviderConfig $config): ?Credentials
    {
        $key = $this->option($config, 'key') ?? $this->option($config, 'access_key_id');
        $secret = $this->option($config, 'secret') ?? $this->option($config, 'secret_access_key');

        if ($key === null || $secret === null) {
            return null;
        }

        // `token` is reused here for the AWS session token, which is what
        // temporary STS credentials need alongside the key and secret.
        $sessionToken = $this->option($config, 'session_token')
            ?? ($config->token() === '' ? null : $config->token());

        return new Credentials($key, $secret, $sessionToken);
    }

    /**
     * A non-empty string option, or null.
     */
    private function option(ProviderConfig $config, string $key): ?string
    {
        $value = $config->option($key);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
