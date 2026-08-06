<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider;

use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Cloudflare\CloudflareProvider;
use Ddns\Tests\Support\Fixtures;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class CloudflareProviderTest extends TestCase
{
    private ?MockHttpClient $http = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    public function testResolvesTheZoneIdThenFindsTheRecord(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => true, 'result' => [
                ['id' => 'rec-1', 'type' => 'A', 'name' => 'home.example.com', 'content' => '203.0.113.7', 'ttl' => 60],
            ]]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertSame('rec-1', $record?->id());
        self::assertStringContainsString('/zones?name=example.com', (string) $this->http()->request(0)->getUri());
        self::assertStringContainsString('/zones/zone-123/dns_records', (string) $this->http()->request(1)->getUri());
    }

    /**
     * Zone IDs are stable, so paying for the lookup once per process rather
     * than once per update matters on a short watch interval.
     */
    public function testMemoisesTheZoneIdAcrossCalls(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => true, 'result' => []])
            ->queue(200, ['success' => true, 'result' => []]);

        $provider = $this->provider();
        $provider->findRecord($this->hostname(), RecordType::A);
        $provider->findRecord($this->hostname(), RecordType::A);

        self::assertSame(3, $this->http()->requestCount(), 'The zone should be looked up only once.');
    }

    /**
     * A token scoped to a single zone cannot list zones, so an explicit ID has
     * to skip the lookup entirely.
     */
    public function testSkipsTheZoneLookupWhenAnIdIsConfigured(): void
    {
        $this->http()->queue(200, ['success' => true, 'result' => []]);

        $provider = new CloudflareProvider(
            Fixtures::restClient($this->http(), CloudflareProvider::DRIVER, CloudflareProvider::BASE_URI),
            'preset-zone',
        );

        $provider->findRecord($this->hostname(), RecordType::A);

        self::assertSame(1, $this->http()->requestCount());
        self::assertStringContainsString('/zones/preset-zone/dns_records', (string) $this->http()->request(0)->getUri());
    }

    public function testThrowsZoneNotFoundWhenTheZoneIsAbsent(): void
    {
        $this->http()->queue(200, ['success' => true, 'result' => []]);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testIgnoresAZoneWhoseNameDoesNotMatch(): void
    {
        $this->http()->queue(200, ['success' => true, 'result' => [['id' => 'other', 'name' => 'example.org']]]);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * Cloudflare can answer HTTP 200 with `success: false`, so the envelope has
     * to be inspected as well as the status code.
     */
    public function testTreatsSuccessFalseAsAFailureDespiteHttp200(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => false, 'errors' => [['message' => 'Invalid DNS record']], 'result' => null]);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('Invalid DNS record');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testCreatesARecordUsingTheFullyQualifiedName(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => true, 'result' => [
                'id' => 'rec-9', 'type' => 'A', 'name' => 'home.example.com', 'content' => '203.0.113.7', 'ttl' => 60,
            ]]);

        $record = $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('rec-9', $record->id());
        self::assertSame(
            ['type' => 'A', 'name' => 'home.example.com', 'content' => '203.0.113.7', 'ttl' => 60],
            $this->http()->bodyOf(1),
        );
    }

    /**
     * PATCH rather than PUT, so a proxied record keeps its `proxied` flag.
     */
    public function testUpdatesWithAPartialPatch(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => true, 'result' => [
                'id' => 'rec-1', 'type' => 'A', 'name' => 'home.example.com', 'content' => '203.0.113.9', 'ttl' => 60,
            ]]);

        $existing = new DnsRecord('rec-1', $this->hostname(), RecordType::A, '198.51.100.1', 60);

        $updated = $this->provider()->updateRecord($existing, IpAddress::fromString('203.0.113.9'), 60);

        self::assertSame('203.0.113.9', $updated->value());
        self::assertSame('PATCH', $this->http()->request(1)->getMethod());
        self::assertSame(['content' => '203.0.113.9', 'ttl' => 60], $this->http()->bodyOf(1));
    }

    /**
     * Cloudflare uses TTL 1 to mean "automatic". Reporting it verbatim would
     * make the record look permanently out of date and rewrite it every poll.
     */
    public function testTreatsTheAutomaticTtlAsUnknownRatherThanAMismatch(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => true, 'result' => [
                ['id' => 'rec-1', 'type' => 'A', 'name' => 'home.example.com', 'content' => '203.0.113.7', 'ttl' => 1],
            ]]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertNull($record?->ttl());
        self::assertTrue($record?->isUpToDate(IpAddress::fromString('203.0.113.7'), 300));
    }

    public function testMatchesTheApexByItsFullyQualifiedName(): void
    {
        $this->http()
            ->queue(200, ['success' => true, 'result' => [['id' => 'zone-123', 'name' => 'example.com']]])
            ->queue(200, ['success' => true, 'result' => [
                ['id' => 'apex', 'type' => 'A', 'name' => 'example.com', 'content' => '203.0.113.7', 'ttl' => 300],
            ]]);

        $record = $this->provider()->findRecord(Hostname::create('example.com', '@'), RecordType::A);

        self::assertSame('apex', $record?->id());
    }

    private function provider(): CloudflareProvider
    {
        return new CloudflareProvider(
            Fixtures::restClient($this->http(), CloudflareProvider::DRIVER, CloudflareProvider::BASE_URI),
        );
    }

    private function hostname(): Hostname
    {
        return Hostname::create('example.com', 'home');
    }
}
