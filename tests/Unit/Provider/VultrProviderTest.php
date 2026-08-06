<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider;

use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Vultr\VultrProvider;
use Ddns\Tests\Support\Fixtures;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class VultrProviderTest extends TestCase
{
    private ?MockHttpClient $http = null;

    private ?VultrProvider $provider = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    private function provider(): VultrProvider
    {
        return $this->provider ??= new VultrProvider(
            Fixtures::restClient($this->http(), VultrProvider::DRIVER, VultrProvider::BASE_URI),
        );
    }

    public function testFindsAnExistingRecord(): void
    {
        $this->http()->queue(200, [
            'records' => [
                ['id' => 'rec-1', 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 120],
            ],
            'meta' => ['total' => 1, 'links' => ['next' => '', 'prev' => '']],
        ]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertNotNull($record);
        self::assertSame('rec-1', $record->id());
        self::assertSame('203.0.113.7', $record->value());
        self::assertSame(120, $record->ttl());
    }

    /**
     * Vultr offers no server-side name or type filter, so the zone has to be
     * walked and matched locally.
     */
    public function testFollowsTheCursorAcrossPages(): void
    {
        $this->http()
            ->queue(200, [
                'records' => [
                    ['id' => 'a', 'type' => 'A', 'name' => 'other', 'data' => '198.51.100.1', 'ttl' => 120],
                ],
                'meta' => ['links' => ['next' => 'CURSOR2']],
            ])
            ->queue(200, [
                'records' => [
                    ['id' => 'b', 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 120],
                ],
                'meta' => ['links' => ['next' => '']],
            ]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertSame('b', $record?->id());
        self::assertSame(2, $this->http()->requestCount());
        self::assertStringContainsString('cursor=CURSOR2', (string) $this->http()->request(1)->getUri());
    }

    public function testStopsWhenTheCursorRepeatsItself(): void
    {
        $this->http()
            ->queue(200, ['records' => [], 'meta' => ['links' => ['next' => 'SAME']]])
            ->queue(200, ['records' => [], 'meta' => ['links' => ['next' => 'SAME']]]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
        self::assertSame(2, $this->http()->requestCount(), 'A repeating cursor must not loop forever.');
    }

    /**
     * Vultr represents the apex as an empty name rather than `@`.
     */
    public function testMatchesTheApexExpressedAsAnEmptyName(): void
    {
        $this->http()->queue(200, [
            'records' => [
                ['id' => 'apex', 'type' => 'A', 'name' => '', 'data' => '203.0.113.7', 'ttl' => 300],
            ],
            'meta' => ['links' => ['next' => '']],
        ]);

        $record = $this->provider()->findRecord(Hostname::create('example.com', '@'), RecordType::A);

        self::assertSame('apex', $record?->id());
    }

    public function testCreatesARecord(): void
    {
        $this->http()->queue(201, [
            'record' => ['id' => 'new-1', 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 60],
        ]);

        $record = $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('new-1', $record->id());
        self::assertSame('POST', $this->http()->request(0)->getMethod());
        self::assertSame(
            ['name' => 'home', 'type' => 'A', 'data' => '203.0.113.7', 'ttl' => 60],
            $this->http()->bodyOf(0),
        );
    }

    public function testCreateSendsAnEmptyNameForTheApex(): void
    {
        $this->http()->queue(201, [
            'record' => ['id' => 'apex', 'type' => 'A', 'name' => '', 'data' => '203.0.113.7', 'ttl' => 60],
        ]);

        $this->provider()->createRecord(
            Hostname::create('example.com', '@'),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('', $this->http()->bodyOf(0)['name'] ?? null);
    }

    /**
     * Vultr answers a successful PATCH with 204 and no body, so the updated
     * record has to be reconstructed locally rather than parsed back.
     */
    public function testUpdatesARecordDespiteAnEmpty204Response(): void
    {
        $this->http()->queue(204, '');

        $existing = new DnsRecord('rec-1', $this->hostname(), RecordType::A, '198.51.100.1', 120);

        $updated = $this->provider()->updateRecord($existing, IpAddress::fromString('203.0.113.7'), 60);

        self::assertSame('203.0.113.7', $updated->value());
        self::assertSame(60, $updated->ttl());
        self::assertSame('rec-1', $updated->id());
        self::assertSame('PATCH', $this->http()->request(0)->getMethod());
        self::assertSame(
            'https://api.vultr.com/v2/domains/example.com/records/rec-1',
            (string) $this->http()->request(0)->getUri(),
        );
    }

    public function testTranslatesA404IntoZoneNotFound(): void
    {
        $this->http()->queue(404, ['error' => 'Domain not found']);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    private function hostname(): Hostname
    {
        return Hostname::create('example.com', 'home');
    }
}
