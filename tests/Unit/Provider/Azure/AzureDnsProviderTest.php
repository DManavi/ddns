<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider\Azure;

use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Domain\Provider\Exception\RateLimited;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Azure\AzureDnsProvider;
use Ddns\Tests\Support\Fixtures;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AzureDnsProviderTest extends TestCase
{
    private const SUBSCRIPTION = '00000000-0000-0000-0000-000000000000';
    private const RESOURCE_GROUP = 'my-rg';

    private ?MockHttpClient $http = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    private function provider(): AzureDnsProvider
    {
        return new AzureDnsProvider(
            Fixtures::restClientWithHeaders(
                $this->http(),
                AzureDnsProvider::DRIVER,
                AzureDnsProvider::MANAGEMENT_ENDPOINT,
                static fn (): array => ['Authorization' => 'Bearer test-token'],
            ),
            self::SUBSCRIPTION,
            self::RESOURCE_GROUP,
        );
    }

    private function hostname(string $name = 'home'): Hostname
    {
        return Hostname::create('example.com', $name);
    }

    // ----------------------------------------------------------- find record

    public function testFindsAnExistingARecord(): void
    {
        $this->http()->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 60));

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertNotNull($record);
        self::assertSame('203.0.113.7', $record->value());
        self::assertSame(60, $record->ttl());
    }

    public function testSendsTheExpectedLookupRequest(): void
    {
        $this->http()->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 60));

        $this->provider()->findRecord($this->hostname(), RecordType::A);

        $request = $this->http()->request(0);
        $uri = (string) $request->getUri();

        self::assertSame('GET', $request->getMethod());
        self::assertStringContainsString(
            '/subscriptions/' . self::SUBSCRIPTION . '/resourceGroups/my-rg'
            . '/providers/Microsoft.Network/dnsZones/example.com/A/home',
            $uri,
        );
        self::assertStringContainsString('api-version=2018-05-01', $uri);
    }

    /**
     * The rotating token has to be resolved per request, not baked in at
     * construction, or a long-running watch would keep sending a stale one.
     */
    public function testAuthenticatesWithTheCurrentBearerToken(): void
    {
        $this->http()->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 60));

        $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertSame('Bearer test-token', $this->http()->request(0)->getHeaderLine('Authorization'));
    }

    /**
     * A record that does not exist yet is the normal case on first run.
     */
    public function testReturnsNullWhenTheRecordDoesNotExist(): void
    {
        $this->http()->queue(404, ['error' => ['code' => 'NotFound', 'message' => 'Not found.']]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    /**
     * A 404 is ambiguous. Treating a wrong zone or resource group as "no
     * record" would turn a typo into a confusing write failure later.
     */
    #[DataProvider('containerMissingCodes')]
    public function testDistinguishesAMissingZoneFromAMissingRecord(string $code): void
    {
        $this->http()->queue(404, ['error' => ['code' => $code, 'message' => 'gone']]);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function containerMissingCodes(): iterable
    {
        yield 'resource group' => ['ResourceGroupNotFound'];
        yield 'subscription' => ['SubscriptionNotFound'];
        yield 'zone' => ['ZoneNotFound'];
        yield 'parent' => ['ParentResourceNotFound'];
    }

    public function testTheMissingZoneErrorNamesTheResourceGroup(): void
    {
        $this->http()->queue(404, ['error' => ['code' => 'ResourceGroupNotFound', 'message' => 'gone']]);

        try {
            $this->provider()->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected a ZoneNotFound exception.');
        } catch (ZoneNotFound $e) {
            self::assertStringContainsString('example.com', $e->getMessage());
            self::assertStringContainsString('my-rg', $e->getMessage());
        }
    }

    public function testFindsAnAaaaRecordUsingItsOwnBodyShape(): void
    {
        $this->http()->queue(200, $this->recordSet('AAAA', 'ipv6Address', '2001:db8::1', 120));

        $record = $this->provider()->findRecord($this->hostname(), RecordType::AAAA);

        self::assertSame('2001:db8::1', $record?->value());
    }

    /**
     * The record type appears in the URL path as well as the body.
     */
    public function testLooksUpAaaaOnItsOwnPath(): void
    {
        $this->http()->queue(200, $this->recordSet('AAAA', 'ipv6Address', '2001:db8::1', 120));

        $this->provider()->findRecord($this->hostname(), RecordType::AAAA);

        self::assertStringContainsString('/AAAA/home', (string) $this->http()->request(0)->getUri());
    }

    public function testLooksUpTheApex(): void
    {
        $this->http()->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 300));

        $record = $this->provider()->findRecord(Hostname::create('example.com', '@'), RecordType::A);

        self::assertSame('203.0.113.7', $record?->value());
        self::assertStringContainsString('/A/%40', (string) $this->http()->request(0)->getUri());
    }

    public function testReturnsNullForARecordSetWithNoAddresses(): void
    {
        $this->http()->queue(200, ['name' => 'home', 'properties' => ['TTL' => 60, 'ARecords' => []]]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    /**
     * Replacing an alias would silently detach a Traffic Manager profile or CDN
     * endpoint.
     */
    public function testRefusesToTouchAnAliasRecord(): void
    {
        $this->http()->queue(200, [
            'name' => 'home',
            'properties' => [
                'TTL' => 60,
                'targetResource' => [
                    'id' => '/subscriptions/x/resourceGroups/y/providers/'
                        . 'Microsoft.Network/trafficManagerProfiles/tm',
                ],
            ],
        ]);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('is an alias record');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * A multi-value set reports its first value, so it compares as out of date
     * and the next write collapses it to a single address.
     */
    public function testReportsTheFirstValueOfAMultiValueRecordSet(): void
    {
        $this->http()->queue(200, ['name' => 'home', 'properties' => [
            'TTL' => 60,
            'ARecords' => [['ipv4Address' => '203.0.113.7'], ['ipv4Address' => '203.0.113.8']],
        ]]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertNotNull($record);
        self::assertSame('203.0.113.7', $record->value());
        self::assertFalse($record->isUpToDate(IpAddress::fromString('203.0.113.8'), 60));
    }

    public function testAnUpToDateRecordCompactsAsUnchanged(): void
    {
        $this->http()->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 60));

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertTrue($record?->isUpToDate(IpAddress::fromString('203.0.113.7'), 60));
    }

    // --------------------------------------------------------- create/update

    public function testCreatesAnARecord(): void
    {
        $this->http()->queue(201, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 60));

        $record = $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('203.0.113.7', $record->value());

        $request = $this->http()->request(0);

        self::assertSame('PUT', $request->getMethod());
        self::assertStringContainsString('api-version=2018-05-01', (string) $request->getUri());
        self::assertSame(
            ['properties' => ['TTL' => 60, 'ARecords' => [['ipv4Address' => '203.0.113.7']]]],
            $this->http()->bodyOf(0),
        );
    }

    /**
     * The body key and the value key both change with the record type; there is
     * no shared shape between A and AAAA.
     */
    public function testCreatesAnAaaaRecordWithItsOwnBodyShape(): void
    {
        $this->http()->queue(201, $this->recordSet('AAAA', 'ipv6Address', '2001:db8::1', 60));

        $this->provider()->createRecord(
            $this->hostname(),
            RecordType::AAAA,
            IpAddress::fromString('2001:db8::1'),
            60,
        );

        self::assertSame(
            ['properties' => ['TTL' => 60, 'AAAARecords' => [['ipv6Address' => '2001:db8::1']]]],
            $this->http()->bodyOf(0),
        );
    }

    /**
     * PUT is create-or-update, so both operations must issue the same request.
     */
    public function testUpdateIssuesTheSamePutAsCreate(): void
    {
        $this->http()
            ->queue(201, $this->recordSet('A', 'ipv4Address', '203.0.113.9', 120))
            ->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.9', 120));

        $provider = $this->provider();
        $ip = IpAddress::fromString('203.0.113.9');

        $provider->createRecord($this->hostname(), RecordType::A, $ip, 120);
        $provider->updateRecord(
            new DnsRecord('id', $this->hostname(), RecordType::A, '198.51.100.1', 120),
            $ip,
            120,
        );

        self::assertSame($this->http()->bodyOf(0), $this->http()->bodyOf(1));
        self::assertSame(
            (string) $this->http()->request(0)->getUri(),
            (string) $this->http()->request(1)->getUri(),
        );
    }

    public function testUpdateReturnsTheNewValue(): void
    {
        $this->http()->queue(200, $this->recordSet('A', 'ipv4Address', '203.0.113.9', 300));

        $updated = $this->provider()->updateRecord(
            new DnsRecord('id', $this->hostname(), RecordType::A, '198.51.100.1', 60),
            IpAddress::fromString('203.0.113.9'),
            300,
        );

        self::assertSame('203.0.113.9', $updated->value());
        self::assertSame(300, $updated->ttl());
    }

    /**
     * Azure normally echoes the record back, but the write is what matters, so
     * an unexpected body must not fail the operation.
     */
    public function testStillReportsSuccessWhenTheResponseBodyIsUnhelpful(): void
    {
        $this->http()->queue(200, []);

        $record = $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('203.0.113.7', $record->value());
        self::assertSame(60, $record->ttl());
    }

    public function testWritesToTheApex(): void
    {
        $this->http()->queue(201, $this->recordSet('A', 'ipv4Address', '203.0.113.7', 60));

        $this->provider()->createRecord(
            Hostname::create('example.com', '@'),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertStringContainsString('/A/%40?', (string) $this->http()->request(0)->getUri());
    }

    public function testAMissingZoneOnWriteIsReportedAsSuch(): void
    {
        $this->http()->queue(404, ['error' => ['code' => 'ResourceGroupNotFound', 'message' => 'gone']]);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );
    }

    // --------------------------------------------------------- error mapping

    public function testAnExpiredTokenIsAnAuthenticationFailure(): void
    {
        $this->http()->queue(401, ['error' => ['code' => 'ExpiredAuthenticationToken', 'message' => 'expired']]);

        $this->expectException(AuthenticationFailed::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * The most common Azure misconfiguration: valid credentials without the
     * DNS Zone Contributor role.
     */
    public function testMissingRbacIsAnAuthenticationFailure(): void
    {
        $this->http()->queue(403, ['error' => [
            'code' => 'AuthorizationFailed',
            'message' => 'does not have authorization to perform action',
        ]]);

        try {
            $this->provider()->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected an AuthenticationFailed exception.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(502, $e->suggestedHttpStatus());
        }
    }

    public function testThrottlingIsReportedAsRateLimited(): void
    {
        $this->http()->queue(429, ['error' => ['code' => 'TooManyRequests', 'message' => 'slow down']], [
            'Retry-After' => '30',
        ]);

        try {
            $this->provider()->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected a RateLimited exception.');
        } catch (RateLimited $e) {
            self::assertSame(30, $e->retryAfterSeconds());
            self::assertSame(429, $e->suggestedHttpStatus());
        }
    }

    public function testAServerErrorSurfacesWithItsDetail(): void
    {
        $this->http()->queue(500, ['error' => ['code' => 'InternalServerError', 'message' => 'boom']]);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testReportsTheDriverName(): void
    {
        self::assertSame('azuredns', $this->provider()->driver());
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSet(string $type, string $addressKey, string $value, int $ttl): array
    {
        return [
            'id' => '/subscriptions/x/resourceGroups/y/providers/Microsoft.Network/dnsZones/example.com/'
                . $type . '/home',
            'name' => 'home',
            'type' => 'Microsoft.Network/dnsZones/' . $type,
            'etag' => '00000000-0000-0000-0000-000000000000',
            'properties' => [
                'TTL' => $ttl,
                'fqdn' => 'home.example.com.',
                $type . 'Records' => [[$addressKey => $value]],
            ],
        ];
    }
}
