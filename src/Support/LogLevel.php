<?php

declare(strict_types=1);

namespace Ddns\Support;

use Monolog\Level;

/**
 * Resolves the log level from the environment.
 *
 * An unrecognised value falls back to INFO rather than throwing: a typo in
 * `DDNS_LOG_LEVEL` should not stop the server from starting.
 */
final class LogLevel
{
    private const VARIABLE = 'DDNS_LOG_LEVEL';

    public static function fromEnvironment(): Level
    {
        $configured = getenv(self::VARIABLE);

        if (!is_string($configured) || trim($configured) === '') {
            return Level::Info;
        }

        return self::parse($configured);
    }

    public static function parse(string $name): Level
    {
        return match (strtoupper(trim($name))) {
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'NOTICE' => Level::Notice,
            'WARNING', 'WARN' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY' => Level::Emergency,
            default => Level::Info,
        };
    }
}
