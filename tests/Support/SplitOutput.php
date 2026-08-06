<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A console output whose error stream is a genuinely separate buffer.
 */
final class SplitOutput extends StreamOutput implements ConsoleOutputInterface
{
    private StreamOutput $stderr;

    /** @var array<int, ConsoleSectionOutput> */
    private array $sections = [];

    /** @var resource */
    private $stdoutStream;

    /** @var resource */
    private $stderrStream;

    public function __construct(int $verbosity)
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('cannot open memory stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('cannot open memory stream');

        $this->stdoutStream = $stdout;
        $this->stderrStream = $stderr;

        // Decoration off so assertions see the payload, not ANSI escapes.
        parent::__construct($stdout, $verbosity, false);

        $this->stderr = new StreamOutput($stderr, $verbosity, false, $this->getFormatter());
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        // The split is the point of this class, so replacing it is refused
        // rather than silently ignored.
        throw new \LogicException('The error output of SplitOutput is fixed.');
    }

    public function section(): ConsoleSectionOutput
    {
        // SymfonyStyle::createTable() asks for one, so this has to work rather
        // than throw, or no table-rendering command could be tested at all.
        $section = new ConsoleSectionOutput(
            $this->stdoutStream,
            $this->sections,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter(),
        );

        $this->sections[] = $section;

        return $section;
    }

    public function stdout(): string
    {
        return $this->read($this->stdoutStream);
    }

    public function stderr(): string
    {
        return $this->read($this->stderrStream);
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
