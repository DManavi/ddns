<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Config\ConfigFile;
use Ddns\Console\EnvFileWriter;
use Ddns\Tests\Support\ConsoleResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `hosts:add`, `hosts:update` and `hosts:remove`.
 *
 * These rewrite the file somebody's service depends on, so what is worth
 * testing is what they refuse to do: write a configuration that will not load,
 * put a secret where it can be committed, discard comments unasked, or leave
 * the server with no hosts at all.
 */
#[CoversNothing]
final class HostsManagementCommandTest extends ConsoleTestCase
{
    /** @var list<string> */
    private array $seeded = [];

    protected function tearDown(): void
    {
        foreach ([...$this->seeded, 'HOME_TOKEN'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }

        $this->seeded = [];

        parent::tearDown();
    }

    #[Test]
    public function it_adds_a_host(): void
    {
        $workspace = $this->workspace();

        $result = $this->add($workspace, ['name' => 'nas', '--provider' => 'p1', '--zone' => 'example.com']);

        self::assertSame(0, $result->exitCode, $result->stdout);

        $raw = ConfigFile::read($workspace . '/ddns.yaml');
        $host = $this->at($raw, 'hosts.nas');

        self::assertIsArray($host);
        self::assertSame('p1', $host['provider'] ?? null);
        self::assertSame('example.com', $host['zone'] ?? null);
        // The record defaults to the host name rather than the apex, which
        // would silently manage the whole domain.
        self::assertSame('nas', $host['name'] ?? null);
        self::assertSame(['A'], $host['types'] ?? null);
    }

    #[Test]
    public function the_generated_token_never_reaches_the_configuration(): void
    {
        $workspace = $this->workspace();

        $result = $this->add($workspace, [
            'name' => 'nas',
            '--provider' => 'p1',
            '--zone' => 'example.com',
            '--json' => true,
        ]);

        $payload = $this->decode($result->stdout);
        $token = $payload['token'] ?? null;

        self::assertIsString($token);
        self::assertGreaterThanOrEqual(32, strlen($token));

        $yaml = (string) file_get_contents($workspace . '/ddns.yaml');

        self::assertStringNotContainsString($token, $yaml);
        self::assertStringContainsString('${NAS_TOKEN}', $yaml);
        self::assertSame($token, EnvFileWriter::read($this->envPath())['NAS_TOKEN'] ?? null);
    }

    #[Test]
    public function what_it_writes_loads(): void
    {
        $workspace = $this->workspace();

        $this->add($workspace, ['name' => 'nas', '--provider' => 'p1', '--zone' => 'example.com']);

        // A real run would pick the new secret up from .env on the next start,
        // which is what this stands in for.
        $this->loadWrittenSecrets();

        $result = $this->runCommand(['command' => 'hosts:list', '--json' => true], '', 32, $workspace . '/ddns.yaml');

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        self::assertSame(
            ['home', 'nas'],
            array_column($this->atArray($this->decode($result->stdout), 'hosts'), 'name'),
        );
    }

    #[Test]
    public function it_refuses_a_provider_that_is_not_configured(): void
    {
        $workspace = $this->workspace();
        $before = (string) file_get_contents($workspace . '/ddns.yaml');

        $result = $this->add($workspace, ['name' => 'nas', '--provider' => 'ghost', '--zone' => 'example.com']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('not a configured provider', self::unwrap($result->stdout));
        self::assertSame($before, file_get_contents($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function it_refuses_a_record_outside_its_zone(): void
    {
        // The loader would refuse it too, but reported here the message names
        // the hostname rather than a config key path.
        $workspace = $this->workspace();

        $result = $this->add($workspace, [
            'name' => 'nas',
            '--provider' => 'p1',
            '--zone' => 'example.com',
            '--record' => 'nas.example.org',
        ]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('does not belong to zone', self::unwrap($result->stdout));
    }

    #[Test]
    public function it_refuses_a_name_that_would_not_work_in_a_url(): void
    {
        $workspace = $this->workspace();

        $result = $this->add($workspace, ['name' => 'not a host', '--provider' => 'p1', '--zone' => 'example.com']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('not usable in a URL', self::unwrap($result->stdout));
    }

    #[Test]
    public function it_refuses_to_add_a_host_that_already_exists(): void
    {
        // Overwriting would replace a working token with a new one, and the
        // client using the old one would simply start failing.
        $workspace = $this->workspace();

        $result = $this->add($workspace, ['name' => 'home', '--provider' => 'p1', '--zone' => 'example.com']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('already exists', self::unwrap($result->stdout));
    }

    /**
     * The command checks what it can - that the provider exists, that the
     * record belongs to the zone - but the loader is what knows the rest, and
     * skipping it would write a file the server then refuses to start with.
     *
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('mistakesOnlyTheLoaderCatches')]
    public function it_will_not_write_a_configuration_that_does_not_load(array $options, string $expected): void
    {
        $workspace = $this->workspace();
        $before = (string) file_get_contents($workspace . '/ddns.yaml');

        $result = $this->add($workspace, [
            'name' => 'other',
            '--provider' => 'p1',
            '--zone' => 'example.com',
        ] + $options);

        self::assertSame(2, $result->exitCode, $result->stdout);
        self::assertStringContainsString($expected, self::unwrap($result->stdout));
        self::assertStringContainsString('Nothing was written', self::unwrap($result->stdout));
        self::assertSame($before, file_get_contents($workspace . '/ddns.yaml'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mistakesOnlyTheLoaderCatches(): iterable
    {
        // Two hosts writing one record would fight each other on every poll.
        yield 'a record another host already manages' => [['--record' => 'home'], 'overwrite each other'];

        yield 'a token too short to be a secret' => [['--token' => 'abc'], 'at least 12 characters'];

        yield 'a ttl beyond what DNS allows' => [['--ttl' => '99999999'], 'must be between'];
    }

    #[Test]
    public function it_changes_only_what_it_was_asked_to(): void
    {
        $workspace = $this->workspace();
        $before = ConfigFile::read($workspace . '/ddns.yaml');

        $result = $this->runCommand(
            ['command' => 'hosts:update', 'name' => 'home', '--ttl' => '900'],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        self::assertSame(0, $result->exitCode, $result->stdout);

        $after = ConfigFile::read($workspace . '/ddns.yaml');
        $host = $this->at($after, 'hosts.home');
        $original = $this->at($before, 'hosts.home');

        self::assertIsArray($host);
        self::assertIsArray($original);
        self::assertSame(900, $host['ttl'] ?? null);

        foreach (['provider', 'zone', 'name', 'types', 'token'] as $untouched) {
            self::assertSame($original[$untouched] ?? null, $host[$untouched] ?? null, $untouched);
        }
    }

    #[Test]
    public function rotating_replaces_the_secret_and_leaves_the_file_alone(): void
    {
        // The placeholder is the file's only reference to the token, so
        // rotation changes what it points at rather than the file itself.
        $workspace = $this->workspace();
        $before = (string) file_get_contents($workspace . '/ddns.yaml');

        $result = $this->runCommand(
            ['command' => 'hosts:update', 'name' => 'home', '--rotate-token' => true, '--json' => true],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        $token = $this->decode($result->stdout)['token'] ?? null;

        self::assertIsString($token);
        self::assertNotSame('home-token-0123456789', $token);
        self::assertSame($token, EnvFileWriter::read($this->envPath())['HOME_TOKEN'] ?? null);
        self::assertSame($before, file_get_contents($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function updating_nothing_is_reported_rather_than_written(): void
    {
        $workspace = $this->workspace();

        $result = $this->runCommand(
            ['command' => 'hosts:update', 'name' => 'home'],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Nothing to change', self::unwrap($result->stdout));
    }

    #[Test]
    public function updating_a_host_that_does_not_exist_fails(): void
    {
        $workspace = $this->workspace();

        $result = $this->runCommand(
            ['command' => 'hosts:update', 'name' => 'ghost', '--ttl' => '60'],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('hosts:add', self::unwrap($result->stdout));
    }

    #[Test]
    public function it_removes_a_host(): void
    {
        $workspace = $this->workspace();
        $this->add($workspace, ['name' => 'nas', '--provider' => 'p1', '--zone' => 'example.com']);

        $result = $this->remove($workspace, 'nas');

        self::assertSame(0, $result->exitCode, $result->stdout);
        self::assertArrayNotHasKey('nas', (array) $this->at(ConfigFile::read($workspace . '/ddns.yaml'), 'hosts'));
    }

    #[Test]
    public function removing_names_the_variable_it_did_not_delete(): void
    {
        // .env is hand-edited and a variable may be shared, so it is reported
        // rather than removed.
        $workspace = $this->workspace();
        $this->add($workspace, ['name' => 'nas', '--provider' => 'p1', '--zone' => 'example.com']);

        $result = $this->runCommand(
            ['command' => 'hosts:remove', 'name' => 'nas', '--force' => true, '--json' => true],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        self::assertSame('NAS_TOKEN', $this->decode($result->stdout)['unused_variable'] ?? null);
        self::assertArrayHasKey('NAS_TOKEN', EnvFileWriter::read($this->envPath()));
    }

    #[Test]
    public function it_refuses_to_remove_the_last_host(): void
    {
        // At least one is required, so this would produce a file the server
        // then refuses to start with.
        $workspace = $this->workspace();

        $result = $this->remove($workspace, 'home');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('only configured host', self::unwrap($result->stdout));
        self::assertArrayHasKey('home', (array) $this->at(ConfigFile::read($workspace . '/ddns.yaml'), 'hosts'));
    }

    #[Test]
    public function removing_a_host_that_does_not_exist_fails(): void
    {
        $workspace = $this->workspace();

        $result = $this->remove($workspace, 'ghost');

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('No host called', self::unwrap($result->stdout));
    }

    #[Test]
    public function comments_are_not_discarded_without_being_asked_about(): void
    {
        $workspace = $this->workspace();
        $path = $workspace . '/ddns.yaml';
        $yaml = "# a comment worth keeping\n" . (string) file_get_contents($path);
        file_put_contents($path, $yaml);

        $result = $this->runCommand(
            ['command' => 'hosts:add', 'name' => 'nas', '--provider' => 'p1', '--zone' => 'example.com', '--no-interaction' => true],
            '',
            32,
            $path,
        );

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('--force', self::unwrap($result->stdout));
        self::assertSame($yaml, file_get_contents($path));
    }

    /**
     * A workspace holding a configuration with one provider and one host.
     */
    private function workspace(): string
    {
        $workspace = $this->tempDirectory();

        ConfigFile::write($workspace . '/ddns.yaml', [
            'providers' => ['p1' => ['driver' => 'digitalocean', 'token' => 'provider-secret-value']],
            'hosts' => ['home' => [
                'provider' => 'p1',
                'zone' => 'example.com',
                'name' => 'home',
                'types' => ['A'],
                'ttl' => 60,
                'token' => '${HOME_TOKEN}',
            ]],
        ]);

        // The placeholder has to resolve, or the configuration will not load.
        // Set directly rather than through .env, which the process has already
        // read by the time a test runs.
        $_ENV['HOME_TOKEN'] = 'home-token-0123456789';

        return $workspace;
    }

    /**
     * Make the secrets a command just wrote visible to the next one, the way
     * restarting the application would.
     */
    private function loadWrittenSecrets(): void
    {
        foreach (EnvFileWriter::read($this->envPath()) as $name => $value) {
            $_ENV[$name] = $value;
            $this->seeded[] = $name;
        }
    }

    /**
     * The scratch file the harness points the commands at, so the project's
     * own .env is never touched.
     */
    private function envPath(): string
    {
        self::assertIsString($this->envFile, 'No command has run yet, so no env file has been created.');

        return $this->envFile;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function add(string $workspace, array $options): ConsoleResult
    {
        return $this->runCommand(['command' => 'hosts:add'] + $options, '', 32, $workspace . '/ddns.yaml');
    }

    private function remove(string $workspace, string $name): ConsoleResult
    {
        return $this->runCommand(
            ['command' => 'hosts:remove', 'name' => $name, '--force' => true],
            '',
            32,
            $workspace . '/ddns.yaml',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $stdout): array
    {
        $decoded = json_decode($stdout, true);

        self::assertIsArray($decoded, sprintf("stdout was not JSON:\n%s", $stdout));

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function at(array $payload, string $path): mixed
    {
        $current = $payload;

        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($current, sprintf('"%s" is not traversable at "%s".', $path, $segment));
            self::assertArrayHasKey($segment, $current, sprintf('No "%s".', $path));

            $current = $current[$segment] ?? null;
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function atArray(array $payload, string $path): array
    {
        $value = $this->at($payload, $path);

        self::assertIsArray($value);

        return $value;
    }

    private static function unwrap(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
