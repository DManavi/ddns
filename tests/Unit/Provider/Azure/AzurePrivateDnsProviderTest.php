<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider\Azure;

use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Azure\AzureDnsProvider;
use Ddns\Provider\Azure\AzureZoneKind;
use Ddns\Tests\Support\Fixtures;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Private zones are a separate Azure resource type with a separate API version
 * and - the part that bites - a different casing convention in the record body.
 *
 * A wrongly cased body is not rejected by Azure: it stores a record set with no
 * addresses in it. So these tests assert on the exact keys sent, not merely
 * that a request was made.
 */
final class AzurePrivateDnsProviderTest extends TestCase
{
    private ?MockHttpClient $http = null;

    private function http(): MockHttpClient
    {
        return $this->http ??= new MockHttpClient();
    }

    private function provider(AzureZoneKind $kind = AzureZoneKind::Private): AzureDnsProvider
    {
        return new AzureDnsProvider(
            Fixtures::restClientWithHeaders(
                $this->http(),
                $kind->driver(),
                AzureDnsProvider::MANAGEMENT_ENDPOINT,
                static fn (): array => ['Authorization' => 'Bearer test-token'],
            ),
            'sub-1',
            'my-rg',
            $kind,
        );
    }

    private function hostname(string $name = 'home'): Hostname
    {
        return Hostname::create('internal.example.com', $name);
    }

    // ------------------------------------------------------------- identity

    public function testReportsItsOwnDriverName(): void
    {
        self::assertSame('azureprivatedns', $this->provider()->driver());
        self::assertSame('azuredns', $this->provider(AzureZoneKind::Public)->driver());
    }

    // ----------------------------------------------------------------- path

    public function testUsesThePrivateZoneResourceType(): void
    {
        $this->http()->queue(200, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.3', 60));

        $this->provider()->findRecord($this->hostname(), RecordType::A);

        $uri = (string) $this->http()->request(0)->getUri();

        self::assertStringContainsString('/providers/Microsoft.Network/privateDnsZones/internal.example.com/A/home', $uri);
        self::assertStringNotContainsString('/dnsZones/internal.example.com', $uri);
    }

    public function testUsesThePrivateApiVersion(): void
    {
        $this->http()->queue(200, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.3', 60));

        $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertStringContainsString('api-version=2018-09-01', (string) $this->http()->request(0)->getUri());
    }

    // -------------------------------------------------------- reading records

    public function testReadsALowerCaseTtlAndCamelCaseARecords(): void
    {
        $this->http()->queue(200, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.3', 60));

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertNotNull($record);
        self::assertSame('10.1.2.3', $record->value());
        self::assertSame(60, $record->ttl());
    }

    public function testReadsCamelCaseAaaaRecords(): void
    {
        $this->http()->queue(200, $this->privateRecordSet('aaaaRecords', 'ipv6Address', 'fd00::1', 120));

        $record = $this->provider()->findRecord($this->hostname(), RecordType::AAAA);

        self::assertNotNull($record);
        self::assertSame('fd00::1', $record->value());
        self::assertSame(120, $record->ttl());
    }

    /**
     * The public casing must not be understood by the private driver, or a
     * misconfiguration would go unnoticed.
     */
    public function testDoesNotReadPublicCasedRecords(): void
    {
        $this->http()->queue(200, [
            'name' => 'home',
            'properties' => ['TTL' => 60, 'ARecords' => [['ipv4Address' => '10.1.2.3']]],
        ]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    public function testReturnsNullWhenTheRecordDoesNotExist(): void
    {
        $this->http()->queue(404, ['error' => ['code' => 'NotFound', 'message' => 'not found']]);

        self::assertNull($this->provider()->findRecord($this->hostname(), RecordType::A));
    }

    public function testStillDistinguishesAMissingZone(): void
    {
        $this->http()->queue(404, ['error' => ['code' => 'ResourceGroupNotFound', 'message' => 'gone']]);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testAnUpToDateRecordCompactsAsUnchanged(): void
    {
        $this->http()->queue(200, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.3', 60));

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertTrue($record?->isUpToDate(IpAddress::fromString('10.1.2.3'), 60));
    }

    // -------------------------------------------------------- writing records

    public function testWritesALowerCaseTtlAndCamelCaseARecords(): void
    {
        $this->http()->queue(201, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.3', 60));

        $this->provider()->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('10.1.2.3'),
            60,
        );

        self::assertSame(
            ['properties' => ['ttl' => 60, 'aRecords' => [['ipv4Address' => '10.1.2.3']]]],
            $this->http()->bodyOf(0),
        );
    }

    public function testWritesCamelCaseAaaaRecords(): void
    {
        $this->http()->queue(201, $this->privateRecordSet('aaaaRecords', 'ipv6Address', 'fd00::1', 60));

        $this->provider()->createRecord(
            $this->hostname(),
            RecordType::AAAA,
            IpAddress::fromString('fd00::1'),
            60,
        );

        self::assertSame(
            ['properties' => ['ttl' => 60, 'aaaaRecords' => [['ipv6Address' => 'fd00::1']]]],
            $this->http()->bodyOf(0),
        );
    }

    public function testUpdateIssuesTheSamePutAsCreate(): void
    {
        $this->http()
            ->queue(201, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.9', 60))
            ->queue(200, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.9', 60));

        $provider = $this->provider();
        $ip = IpAddress::fromString('10.1.2.9');

        $provider->createRecord($this->hostname(), RecordType::A, $ip, 60);
        $provider->updateRecord(
            new DnsRecord('id', $this->hostname(), RecordType::A, '10.1.2.1', 60),
            $ip,
            60,
        );

        self::assertSame($this->http()->bodyOf(0), $this->http()->bodyOf(1));
    }

    public function testWritesToTheApex(): void
    {
        $this->http()->queue(201, $this->privateRecordSet('aRecords', 'ipv4Address', '10.1.2.3', 300));

        $this->provider()->createRecord(
            Hostname::create('internal.example.com', '@'),
            RecordType::A,
            IpAddress::fromString('10.1.2.3'),
            300,
        );

        self::assertStringContainsString('/A/%40?', (string) $this->http()->request(0)->getUri());
    }

    // ------------------------------------------------- the two kinds diverge

    /**
     * The regression that would otherwise slip through: if the two kinds ever
     * produced the same body, one of them would be silently wrong.
     */
    #[DataProvider('recordTypes')]
    public function testTheTwoZoneKindsSendDifferentBodies(RecordType $type, string $address): void
    {
        $this->http()
            ->queue(201, [])
            ->queue(201, []);

        $ip = IpAddress::fromString($address);

        $this->provider(AzureZoneKind::Private)->createRecord($this->hostname(), $type, $ip, 60);
        $this->provider(AzureZoneKind::Public)->createRecord($this->hostname(), $type, $ip, 60);

        $private = $this->http()->bodyOf(0);
        $public = $this->http()->bodyOf(1);

        self::assertNotSame($public, $private, 'Public and private zones must not share a body shape.');
        self::assertSame(['ttl', $type->value === 'A' ? 'aRecords' : 'aaaaRecords'], $this->propertyKeys($private));
        self::assertSame(['TTL', $type->value . 'Records'], $this->propertyKeys($public));
    }

    /**
     * @return iterable<string, array{RecordType, string}>
     */
    public static function recordTypes(): iterable
    {
        yield 'A' => [RecordType::A, '10.1.2.3'];
        yield 'AAAA' => [RecordType::AAAA, 'fd00::1'];
    }

    public function testTheTwoZoneKindsUseDifferentPathsAndVersions(): void
    {
        $this->http()
            ->queue(404, ['error' => ['code' => 'NotFound']])
            ->queue(404, ['error' => ['code' => 'NotFound']]);

        $this->provider(AzureZoneKind::Private)->findRecord($this->hostname(), RecordType::A);
        $this->provider(AzureZoneKind::Public)->findRecord($this->hostname(), RecordType::A);

        $private = (string) $this->http()->request(0)->getUri();
        $public = (string) $this->http()->request(1)->getUri();

        self::assertStringContainsString('/privateDnsZones/', $private);
        self::assertStringContainsString('api-version=2018-09-01', $private);
        self::assertStringContainsString('/dnsZones/', $public);
        self::assertStringContainsString('api-version=2018-05-01', $public);
    }

    // ------------------------------------------------------ auto-registration

    /**
     * Azure maintains auto-registered records for VMs on a linked VNet and
     * rejects manual writes to them, so refusing up front is clearer than
     * letting the platform reject the update later.
     */
    public function testRefusesAnAutoRegisteredRecord(): void
    {
        $this->http()->queue(200, [
            'name' => 'home',
            'properties' => [
                'ttl' => 10,
                'isAutoRegistered' => true,
                'aRecords' => [['ipv4Address' => '10.1.2.3']],
            ],
        ]);

        try {
            $this->provider()->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected a RecordOperationFailed exception.');
        } catch (RecordOperationFailed $e) {
            self::assertStringContainsString('auto-registered', $e->getMessage());
            self::assertStringContainsString('disable auto-registration', $e->getMessage());
            self::assertSame('azureprivatedns', $e->driver());
        }
    }

    public function testAcceptsARecordThatIsNotAutoRegistered(): void
    {
        $this->http()->queue(200, [
            'name' => 'home',
            'properties' => [
                'ttl' => 60,
                'isAutoRegistered' => false,
                'aRecords' => [['ipv4Address' => '10.1.2.3']],
            ],
        ]);

        self::assertSame('10.1.2.3', $this->provider()->findRecord($this->hostname(), RecordType::A)?->value());
    }

    /**
     * Public zones have no such field, and must not start rejecting records
     * because of one that happens to appear.
     */
    public function testPublicZonesIgnoreTheAutoRegistrationFlag(): void
    {
        $this->http()->queue(200, [
            'name' => 'home',
            'properties' => [
                'TTL' => 60,
                'isAutoRegistered' => true,
                'ARecords' => [['ipv4Address' => '203.0.113.7']],
            ],
        ]);

        $record = $this->provider(AzureZoneKind::Public)->findRecord($this->hostname(), RecordType::A);

        self::assertSame('203.0.113.7', $record?->value());
    }

    // -------------------------------------------------------------- aliases

    /**
     * Private zones cannot alias another Azure resource, so the guard that
     * protects public zones must not fire here.
     */
    public function testDoesNotApplyTheAliasGuardToPrivateZones(): void
    {
        $this->http()->queue(200, [
            'name' => 'home',
            'properties' => [
                'ttl' => 60,
                'targetResource' => ['id' => '/subscriptions/x/providers/Microsoft.Network/whatever'],
                'aRecords' => [['ipv4Address' => '10.1.2.3']],
            ],
        ]);

        self::assertSame('10.1.2.3', $this->provider()->findRecord($this->hostname(), RecordType::A)?->value());
    }

    /**
     * The `properties` keys of a recorded request body.
     *
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private function propertyKeys(array $body): array
    {
        $properties = $body['properties'] ?? null;

        self::assertIsArray($properties, 'The request body had no "properties".');

        return array_map(strval(...), array_keys($properties));
    }

    /**
     * @return array<string, mixed>
     */
    private function privateRecordSet(string $recordsKey, string $addressKey, string $value, int $ttl): array
    {
        return [
            'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network'
                . '/privateDnsZones/internal.example.com/A/home',
            'name' => 'home',
            'etag' => '00000000-0000-0000-0000-000000000000',
            'properties' => [
                'ttl' => $ttl,
                'fqdn' => 'home.internal.example.com.',
                'isAutoRegistered' => false,
                $recordsKey => [[$addressKey => $value]],
            ],
        ];
    }
}
