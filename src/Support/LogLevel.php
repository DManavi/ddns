<?php

declare(strict_types=1);

namespace Ddns\Support;

use Monolog\Level;

/**
 * Resolves the log level from the environment.
 *
 * An unrecognised value falls back to INFO rather than throwing: a typo in
 * `DDNS_LOG_LEVEL` should not stop the server from starting.
 *
 * The value is passed in rather than read here, so that the caller decides
 * where the environment comes from. That matters more than it sounds: this
 * used to call getenv(), which does not see anything set in .env, so the
 * variable worked from Docker and the shell but not from the one file that
 * documents it.
 */
final class LogLevel
{
    public const VARIABLE = 'DDNS_LOG_LEVEL';

    public static function fromEnvironment(?string $configured): Level
    {
        if ($configured === null || trim($configured) === '') {
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
