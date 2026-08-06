<?php

declare(strict_types=1);

namespace Ddns\Console;

use Ddns\Config\ConfigField;
use Ddns\Config\Redactor;
use Ddns\Provider\ProviderFactories;

/**
 * Masks secrets in a raw configuration array so it can be shown or pasted.
 *
 * Which keys hold a secret is answered by the drivers themselves, through the
 * same {@see ConfigField} descriptions the wizard asks from - so a driver that
 * introduces a new credential is covered without this class being told.
 *
 * A `${VAR}` placeholder is left alone. It is not a secret, it is a reference
 * to one, and masking it would hide the very thing a reader needs to see.
 */
final class ConfigRedaction
{
    /**
     * Always secret regardless of driver: the loader validates `token` for
     * providers, and every host has one.
     *
     * @var list<string>
     */
    private const ALWAYS_SECRET = ['token'];

    /**
     * @param array<array-key, mixed> $raw
     * @param list<string>            $secretKeys
     *
     * @return array<array-key, mixed>
     */
    public static function mask(array $raw, array $secretKeys = self::ALWAYS_SECRET): array
    {
        foreach (['providers', 'hosts'] as $section) {
            if (!isset($raw[$section]) || !is_array($raw[$section])) {
                continue;
            }

            $masked = [];

            foreach ($raw[$section] as $name => $definition) {
                $masked[$name] = is_array($definition)
                    ? self::maskEntry($definition, $secretKeys)
                    : $definition;
            }

            $raw[$section] = $masked;
        }

        return $raw;
    }

    /**
     * Every key any registered driver treats as a secret.
     *
     * @return list<string>
     */
    public static function secretKeys(ProviderFactories $factories): array
    {
        $keys = self::ALWAYS_SECRET;

        foreach ($factories->all() as $factory) {
            foreach ($factory->configFields() as $field) {
                if ($field->secret && !in_array($field->key, $keys, true)) {
                    $keys[] = $field->key;
                }
            }
        }

        return $keys;
    }

    public static function isPlaceholder(string $value): bool
    {
        return preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*(?::?-.*)?\}$/', trim($value)) === 1;
    }

    /**
     * @param array<array-key, mixed> $definition
     * @param list<string>            $secretKeys
     *
     * @return array<array-key, mixed>
     */
    private static function maskEntry(array $definition, array $secretKeys): array
    {
        foreach ($definition as $key => $value) {
            if (!is_string($key) || !in_array($key, $secretKeys, true) || !is_string($value)) {
                continue;
            }

            $definition[$key] = self::isPlaceholder($value) ? $value : Redactor::redact($value);
        }

        return $definition;
    }
}
