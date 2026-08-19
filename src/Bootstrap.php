<?php

declare(strict_types=1);

namespace Ddns;

use Ddns\Config\Environment;
use Ddns\Config\Exception\ConfigurationError;
use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;

/**
 * Builds the dependency container shared by both entrypoints.
 *
 * `public/index.php` and `bin/ddns` bootstrap identically; the only difference
 * is which adapter they put in front of the container afterwards.
 */
final class Bootstrap
{
    /**
     * The application version, reported by `ddns --version` and by the OpenAPI
     * document. Lives here because both front-ends need it and neither should
     * have to import the other.
     */
    public const VERSION = '1.20260819.1652';

    /**
     * Where the configuration is looked for, relative to the project root.
     *
     * Exactly one path, deliberately. `config/ddns.yaml` is where `config:init`
     * writes and where the container expects the file mounted, so the same path
     * means the same thing on the host and in the image. Searching alternatives
     * bought very little and cost the only question that matters when an answer
     * surprises you - which file is this actually reading?
     *
     * @var list<string>
     */
    private const CONFIG_CANDIDATES = [
        'config/ddns.yaml',
    ];

    /** Where `config:init` writes when it is not told otherwise. */
    public const DEFAULT_CONFIG_PATH = 'config/ddns.yaml';

    private static bool $dotEnvLoaded = false;

    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @param string|null $configPath explicit config file, overriding discovery
     */
    public static function container(?string $configPath = null): Container
    {
        self::loadDotEnv();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        /** @var array<string, mixed> $definitions */
        $definitions = require self::projectRoot() . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            // Resolved lazily so commands that need no configuration - such as
            // `providers:list` and `--help` - still work before a file exists.
            'config.path' => $configPath ?? \DI\factory(static fn (): string => self::discoverConfigPath()),

            // Where secrets are written. A binding rather than a constant so a
            // test can point it somewhere harmless: only the project's own
            // .env is loaded at runtime, so this is the only place a ${VAR}
            // placeholder can actually be resolved from.
            'env.path' => self::projectRoot() . '/.env',
        ]);

        return $builder->build();
    }

    /**
     * Locate the configuration file.
     *
     * `DDNS_CONFIG` wins so a container can mount the file anywhere; otherwise
     * `config/ddns.yaml` is the only thing looked for. Nothing stands in for a
     * configuration that is not there: an application answering from a file
     * nobody chose is worse than one that refuses to start.
     *
     * @throws ConfigurationError when nothing is found
     */
    public static function discoverConfigPath(): string
    {
        $explicit = self::configPathFromEnvironment();

        if ($explicit !== null) {
            return $explicit;
        }

        foreach (self::configCandidates() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw ConfigurationError::notFound(self::configCandidates());
    }

    /**
     * Every place the configuration is looked for, in order of preference.
     *
     * @return list<string> absolute paths
     */
    public static function configCandidates(): array
    {
        $root = self::projectRoot();

        return array_map(static fn (string $c): string => $root . '/' . $c, self::CONFIG_CANDIDATES);
    }

    /**
     * The path the configuration would be read from, whether or not it exists.
     *
     * Unlike {@see discoverConfigPath()} this never throws, because "there is
     * no file" is not an answer to "where should the file go?" - which is
     * precisely the question being asked when there is none.
     */
    public static function intendedConfigPath(): string
    {
        return self::configPathFromEnvironment() ?? self::projectRoot() . '/' . self::DEFAULT_CONFIG_PATH;
    }

    /**
     * `DDNS_CONFIG`, wherever it was set.
     *
     * Reads the superglobals as well as `getenv()`, because phpdotenv v5 loads
     * `.env` into `$_ENV` and `$_SERVER` without calling `putenv()`. Consulting
     * only `getenv()` meant a `DDNS_CONFIG` written in `.env` was silently
     * ignored while every other variable in that file worked, since the config
     * loader reads the superglobals for its `${VAR}` placeholders.
     */
    public static function configPathFromEnvironment(): ?string
    {
        $value = self::environment()->get('DDNS_CONFIG');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function environment(): Environment
    {
        self::loadDotEnv();

        return Environment::fromGlobals();
    }

    private static function loadDotEnv(): void
    {
        // Reached from both the container and the path lookup, and loading an
        // immutable Dotenv twice would be wasted work rather than a bug.
        if (self::$dotEnvLoaded) {
            return;
        }

        self::$dotEnvLoaded = true;

        $root = self::projectRoot();

        if (!is_file($root . '/.env')) {
            return;
        }

        Dotenv::createImmutable($root)->safeLoad();
    }
}
