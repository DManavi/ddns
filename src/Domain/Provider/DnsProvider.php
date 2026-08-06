<?php

declare(strict_types=1);

namespace Ddns\Domain\Provider;

use Ddns\Domain\Provider\Exception\ProviderException;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;

/**
 * The complete surface a DNS provider has to implement.
 *
 * Everything else - upsert semantics, no-change detection, TTL defaulting,
 * handling several record types per host - lives in
 * {@see \Ddns\Domain\Update\DdnsUpdater}, so adding a provider means writing
 * these three operations and nothing more.
 */
interface DnsProvider
{
    /**
     * The driver identifier used in configuration, e.g. `digitalocean`.
     */
    public function driver(): string;

    /**
     * Locate the address record for a hostname, or null when it does not exist.
     *
     * @throws ProviderException
     */
    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord;

    /**
     * Create a new address record.
     *
     * @throws ProviderException
     */
    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord;

    /**
     * Repoint an existing record at a new address.
     *
     * @throws ProviderException
     */
    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord;
}
