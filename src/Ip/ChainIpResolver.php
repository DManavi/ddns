<?php

declare(strict_types=1);

namespace Ddns\Ip;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Ip\Exception\IpResolutionFailed;

/**
 * Tries each delegate in order and returns the first address found.
 *
 * This is what implements "an explicit `ip` parameter wins, otherwise fall back
 * to the request's source address": the static resolver holding the explicit
 * value is simply placed first.
 */
final class ChainIpResolver implements IpResolver
{
    /** @var list<IpResolver> */
    private readonly array $resolvers;

    public function __construct(IpResolver ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    public function resolve(RecordType $type): IpAddress
    {
        return $this->tryResolve($type)
            ?? throw IpResolutionFailed::noAddressFor($type, 'None of the configured sources returned one.');
    }

    public function tryResolve(RecordType $type): ?IpAddress
    {
        foreach ($this->resolvers as $resolver) {
            $address = $resolver->tryResolve($type);

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }
}
