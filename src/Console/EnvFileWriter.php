<?php

declare(strict_types=1);

namespace Ddns\Console;

/**
 * Reads and updates a `.env` file without disturbing what is already there.
 *
 * The wizard can be re-run, and the file is hand-edited between runs, so this
 * preserves layout and comments: new variables are appended, and an existing
 * one is only replaced when the caller explicitly asks. Silently overwriting a
 * working credential, and silently keeping a stale one while the configuration
 * points at it, are both failures that would be hard to diagnose.
 */
final class EnvFileWriter
{
    /** Owner-only: the whole point of this file is that it holds secrets. */
    private const FILE_MODE = 0600;

    private const ASSIGNMENT = '/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$/m';

    /**
     * The variables the file assigns, ignoring commented-out examples such as
     * the ones shipped in `.env.example`.
     *
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        $values = [];

        preg_match_all(self::ASSIGNMENT, $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $values[$match[1]] = self::unquote(trim($match[2]));
        }

        return $values;
    }

    /**
     * Append the variables that are new, replace the ones named in `$replace`,
     * and leave every other existing assignment untouched.
     *
     * @param array<string, string> $values
     * @param list<string>          $replace variables the caller has confirmed may be overwritten
     *
     * @return array{written: list<string>, replaced: list<string>, kept: list<string>}
     */
    public static function apply(string $path, array $values, array $replace = []): array
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $existing = self::read($path);

        $written = [];
        $replaced = [];
        $kept = [];
        $appended = [];

        foreach ($values as $name => $value) {
            if (!array_key_exists($name, $existing)) {
                $written[] = $name;
                $appended[] = $name . '=' . self::quote($value);

                continue;
            }

            if ($existing[$name] === $value || !in_array($name, $replace, true)) {
                $kept[] = $name;

                continue;
            }

            $contents = self::replaceAssignment($contents, $name, $value);
            $replaced[] = $name;
        }

        if ($appended !== []) {
            if ($contents === '') {
                $contents = "# Written by `ddns config:init`.\n";
            } elseif (!str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= implode("\n", $appended) . "\n";
        }

        if ($appended !== [] || $replaced !== []) {
            file_put_contents($path, $contents);
            chmod($path, self::FILE_MODE);
        }

        return ['written' => $written, 'replaced' => $replaced, 'kept' => $kept];
    }

    private static function replaceAssignment(string $contents, string $name, string $value): string
    {
        $quoted = self::quote($value);

        return (string) preg_replace_callback(
            '/^([ \t]*(?:export[ \t]+)?' . preg_quote($name, '/') . '[ \t]*=).*$/m',
            // A callback, not a replacement string: a secret containing `$1`
            // or a backslash would otherwise be mangled by backreference
            // expansion.
            static fn (array $m): string => $m[1] . $quoted,
            $contents,
            1,
        );
    }

    /**
     * Quote only when the value could otherwise be misread. Tokens are usually
     * plain, and unnecessary quoting makes the file harder to scan.
     */
    private static function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:\/+=@-]*$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $inner = substr($value, 1, -1);

            return $value[0] === '"'
                ? str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $inner)
                : $inner;
        }

        return $value;
    }
}
