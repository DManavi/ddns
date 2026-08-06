<?php

declare(strict_types=1);

namespace Ddns\Config\Exception;

/**
 * The configuration file is missing, unreadable, or semantically invalid.
 *
 * Messages are written for a human staring at a YAML file at 2am, so they name
 * the offending key path and say what was expected.
 */
final class ConfigurationError extends \RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public static function invalid(array $problems): self
    {
        $lines = array_map(static fn (string $problem): string => '  - ' . $problem, $problems);

        return new self("The DDNS configuration is invalid:\n" . implode("\n", $lines));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('Configuration file "%s" does not exist or is not readable.', $path));
    }

    public static function malformed(string $path, string $detail): self
    {
        return new self(sprintf('Configuration file "%s" could not be parsed: %s', $path, $detail));
    }

    public static function missingEnvironmentVariable(string $variable, string $keyPath): self
    {
        return new self(sprintf(
            'Configuration key "%s" references environment variable "%s", which is not set. '
            . 'Set it, or give it a default with ${%s:-fallback}.',
            $keyPath,
            $variable,
            $variable,
        ));
    }

    public static function unknownHost(string $host): self
    {
        return new self(sprintf('No host named "%s" is configured.', $host));
    }
}
