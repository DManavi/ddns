<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Config;

use Ddns\Config\ConfigFile;
use Ddns\Config\Exception\ConfigurationError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigFile::class)]
final class ConfigFileTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ddns-cf-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) scandir($this->directory) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                unlink($this->directory . '/' . $entry);
            }
        }

        rmdir($this->directory);
    }

    #[Test]
    public function it_round_trips_a_configuration(): void
    {
        $config = [
            'server' => ['default_ttl' => 300],
            'providers' => ['p1' => ['driver' => 'digitalocean', 'token' => '${DO_TOKEN}']],
            'hosts' => ['home' => ['provider' => 'p1', 'zone' => 'example.com', 'types' => ['A', 'AAAA']]],
        ];

        ConfigFile::write($this->path(), $config);

        self::assertSame($config, ConfigFile::read($this->path()));
    }

    #[Test]
    public function reading_does_not_expand_placeholders(): void
    {
        // The property that makes save-after-read safe: a round trip must not
        // turn ${DO_TOKEN} into the secret it resolves to, which would write
        // credentials into a file meant to be committable.
        putenv('DO_TOKEN=the-real-secret');

        ConfigFile::write($this->path(), ['providers' => ['p1' => ['token' => '${DO_TOKEN}']]]);
        ConfigFile::write($this->path(), ConfigFile::read($this->path()));

        $contents = (string) file_get_contents($this->path());

        self::assertStringContainsString('${DO_TOKEN}', $contents);
        self::assertStringNotContainsString('the-real-secret', $contents);

        putenv('DO_TOKEN');
    }

    #[Test]
    public function it_writes_owner_only(): void
    {
        ConfigFile::write($this->path(), ['hosts' => []]);

        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($this->path())), -4));
    }

    #[Test]
    public function it_keeps_nested_structures_expanded(): void
    {
        // Collapsing hosts.<name>.types to inline JSON-ish notation would still
        // parse, but the file is meant to be edited by hand afterwards.
        ConfigFile::write($this->path(), [
            'hosts' => ['home' => ['types' => ['A', 'AAAA']]],
        ]);

        self::assertStringContainsString("      - A\n", (string) file_get_contents($this->path()));
    }

    #[Test]
    public function it_carries_a_header_explaining_where_the_file_came_from(): void
    {
        ConfigFile::write($this->path(), ['hosts' => []]);

        self::assertStringStartsWith('# ddns configuration', (string) file_get_contents($this->path()));
    }

    /**
     * A header this class emits is not a comment somebody wrote, because
     * rewriting emits it again. Warning about it would train people to click
     * through the warning that does mean something - so every known header has
     * to be recognised, not just the default one.
     */
    #[Test]
    public function a_header_it_wrote_is_not_a_comment_it_would_lose(): void
    {
        foreach ([ConfigFile::HEADER, ConfigFile::SAMPLE_HEADER] as $header) {
            ConfigFile::write($this->path(), ['hosts' => []], $header);

            self::assertFalse(
                ConfigFile::hasComments((string) file_get_contents($this->path())),
                'A generated header was mistaken for the user\'s own comments.',
            );
        }
    }

    #[Test]
    public function a_comment_somebody_wrote_is_one_it_would_lose(): void
    {
        file_put_contents($this->path(), ConfigFile::SAMPLE_HEADER . "\n\n# mine\nhosts: {}\n");

        self::assertTrue(ConfigFile::hasComments((string) file_get_contents($this->path())));
    }

    #[Test]
    public function an_existing_file_is_replaced_atomically(): void
    {
        file_put_contents($this->path(), "old\n");

        ConfigFile::write($this->path(), ['server' => ['default_ttl' => 60]]);

        self::assertSame(['server' => ['default_ttl' => 60]], ConfigFile::read($this->path()));
        // The temporary file must not be left behind next to the real one.
        self::assertCount(1, array_filter(
            (array) scandir($this->directory),
            static fn (mixed $e): bool => is_string($e) && $e !== '.' && $e !== '..',
        ));
    }

    #[Test]
    public function reading_a_missing_file_is_an_error(): void
    {
        $this->expectException(ConfigurationError::class);

        ConfigFile::read($this->directory . '/nope.yaml');
    }

    #[Test]
    public function reading_malformed_yaml_names_the_file(): void
    {
        file_put_contents($this->path(), "hosts:\n  - [unclosed\n");

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessageMatches('/could not be parsed/');

        ConfigFile::read($this->path());
    }

    #[Test]
    public function an_empty_file_reads_as_an_empty_configuration(): void
    {
        file_put_contents($this->path(), '');

        self::assertSame([], ConfigFile::read($this->path()));
    }

    #[Test]
    public function writing_into_a_missing_directory_is_an_error(): void
    {
        $this->expectException(ConfigurationError::class);

        ConfigFile::write($this->directory . '/nope/ddns.yaml', []);
    }

    private function path(): string
    {
        return $this->directory . '/ddns.yaml';
    }
}
