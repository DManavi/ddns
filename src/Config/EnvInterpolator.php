<?php

declare(strict_types=1);

namespace Ddns\Config;

use Ddns\Config\Exception\ConfigurationError;

/**
 * Expands `${VAR}`, `${VAR:-default}` and `${VAR-default}` inside config values.
 *
 * This is what lets the YAML file be committed to version control while the
 * actual secrets arrive from the environment or a `.env` file.
 */
final class EnvInterpolator
{
    private const PATTERN = '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?-((?:[^{}]|\{[^{}]*\})*))?\}/';

    public function __construct(private readonly Environment $env)
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationError when a referenced variable is unset and has no default
     */
    public function interpolate(array $data): array
    {
        /** @var array<array-key, mixed> $result */
        $result = $this->walk($data, '');

        return $result;
    }

    private function walk(mixed $value, string $keyPath): mixed
    {
        if (is_string($value)) {
            return $this->expand($value, $keyPath);
        }

        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $childPath = $keyPath === '' ? (string) $key : $keyPath . '.' . $key;
            $out[$key] = $this->walk($item, $childPath);
        }

        return $out;
    }

    private function expand(string $value, string $keyPath): string
    {
        $result = preg_replace_callback(
            self::PATTERN,
            function (array $matches) use ($keyPath): string {
                $name = $matches[1];
                $resolved = $this->env->get($name);

                if ($resolved !== null && $resolved !== '') {
                    return $resolved;
                }

                if (array_key_exists(2, $matches)) {
                    return $matches[2];
                }

                if ($resolved === '') {
                    return '';
                }

                throw ConfigurationError::missingEnvironmentVariable($name, $keyPath);
            },
            $value,
        );

        return $result ?? $value;
    }
}
