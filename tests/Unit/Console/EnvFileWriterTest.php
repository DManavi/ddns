<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Console;

use Ddns\Console\EnvFileWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvFileWriter::class)]
final class EnvFileWriterTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ddns-env-') ?: throw new \RuntimeException('tempnam failed');
        unlink($this->path);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Read one variable, failing with the missing name rather than a notice.
     */
    private static function value(string $path, string $name): string
    {
        $values = EnvFileWriter::read($path);

        self::assertArrayHasKey($name, $values, sprintf('%s is not defined in the file.', $name));

        return $values[$name] ?? '';
    }

    #[Test]
    public function it_creates_the_file_when_it_does_not_exist(): void
    {
        $result = EnvFileWriter::apply($this->path, ['TOKEN' => 'abc123']);

        self::assertSame(['TOKEN'], $result['written']);
        self::assertSame('abc123', self::value($this->path, 'TOKEN'));
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($this->path)), -4));
    }

    #[Test]
    public function it_appends_without_disturbing_existing_content(): void
    {
        file_put_contents($this->path, "# a comment\nEXISTING=value\n");

        EnvFileWriter::apply($this->path, ['ADDED' => 'new']);

        $contents = (string) file_get_contents($this->path);

        self::assertStringContainsString('# a comment', $contents);
        self::assertStringContainsString('EXISTING=value', $contents);
        self::assertStringContainsString('ADDED=new', $contents);
    }

    #[Test]
    public function it_keeps_an_existing_value_unless_replacement_is_requested(): void
    {
        file_put_contents($this->path, "TOKEN=original\n");

        $result = EnvFileWriter::apply($this->path, ['TOKEN' => 'replacement']);

        self::assertSame(['TOKEN'], $result['kept']);
        self::assertSame('original', self::value($this->path, 'TOKEN'));
    }

    #[Test]
    public function it_replaces_in_place_when_asked(): void
    {
        file_put_contents($this->path, "# keep\nTOKEN=original\nOTHER=untouched\n");

        $result = EnvFileWriter::apply($this->path, ['TOKEN' => 'replacement'], ['TOKEN']);
        $contents = (string) file_get_contents($this->path);

        self::assertSame(['TOKEN'], $result['replaced']);
        self::assertSame('replacement', self::value($this->path, 'TOKEN'));
        self::assertSame('untouched', self::value($this->path, 'OTHER'));
        self::assertStringContainsString('# keep', $contents);
        // Replaced, not appended: one assignment must remain.
        self::assertSame(1, substr_count($contents, 'TOKEN='));
    }

    #[Test]
    public function a_commented_example_does_not_count_as_defined(): void
    {
        // .env.example ships commented-out placeholders; treating one as a
        // definition would silently drop the real value.
        file_put_contents($this->path, "# TOKEN=example\n");

        $result = EnvFileWriter::apply($this->path, ['TOKEN' => 'real']);

        self::assertSame(['TOKEN'], $result['written']);
        self::assertSame('real', self::value($this->path, 'TOKEN'));
    }

    #[Test]
    public function it_reads_exported_and_quoted_assignments(): void
    {
        file_put_contents($this->path, "export TOKEN=\"quoted value\"\nSINGLE='single'\n");

        self::assertSame('quoted value', self::value($this->path, 'TOKEN'));
        self::assertSame('single', self::value($this->path, 'SINGLE'));
    }

    #[Test]
    public function it_quotes_values_that_would_otherwise_be_misread(): void
    {
        EnvFileWriter::apply($this->path, ['SPACED' => 'has spaces', 'PLAIN' => 'no-spaces_1']);

        $contents = (string) file_get_contents($this->path);

        self::assertStringContainsString('SPACED="has spaces"', $contents);
        self::assertStringContainsString('PLAIN=no-spaces_1', $contents);
        self::assertSame('has spaces', self::value($this->path, 'SPACED'));
    }

    #[Test]
    public function a_replacement_value_containing_a_backreference_survives(): void
    {
        // Secrets are arbitrary bytes; "$1" in a replacement string would be
        // expanded by the regex engine and silently corrupt the credential.
        file_put_contents($this->path, "TOKEN=old\n");

        EnvFileWriter::apply($this->path, ['TOKEN' => 'a$1b\\2c'], ['TOKEN']);

        self::assertSame('a$1b\\2c', self::value($this->path, 'TOKEN'));
    }

    #[Test]
    public function nothing_is_written_when_there_is_nothing_to_do(): void
    {
        file_put_contents($this->path, "TOKEN=same\n");
        $before = filemtime($this->path);

        $result = EnvFileWriter::apply($this->path, ['TOKEN' => 'same']);

        self::assertSame([], $result['written']);
        self::assertSame([], $result['replaced']);
        self::assertSame($before, filemtime($this->path));
    }
}
