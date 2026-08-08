<?php

declare(strict_types=1);

namespace Ddns\Config;

use Ddns\Config\Exception\ConfigurationError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes the configuration file as raw YAML.
 *
 * Deliberately separate from {@see ConfigLoader}, which interpolates `${VAR}`
 * placeholders and returns validated objects. Anything that writes the file
 * back must work with what is actually written in it: loading through the
 * interpolator and saving the result would replace every `${DO_TOKEN}` with
 * the secret it resolved to, quietly turning a committable file into one full
 * of credentials.
 */
final class ConfigFile
{
    /** Owner-only: this file may hold tokens even when placeholders are used. */
    private const FILE_MODE = 0600;

    /** @var list<string> */
    private const SECTION_ORDER = ['server', 'providers', 'hosts'];

    public const HEADER = <<<'YAML'
        # ddns configuration
        #
        # Written by `ddns config:init`. Check it with `ddns config:validate`.
        #
        # ${VAR} placeholders are read from the environment or a .env file at
        # runtime, so a file using them can be committed safely.
        YAML;

    /**
     * The header on a file written by `config:init --sample`.
     *
     * Says what the loosened settings below are for, because the two of them -
     * trusting private proxies and publishing private addresses - are exactly
     * what production must not do, and a file that arrives with them already
     * set should say so where someone reading it will see it.
     */
    public const SAMPLE_HEADER = <<<'YAML'
        # ddns configuration - local development
        #
        # Written by `ddns config:init --sample`. Check it with
        # `ddns config:validate`.
        #
        # NOT A PRODUCTION CONFIGURATION. It trusts the private ranges as
        # proxies and permits publishing private addresses, so that a request
        # arriving through Docker's bridge behaves like a real one. Both are
        # wrong on a public deployment; run `ddns config:init` for that.
        #
        # The credentials are randomly generated placeholders held in .env. The
        # provider one is not a real account, so booting, /health and
        # config:validate work while an actual update is refused upstream.
        YAML;

    /**
     * Every header this class emits, so {@see self::hasComments()} can tell one
     * from a comment somebody wrote.
     *
     * @var list<string>
     */
    private const HEADERS = [self::HEADER, self::SAMPLE_HEADER];

    /**
     * The file exactly as written, with no interpolation and no validation.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationError
     */
    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ConfigurationError::unreadable($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw ConfigurationError::unreadable($path);
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw ConfigurationError::malformed($path, $e->getMessage());
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed)) {
            throw ConfigurationError::malformed($path, 'the top level must be a mapping.');
        }

        return $parsed;
    }

    /**
     * Whether the file carries comments that rewriting would actually lose.
     *
     * A header this class writes does not count: {@see self::render()} emits it
     * again, so warning about it would train people to ignore the warning that
     * matters. Every header is stripped, not just the default one, or a file
     * from `config:init --sample` would prompt on its own preamble.
     */
    public static function hasComments(string $contents): bool
    {
        foreach (self::HEADERS as $header) {
            if (str_starts_with($contents, $header)) {
                $contents = substr($contents, strlen($header));

                break;
            }
        }

        return preg_match('/^[ \t]*#/m', $contents) === 1;
    }

    /**
     * The header this file already carries, so rewriting it keeps it.
     *
     * `config:set` and the `hosts:*` commands rewrite the whole file, and
     * emitting the default header regardless would quietly swap the sample's
     * "not a production configuration" warning for a neutral one - silently,
     * because {@see self::hasComments()} correctly does not count a generated
     * header as a comment worth confirming the loss of.
     */
    public static function headerOf(string $contents): string
    {
        foreach (self::HEADERS as $header) {
            if (str_starts_with($contents, $header)) {
                return $header;
            }
        }

        return self::HEADER;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function render(array $config, string $header = self::HEADER): string
    {
        // Six levels of nesting before YAML collapses to inline notation is
        // enough for hosts.<name>.types, the deepest structure in the file.
        return $header . "\n\n" . Yaml::dump(self::ordered($config), 6, 2, Yaml::DUMP_NULL_AS_TILDE);
    }

    /**
     * Put the known sections in their documented order.
     *
     * Without this a section added by `config:set` lands wherever it happened
     * to be appended, so two files with identical content could read - and
     * diff - differently.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function ordered(array $config): array
    {
        $ordered = [];

        foreach (self::SECTION_ORDER as $section) {
            if (array_key_exists($section, $config)) {
                $ordered[$section] = $config[$section];
            }
        }

        // Anything unrecognised keeps its position relative to the rest, rather
        // than being dropped: this file is hand-editable.
        foreach ($config as $key => $value) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    /**
     * Write via a temporary file in the same directory, then rename.
     *
     * A half-written config is a config the server cannot start with, and
     * `config:set` rewrites the whole file, so the replacement is made atomic
     * rather than truncating the original in place.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws ConfigurationError
     */
    public static function write(string $path, array $config, string $header = self::HEADER): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw new ConfigurationError(sprintf('Directory "%s" does not exist.', $directory));
        }

        if (!is_writable($directory)) {
            throw new ConfigurationError(sprintf('Directory "%s" is not writable.', $directory));
        }

        $temporary = tempnam($directory, '.ddns-');

        if ($temporary === false) {
            throw new ConfigurationError(sprintf('Could not create a temporary file in "%s".', $directory));
        }

        // Before any content is written, so the secrets are never briefly
        // world-readable.
        chmod($temporary, self::FILE_MODE);

        if (file_put_contents($temporary, self::render($config, $header)) === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new ConfigurationError(sprintf('Could not write "%s".', $path));
        }

        chmod($path, self::FILE_MODE);
    }
}
