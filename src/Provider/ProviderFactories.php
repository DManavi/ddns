<?php

declare(strict_types=1);

namespace Ddns\Provider;

use Ddns\Config\DriverCatalog;

/**
 * The set of drivers this build knows about.
 *
 * A first-class collection rather than a bare array so it can be fetched from
 * the container with a real type, and so the configuration validator has a
 * single authority for which `driver:` values are legal.
 *
 * @implements \IteratorAggregate<int, ProviderFactory>
 */
final class ProviderFactories implements \IteratorAggregate, \Countable
{
    /** @var list<ProviderFactory> */
    private readonly array $factories;

    public function __construct(ProviderFactory ...$factories)
    {
        $this->factories = array_values($factories);
    }

    /**
     * @return list<ProviderFactory>
     */
    public function all(): array
    {
        return $this->factories;
    }

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_map(static fn (ProviderFactory $f): string => $f->driver(), $this->factories);
    }

    /**
     * What the configuration loader needs to know about these drivers.
     */
    public function catalog(): DriverCatalog
    {
        $drivers = [];

        foreach ($this->factories as $factory) {
            $drivers[$factory->driver()] = [
                'token' => $factory->requiresToken(),
                'options' => $factory->requiredOptions(),
            ];
        }

        return DriverCatalog::of($drivers);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->factories);
    }

    public function count(): int
    {
        return count($this->factories);
    }
}
