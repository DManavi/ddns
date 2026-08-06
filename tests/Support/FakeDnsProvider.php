<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\ProviderException;
use Ddns\Domain\Provider\ProviderLocator;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;

/**
 * In-memory DNS provider that records every call.
 *
 * Lets the updater's decision matrix be tested precisely - in particular that
 * an already-correct record results in no write at all.
 */
final class FakeDnsProvider implements DnsProvider, ProviderLocator
{
    /** @var array<string, DnsRecord> */
    private array $records = [];

    /** @var list<string> */
    public array $calls = [];

    private ?ProviderException $failure = null;

    private int $nextId = 1;

    public function seed(Hostname $hostname, RecordType $type, string $value, ?int $ttl = 300): self
    {
        $record = new DnsRecord('seeded-' . $this->nextId++, $hostname, $type, $value, $ttl);
        $this->records[$this->key($hostname, $type)] = $record;

        return $this;
    }

    public function failWith(ProviderException $exception): self
    {
        $this->failure = $exception;

        return $this;
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function forProvider(string $providerName): DnsProvider
    {
        return $this;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $this->calls[] = sprintf('find:%s:%s', $hostname->fqdn(), $type->value);

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->records[$this->key($hostname, $type)] ?? null;
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        $this->calls[] = sprintf('create:%s:%s:%s:%d', $hostname->fqdn(), $type->value, $ip->value(), $ttl);

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $record = new DnsRecord('created-' . $this->nextId++, $hostname, $type, $ip->value(), $ttl);
        $this->records[$this->key($hostname, $type)] = $record;

        return $record;
    }

    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord
    {
        $this->calls[] = sprintf('update:%s:%s:%s:%d', $record->hostname()->fqdn(), $record->id(), $ip->value(), $ttl);

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $updated = $record->withValue($ip, $ttl);
        $this->records[$this->key($record->hostname(), $record->type())] = $updated;

        return $updated;
    }

    /**
     * @return list<string>
     */
    public function writeCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => str_starts_with($call, 'create:') || str_starts_with($call, 'update:'),
        ));
    }

    public function currentValue(Hostname $hostname, RecordType $type): ?string
    {
        return ($this->records[$this->key($hostname, $type)] ?? null)?->value();
    }

    private function key(Hostname $hostname, RecordType $type): string
    {
        return $hostname->fqdn() . '|' . $type->value;
    }
}
