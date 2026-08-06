<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure;

use Ddns\Domain\Record\RecordType;

/**
 * The two kinds of Azure DNS zone, and everything that differs between them.
 *
 * Public and private zones are separate Azure resource types with separate API
 * versions, and - the part worth being careful about - they disagree on the
 * casing of the record set body. A public zone wants `TTL` and `ARecords`; a
 * private zone wants `ttl` and `aRecords`. Sending the wrong case does not
 * fail: Azure accepts the request and stores a record set with no addresses in
 * it. Keeping the whole delta in one table is the point of this enum.
 *
 * @see https://learn.microsoft.com/rest/api/dns/record-sets
 * @see https://learn.microsoft.com/rest/api/dns/privatedns/record-sets
 */
enum AzureZoneKind: string
{
    case Public = 'public';
    case Private = 'private';

    /**
     * The identifier used as `driver:` in the config file.
     */
    public function driver(): string
    {
        return match ($this) {
            self::Public => 'azuredns',
            self::Private => 'azureprivatedns',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Azure DNS (management REST API)',
            self::Private => 'Azure Private DNS (management REST API)',
        };
    }

    /**
     * The ARM resource type segment in the record URL.
     */
    public function resourceType(): string
    {
        return match ($this) {
            self::Public => 'dnsZones',
            self::Private => 'privateDnsZones',
        };
    }

    /**
     * Pinned rather than tracking latest, so a change at Azure's end cannot
     * alter behaviour without a deliberate edit here.
     */
    public function apiVersion(): string
    {
        return match ($this) {
            self::Public => '2018-05-01',
            self::Private => '2018-09-01',
        };
    }

    public function ttlKey(): string
    {
        return match ($this) {
            self::Public => 'TTL',
            self::Private => 'ttl',
        };
    }

    /**
     * `ARecords` / `AAAARecords` for public zones, `aRecords` / `aaaaRecords`
     * for private ones.
     */
    public function recordsKey(RecordType $type): string
    {
        return match ($this) {
            self::Public => $type->value . 'Records',
            self::Private => strtolower($type->value) . 'Records',
        };
    }

    /**
     * The key holding the address inside a record entry. The one thing both
     * kinds agree on.
     */
    public function addressKey(RecordType $type): string
    {
        return $type->isIpv6() ? 'ipv6Address' : 'ipv4Address';
    }

    /**
     * Only public zones can alias another Azure resource, so only they need
     * guarding against having one overwritten.
     */
    public function supportsAliasRecords(): bool
    {
        return $this === self::Public;
    }

    /**
     * Only private zones auto-register records for VMs on a linked VNet, and
     * those cannot be written to manually.
     */
    public function supportsAutoRegistration(): bool
    {
        return $this === self::Private;
    }

    public function isPrivate(): bool
    {
        return $this === self::Private;
    }
}
