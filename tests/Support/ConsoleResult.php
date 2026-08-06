<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

/**
 * The outcome of one console run.
 */
final class ConsoleResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}
