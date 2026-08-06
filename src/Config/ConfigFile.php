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

    private const HEADER = <<<'YAML'
        # ddns configuration
        #
        # Written by `ddns config:init`. Check it with `ddns config:validate`.
        #
        # ${VAR} placeholders are read from the environment or a .env file at
        # runtime, so a file using them can be committed safely.
        YAML;

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
     * The header this class writes does not count: {@see self::render()} emits
     * it again, so warning about it would train people to ignore the warning
     * that matters.
     */
    public static function hasComments(string $contents): bool
    {
        $withoutHeader = str_starts_with($contents, self::HEADER)
            ? substr($contents, strlen(self::HEADER))
            : $contents;

        return preg_match('/^[ \t]*#/m', $withoutHeader) === 1;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function render(array $config): string
    {
        // Six levels of nesting before YAML collapses to inline notation is
        // enough for hosts.<name>.types, the deepest structure in the file.
        return self::HEADER . "\n\n" . Yaml::dump(self::ordered($config), 6, 2, Yaml::DUMP_NULL_AS_TILDE);
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
    public static function write(string $path, array $config): void
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

        if (file_put_contents($temporary, self::render($config)) === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new ConfigurationError(sprintf('Could not write "%s".', $path));
        }

        chmod($path, self::FILE_MODE);
    }
}
