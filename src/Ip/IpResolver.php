<?php

declare(strict_types=1);

namespace Ddns\Ip;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Ip\Exception\IpResolutionFailed;

/**
 * Determines the address a record should point at.
 *
 * The CLI asks an external echo service; the HTTP layer prefers an explicit
 * parameter and otherwise uses the request's source address. Both arrive here
 * behind the same interface so {@see \Ddns\Domain\Update\DdnsUpdater} never has
 * to care which front-end invoked it.
 */
interface IpResolver
{
    /**
     * Resolve the current address for a protocol family.
     *
     * @throws IpResolutionFailed when no address of that family can be determined
     */
    public function resolve(RecordType $type): IpAddress;

    /**
     * Resolve without throwing, for families that may legitimately be absent
     * (an IPv4-only client asked to keep an AAAA record in sync, say).
     */
    public function tryResolve(RecordType $type): ?IpAddress;
}
