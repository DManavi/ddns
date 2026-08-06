<?php

declare(strict_types=1);

namespace Ddns\Provider\Azure;

use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Http\RestClient;
use Ddns\Provider\Http\RestResponse;

/**
 * Azure DNS driver, over the management REST API.
 *
 * Microsoft archived the Azure SDK for PHP in 2023, so there is no SDK to use;
 * direct REST is the documented approach, and it fits the transport the other
 * REST drivers already share.
 *
 * This is the most direct provider here: a record set is addressed by zone,
 * type and relative name, so a lookup is one GET with no listing or pagination,
 * and PUT is create-or-update.
 *
 * Serves both public and private zones. They are separate Azure resource types
 * that disagree on the API version and on the casing of the record body, so
 * everything that differs is held by {@see AzureZoneKind} rather than being
 * hard-coded here.
 *
 * @see https://learn.microsoft.com/rest/api/dns/record-sets
 * @see https://learn.microsoft.com/rest/api/dns/privatedns/record-sets
 */
final class AzureDnsProvider implements DnsProvider
{
    public const MANAGEMENT_ENDPOINT = 'https://management.azure.com';

    /** OAuth2 scope for the management API, as the client credentials grant wants it. */
    public const DEFAULT_SCOPE = 'https://management.azure.com/.default';

    /**
     * Error codes that mean the containing resource is missing rather than the
     * record, so a 404 can be told apart from "no such record yet".
     */
    private const CONTAINER_MISSING_CODES = [
        'ResourceGroupNotFound',
        'SubscriptionNotFound',
        'ZoneNotFound',
        'ParentResourceNotFound',
    ];

    public function __construct(
        private readonly RestClient $client,
        private readonly string $subscriptionId,
        private readonly string $resourceGroup,
        private readonly AzureZoneKind $kind = AzureZoneKind::Public,
    ) {
    }

    public function driver(): string
    {
        return $this->kind->driver();
    }

    public function kind(): AzureZoneKind
    {
        return $this->kind;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $response = $this->client->get($this->recordPath($hostname, $type), [
            'api-version' => $this->kind->apiVersion(),
        ]);

        // A 404 is ambiguous: the record may simply not exist yet, or the zone,
        // resource group or subscription may be wrong. Treating the latter as
        // "no record" would turn a typo into a confusing write failure, so the
        // error code decides.
        if ($response->isNotFound()) {
            if ($this->indicatesMissingContainer($response)) {
                throw ZoneNotFound::for($this->driver(), sprintf(
                    '%s (resource group "%s")',
                    $hostname->zone(),
                    $this->resourceGroup,
                ));
            }

            return null;
        }

        $response->assertSuccessful('read DNS record');

        return $this->toRecord($hostname, $type, $response);
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        return $this->put($hostname, $type, $ip, $ttl);
    }

    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord
    {
        return $this->put($record->hostname(), $record->type(), $ip, $ttl);
    }

    /**
     * PUT is create-or-update, so it serves both operations and is repeatable.
     */
    private function put(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        $response = $this->client->put(
            $this->recordPath($hostname, $type),
            [
                'properties' => [
                    // Both the TTL key and the records key differ between public
                    // and private zones, and the records key differs again by
                    // record type. Nothing here is a fixed string.
                    $this->kind->ttlKey() => $ttl,
                    $this->kind->recordsKey($type) => [
                        [$this->kind->addressKey($type) => $ip->value()],
                    ],
                ],
            ],
            ['api-version' => $this->kind->apiVersion()],
        );

        if ($response->isNotFound() && $this->indicatesMissingContainer($response)) {
            throw ZoneNotFound::for($this->driver(), sprintf(
                '%s (resource group "%s")',
                $hostname->zone(),
                $this->resourceGroup,
            ));
        }

        $response->assertSuccessful('write DNS record');

        return $this->toRecord($hostname, $type, $response)
            ?? new DnsRecord($this->recordId($hostname, $type), $hostname, $type, $ip->value(), $ttl);
    }

    /**
     * `/subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.Network/{dnsZones|privateDnsZones}/{zone}/{TYPE}/{name}`
     */
    private function recordPath(Hostname $hostname, RecordType $type): string
    {
        return sprintf(
            '/subscriptions/%s/resourceGroups/%s/providers/Microsoft.Network/%s/%s/%s/%s',
            rawurlencode($this->subscriptionId),
            rawurlencode($this->resourceGroup),
            $this->kind->resourceType(),
            rawurlencode($hostname->zone()),
            $type->value,
            // The apex is `@`, which has no reserved meaning in a path segment
            // but is encoded anyway so every name goes through the same rule.
            rawurlencode($hostname->name()),
        );
    }

    private function toRecord(Hostname $hostname, RecordType $type, RestResponse $response): ?DnsRecord
    {
        // An alias record takes its value from another Azure resource and has no
        // address of its own. Overwriting one would silently detach a Traffic
        // Manager profile or CDN endpoint, so refuse rather than clobber.
        // Private zones have no such concept.
        if ($this->kind->supportsAliasRecords() && $response->get('properties.targetResource.id') !== null) {
            throw RecordOperationFailed::for($this->driver(), 'read DNS record', sprintf(
                '"%s" is an alias record pointing at another Azure resource. Refusing to replace it '
                . 'with a plain %s record.',
                $hostname->fqdn(),
                $type->value,
            ));
        }

        // A private zone linked to a VNet with auto-registration enabled holds
        // records Azure maintains for its VMs. Those cannot be written to
        // manually - the platform rejects it - so say so plainly here rather
        // than letting the update fail later with an opaque error.
        if ($this->kind->supportsAutoRegistration() && $response->get('properties.isAutoRegistered') === true) {
            throw RecordOperationFailed::for($this->driver(), 'read DNS record', sprintf(
                '"%s" is auto-registered by Azure for a virtual machine on a linked VNet, and cannot be '
                . 'changed manually. Use a different hostname, or disable auto-registration on the '
                . 'VNet link.',
                $hostname->fqdn(),
            ));
        }

        $values = [];

        foreach ($response->listOf('properties.' . $this->kind->recordsKey($type)) as $entry) {
            $address = $entry[$this->kind->addressKey($type)] ?? null;

            if (is_string($address) && $address !== '') {
                $values[] = $address;
            }
        }

        if ($values === []) {
            return null;
        }

        $ttl = $response->get('properties.' . $this->kind->ttlKey());

        // A multi-value set is reported by its first value, so it compares as
        // out of date whenever it holds anything but our address and the next
        // write collapses it to one - which is the point of a dynamic record.
        return new DnsRecord(
            $this->recordId($hostname, $type),
            $hostname,
            $type,
            $values[0],
            is_int($ttl) ? $ttl : null,
        );
    }

    /**
     * Azure identifies a record set by its ARM resource ID; the response
     * carries it, and it is reconstructable when it does not.
     */
    private function recordId(Hostname $hostname, RecordType $type): string
    {
        return $this->recordPath($hostname, $type);
    }

    private function indicatesMissingContainer(RestResponse $response): bool
    {
        $code = $response->get('error.code');

        if (!is_string($code)) {
            return false;
        }

        return in_array($code, self::CONTAINER_MISSING_CODES, true);
    }
}
