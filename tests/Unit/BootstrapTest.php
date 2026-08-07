<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit;

use Ddns\Bootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bootstrap::class)]
final class BootstrapTest extends TestCase
{
    private string|false $savedProcess = false;

    private string|false $savedFallback = false;

    private ?string $savedEnv = null;

    private ?string $savedServer = null;

    protected function setUp(): void
    {
        // DDNS_CONFIG is process-wide, so it is put back afterwards rather
        // than left set for whatever runs next.
        $this->savedFallback = getenv('DDNS_CONFIG_FALLBACK');
        $this->savedProcess = getenv('DDNS_CONFIG');
        $this->savedEnv = is_string($_ENV['DDNS_CONFIG'] ?? null) ? $_ENV['DDNS_CONFIG'] : null;
        $this->savedServer = is_string($_SERVER['DDNS_CONFIG'] ?? null) ? $_SERVER['DDNS_CONFIG'] : null;

        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();

        if ($this->savedEnv !== null) {
            $_ENV['DDNS_CONFIG'] = $this->savedEnv;
        }

        if ($this->savedServer !== null) {
            $_SERVER['DDNS_CONFIG'] = $this->savedServer;
        }

        if (is_string($this->savedProcess)) {
            putenv('DDNS_CONFIG=' . $this->savedProcess);
        }

        if (is_string($this->savedFallback)) {
            putenv('DDNS_CONFIG_FALLBACK=' . $this->savedFallback);
        }
    }

    private function clear(): void
    {
        putenv('DDNS_CONFIG');
        putenv('DDNS_CONFIG_FALLBACK');
        unset(
            $_ENV['DDNS_CONFIG'],
            $_SERVER['DDNS_CONFIG'],
            $_ENV['DDNS_CONFIG_FALLBACK'],
            $_SERVER['DDNS_CONFIG_FALLBACK'],
        );
    }

    #[Test]
    public function the_configuration_is_looked_for_in_the_config_directory_first(): void
    {
        // It has to match where config:init writes and where the image expects
        // the file mounted, or the wizard writes somewhere nothing reads.
        $candidates = Bootstrap::configCandidates();

        self::assertSame(Bootstrap::projectRoot() . '/config/ddns.yaml', $candidates[0] ?? null);
        self::assertSame('config/ddns.yaml', Bootstrap::DEFAULT_CONFIG_PATH);
    }

    #[Test]
    public function the_project_root_is_still_searched_for_older_installations(): void
    {
        self::assertContains(Bootstrap::projectRoot() . '/ddns.yaml', Bootstrap::configCandidates());
    }

    #[Test]
    public function it_reads_the_config_path_from_the_real_environment(): void
    {
        putenv('DDNS_CONFIG=/tmp/from-getenv.yaml');

        self::assertSame('/tmp/from-getenv.yaml', Bootstrap::configPathFromEnvironment());
    }

    /**
     * phpdotenv v5 loads `.env` into the superglobals without calling
     * `putenv()`, so a lookup that consulted only `getenv()` ignored a
     * `DDNS_CONFIG` written there - while every other variable in the same
     * file worked, because the config loader reads the superglobals.
     */
    #[Test]
    public function it_reads_the_config_path_set_only_in_the_superglobals(): void
    {
        $_ENV['DDNS_CONFIG'] = '/tmp/from-dotenv.yaml';

        self::assertSame('/tmp/from-dotenv.yaml', Bootstrap::configPathFromEnvironment());
    }

    #[Test]
    public function an_unset_or_blank_value_is_no_value(): void
    {
        self::assertNull(Bootstrap::configPathFromEnvironment());

        $_ENV['DDNS_CONFIG'] = '   ';

        self::assertNull(Bootstrap::configPathFromEnvironment());
    }

    /**
     * The editor profiles used to pin DDNS_CONFIG at the committed sample, so
     * pressing play served a different file - and a different host token -
     * from the one `config:init` had just written, with nothing to say so.
     * They now use the same discovery as everything else.
     */
    #[Test]
    public function the_editor_profiles_do_not_pin_a_configuration_file(): void
    {
        $directory = Bootstrap::projectRoot() . '/.vscode';

        // The runtime image ships the application, not the editor config, so
        // there is nothing to check when the suite runs from one. Keyed on the
        // directory rather than the file: if .vscode is there but the profiles
        // are not, that is a deletion worth failing on.
        if (!is_dir($directory)) {
            self::markTestSkipped('Not a source checkout, so there are no editor profiles to check.');
        }

        $path = $directory . '/launch.json';

        self::assertFileExists($path);

        $stripped = (string) preg_replace('#^\s*//.*$#m', '', (string) file_get_contents($path));
        $decoded = json_decode($stripped, true);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['configurations'] ?? null);

        foreach ($decoded['configurations'] as $configuration) {
            self::assertIsArray($configuration);

            $name = is_string($configuration['name'] ?? null) ? $configuration['name'] : '?';
            $env = $configuration['env'] ?? [];

            self::assertIsArray($env);

            // One profile exists precisely to run the sample, and says so.
            if (str_contains($name, 'sample')) {
                self::assertArrayHasKey('DDNS_CONFIG', $env, $name);

                continue;
            }

            self::assertArrayNotHasKey('DDNS_CONFIG', $env, sprintf(
                '"%s" pins a configuration file, so it would not use the one config:init writes.',
                $name,
            ));

            // Whatever a profile falls back to has to be there, or a fresh
            // clone gets an error on the first press of play.
            $fallback = $env['DDNS_CONFIG_FALLBACK'] ?? null;

            if (is_string($fallback)) {
                self::assertFileExists(
                    str_replace('${workspaceFolder}', Bootstrap::projectRoot(), $fallback),
                    sprintf('"%s" falls back to a file that is not in the repository.', $name),
                );
            }
        }
    }

    /**
     * The editor profiles set a fallback so a clone with no configuration runs
     * with no setup, which is what removing the pin had taken away. It applies
     * only when nothing real is found, so it steps aside the moment
     * `config:init` has written something.
     *
     * Uses a file of its own rather than the committed sample, so it tests the
     * mechanism wherever it runs - the images ship the application, not the
     * sample configuration.
     */
    #[Test]
    public function the_fallback_is_used_only_when_nothing_real_exists(): void
    {
        $fallback = tempnam(sys_get_temp_dir(), 'ddns-fallback-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($fallback, "hosts: {}\n");

        try {
            $_ENV['DDNS_CONFIG_FALLBACK'] = $fallback;

            $existing = array_values(array_filter(Bootstrap::configCandidates(), 'is_file'));

            if ($existing !== []) {
                // A real configuration is present, so it wins outright and the
                // fallback is not reported as being in use.
                self::assertSame($existing[0], Bootstrap::discoverConfigPath());
                self::assertFalse(Bootstrap::isFallbackConfig($existing[0]));

                return;
            }

            self::assertSame($fallback, Bootstrap::discoverConfigPath());
            self::assertTrue(Bootstrap::isFallbackConfig($fallback));
        } finally {
            unlink($fallback);
        }
    }

    #[Test]
    public function nothing_falls_back_unless_it_is_asked_for(): void
    {
        // Production sets no fallback. Starting up on a sample configuration -
        // whose host token is published in this repository - would be worse
        // than refusing to start.
        if (array_filter(Bootstrap::configCandidates(), 'is_file') !== []) {
            self::markTestSkipped('A real configuration is present, so there is nothing to fall back from.');
        }

        $this->expectException(\Ddns\Config\Exception\ConfigurationError::class);

        Bootstrap::discoverConfigPath();
    }

    #[Test]
    public function a_fallback_that_is_not_there_is_ignored(): void
    {
        // A stale path in the environment must not become a configuration
        // file the loader then fails to read: it is simply not a candidate.
        if (array_filter(Bootstrap::configCandidates(), 'is_file') !== []) {
            self::markTestSkipped('A real configuration is present, so the fallback is never reached.');
        }

        $_ENV['DDNS_CONFIG_FALLBACK'] = '/tmp/ddns-no-such-fallback.yaml';

        $this->expectException(\Ddns\Config\Exception\ConfigurationError::class);

        Bootstrap::discoverConfigPath();
    }

    #[Test]
    public function surrounding_whitespace_is_trimmed(): void
    {
        $_ENV['DDNS_CONFIG'] = "  /tmp/padded.yaml\t";

        self::assertSame('/tmp/padded.yaml', Bootstrap::configPathFromEnvironment());
    }
}
