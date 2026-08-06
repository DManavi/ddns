<?php

declare(strict_types=1);

namespace Ddns\Provider\Vultr;

use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Http\RestClient;

/**
 * Vultr DNS driver.
 *
 * Vultr has no server-side filter on record name or type, so the zone is walked
 * cursor by cursor and matched locally.
 *
 * @see https://www.vultr.com/api/#tag/dns
 */
final class VultrProvider implements DnsProvider
{
    public const DRIVER = 'vultr';
    public const BASE_URI = 'https://api.vultr.com/v2';

    private const PAGE_SIZE = 500;

    /** Guards against a malformed cursor sending us round forever. */
    private const MAX_PAGES = 100;

    public function __construct(private readonly RestClient $client)
    {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $query = ['per_page' => self::PAGE_SIZE];

            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }

            $response = $this->client->get($this->recordsPath($hostname), $query);

            if ($response->isNotFound()) {
                throw ZoneNotFound::for(self::DRIVER, $hostname->zone());
            }

            $response->assertSuccessful('list DNS records');

            foreach ($response->listOf('records') as $raw) {
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

            $next = $response->get('meta.links.next');

            if (!is_string($next) || $next === '' || $next === $cursor) {
                return null;
            }

            $cursor = $next;
        }

        return null;
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        $response = $this->client
            ->post($this->recordsPath($hostname), [
                'name' => $this->providerName($hostname),
                'type' => $type->value,
                'data' => $ip->value(),
                'ttl' => $ttl,
            ])
            ->assertSuccessful('create DNS record');

        $raw = $response->get('record');

        if (!is_array($raw)) {
            throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'create DNS record');
        }

        return $this->toRecord($hostname, $raw)
            ?? throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'create DNS record');
    }

    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord
    {
        $path = sprintf(
            '%s/%s',
            $this->recordsPath($record->hostname()),
            rawurlencode($record->id()),
        );

        // Vultr answers a successful PATCH with 204 and no body, so the updated
        // record is reconstructed locally rather than parsed back.
        $this->client
            ->patch($path, [
                'name' => $this->providerName($record->hostname()),
                'data' => $ip->value(),
                'ttl' => $ttl,
            ])
            ->assertSuccessful('update DNS record');

        return $record->withValue($ip, $ttl);
    }

    private function recordsPath(Hostname $hostname): string
    {
        return sprintf('/domains/%s/records', rawurlencode($hostname->zone()));
    }

    /**
     * Vultr represents the zone apex as an empty name rather than `@`.
     */
    private function providerName(Hostname $hostname): string
    {
        return $hostname->isApex() ? '' : $hostname->name();
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function toRecord(Hostname $hostname, array $raw): ?DnsRecord
    {
        $id = $raw['id'] ?? null;
        $typeName = $raw['type'] ?? null;
        $data = $raw['data'] ?? null;

        if ((!is_int($id) && !is_string($id)) || !is_string($typeName) || !is_string($data)) {
            return null;
        }

        $type = RecordType::tryFrom(strtoupper($typeName));

        if ($type === null) {
            return null;
        }

        $ttl = $raw['ttl'] ?? null;

        return new DnsRecord(
            (string) $id,
            $hostname,
            $type,
            $data,
            is_int($ttl) ? $ttl : null,
        );
    }
}
