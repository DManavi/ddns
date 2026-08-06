<?php

declare(strict_types=1);

namespace Ddns;

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
    /** @var list<string> relative to the project root, in order of preference */
    private const CONFIG_CANDIDATES = [
        'ddns.yaml',
        'ddns.yml',
        'config/ddns.yaml',
        'config/ddns.yml',
    ];

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
        $explicit = getenv('DDNS_CONFIG');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $root = self::projectRoot();

        foreach (self::CONFIG_CANDIDATES as $candidate) {
            $path = $root . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        throw ConfigurationError::notFound(
            array_map(static fn (string $c): string => $root . '/' . $c, self::CONFIG_CANDIDATES),
        );
    }

    private static function loadDotEnv(): void
    {
        $root = self::projectRoot();

        if (!is_file($root . '/.env')) {
            return;
        }

        Dotenv::createImmutable($root)->safeLoad();
    }
}
