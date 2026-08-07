<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Bootstrap;
use Ddns\Config\ConfigField;
use Ddns\Config\ConfigFile;
use Ddns\Config\ConfigLoader;
use Ddns\Config\Configuration;
use Ddns\Config\EnvInterpolator;
use Ddns\Config\Environment;
use Ddns\Console\EnvFileWriter;
use Ddns\Provider\ProviderFactories;
use Ddns\Provider\ProviderFactory;
use Ddns\Tests\Support\ConsoleResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `config:init` wizard.
 *
 * Two promises are worth testing above all others: what it writes always
 * loads, and no credential ever reaches the configuration file. Everything
 * else the wizard does is a convenience; those two are what make it safe to
 * point a new user at.
 */
#[CoversNothing]
final class ConfigInitCommandTest extends ConsoleTestCase
{
    private const PROVIDER_TOKEN = 'dop_v1_0123456789abcdef';

    #[Test]
    public function it_writes_a_configuration_that_loads(): void
    {
        $workspace = $this->tempDirectory();

        $result = $this->wizard($workspace, [
            'digitalocean',
            'do-personal',
            self::PROVIDER_TOKEN,
            'example.com',
            'home',
            'home',
            'A',
            '60',
        ]);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        self::assertFileExists($workspace . '/ddns.yaml');

        // The guarantee: whatever it wrote survives a real load, placeholders
        // and all. Anything less and the wizard could hand someone a file that
        // config:validate immediately rejects.
        $configuration = $this->load($workspace);
        $host = $configuration->host('home');

        self::assertSame('home.example.com', $host->hostname()->fqdn());
        self::assertSame('do-personal', $host->providerName());
        self::assertSame(60, $host->ttl());
        self::assertSame(self::PROVIDER_TOKEN, $configuration->provider('do-personal')->token());
    }

    #[Test]
    public function no_secret_is_written_into_the_configuration_file(): void
    {
        $workspace = $this->tempDirectory();

        $this->wizard($workspace, [
            'digitalocean', 'do-personal', self::PROVIDER_TOKEN,
            'example.com', 'home', 'home', 'A', '60',
        ]);

        $yaml = (string) file_get_contents($workspace . '/ddns.yaml');

        self::assertStringNotContainsString(self::PROVIDER_TOKEN, $yaml);
        self::assertStringContainsString('${DO_PERSONAL_TOKEN}', $yaml);

        // The generated host token must not leak into the file either.
        self::assertStringNotContainsString($this->envValue($workspace, 'HOME_TOKEN'), $yaml);
    }

    #[Test]
    public function secrets_land_in_a_private_env_file(): void
    {
        $workspace = $this->tempDirectory();

        $this->wizard($workspace, [
            'digitalocean', 'do-personal', self::PROVIDER_TOKEN,
            'example.com', 'home', 'home', 'A', '60',
        ]);

        self::assertSame(self::PROVIDER_TOKEN, $this->envValue($workspace, 'DO_PERSONAL_TOKEN'));
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($workspace . '/.env')), -4));
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($workspace . '/ddns.yaml')), -4));
    }

    #[Test]
    public function the_host_token_is_generated_and_unpredictable(): void
    {
        $first = $this->tempDirectory();
        $second = $this->tempDirectory();
        $answers = ['digitalocean', 'p', self::PROVIDER_TOKEN, 'example.com', 'home', 'home', 'A', '60'];

        $this->wizard($first, $answers);
        $this->wizard($second, $answers);

        $a = $this->envValue($first, 'HOME_TOKEN');
        $b = $this->envValue($second, 'HOME_TOKEN');

        self::assertNotSame($a, $b, 'Two runs produced the same host token.');
        // Comfortably above the loader's 12-character floor.
        self::assertGreaterThanOrEqual(32, strlen($a));
    }

    #[Test]
    public function a_provider_and_a_host_sharing_a_name_get_separate_variables(): void
    {
        // Both reduce to HOME_TOKEN, and one variable holding two secrets would
        // silently give the provider the host's token.
        $workspace = $this->tempDirectory();

        $this->wizard($workspace, [
            'digitalocean', 'home', self::PROVIDER_TOKEN,
            'example.com', 'home', 'home', 'A', '60',
        ]);

        self::assertCount(2, EnvFileWriter::read($workspace . '/.env'));
        self::assertSame(self::PROVIDER_TOKEN, $this->envValue($workspace, 'HOME_TOKEN'));

        // And the loader must still resolve both to the right values.
        $configuration = $this->load($workspace);
        self::assertSame(self::PROVIDER_TOKEN, $configuration->provider('home')->token());
        self::assertSame($this->envValue($workspace, 'HOME_TOKEN_2'), $configuration->host('home')->token());
    }

    /**
     * The wizard has to write where the application reads, or it reports
     * success and nothing changes. `config/` is first in the search order and
     * is the path the container expects mounted, so both agree.
     */
    #[Test]
    public function the_default_target_is_the_first_place_the_application_looks(): void
    {
        self::assertSame('config/ddns.yaml', Bootstrap::DEFAULT_CONFIG_PATH);
        self::assertSame(
            Bootstrap::projectRoot() . '/' . Bootstrap::DEFAULT_CONFIG_PATH,
            Bootstrap::configCandidates()[0] ?? null,
        );
    }

    #[Test]
    public function it_warns_when_the_file_it_wrote_would_be_ignored(): void
    {
        // Writing somewhere the server reads second is the most confusing
        // outcome available: everything reports success and nothing changes.
        $workspace = $this->tempDirectory();

        $result = $this->wizard($workspace, [
            'digitalocean', 'do-personal', self::PROVIDER_TOKEN,
            'example.com', 'home', 'home', 'A', '60',
        ]);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        // The workspace is not on the search path at all, so the file it wrote
        // is shadowed by whatever the project itself has.
        $shadowed = str_contains(self::unwrap($result->stdout), 'read in preference to what was just written');

        self::assertSame(
            $shadowed,
            array_filter(Bootstrap::configCandidates(), 'is_file') !== [],
            'The warning must appear exactly when another configuration would win.',
        );
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_file(): void
    {
        $workspace = $this->tempDirectory();
        file_put_contents($workspace . '/ddns.yaml', "# hand written\n");

        $result = $this->wizard($workspace, ['digitalocean']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('already exists', $result->stdout);
        self::assertSame("# hand written\n", file_get_contents($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function force_replaces_an_existing_file(): void
    {
        $workspace = $this->tempDirectory();
        file_put_contents($workspace . '/ddns.yaml', "# hand written\n");

        $result = $this->wizard($workspace, [
            'digitalocean', 'do-personal', self::PROVIDER_TOKEN,
            'example.com', 'home', 'home', 'A', '60',
        ], ['--force' => true]);

        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString('hand written', (string) file_get_contents($workspace . '/ddns.yaml'));
    }

    #[Test]
    public function it_refuses_to_run_without_a_terminal(): void
    {
        // Silently choosing defaults for a file full of credentials would be
        // worse than refusing.
        $workspace = $this->tempDirectory();

        $result = $this->runCommand([
            'command' => 'config:init',
            '--config' => $workspace . '/ddns.yaml',
            '--env' => $workspace . '/.env',
            '--no-interaction' => true,
        ]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('interactive', $result->stdout);
        self::assertFileDoesNotExist($workspace . '/ddns.yaml');
    }

    #[Test]
    public function driver_specific_fields_are_asked_for(): void
    {
        $workspace = $this->tempDirectory();

        $result = $this->wizard($workspace, [
            'azuredns',
            'az-main',
            'subscription-1234',
            'my-resource-group',
            'no',                 // skip the optional service-principal fields
            'example.com',
            'www',
            'www',
            'A',
            '300',
        ]);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        $provider = $this->provider($workspace, 'az-main');

        self::assertSame('azuredns', $provider['driver'] ?? null);
        self::assertSame('subscription-1234', $provider['subscription_id'] ?? null);
        self::assertSame('my-resource-group', $provider['resource_group'] ?? null);
        // Declined, so absent rather than empty - which is what selects managed
        // identity authentication.
        self::assertArrayNotHasKey('client_secret', $provider);
        self::assertSame('azuredns', $this->load($workspace)->provider('az-main')->driver());
    }

    #[Test]
    public function a_driver_needing_nothing_produces_a_provider_with_only_a_driver(): void
    {
        // Route53's recommended setup is exactly this: no credentials in the
        // file, everything from the AWS chain.
        $workspace = $this->tempDirectory();

        $result = $this->wizard($workspace, [
            'route53', 'aws-main', 'no',
            'example.net', '@', 'apex', 'A,AAAA', '300',
        ]);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        self::assertSame(['driver' => 'route53'], $this->provider($workspace, 'aws-main'));
        self::assertSame('example.net', $this->load($workspace)->host('apex')->hostname()->fqdn());
    }

    #[Test]
    public function a_private_zone_offers_to_allow_private_addresses(): void
    {
        $workspace = $this->tempDirectory();

        $this->wizard($workspace, [
            'azureprivatedns', 'az-internal', 'sub-1', 'rg-1', 'no',
            'internal.example.com', 'db', 'db', 'A', '300',
            'yes',
        ]);

        // Without this a private zone would refuse every address it exists to
        // hold.
        self::assertTrue($this->load($workspace)->server()->allowPrivateIps());
    }

    #[Test]
    public function an_existing_env_variable_is_kept_unless_replacement_is_confirmed(): void
    {
        $workspace = $this->tempDirectory();
        file_put_contents($workspace . '/.env', "# keep me\nDO_PERSONAL_TOKEN=the-original-value\n");

        $result = $this->wizard($workspace, [
            'digitalocean', 'do-personal', 'a-brand-new-token',
            'example.com', 'home', 'home', 'A', '60',
            'no',
        ]);

        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        self::assertSame('the-original-value', $this->envValue($workspace, 'DO_PERSONAL_TOKEN'));
        self::assertStringContainsString('# keep me', (string) file_get_contents($workspace . '/.env'));
    }

    #[Test]
    public function an_existing_env_variable_is_replaced_when_confirmed(): void
    {
        $workspace = $this->tempDirectory();
        file_put_contents($workspace . '/.env', "# keep me\nDO_PERSONAL_TOKEN=the-original-value\nOTHER=untouched\n");

        $this->wizard($workspace, [
            'digitalocean', 'do-personal', 'a-brand-new-token',
            'example.com', 'home', 'home', 'A', '60',
            'yes',
        ]);

        self::assertSame('a-brand-new-token', $this->envValue($workspace, 'DO_PERSONAL_TOKEN'));
        self::assertSame('untouched', $this->envValue($workspace, 'OTHER'));
        self::assertStringContainsString('# keep me', (string) file_get_contents($workspace . '/.env'));
    }

    #[Test]
    public function it_gives_up_rather_than_looping_when_the_answers_run_out(): void
    {
        // A closed stream answers every question with an empty string, so
        // without a bound a required field would re-ask forever.
        $workspace = $this->tempDirectory();

        $result = $this->wizard($workspace, ['digitalocean', 'do-personal']);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('No value was given', $result->stdout);
        self::assertFileDoesNotExist($workspace . '/ddns.yaml');
        self::assertFileDoesNotExist($workspace . '/.env');
    }

    #[Test]
    public function it_re_asks_when_a_hostname_does_not_belong_to_the_zone(): void
    {
        $workspace = $this->tempDirectory();

        $result = $this->wizard($workspace, [
            'digitalocean', 'do-personal', self::PROVIDER_TOKEN,
            'example.com',
            'home.example.org',   // rejected: a different zone
            'home',               // accepted
            'home', 'A', '60',
        ]);

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('does not belong to zone', self::unwrap($result->stdout));
        self::assertSame('home.example.com', $this->load($workspace)->host('home')->hostname()->fqdn());
    }

    #[Test]
    public function every_driver_describes_the_fields_the_loader_insists_on(): void
    {
        // The wizard asks only what the factories declare, so a field that is
        // required by the loader but missing here would produce a file the
        // loader then rejects. This is the drift that would otherwise appear
        // only when someone ran the wizard for a newly added driver.
        foreach ($this->factories() as $factory) {
            $fields = $factory->configFields();
            $required = array_map(
                static fn (ConfigField $f): string => $f->key,
                array_values(array_filter($fields, static fn (ConfigField $f): bool => $f->required)),
            );

            foreach ($factory->requiredOptions() as $option) {
                self::assertContains($option, $required, sprintf(
                    '%s requires "%s" but config:init never asks for it.',
                    $factory->driver(),
                    $option,
                ));
            }

            if ($factory->requiresToken()) {
                self::assertContains('token', $required, sprintf(
                    '%s requires a token but config:init never asks for one.',
                    $factory->driver(),
                ));
            }

            $keys = array_map(static fn (ConfigField $f): string => $f->key, $fields);
            self::assertSame(array_unique($keys), $keys, sprintf('%s declares a duplicate field.', $factory->driver()));
        }
    }

    private function envValue(string $workspace, string $name): string
    {
        $values = EnvFileWriter::read($workspace . '/.env');

        self::assertArrayHasKey($name, $values, sprintf('%s is not defined in .env.', $name));

        return $values[$name] ?? '';
    }

    /**
     * The provider block as it was actually written to the file.
     *
     * @return array<string, mixed>
     */
    private function provider(string $workspace, string $name): array
    {
        $raw = ConfigFile::read($workspace . '/ddns.yaml');

        self::assertArrayHasKey('providers', $raw);

        $providers = $raw['providers'] ?? null;

        self::assertIsArray($providers);
        self::assertArrayHasKey($name, $providers);

        $provider = $providers[$name] ?? null;

        self::assertIsArray($provider);

        /** @var array<string, mixed> $provider */
        return $provider;
    }

    /**
     * Collapse the console's line wrapping so a message can be matched as the
     * sentence it is, rather than as whatever the terminal width split it into.
     */
    private static function unwrap(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @param list<string>         $answers
     * @param array<string, mixed> $options
     */
    private function wizard(string $workspace, array $answers, array $options = []): ConsoleResult
    {
        return $this->runInteractive([
            'command' => 'config:init',
            '--config' => $workspace . '/ddns.yaml',
            '--env' => $workspace . '/.env',
        ] + $options, $answers);
    }

    /**
     * Load the generated file the way the application would, with the secrets
     * the wizard put in its `.env`.
     */
    private function load(string $workspace): Configuration
    {
        $loader = new ConfigLoader(
            new EnvInterpolator(new Environment(EnvFileWriter::read($workspace . '/.env'))),
            $this->factoriesCollection()->catalog(),
        );

        return $loader->load($workspace . '/ddns.yaml');
    }

    /**
     * @return list<ProviderFactory>
     */
    private function factories(): array
    {
        return $this->factoriesCollection()->all();
    }

    private function factoriesCollection(): ProviderFactories
    {
        $factories = $this->container()->get(ProviderFactories::class);

        self::assertInstanceOf(ProviderFactories::class, $factories);

        return $factories;
    }
}
