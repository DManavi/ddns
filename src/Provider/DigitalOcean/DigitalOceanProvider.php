<?php

declare(strict_types=1);

namespace Ddns\Provider\DigitalOcean;

use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Http\RestClient;

/**
 * DigitalOcean DNS driver.
 *
 * @see https://docs.digitalocean.com/reference/api/digitalocean/#tag/Domain-Records
 */
final class DigitalOceanProvider implements DnsProvider
{
    public const DRIVER = 'digitalocean';
    public const BASE_URI = 'https://api.digitalocean.com/v2';

    private const PAGE_SIZE = 200;

    public function __construct(private readonly RestClient $client)
    {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $response = $this->client->get($this->recordsPath($hostname), [
            'type' => $type->value,
            'name' => $hostname->fqdn(),
            'per_page' => self::PAGE_SIZE,
        ]);

        if ($response->isNotFound()) {
            throw ZoneNotFound::for(self::DRIVER, $hostname->zone());
        }

        $response->assertSuccessful('list DNS records');

        // The name/type filter is applied server-side, but the result is still
        // paginated. The previous implementation of this project read only the
        // first page and silently reported "no record" for large zones, so every
        // page is walked here.
        while (true) {
            foreach ($response->listOf('domain_records') as $raw) {
                $record = $this->toRecord($hostname, $raw);

                if ($record !== null && $record->type() === $type && $this->namesMatch($hostname, $raw)) {
                    return $record;
                }
            }

            $next = $response->get('links.pages.next');

            if (!is_string($next) || $next === '') {
                return null;
            }

            $response = $this->client->follow($next)->assertSuccessful('list DNS records');
        }
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        $response = $this->client
            ->post($this->recordsPath($hostname), [
                'type' => $type->value,
                'name' => $hostname->name(),
                'data' => $ip->value(),
                'ttl' => $ttl,
            ])
            ->assertSuccessful('create DNS record');

        $raw = $response->get('domain_record');

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

        $this->client
            ->put($path, [
                'type' => $record->type()->value,
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
     * @param array<array-key, mixed> $raw
     */
    private function namesMatch(Hostname $hostname, array $raw): bool
    {
        $name = $raw['name'] ?? null;

        return is_string($name) && $hostname->matchesProviderName($name);
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
