<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Ddns\Config\ConfigLoader;
use Ddns\Config\Configuration;
use Ddns\Config\EnvInterpolator;
use Ddns\Config\Environment;
use Ddns\Config\HostConfig;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Http\RestClient;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Shorthand builders so tests state only what they are actually about.
 */
final class Fixtures
{
    public const TOKEN = 'test-token-0123456789abcdef';

    /** @var list<string> */
    public const DRIVERS = ['digitalocean', 'vultr', 'cloudflare', 'route53'];

    public static function restClient(MockHttpClient $client, string $driver, string $baseUri): RestClient
    {
        return new RestClient(
            $client,
            new RequestFactory(),
            new StreamFactory(),
            $driver,
            $baseUri,
            ['Authorization' => 'Bearer secret-api-key'],
        );
    }

    /**
     * @param list<RecordType> $types
     */
    public static function host(
        string $name = 'home',
        string $zone = 'example.com',
        string $record = 'home',
        array $types = [RecordType::A],
        int $ttl = 300,
        string $provider = 'p1',
        string $token = self::TOKEN,
    ): HostConfig {
        return new HostConfig($name, $provider, Hostname::create($zone, $record), $types, $ttl, $token);
    }

    /**
     * Build a Configuration straight from an array, bypassing the filesystem.
     *
     * @param array<array-key, mixed> $raw
     * @param array<string, string>   $env
     */
    public static function configuration(array $raw, array $env = []): Configuration
    {
        return self::loader($env)->fromArray($raw);
    }

    /**
     * @param array<string, string> $env
     */
    public static function loader(array $env = []): ConfigLoader
    {
        return new ConfigLoader(new EnvInterpolator(new Environment($env)), self::DRIVERS);
    }

    /**
     * A minimal but complete configuration array.
     *
     * The precise shape lets tests mutate individual keys without losing type
     * information.
     *
     * @param array<string, mixed> $hostOverrides
     *
     * @return array{
     *     server: array<string, mixed>,
     *     providers: array{p1: array<string, mixed>},
     *     hosts: array{home: array<string, mixed>}
     * }
     */
    public static function rawConfig(array $hostOverrides = []): array
    {
        return [
            'server' => ['default_ttl' => 300],
            'providers' => [
                'p1' => ['driver' => 'digitalocean', 'token' => 'provider-token'],
            ],
            'hosts' => [
                'home' => [
                    'provider' => 'p1',
                    'zone' => 'example.com',
                    'name' => 'home',
                    'types' => ['A'],
                    'ttl' => 60,
                    'token' => self::TOKEN,
                    ...$hostOverrides,
                ],
            ],
        ];
    }
}
