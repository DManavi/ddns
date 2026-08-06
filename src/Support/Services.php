<?php

declare(strict_types=1);

namespace Ddns\Support;

use Psr\Container\ContainerInterface;

/**
 * Type-safe container lookups.
 *
 * PSR-11 returns `mixed`, which would otherwise force an unchecked cast at
 * every call site. Failing loudly here turns a container misconfiguration into
 * an immediate, named error instead of a confusing type error further in.
 */
final class Services
{
    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public static function get(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);

        if (!$service instanceof $id) {
            throw new \RuntimeException(sprintf(
                'Container entry "%s" resolved to %s instead of an instance of that class.',
                $id,
                get_debug_type($service),
            ));
        }

        return $service;
    }

    public static function string(ContainerInterface $container, string $id): string
    {
        $value = $container->get($id);

        if (!is_string($value)) {
            throw new \RuntimeException(sprintf(
                'Container entry "%s" resolved to %s instead of a string.',
                $id,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
