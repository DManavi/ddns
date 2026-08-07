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
    public const VERSION = '1.0.0';

    /**
     * Where the configuration is looked for, relative to the project root, in
     * order of preference.
     *
     * `config/` comes first because that is where `config:init` writes and
     * where the container expects it mounted, so the same path means the same
     * thing on the host and in the image. The project root is still searched,
     * because that is where earlier versions put it.
     *
     * @var list<string>
     */
    private const CONFIG_CANDIDATES = [
        'config/ddns.yaml',
        'config/ddns.yml',
        'ddns.yaml',
        'ddns.yml',
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
     * the conventional locations in the project root are tried in order.
     *
     * @throws ConfigurationError when nothing is found
     */
    public static function discoverConfigPath(): string
    {
        $explicit = self::configPathFromEnvironment();

        if ($explicit !== null) {
            return $explicit;
        }

        $root = self::projectRoot();

        foreach (self::CONFIG_CANDIDATES as $candidate) {
            $path = $root . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        // Only reached when nothing real exists. The editor profiles point
        // this at the committed sample so that a fresh clone runs with no
        // setup at all; nothing sets it in production, where starting up on a
        // sample configuration - with a token published in this repository -
        // would be far worse than refusing to start.
        $fallback = self::environment()->get('DDNS_CONFIG_FALLBACK');

        if (is_string($fallback) && trim($fallback) !== '' && is_file(trim($fallback))) {
            return trim($fallback);
        }

        throw ConfigurationError::notFound(self::configCandidates());
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

    public static function configPathFromEnvironment(): ?string
    {
        $value = self::environment()->get('DDNS_CONFIG');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Whether the configuration in use is only there because nothing real was
     * found, so callers can say so rather than let it pass unnoticed.
     */
    public static function isFallbackConfig(string $path): bool
    {
        $fallback = self::environment()->get('DDNS_CONFIG_FALLBACK');

        if (!is_string($fallback) || trim($fallback) === '') {
            return false;
        }

        return (realpath(trim($fallback)) ?: trim($fallback)) === (realpath($path) ?: $path);
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
