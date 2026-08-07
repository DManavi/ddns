<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Bootstrap;
use Ddns\Console\ConsoleApplicationFactory;
use Ddns\Http\AppFactoryBuilder;
use Ddns\Ip\HttpIpResolver;
use Ddns\Support\Services;
use Ddns\Tests\Support\ConsoleResult;
use Ddns\Tests\Support\MockHttpClient;
use Ddns\Tests\Support\SplitOutput;
use Ddns\Tests\Support\StaticHttpClient;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drives the real console application in process, with only the outbound HTTP
 * client replaced.
 *
 * stdout and stderr are captured into separate buffers, because the promise
 * `--json` makes is that stdout carries nothing but the document. A harness
 * that merged the two could not tell a passing command from one that had
 * quietly written a warning into the middle of the JSON.
 */
abstract class ConsoleTestCase extends TestCase
{
    private ?MockHttpClient $upstream = null;

    /**
     * Where the commands write secrets. A scratch file, never the project's
     * own .env, which is a real developer's file and not this suite's to edit.
     */
    protected ?string $envFile = null;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirectories = [];

    /**
     * The address the faked echo service reports, for commands that resolve
     * rather than being told one.
     */
    protected string $publicIp = '203.0.113.7';

    protected function upstream(): MockHttpClient
    {
        return $this->upstream ??= new MockHttpClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ($this->tempDirectories as $directory) {
            // scandir rather than glob: GLOB_BRACE is not available on every
            // build, and dotfiles matter here because .env is one.
            foreach ((array) scandir($directory) as $entry) {
                if (is_string($entry) && $entry !== '.' && $entry !== '..' && is_file($directory . '/' . $entry)) {
                    unlink($directory . '/' . $entry);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        $this->tempFiles = [];
        $this->tempDirectories = [];
        $this->upstream = null;
    }

    /**
     * Run a command and capture both streams independently.
     *
     * @param array<string, mixed> $input
     */
    protected function runCommand(
        array $input,
        string $configYaml = '',
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
        ?string $configPath = null,
    ): ConsoleResult {
        // bin/ddns reads --config before the container is built, so a test
        // naming a file does the same rather than passing an option the
        // commands never see.
        $configPath ??= $this->tempFile($configYaml === '' ? $this->defaultConfig() : $configYaml);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require Bootstrap::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            'config.path' => $configPath,
            'env.path' => $this->envFile ??= $this->tempFile(''),
            ClientInterface::class => $this->upstream(),
            LoggerInterface::class => new NullLogger(),
            // The resolver builds its own Guzzle client in the container, so
            // without this override the suite would reach the real internet.
            HttpIpResolver::class => fn (ContainerInterface $c): HttpIpResolver => new HttpIpResolver(
                new StaticHttpClient($this->publicIp),
                Services::get($c, RequestFactoryInterface::class),
                ['https://echo.test/ip'],
                [],
            ),
        ]);

        $application = ConsoleApplicationFactory::create($builder->build());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new SplitOutput($verbosity);
        $exitCode = $application->run(new ArrayInput($input), $output);

        return new ConsoleResult($exitCode, $output->stdout(), $output->stderr());
    }

    /**
     * Run a command that asks questions, answering them from a script.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $answers one per prompt, in order
     */
    protected function runInteractive(array $input, array $answers, string $configYaml = ''): ConsoleResult
    {
        $configPath = $this->tempFile($configYaml === '' ? $this->defaultConfig() : $configYaml);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require Bootstrap::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            'config.path' => $configPath,
            'env.path' => $this->envFile ??= $this->tempFile(''),
            ClientInterface::class => $this->upstream(),
            LoggerInterface::class => new NullLogger(),
        ]);

        $application = ConsoleApplicationFactory::create($builder->build());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $stream = fopen('php://memory', 'r+') ?: throw new \RuntimeException('cannot open memory stream');
        fwrite($stream, implode("\n", $answers) . "\n");
        rewind($stream);

        $consoleInput = new ArrayInput($input);
        $consoleInput->setInteractive(true);
        $consoleInput->setStream($stream);

        $output = new SplitOutput(OutputInterface::VERBOSITY_NORMAL);
        $exitCode = $application->run($consoleInput, $output);

        return new ConsoleResult($exitCode, $output->stdout(), $output->stderr());
    }

    /**
     * The application container, wired exactly as the commands see it.
     */
    protected function container(): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require Bootstrap::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            'config.path' => $this->tempFile($this->defaultConfig()),
            ClientInterface::class => $this->upstream(),
            LoggerInterface::class => new NullLogger(),
        ]);

        return $builder->build();
    }

    /**
     * A directory that is cleaned up with the test, for files a command writes.
     */
    protected function tempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/ddns-cli-' . bin2hex(random_bytes(6));
        mkdir($path, 0700, true);
        $this->tempDirectories[] = $path;

        return $path;
    }

    /**
     * Drive the HTTP front-end over the same config and faked upstream, so the
     * two front-ends can be compared directly.
     *
     * @return array<string, mixed>
     */
    protected function httpUpdate(string $ip, string $host = 'home', string $configYaml = ''): array
    {
        $configPath = $this->tempFile($configYaml === '' ? $this->defaultConfig() : $configYaml);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require Bootstrap::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            'config.path' => $configPath,
            'env.path' => $this->envFile ??= $this->tempFile(''),
            ClientInterface::class => $this->upstream(),
            LoggerInterface::class => new NullLogger(),
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', sprintf('https://ddns.test/v1/hosts/%s/update?ip=%s', $host, $ip), [
                'REMOTE_ADDR' => '203.0.113.1',
            ])
            ->withHeader('Authorization', 'Bearer host-token-0123456789abcdef');

        $response = AppFactoryBuilder::create($builder->build())->handle($request);
        $response->getBody()->rewind();

        $decoded = json_decode((string) $response->getBody(), true);

        TestCase::assertIsArray($decoded, 'The HTTP response was not JSON.');

        return $decoded;
    }

    protected function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ddns-cli-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function defaultConfig(string $extraProviders = '', string $extraHosts = ''): string
    {
        return <<<YAML
            server:
              default_ttl: 300
            providers:
              p1:
                driver: digitalocean
                token: provider-secret
            {$extraProviders}
            hosts:
              home:
                provider: p1
                zone: example.com
                name: home
                types: [A]
                ttl: 60
                token: host-token-0123456789abcdef
            {$extraHosts}
            YAML;
    }

    protected function expectCreateFlow(string $ip): void
    {
        $this->upstream()
            ->queue(200, ['domain_records' => []])
            ->queue(201, [
                'domain_record' => ['id' => 1, 'type' => 'A', 'name' => 'home', 'data' => $ip, 'ttl' => 60],
            ]);
    }

    protected function expectUnchangedFlow(string $ip): void
    {
        $this->upstream()->queue(200, [
            'domain_records' => [
                ['id' => 1, 'type' => 'A', 'name' => 'home', 'data' => $ip, 'ttl' => 60],
            ],
        ]);
    }
}
