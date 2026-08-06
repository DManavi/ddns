<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * Reads and writes values in a raw configuration array by dotted path.
 *
 * `hosts.home.ttl` is how a person refers to a setting when talking about it,
 * so it is how the management commands accept one. Kept separate from the
 * commands because both reading and writing need exactly the same traversal,
 * and because getting "create the intermediate levels" subtly different
 * between them would be a quiet source of corruption.
 */
final class ConfigPath
{
    /**
     * @param array<array-key, mixed> $config
     *
     * @return array{found: bool, value: mixed}
     */
    public static function get(array $config, string $path): array
    {
        $current = $config;

        foreach (self::segments($path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return ['found' => false, 'value' => null];
            }

            $current = $current[$segment];
        }

        return ['found' => true, 'value' => $current];
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    public static function set(array $config, string $path, mixed $value): array
    {
        $segments = self::segments($path);
        $result = $config;

        /** @var array<array-key, mixed> $cursor */
        $cursor = &$result;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                break;
            }

            // Replace a scalar standing where a mapping needs to be, rather
            // than failing: `config:set hosts.home.ttl` on a file where
            // `hosts.home` is a string is a mistake worth reporting, and the
            // caller checks the shape before getting here.
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            /** @var array<array-key, mixed> $next */
            $next = &$cursor[$segment];
            $cursor = &$next;
        }

        return $result;
    }

    /**
     * Whether every level above the last is a mapping, so a value can be
     * written without silently discarding something.
     *
     * @param array<array-key, mixed> $config
     *
     * @return string|null the path of the offending level, or null when writable
     */
    public static function blockedBy(array $config, string $path): ?string
    {
        $segments = self::segments($path);
        /** @var array<array-key, mixed> $current */
        $current = $config;
        $walked = [];

        foreach (array_slice($segments, 0, -1) as $segment) {
            $walked[] = $segment;

            if (!array_key_exists($segment, $current)) {
                return null;
            }

            $next = $current[$segment];

            if (!is_array($next)) {
                return implode('.', $walked);
            }

            $current = $next;
        }

        return null;
    }

    /**
     * Every dotted path that leads to a scalar or a list, for suggesting
     * alternatives when someone asks for one that does not exist.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    public static function leaves(array $config, string $prefix = ''): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $paths = [...$paths, ...self::leaves($value, $path)];

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('.', $path), static fn (string $s): bool => $s !== ''));
    }
}
