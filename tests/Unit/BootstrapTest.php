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

    /**
     * One file, or none. The list used to run to four paths with a fifth
     * environment-driven fallback behind them, so "which file is this actually
     * reading?" had five possible answers and the server could quietly answer
     * from a sample nobody had chosen.
     */
    #[Test]
    public function exactly_one_path_is_searched(): void
    {
        self::assertSame(
            [Bootstrap::projectRoot() . '/config/ddns.yaml'],
            Bootstrap::configCandidates(),
        );
    }

    #[Test]
    public function the_configuration_is_looked_for_in_the_config_directory(): void
    {
        // It has to match where config:init writes and where the image expects
        // the file mounted, or the wizard writes somewhere nothing reads.
        self::assertSame('config/ddns.yaml', Bootstrap::DEFAULT_CONFIG_PATH);
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
     * No configuration, no application. Nothing stands in for the file: an
     * application answering from something nobody chose - once, a committed
     * sample whose host token is published in this repository - is worse than
     * one that refuses to start and names the path it wanted.
     */
    #[Test]
    public function nothing_stands_in_for_a_missing_configuration(): void
    {
        if (array_filter(Bootstrap::configCandidates(), 'is_file') !== []) {
            self::markTestSkipped('A real configuration is present, so there is nothing to be missing.');
        }

        // The variable that used to supply one, set and pointing at a readable
        // file, so this fails if the mechanism is ever reintroduced.
        $decoy = tempnam(sys_get_temp_dir(), 'ddns-decoy-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($decoy, "hosts: {}\n");
        $_ENV['DDNS_CONFIG_FALLBACK'] = $decoy;

        try {
            $this->expectException(\Ddns\Config\Exception\ConfigurationError::class);

            Bootstrap::discoverConfigPath();
        } finally {
            unset($_ENV['DDNS_CONFIG_FALLBACK']);
            unlink($decoy);
        }
    }

    /**
     * The editor profiles used to pin DDNS_CONFIG at a committed sample, so
     * pressing play served a different file - and a different host token -
     * from the one `config:init` had just written, with nothing to say so.
     * They now use the same discovery as everything else, with no exceptions:
     * the one profile that existed to run the sample went with the sample.
     */
    #[Test]
    public function no_editor_profile_pins_a_configuration_file(): void
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

            self::assertArrayNotHasKey('DDNS_CONFIG', $env, sprintf(
                '"%s" pins a configuration file, so it would not use the one config:init writes.',
                $name,
            ));

            self::assertArrayNotHasKey('DDNS_CONFIG_FALLBACK', $env, sprintf(
                '"%s" sets a fallback, which the application no longer consults.',
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
