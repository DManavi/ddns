<?php

declare(strict_types=1);

use Ddns\Config\ConfigLoader;
use Ddns\Config\Configuration;
use Ddns\Config\EnvInterpolator;
use Ddns\Config\Environment;
use Ddns\Config\ServerConfig;
use Ddns\Domain\Provider\ProviderLocator;
use Ddns\Domain\Update\DdnsUpdater;
use Ddns\Http\Middleware\AuthenticationMiddleware;
use Ddns\Http\Middleware\TrustedProxyMiddleware;
use Ddns\Ip\ClientIpDetector;
use Ddns\Ip\HttpIpResolver;
use Ddns\Provider\Cloudflare\CloudflareProviderFactory;
use Ddns\Provider\DigitalOcean\DigitalOceanProviderFactory;
use Ddns\Provider\ProviderFactories;
use Ddns\Provider\ProviderRegistry;
use Ddns\Provider\Route53\Route53ProviderFactory;
use Ddns\Provider\Vultr\VultrProviderFactory;
use Ddns\Support\LogLevel;
use Ddns\Support\Services;

use function DI\get;

use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

return [
    // ---------------------------------------------------------------- logging
    LoggerInterface::class => static function (): LoggerInterface {
        $logger = new Logger('ddns');
        $logger->pushHandler(new StreamHandler('php://stderr', LogLevel::fromEnvironment()));

        return $logger;
    },

    // ------------------------------------------------------------- PSR-17/18
    RequestFactoryInterface::class => get(RequestFactory::class),
    ResponseFactoryInterface::class => get(ResponseFactory::class),
    StreamFactoryInterface::class => get(StreamFactory::class),

    ClientInterface::class => static fn (): ClientInterface => new GuzzleClient([
        'timeout' => 15.0,
        'connect_timeout' => 5.0,
        // Error statuses are meaningful to the drivers, so they must not be
        // turned into exceptions before the response is inspected.
        'http_errors' => false,
    ]),

    // ---------------------------------------------------------- provider set
    // Built before the configuration so the loader can validate `driver:`
    // values against the drivers that actually exist.
    ProviderFactories::class => static function (ContainerInterface $c): ProviderFactories {
        $http = Services::get($c, ClientInterface::class);
        $requests = Services::get($c, RequestFactoryInterface::class);
        $streams = Services::get($c, StreamFactoryInterface::class);

        return new ProviderFactories(
            new DigitalOceanProviderFactory($http, $requests, $streams),
            new VultrProviderFactory($http, $requests, $streams),
            new CloudflareProviderFactory($http, $requests, $streams),
            new Route53ProviderFactory(),
        );
    },

    // --------------------------------------------------------- configuration
    Environment::class => static fn (): Environment => Environment::fromGlobals(),

    EnvInterpolator::class => static fn (ContainerInterface $c): EnvInterpolator => new EnvInterpolator(
        Services::get($c, Environment::class),
    ),

    ConfigLoader::class => static fn (ContainerInterface $c): ConfigLoader => new ConfigLoader(
        Services::get($c, EnvInterpolator::class),
        Services::get($c, ProviderFactories::class)->catalog(),
    ),

    Configuration::class => static fn (ContainerInterface $c): Configuration => Services::get($c, ConfigLoader::class)
        ->load(Services::string($c, 'config.path')),

    ServerConfig::class => static fn (ContainerInterface $c): ServerConfig => Services::get($c, Configuration::class)
        ->server(),

    // -------------------------------------------------------------- registry
    ProviderRegistry::class => static fn (ContainerInterface $c): ProviderRegistry => new ProviderRegistry(
        Services::get($c, Configuration::class),
        ...Services::get($c, ProviderFactories::class)->all(),
    ),

    ProviderLocator::class => get(ProviderRegistry::class),

    // ---------------------------------------------------------- the use case
    DdnsUpdater::class => static fn (ContainerInterface $c): DdnsUpdater => new DdnsUpdater(
        Services::get($c, Configuration::class),
        Services::get($c, ProviderLocator::class),
        Services::get($c, LoggerInterface::class),
    ),

    // -------------------------------------------------------------------- IP
    HttpIpResolver::class => static function (ContainerInterface $c): HttpIpResolver {
        $server = Services::get($c, ServerConfig::class);

        return new HttpIpResolver(
            // A separate client: echo services must fail fast so the next one
            // is tried promptly, unlike a provider API call.
            new GuzzleClient([
                'timeout' => $server->ipLookupTimeout(),
                'connect_timeout' => $server->ipLookupTimeout(),
                'http_errors' => false,
            ]),
            Services::get($c, RequestFactoryInterface::class),
            $server->ipv4Services(),
            $server->ipv6Services(),
            Services::get($c, LoggerInterface::class),
        );
    },

    ClientIpDetector::class => static fn (ContainerInterface $c): ClientIpDetector => new ClientIpDetector(
        Services::get($c, ServerConfig::class),
    ),

    // ------------------------------------------------------------- HTTP bits
    AuthenticationMiddleware::class => static fn (ContainerInterface $c): AuthenticationMiddleware
        => new AuthenticationMiddleware(
            Services::get($c, Configuration::class),
            Services::get($c, ResponseFactoryInterface::class),
            Services::get($c, LoggerInterface::class),
        ),

    TrustedProxyMiddleware::class => static fn (ContainerInterface $c): TrustedProxyMiddleware
        => new TrustedProxyMiddleware(Services::get($c, ClientIpDetector::class)),
];
