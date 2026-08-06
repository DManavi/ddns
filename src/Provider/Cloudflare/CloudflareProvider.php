<?php

declare(strict_types=1);

namespace Ddns\Provider\Cloudflare;

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
 * Cloudflare DNS driver.
 *
 * Cloudflare addresses records by zone ID rather than zone name, so every
 * operation needs a name-to-ID lookup first. Those IDs are stable, so they are
 * memoised for the lifetime of the instance and the extra round trip is paid
 * once per zone per process instead of once per update.
 *
 * @see https://developers.cloudflare.com/api/operations/dns-records-for-a-zone-list-dns-records
 */
final class CloudflareProvider implements DnsProvider
{
    public const DRIVER = 'cloudflare';
    public const BASE_URI = 'https://api.cloudflare.com/client/v4';

    /** @var array<string, string> zone name => zone ID */
    private array $zoneIds = [];

    public function __construct(
        private readonly RestClient $client,
        private readonly ?string $configuredZoneId = null,
    ) {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $zoneId = $this->zoneId($hostname->zone());

        $response = $this->client->get(
            sprintf('/zones/%s/dns_records', rawurlencode($zoneId)),
            ['type' => $type->value, 'name' => $hostname->fqdn(), 'per_page' => 100],
        );

        $this->assertOk($response, 'list DNS records');

        foreach ($response->listOf('result') as $raw) {
            $record = $this->toRecord($hostname, $raw);
            $name = $raw['name'] ?? null;

            if (
                $record !== null
                && $record->type() === $type
                && is_string($name)
                && $hostname->matchesProviderName($name)
            ) {
                return $record;
            }
        }

        return null;
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        $zoneId = $this->zoneId($hostname->zone());

        $response = $this->client->post(
            sprintf('/zones/%s/dns_records', rawurlencode($zoneId)),
            [
                'type' => $type->value,
                'name' => $hostname->fqdn(),
                'content' => $ip->value(),
                'ttl' => $ttl,
            ],
        );

        $this->assertOk($response, 'create DNS record');

        $raw = $response->get('result');

        if (!is_array($raw)) {
            throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'create DNS record');
        }

        return $this->toRecord($hostname, $raw)
            ?? throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'create DNS record');
    }

    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord
    {
        $zoneId = $this->zoneId($record->hostname()->zone());

        // PATCH rather than PUT so the record's other settings - most
        // importantly `proxied` - are left exactly as the user configured them.
        $response = $this->client->patch(
            sprintf('/zones/%s/dns_records/%s', rawurlencode($zoneId), rawurlencode($record->id())),
            ['content' => $ip->value(), 'ttl' => $ttl],
        );

        $this->assertOk($response, 'update DNS record');

        return $record->withValue($ip, $ttl);
    }

    private function zoneId(string $zone): string
    {
        if ($this->configuredZoneId !== null && $this->configuredZoneId !== '') {
            return $this->configuredZoneId;
        }

        if (isset($this->zoneIds[$zone])) {
            return $this->zoneIds[$zone];
        }

        $response = $this->client->get('/zones', ['name' => $zone, 'per_page' => 50]);

        $this->assertOk($response, 'look up zone');

        foreach ($response->listOf('result') as $raw) {
            $name = $raw['name'] ?? null;
            $id = $raw['id'] ?? null;

            if (is_string($name) && is_string($id) && strcasecmp(rtrim($name, '.'), $zone) === 0) {
                return $this->zoneIds[$zone] = $id;
            }
        }

        throw ZoneNotFound::for(self::DRIVER, $zone);
    }

    /**
     * Cloudflare can answer HTTP 200 with `"success": false`, so the envelope
     * has to be checked in addition to the status code.
     */
    private function assertOk(RestResponse $response, string $operation): void
    {
        $response->assertSuccessful($operation);

        if ($response->get('success') === false) {
            throw RecordOperationFailed::for(self::DRIVER, $operation, $response->errorDetail());
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function toRecord(Hostname $hostname, array $raw): ?DnsRecord
    {
        $id = $raw['id'] ?? null;
        $typeName = $raw['type'] ?? null;
        $content = $raw['content'] ?? null;

        if (!is_string($id) || !is_string($typeName) || !is_string($content)) {
            return null;
        }

        $type = RecordType::tryFrom(strtoupper($typeName));

        if ($type === null) {
            return null;
        }

        $ttl = $raw['ttl'] ?? null;

        // Cloudflare uses TTL 1 to mean "automatic". Reporting it as null keeps
        // DnsRecord::hasTtl() from treating an automatic TTL as a mismatch and
        // rewriting the record on every single poll.
        $normalisedTtl = is_int($ttl) && $ttl > 1 ? $ttl : null;

        return new DnsRecord($id, $hostname, $type, $content, $normalisedTtl);
    }
}
