<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider;

use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Domain\Provider\Exception\RateLimited;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\DigitalOcean\DigitalOceanProvider;
use Ddns\Tests\Support\Fixtures;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class DigitalOceanProviderTest extends TestCase
{
    private ?MockHttpClient $http = null;

    private ?DigitalOceanProvider $provider = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    private function provider(): DigitalOceanProvider
    {
        return $this->provider ??= new DigitalOceanProvider(
            Fixtures::restClient($this->http(), DigitalOceanProvider::DRIVER, DigitalOceanProvider::BASE_URI),
        );
    }

    public function testFindsAnExistingRecord(): void
    {
        $this->http()->queue(200, [
            'domain_records' => [
                ['id' => 42, 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 60],
            ],
        ]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertInstanceOf(DnsRecord::class, $record);
        self::assertSame('42', $record->id());
        self::assertSame('203.0.113.7', $record->value());
        self::assertSame(60, $record->ttl());
    }

    public function testSendsTheExpectedLookupRequest(): void
    {
        $this->http()->queue(200, ['domain_records' => []]);

        $this->provider()->findRecord($this->hostname(), RecordType::A);

        $request = $this->http()->request(0);
        $uri = (string) $request->getUri();

        self::assertSame('GET', $request->getMethod());
        self::assertStringStartsWith('https://api.digitalocean.com/v2/domains/example.com/records?', $uri);
        self::assertStringContainsString('type=A', $uri);
        self::assertStringContainsString('name=home.example.com', $uri);
        self::assertSame('Bearer secret-api-key', $request->getHeaderLine('Authorization'));
    }

    public function testReturnsNullWhenNoRecordMatches(): void
    {
        $this->http()->queue(200, ['domain_records' => []]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    /**
     * A record for a different name must never be mistaken for ours, even if
     * the API returns it.
     */
    public function testIgnoresRecordsForOtherNames(): void
    {
        $this->http()->queue(200, [
            'domain_records' => [
                ['id' => 1, 'type' => 'A', 'name' => 'office', 'data' => '198.51.100.1', 'ttl' => 60],
            ],
        ]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    public function testIgnoresRecordsOfADifferentType(): void
    {
        $this->http()->queue(200, [
            'domain_records' => [
                ['id' => 1, 'type' => 'AAAA', 'name' => 'home', 'data' => '2001:db8::1', 'ttl' => 60],
            ],
        ]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    /**
     * The previous Python implementation of this project read only the first
     * page, so a record in a large zone looked like it did not exist and was
     * duplicated on every run.
     */
    public function testFollowsPaginationToFindARecordOnALaterPage(): void
    {
        $this->http()
            ->queue(200, [
                'domain_records' => [
                    ['id' => 1, 'type' => 'A', 'name' => 'other', 'data' => '198.51.100.1', 'ttl' => 60],
                ],
                'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/domains/example.com/records?page=2']],
            ])
            ->queue(200, [
                'domain_records' => [
                    ['id' => 99, 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 60],
                ],
                'links' => ['pages' => []],
            ]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertSame('99', $record?->id());
        self::assertSame(2, $this->http()->requestCount());
        self::assertStringContainsString('page=2', (string) $this->http()->request(1)->getUri());
    }

    public function testStopsPaginatingWhenThereIsNoNextLink(): void
    {
        $this->http()->queue(200, ['domain_records' => [], 'links' => ['pages' => ['prev' => 'x']]]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
        self::assertSame(1, $this->http()->requestCount());
    }

    public function testMatchesTheApexRecord(): void
    {
        $this->http()->queue(200, [
            'domain_records' => [
                ['id' => 7, 'type' => 'A', 'name' => '@', 'data' => '203.0.113.7', 'ttl' => 300],
            ],
        ]);

        $record = $this->provider()->findRecord(Hostname::create('example.com', '@'), RecordType::A);

        self::assertSame('7', $record?->id());
    }

    public function testCreatesARecord(): void
    {
        $this->http()->queue(201, [
            'domain_record' => ['id' => 5, 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 60],
        ]);

        $record = $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('5', $record->id());
        self::assertSame('POST', $this->http()->request(0)->getMethod());
        self::assertSame(
            'https://api.digitalocean.com/v2/domains/example.com/records',
            (string) $this->http()->request(0)->getUri(),
        );
        self::assertSame(
            ['type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 60],
            $this->http()->bodyOf(0),
        );
    }

    /**
     * DigitalOcean expects the relative name, with `@` for the apex.
     */
    public function testCreateUsesTheRelativeNameForTheApex(): void
    {
        $this->http()->queue(201, [
            'domain_record' => ['id' => 5, 'type' => 'A', 'name' => '@', 'data' => '203.0.113.7', 'ttl' => 60],
        ]);

        $this->provider()->createRecord(
            Hostname::create('example.com', '@'),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('@', $this->http()->bodyOf(0)['name'] ?? null);
    }

    public function testUpdatesARecord(): void
    {
        $this->http()->queue(200, [
            'domain_record' => ['id' => 42, 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.9', 'ttl' => 120],
        ]);

        $existing = new DnsRecord('42', $this->hostname(), RecordType::A, '203.0.113.7', 60);

        $updated = $this->provider()->updateRecord($existing, IpAddress::fromString('203.0.113.9'), 120);

        self::assertSame('203.0.113.9', $updated->value());
        self::assertSame(120, $updated->ttl());
        self::assertSame('PUT', $this->http()->request(0)->getMethod());
        self::assertSame(
            'https://api.digitalocean.com/v2/domains/example.com/records/42',
            (string) $this->http()->request(0)->getUri(),
        );
        self::assertSame(['type' => 'A', 'data' => '203.0.113.9', 'ttl' => 120], $this->http()->bodyOf(0));
    }

    public function testTranslatesA404IntoZoneNotFound(): void
    {
        $this->http()->queue(404, ['id' => 'not_found', 'message' => 'The resource you were accessing could not be found.']);

        $this->expectException(ZoneNotFound::class);
        $this->expectExceptionMessage('Zone "example.com" was not found');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testTranslatesA401IntoAuthenticationFailed(): void
    {
        $this->http()->queue(401, ['id' => 'unauthorized', 'message' => 'Unable to authenticate you.']);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('Unable to authenticate you.');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testTranslatesA429IntoRateLimited(): void
    {
        $this->http()->queue(429, ['message' => 'too many requests'], ['Retry-After' => '30']);

        try {
            $this->provider()->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected a RateLimited exception.');
        } catch (RateLimited $e) {
            self::assertSame(30, $e->retryAfterSeconds());
            self::assertSame(429, $e->suggestedHttpStatus());
        }
    }

    public function testSurfacesAnUnexpectedServerError(): void
    {
        $this->http()->queue(500, ['message' => 'internal error']);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testRejectsACreateResponseItCannotParse(): void
    {
        $this->http()->queue(201, ['unexpected' => true]);

        $this->expectException(RecordOperationFailed::class);

        $this->provider()->createRecord($this->hostname(), RecordType::A, IpAddress::fromString('203.0.113.7'), 60);
    }

    private function hostname(): Hostname
    {
        return Hostname::create('example.com', 'home');
    }
}
