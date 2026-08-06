<?php

declare(strict_types=1);

namespace Ddns\Ip;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Ip\Exception\IpResolutionFailed;

/**
 * Serves a fixed set of addresses.
 *
 * Backs `--ip` on the CLI, the `ip` query parameter over HTTP, and the test
 * suite.
 */
final class StaticIpResolver implements IpResolver
{
    /** @var array<string, IpAddress> */
    private array $addresses = [];

    /**
     * @param IpAddress ...$addresses at most one per address family; a later
     *                                address of the same family replaces an earlier one
     */
    public function __construct(IpAddress ...$addresses)
    {
        foreach ($addresses as $address) {
            $this->addresses[$address->recordType()->value] = $address;
        }
    }

    /**
     * @param list<string> $values
     *
     * @throws \InvalidArgumentException when a value is not a valid IP address
     */
    public static function fromStrings(array $values): self
    {
        return new self(...array_map(
            static fn (string $value): IpAddress => IpAddress::fromString($value),
            $values,
        ));
    }

    public function resolve(RecordType $type): IpAddress
    {
        return $this->addresses[$type->value]
            ?? throw IpResolutionFailed::noAddressFor($type, 'No address of that family was supplied.');
    }

    public function tryResolve(RecordType $type): ?IpAddress
    {
        return $this->addresses[$type->value] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->addresses === [];
    }
}
