<?php

declare(strict_types=1);

namespace Ddns\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes machine-readable output to stdout.
 *
 * Two shapes, because the commands come in two kinds. A one-shot command emits
 * a single pretty-printed document; `watch` emits a stream, where one compact
 * object per line is what a reader can consume incrementally.
 *
 * Whatever this writes has to be the only thing on stdout, or the caller cannot
 * parse it. Human-facing messages therefore go to stderr in JSON mode - see
 * {@see \Ddns\Console\AbstractDdnsCommand::humanOutput()}.
 */
final class JsonOutput
{
    private const DOCUMENT_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const EVENT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly OutputInterface $output)
    {
    }

    /**
     * A complete result, for a command that runs once and exits.
     *
     * @param array<string, mixed> $payload
     */
    public function document(array $payload): void
    {
        $this->write(json_encode($payload, self::DOCUMENT_FLAGS | JSON_THROW_ON_ERROR));
    }

    /**
     * One event of a stream, as newline-delimited JSON.
     *
     * @param array<string, mixed> $payload
     */
    public function event(array $payload): void
    {
        $this->write(json_encode($payload, self::EVENT_FLAGS | JSON_THROW_ON_ERROR));
    }

    private function write(string $encoded): void
    {
        // Raw, so Symfony neither wraps long lines nor interprets anything in
        // the payload as console formatting - a `<` in an error message would
        // otherwise be eaten as a style tag.
        $this->output->writeln($encoded, OutputInterface::OUTPUT_RAW);
    }
}
