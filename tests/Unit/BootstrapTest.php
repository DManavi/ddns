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

    private ?string $savedEnv = null;

    private ?string $savedServer = null;

    protected function setUp(): void
    {
        // DDNS_CONFIG is process-wide, so it is put back afterwards rather
        // than left set for whatever runs next.
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
    }

    private function clear(): void
    {
        putenv('DDNS_CONFIG');
        unset($_ENV['DDNS_CONFIG'], $_SERVER['DDNS_CONFIG']);
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
        $path = Bootstrap::projectRoot() . '/.vscode/launch.json';

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
        }
    }

    #[Test]
    public function surrounding_whitespace_is_trimmed(): void
    {
        $_ENV['DDNS_CONFIG'] = "  /tmp/padded.yaml\t";

        self::assertSame('/tmp/padded.yaml', Bootstrap::configPathFromEnvironment());
    }
}
