<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * Read-only view of the process environment.
 *
 * Wrapping the superglobals keeps configuration loading testable without
 * mutating real environment state between test cases.
 */
final class Environment
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public static function fromGlobals(): self
    {
        /** @var array<string, string> $values */
        $values = [];

        foreach ([$_ENV, $_SERVER] as $source) {
            /** @var array<array-key, mixed> $source */
            foreach ($source as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $values[$key] = (string) $value;
                }
            }
        }

        return new self($values);
    }

    public function get(string $name): ?string
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * @param array<string, string> $values
     */
    public function with(array $values): self
    {
        return new self([...$this->values, ...$values]);
    }
}
