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
     * @param bool $namesPaths whether the message contains filesystem paths,
     *                         which the HTTP front-end must not echo back
     */
    public function __construct(string $message, public readonly bool $namesPaths = false)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $problems
     */
    public static function invalid(array $problems): self
    {
        $lines = array_map(static fn (string $problem): string => '  - ' . $problem, $problems);

        return new self("The DDNS configuration is invalid:\n" . implode("\n", $lines));
    }

    /**
     * No configuration file exists yet.
     *
     * Distinct from {@see self::unreadable()} because the answer is different:
     * there is nothing to fix in a file that was never written, so this points
     * at the wizard instead of listing what is wrong.
     *
     * @param list<string> $candidates absolute paths that were tried
     */
    public static function notFound(array $candidates): self
    {
        return new self(sprintf(
            "No configuration file found.\n\n"
            . "Create one with either of:\n"
            . "  ddns config:init            answer a few questions\n"
            . "  ddns config:init --sample   a working local configuration, no questions\n\n"
            . "Or write it yourself, starting from config/ddns.example.yaml, at:\n%s\n"
            . 'Set DDNS_CONFIG, or pass --config, to keep it somewhere else.',
            implode("\n", array_map(static fn (string $c): string => '  - ' . $c, $candidates)),
        ), namesPaths: true);
    }

    /**
     * A named file is not there.
     *
     * Separated from {@see self::unreadable()} because the two have different
     * answers: a missing file is created, an unreadable one is a permissions
     * problem, and conflating them sends people looking in the wrong place.
     */
    public static function missing(string $path): self
    {
        return new self(sprintf(
            "Configuration file \"%s\" does not exist.\n\nCreate it with:\n  ddns config:init --config %s",
            $path,
            $path,
        ), namesPaths: true);
    }

    public static function unreadable(string $path): self
    {
        return is_file($path)
            ? new self(sprintf('Configuration file "%s" is not readable. Check its permissions and owner.', $path), namesPaths: true)
            : self::missing($path);
    }

    public static function malformed(string $path, string $detail): self
    {
        return new self(sprintf('Configuration file "%s" could not be parsed: %s', $path, $detail), namesPaths: true);
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
