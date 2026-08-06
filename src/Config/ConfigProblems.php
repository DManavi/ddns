<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * Accumulates configuration problems so they can all be reported at once.
 *
 * Failing on the first error would mean fixing a broken file one run at a time;
 * collecting them lets a single `config:validate` show the whole picture.
 */
final class ConfigProblems
{
    /** @var list<string> */
    private array $problems = [];

    public function add(string $problem): void
    {
        $this->problems[] = $problem;
    }

    public function addf(string $format, string|int|float ...$args): void
    {
        $this->problems[] = sprintf($format, ...$args);
    }

    public function any(): bool
    {
        return $this->problems !== [];
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->problems;
    }
}
