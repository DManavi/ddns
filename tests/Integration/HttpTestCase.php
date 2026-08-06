<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Bootstrap;
use Ddns\Http\AppFactoryBuilder;
use Ddns\Http\OpenApi\SwaggerUiAssets;
use Ddns\Tests\Support\MockHttpClient;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Drives the real Slim application in process, with only the outbound HTTP
 * client replaced, so routing, middleware ordering, authentication and error
 * translation are all exercised together.
 */
abstract class HttpTestCase extends TestCase
{
    protected const HOST_TOKEN = 'host-token-0123456789abcdef';

    private ?MockHttpClient $upstream = null;

    private ?string $configPath = null;

    /**
     * Where the application should look for locally installed Swagger UI
     * assets. Left unset, it is a directory that has none.
     */
    protected ?string $assetDirectory = null;

    /**
     * The fake upstream provider API, created on first use.
     */
    protected function upstream(): MockHttpClient
    {
        return $this->upstream ??= new MockHttpClient();
    }

    protected function tearDown(): void
    {
        if ($this->configPath !== null && is_file($this->configPath)) {
            unlink($this->configPath);
        }

        $this->configPath = null;
        $this->upstream = null;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $path,
        array $headers = [],
        string $remoteAddr = '203.0.113.7',
        string $configYaml = '',
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'https://ddns.test' . $path,
            ['REMOTE_ADDR' => $remoteAddr],
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app($configYaml === '' ? $this->defaultConfig() : $configYaml)->handle($request);
    }

    /**
     * Drive the application against a configuration file that may not exist,
     * which `request()` cannot express because it always writes one.
     */
    protected function requestWithConfigPath(string $method, string $path, string $configPath): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'https://ddns.test' . $path,
            ['REMOTE_ADDR' => '203.0.113.7'],
        );

        return $this->appWithConfigPath($configPath)->handle($request);
    }

    /**
     * Drive a request whose Basic credentials were consumed by the web server
     * and surfaced as PHP_AUTH_USER / PHP_AUTH_PW, which is what some
     * configurations do before PHP ever sees the header.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $serverParams
     */
    protected function requestWithServerParams(
        string $method,
        string $path,
        array $headers = [],
        array $serverParams = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'https://ddns.test' . $path,
            ['REMOTE_ADDR' => '203.0.113.7'] + $serverParams,
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app($this->defaultConfig())->handle($request);
    }

    protected function handle(ServerRequestInterface $request, string $configYaml = ''): ResponseInterface
    {
        return $this->app($configYaml === '' ? $this->defaultConfig() : $configYaml)->handle($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertIsArray($decoded, 'The response body was not valid JSON.');

        return $decoded;
    }

    /**
     * Read a dotted path out of the JSON body, failing the test when absent.
     */
    protected function at(ResponseInterface $response, string $path): mixed
    {
        $current = $this->json($response);

        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($current, sprintf('"%s" is not traversable at "%s".', $path, $segment));
            self::assertArrayHasKey($segment, $current, sprintf('The response has no "%s".', $path));

            $current = $current[$segment] ?? null;
        }

        return $current;
    }

    protected function atString(ResponseInterface $response, string $path): string
    {
        $value = $this->at($response, $path);

        self::assertIsString($value, sprintf('"%s" is not a string.', $path));

        return $value;
    }

    protected function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    /**
     * @return App<ContainerInterface|null>
     */
    protected function app(string $configYaml): App
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'ddns-it-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($this->configPath, $configYaml);

        return $this->appWithConfigPath($this->configPath);
    }

    /**
     * @return App<ContainerInterface|null>
     */
    protected function appWithConfigPath(string $configPath): App
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require Bootstrap::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            'config.path' => $configPath,
            // Only the outbound client is faked; everything else is the real
            // application wiring.
            ClientInterface::class => $this->upstream(),
            LoggerInterface::class => new NullLogger(),
            // Pointed at a directory with nothing in it, so the suite behaves
            // the same whether or not the developer has installed a local copy
            // of the Swagger UI assets into public/vendor.
            SwaggerUiAssets::class => new SwaggerUiAssets($this->assetDirectory ?? sys_get_temp_dir() . '/ddns-no-assets'),
        ]);

        return AppFactoryBuilder::create($builder->build());
    }

    protected function defaultConfig(string $extraServer = '', string $extraHost = ''): string
    {
        return <<<YAML
            server:
              default_ttl: 300
            {$extraServer}
            providers:
              p1:
                driver: digitalocean
                token: provider-secret
            hosts:
              home:
                provider: p1
                zone: example.com
                name: home
                types: [A]
                ttl: 60
                token: {$this->tokenLiteral()}
            {$extraHost}
            YAML;
    }

    private function tokenLiteral(): string
    {
        return self::HOST_TOKEN;
    }

    /**
     * Queue a DigitalOcean lookup that finds no record, followed by a create.
     */
    protected function expectCreateFlow(string $ip): void
    {
        $this->upstream()
            ->queue(200, ['domain_records' => []])
            ->queue(201, [
                'domain_record' => ['id' => 1, 'type' => 'A', 'name' => 'home', 'data' => $ip, 'ttl' => 60],
            ]);
    }

    /**
     * Queue a DigitalOcean lookup that finds a record already pointing at $ip.
     */
    protected function expectUnchangedFlow(string $ip): void
    {
        $this->upstream()->queue(200, [
            'domain_records' => [
                ['id' => 1, 'type' => 'A', 'name' => 'home', 'data' => $ip, 'ttl' => 60],
            ],
        ]);
    }
}
