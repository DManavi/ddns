<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Support;

use Ddns\Config\Environment;
use Ddns\Support\LogLevel;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Level}>
     */
    public static function names(): iterable
    {
        yield 'debug' => ['DEBUG', Level::Debug];
        yield 'info' => ['INFO', Level::Info];
        yield 'notice' => ['NOTICE', Level::Notice];
        yield 'warning' => ['WARNING', Level::Warning];
        yield 'the abbreviation people actually type' => ['WARN', Level::Warning];
        yield 'error' => ['ERROR', Level::Error];
        yield 'critical' => ['CRITICAL', Level::Critical];
        yield 'alert' => ['ALERT', Level::Alert];
        yield 'emergency' => ['EMERGENCY', Level::Emergency];
        yield 'lower case' => ['debug', Level::Debug];
        yield 'surrounded by whitespace' => ["  debug\n", Level::Debug];
    }

    #[DataProvider('names')]
    public function testItReadsEveryLevelName(string $configured, Level $expected): void
    {
        self::assertSame($expected, LogLevel::fromEnvironment($configured));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableValues(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
        yield 'a typo' => ['DEUBG'];
        yield 'a number' => ['7'];
    }

    /**
     * A typo should not stop the server starting, and it should certainly not
     * silently turn logging off.
     */
    #[DataProvider('unusableValues')]
    public function testItFallsBackToInfo(?string $configured): void
    {
        self::assertSame(Level::Info, LogLevel::fromEnvironment($configured));
    }

    /**
     * The regression this pairs with. phpdotenv populates $_ENV and $_SERVER
     * but never calls putenv(), so a level set in .env is invisible to
     * getenv() - which is how this was read for a long time. .env.example
     * documents the variable, so that was the one place it did not work.
     *
     * This has to go through fromGlobals() to mean anything: constructing an
     * Environment from an explicit map tests the map, not the lookup that was
     * broken.
     */
    public function testItSeesALevelSetTheWayDotEnvSetsIt(): void
    {
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;

        try {
            unset($_SERVER[LogLevel::VARIABLE]);
            $_ENV[LogLevel::VARIABLE] = 'DEBUG';

            self::assertSame(
                Level::Debug,
                LogLevel::fromEnvironment(Environment::fromGlobals()->get(LogLevel::VARIABLE)),
            );
        } finally {
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
        }
    }

    /**
     * The other half: a real process variable, which is how Docker, the
     * Compose files and the editor profiles all set it.
     */
    public function testItSeesALevelSetAsAProcessVariable(): void
    {
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;
        $originalProcess = getenv(LogLevel::VARIABLE);

        try {
            unset($_ENV[LogLevel::VARIABLE], $_SERVER[LogLevel::VARIABLE]);
            putenv(LogLevel::VARIABLE . '=ERROR');

            self::assertSame(
                Level::Error,
                LogLevel::fromEnvironment(Environment::fromGlobals()->get(LogLevel::VARIABLE)),
            );
        } finally {
            // phpunit.xml.dist sets this for the whole suite, so put back what
            // was there rather than clearing it and changing later tests.
            $originalProcess === false
                ? putenv(LogLevel::VARIABLE)
                : putenv(LogLevel::VARIABLE . '=' . $originalProcess);

            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
        }
    }
}
