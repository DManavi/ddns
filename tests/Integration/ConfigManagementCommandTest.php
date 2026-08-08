<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Bootstrap;
use Ddns\Config\ConfigFile;
use Ddns\Config\Exception\ConfigurationError;
use Ddns\Console\ConsoleApplicationFactory;
use Ddns\Tests\Support\ConsoleResult;
use Ddns\Tests\Support\SplitOutput;
use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `config:*` management commands.
 *
 * Two themes. Every one of them has to fail usefully when there is no
 * configuration yet - that is the state a new user is in, and an exception
 * trace is a bad first impression. And `config:set` has to be safe to run
 * against a file someone cares about, since it rewrites the whole thing.
 */
#[CoversNothing]
final class ConfigManagementCommandTest extends ConsoleTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function managementCommands(): iterable
    {
        yield 'config:show' => ['config:show', []];
        yield 'config:show --raw' => ['config:show', ['--raw' => true]];
        yield 'config:get' => ['config:get', ['key' => 'server.default_ttl']];
        yield 'config:set' => ['config:set', ['key' => 'server.default_ttl', 'value' => '600']];
        yield 'hosts:list' => ['hosts:list', []];
        yield 'update' => ['update', ['--all' => true]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('managementCommands')]
    public function a_missing_configuration_names_the_wizard(string $command, array $arguments): void
    {
        $missing = $this->tempDirectory() . '/ddns.yaml';

        $result = $this->runCommand(['command' => $command] + $arguments, '', 32, $missing);

        $output = self::unwrap($result->stdout . $result->stderr);

        self::assertSame(2, $result->exitCode, $output);
        self::assertStringContainsString('does not exist', $output);
        self::assertStringContainsString('ddns config:init', $output);
        // Rendered as an ordinary failure, not as an unhandled exception.
        self::assertStringNotContainsString('[ERROR] In ', $output);
    }

    #[Test]
    public function config_path_still_answers_when_the_file_is_missing(): void
    {
        // The one command that must work without a configuration: it is how
        // you find out where to create one.
        $missing = $this->tempDirectory() . '/ddns.yaml';

        $result = $this->runCommand(['command' => 'config:path', '--json' => true], '', 32, $missing);

        $payload = $this->decode($result->stdout);

        self::assertSame(0, $result->exitCode);
        self::assertSame($missing, $this->at($payload, 'path'));
        self::assertFalse($this->at($payload, 'exists'));
    }

    /**
     * The same promise, but reached the way a real invocation reaches it.
     *
     * The test above names a file that does not exist, which binds `config.path`
     * to a string and never runs discovery at all - so it passed throughout a
     * period when `ddns config:path` on a project with no configuration exited
     * 2 and printed nothing for `$EDITOR` to open. Here the binding is the
     * factory `Bootstrap::container()` installs, so resolving it fails exactly
     * as it does in production.
     */
    #[Test]
    public function config_path_answers_when_discovery_itself_finds_nothing(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require Bootstrap::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            'config.path' => \DI\factory(static function (): string {
                throw ConfigurationError::notFound(['/nowhere/config/ddns.yaml']);
            }),
            LoggerInterface::class => new NullLogger(),
        ]);

        $application = ConsoleApplicationFactory::create($builder->build());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new SplitOutput(OutputInterface::VERBOSITY_NORMAL);
        $exitCode = $application->run(new ArrayInput([
            'command' => 'config:path',
            '--no-interaction' => true,
        ]), $output);

        self::assertSame(0, $exitCode, $output->stdout() . $output->stderr());
        self::assertSame(Bootstrap::intendedConfigPath(), trim($output->stdout()));
    }

    #[Test]
    public function config_path_prints_the_bare_path_so_it_composes(): void
    {
        $result = $this->runCommand(['command' => 'config:path']);

        self::assertSame(0, $result->exitCode);
        // Nothing but the path, so `$EDITOR $(ddns config:path)` works.
        self::assertFileExists(trim($result->stdout));
    }

    #[Test]
    public function config_path_reports_whether_the_file_is_usable(): void
    {
        $result = $this->runCommand(['command' => 'config:path', '--json' => true]);

        $payload = $this->decode($result->stdout);

        self::assertTrue($this->at($payload, 'exists'));
        self::assertTrue($this->at($payload, 'readable'));
    }

    /**
     * A configuration written by `config:init --sample` says it is not a
     * production one. `config:set` rewrites the whole file, so without this it
     * emitted the neutral header instead - and did so silently, because a
     * generated header is deliberately not counted as a comment worth
     * confirming the loss of. The warning simply disappeared on the first edit.
     */
    #[Test]
    public function rewriting_a_sample_configuration_keeps_its_warning(): void
    {
        $workspace = $this->tempDirectory();
        $path = $workspace . '/ddns.yaml';

        ConfigFile::write($path, [
            'server' => ['default_ttl' => 60],
            'providers' => ['dev' => ['driver' => 'digitalocean', 'token' => 'sample-token-0123456789']],
            'hosts' => ['home' => [
                'provider' => 'dev',
                'zone' => 'example.com',
                'name' => 'home',
                'types' => ['A'],
                'ttl' => 60,
                'token' => 'host-token-0123456789abcdef',
            ]],
        ], ConfigFile::SAMPLE_HEADER);

        $result = $this->runCommand([
            'command' => 'config:set',
            'key' => 'server.default_ttl',
            'value' => '120',
            '--no-interaction' => true,
        ], '', 32, $path);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        $after = (string) file_get_contents($path);

        self::assertStringContainsString('NOT A PRODUCTION CONFIGURATION', $after);
        self::assertStringStartsWith(ConfigFile::SAMPLE_HEADER, $after);
        self::assertSame(120, $this->at(ConfigFile::read($path), 'server.default_ttl'));
    }

    #[Test]
    public function rewriting_an_ordinary_configuration_keeps_the_ordinary_header(): void
    {
        $workspace = $this->tempDirectory();
        $path = $workspace . '/ddns.yaml';

        ConfigFile::write($path, [
            'server' => ['default_ttl' => 60],
            'providers' => ['p1' => ['driver' => 'digitalocean', 'token' => 'provider-secret']],
            'hosts' => ['home' => [
                'provider' => 'p1',
                'zone' => 'example.com',
                'name' => 'home',
                'types' => ['A'],
                'ttl' => 60,
                'token' => 'host-token-0123456789abcdef',
            ]],
        ]);

        $result = $this->runCommand([
            'command' => 'config:set',
            'key' => 'server.default_ttl',
            'value' => '120',
            '--no-interaction' => true,
        ], '', 32, $path);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        $after = (string) file_get_contents($path);

        self::assertStringStartsWith(ConfigFile::HEADER, $after);
        self::assertStringNotContainsString('NOT A PRODUCTION CONFIGURATION', $after);
    }

    #[Test]
    public function config_show_masks_secrets(): void
    {
        $result = $this->runCommand(['command' => 'config:show', '--json' => true]);

        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString('host-token-0123456789abcdef', $result->stdout);
        self::assertStringNotContainsString('provider-secret', $result->stdout);
    }

    #[Test]
    public function config_show_raw_keeps_placeholders_but_masks_literals(): void
    {
        $workspace = $this->tempDirectory();
        ConfigFile::write($workspace . '/ddns.yaml', [
            'providers' => ['p1' => ['driver' => 'digitalocean', 'token' => '${DO_TOKEN}']],
            'hosts' => ['home' => [
                'provider' => 'p1',
                'zone' => 'example.com',
                'token' => 'a-literal-host-secret',
            ]],
        ]);

        $result = $this->runCommand(
            ['command' => 'config:show', '--raw' => true, '--json' => true],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        $payload = $this->decode($result->stdout);

        // A placeholder is a reference, not a secret: masking it would hide the
        // one thing the reader needs.
        self::assertSame('${DO_TOKEN}', $this->at($payload, 'config.providers.p1.token'));
        self::assertSame('****cret', $this->at($payload, 'config.hosts.home.token'));
    }

    #[Test]
    public function config_get_returns_the_value_as_written(): void
    {
        $result = $this->runCommand(['command' => 'config:get', 'key' => 'hosts.home.ttl']);

        self::assertSame(0, $result->exitCode);
        self::assertSame('60', trim($result->stdout));
    }

    #[Test]
    public function config_get_does_not_resolve_a_placeholder_to_its_secret(): void
    {
        $workspace = $this->tempDirectory();
        ConfigFile::write($workspace . '/ddns.yaml', [
            'providers' => ['p1' => ['driver' => 'digitalocean', 'token' => '${DO_TOKEN}']],
            'hosts' => ['home' => ['provider' => 'p1', 'zone' => 'example.com', 'token' => '${HOME_TOKEN}']],
        ]);

        putenv('DO_TOKEN=the-real-secret');

        $result = $this->runCommand(
            ['command' => 'config:get', 'key' => 'providers.p1.token'],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        putenv('DO_TOKEN');

        self::assertSame('${DO_TOKEN}', trim($result->stdout));
    }

    #[Test]
    public function config_get_distinguishes_absent_from_empty(): void
    {
        $result = $this->runCommand(['command' => 'config:get', 'key' => 'hosts.home.nothing']);

        // Exit 1, so a script can tell "not set" from "set to an empty value".
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('is not set', $result->stdout);
        // And it says what is available at that level rather than just failing.
        self::assertStringContainsString('hosts.home.ttl', self::unwrap($result->stdout));
    }

    #[Test]
    public function config_get_json_reports_absence_without_failing_to_parse(): void
    {
        $result = $this->runCommand(['command' => 'config:get', 'key' => 'nope', '--json' => true]);

        $payload = $this->decode($result->stdout);

        self::assertSame(1, $result->exitCode);
        self::assertFalse($this->at($payload, 'found'));
        self::assertNull($this->at($payload, 'value'));
    }

    #[Test]
    public function config_set_parses_values_as_yaml_so_types_survive(): void
    {
        $workspace = $this->writableConfig();

        $this->set($workspace, 'server.default_ttl', '600');
        $this->set($workspace, 'server.allow_private_ips', 'true');
        $this->set($workspace, 'hosts.home.types', '[A, AAAA]');

        $raw = ConfigFile::read($workspace . '/ddns.yaml');

        // Not the strings "600" and "true": the loader would reject one and
        // misread the other.
        self::assertSame(600, $this->at($raw, 'server.default_ttl'));
        self::assertTrue($this->at($raw, 'server.allow_private_ips'));
        self::assertSame(['A', 'AAAA'], $this->at($raw, 'hosts.home.types'));
    }

    #[Test]
    public function config_set_validates_before_writing(): void
    {
        $workspace = $this->writableConfig();
        $before = (string) file_get_contents($workspace . '/ddns.yaml');

        $result = $this->set($workspace, 'hosts.home.ttl', '99999999');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Nothing was written', $result->stdout);
        self::assertSame($before, file_get_contents($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function config_set_refuses_a_reference_to_a_provider_that_does_not_exist(): void
    {
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.provider', 'ghost');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('not defined under "providers"', self::unwrap($result->stdout));
    }

    #[Test]
    public function config_set_refuses_to_write_through_a_scalar(): void
    {
        // hosts.home.ttl is a number, so hosts.home.ttl.nested would replace it
        // with a mapping and silently discard the value.
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.ttl.nested', '1');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('is not a mapping', self::unwrap($result->stdout));
    }

    #[Test]
    public function config_set_rejects_a_value_that_is_not_yaml(): void
    {
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.name', '[unclosed');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('could not be parsed as YAML', self::unwrap($result->stdout));
    }

    #[Test]
    public function the_zone_apex_can_be_set_despite_being_reserved_in_yaml(): void
    {
        // "@" cannot begin a plain YAML scalar, and it is also how the apex is
        // written - so strict parsing would reject the most obvious thing
        // anyone would type.
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.name', '@');

        self::assertSame(0, $result->exitCode, self::unwrap($result->stdout));
        self::assertSame('@', $this->at(ConfigFile::read($workspace . '/ddns.yaml'), 'hosts.home.name'));
    }

    #[Test]
    public function config_set_is_a_no_op_when_the_value_already_matches(): void
    {
        $workspace = $this->writableConfig();
        $before = filemtime($workspace . '/ddns.yaml');

        $result = $this->set($workspace, 'hosts.home.ttl', '60');

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('is already 60', self::unwrap($result->stdout));
        self::assertSame($before, filemtime($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function config_set_refuses_to_discard_comments_without_permission(): void
    {
        // Someone who started from the annotated example would not expect a TTL
        // change to strip every explanation out of the file.
        $workspace = $this->tempDirectory();
        $yaml = "# a comment worth keeping\n" . $this->minimalConfig();
        file_put_contents($workspace . '/ddns.yaml', $yaml);

        $result = $this->runCommand(
            ['command' => 'config:set', 'key' => 'hosts.home.ttl', 'value' => '600', '--no-interaction' => true],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('--force', self::unwrap($result->stdout));
        self::assertSame($yaml, file_get_contents($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function force_allows_the_rewrite(): void
    {
        $workspace = $this->tempDirectory();
        file_put_contents($workspace . '/ddns.yaml', "# a comment worth keeping\n" . $this->minimalConfig());

        $result = $this->runCommand(
            ['command' => 'config:set', 'key' => 'hosts.home.ttl', 'value' => '600', '--force' => true],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame(600, $this->at(ConfigFile::read($workspace . '/ddns.yaml'), 'hosts.home.ttl'));
    }

    #[Test]
    public function a_file_the_wizard_wrote_needs_no_confirmation(): void
    {
        // Its header is re-emitted on write, so warning about it would train
        // people to ignore the warning that matters.
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.ttl', '600');

        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString('discard', self::unwrap($result->stdout));
    }

    #[Test]
    public function config_set_warns_when_a_secret_is_written_in_plain_text(): void
    {
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.token', 'a-literal-secret-value');

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('plain text', self::unwrap($result->stdout));
    }

    #[Test]
    public function config_set_stays_quiet_for_a_placeholder(): void
    {
        $workspace = $this->writableConfig();
        putenv('SOME_OTHER_TOKEN=a-token-long-enough');

        try {
            $result = $this->set($workspace, 'hosts.home.token', '${SOME_OTHER_TOKEN}');
        } finally {
            putenv('SOME_OTHER_TOKEN');
        }

        self::assertSame(0, $result->exitCode, self::unwrap($result->stdout));
        self::assertStringNotContainsString('plain text', self::unwrap($result->stdout));
    }

    #[Test]
    public function pointing_at_an_unset_variable_says_which_one(): void
    {
        // Enforces the right order - put the secret in .env, then point at it -
        // and names the variable rather than reporting a vague failure.
        $workspace = $this->writableConfig();

        $result = $this->set($workspace, 'hosts.home.token', '${NEVER_SET_TOKEN}');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('NEVER_SET_TOKEN', self::unwrap($result->stdout));
        self::assertStringContainsString('which is not set', self::unwrap($result->stdout));
    }

    #[Test]
    public function config_set_keeps_the_sections_in_a_stable_order(): void
    {
        // Otherwise a section added by config:set lands wherever it was
        // appended, and two identical configurations diff differently.
        $workspace = $this->writableConfig();

        $this->set($workspace, 'server.default_ttl', '600');

        self::assertSame(
            ['server', 'providers', 'hosts'],
            array_keys(ConfigFile::read($workspace . '/ddns.yaml')),
        );
    }

    #[Test]
    public function what_config_set_writes_is_what_config_get_reads_back(): void
    {
        $workspace = $this->writableConfig();

        $this->set($workspace, 'hosts.home.types', '[A, AAAA]');

        $result = $this->runCommand(
            ['command' => 'config:get', 'key' => 'hosts.home.types'],
            '',
            32,
            $workspace . '/ddns.yaml',
        );

        // The output of get is accepted by set, so the pair composes.
        self::assertSame("- A\n- AAAA", trim($result->stdout));
    }

    private function writableConfig(): string
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
                'token' => 'host-token-0123456789abcdef',
            ]],
        ]);

        return $workspace;
    }

    private function minimalConfig(): string
    {
        return <<<'YAML'
            providers:
              p1:
                driver: digitalocean
                token: provider-secret-value
            hosts:
              home:
                provider: p1
                zone: example.com
                name: home
                types: [A]
                ttl: 60
                token: host-token-0123456789abcdef
            YAML;
    }

    private function set(string $workspace, string $key, string $value): ConsoleResult
    {
        return $this->runCommand(
            ['command' => 'config:set', 'key' => $key, 'value' => $value],
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
            self::assertArrayHasKey($segment, $current, sprintf('No "%s" in the payload.', $path));

            $current = $current[$segment] ?? null;
        }

        return $current;
    }

    private static function unwrap(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
