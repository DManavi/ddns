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
 * @see https://learn.microsoft.com/rest/api/dns/record-sets
 */
final class AzureDnsProvider implements DnsProvider
{
    public const DRIVER = 'azuredns';

    public const MANAGEMENT_ENDPOINT = 'https://management.azure.com';

    /** OAuth2 scope for the management API, as the client credentials grant wants it. */
    public const DEFAULT_SCOPE = 'https://management.azure.com/.default';

    public const API_VERSION = '2018-05-01';

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
    ) {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $response = $this->client->get($this->recordPath($hostname, $type), [
            'api-version' => self::API_VERSION,
        ]);

        // A 404 is ambiguous: the record may simply not exist yet, or the zone,
        // resource group or subscription may be wrong. Treating the latter as
        // "no record" would turn a typo into a confusing write failure, so the
        // error code decides.
        if ($response->isNotFound()) {
            if ($this->indicatesMissingContainer($response)) {
                throw ZoneNotFound::for(self::DRIVER, sprintf(
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
                    'TTL' => $ttl,
                    // The body key and the value key both change with the record
                    // type; there is no shared shape between A and AAAA.
                    $this->recordsKey($type) => [
                        [$this->addressKey($type) => $ip->value()],
                    ],
                ],
            ],
            ['api-version' => self::API_VERSION],
        );

        if ($response->isNotFound() && $this->indicatesMissingContainer($response)) {
            throw ZoneNotFound::for(self::DRIVER, sprintf(
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
     * `/subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.Network/dnsZones/{zone}/{TYPE}/{name}`
     */
    private function recordPath(Hostname $hostname, RecordType $type): string
    {
        return sprintf(
            '/subscriptions/%s/resourceGroups/%s/providers/Microsoft.Network/dnsZones/%s/%s/%s',
            rawurlencode($this->subscriptionId),
            rawurlencode($this->resourceGroup),
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
        if ($response->get('properties.targetResource.id') !== null) {
            throw RecordOperationFailed::for(self::DRIVER, 'read DNS record', sprintf(
                '"%s" is an alias record pointing at another Azure resource. Refusing to replace it '
                . 'with a plain %s record.',
                $hostname->fqdn(),
                $type->value,
            ));
        }

        $values = [];

        foreach ($response->listOf('properties.' . $this->recordsKey($type)) as $entry) {
            $address = $entry[$this->addressKey($type)] ?? null;

            if (is_string($address) && $address !== '') {
                $values[] = $address;
            }
        }

        if ($values === []) {
            return null;
        }

        $ttl = $response->get('properties.TTL');

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

    /**
     * `ARecords` or `AAAARecords`.
     */
    private function recordsKey(RecordType $type): string
    {
        return $type->value . 'Records';
    }

    /**
     * `ipv4Address` or `ipv6Address`.
     */
    private function addressKey(RecordType $type): string
    {
        return $type->isIpv6() ? 'ipv6Address' : 'ipv4Address';
    }
}
