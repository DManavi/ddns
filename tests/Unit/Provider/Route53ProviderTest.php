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
use Ddns\Provider\Route53\Route53Provider;
use Ddns\Tests\Support\FakeRoute53;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Route53ProviderTest extends TestCase
{
    private ?FakeRoute53 $aws = null;

    private function aws(): FakeRoute53
    {
        return $this->aws ??= new FakeRoute53();
    }

    private function provider(?string $zoneId = null, bool $privateZone = false): Route53Provider
    {
        return new Route53Provider($this->aws()->client(), $zoneId, $privateZone);
    }

    private function hostname(string $name = 'home'): Hostname
    {
        return Hostname::create('example.com', $name);
    }

    // ------------------------------------------------------------ zone lookup

    public function testResolvesTheHostedZoneThenReadsTheRecord(): void
    {
        $this->aws()
            ->queueZoneLookup([['Z123', 'example.com']])
            ->queueRecordSets([$this->recordSet('home.example.com.', 'A', '203.0.113.7', 60)]);

        $record = $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertNotNull($record);
        self::assertSame('203.0.113.7', $record->value());
        self::assertSame(60, $record->ttl());

        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets'], $this->aws()->operations());
        self::assertSame('example.com.', $this->aws()->argAt(0, 'DNSName'));
        self::assertSame('Z123', $this->aws()->argAt(1, 'HostedZoneId'));
    }

    /**
     * Zone IDs are stable, so paying for the lookup once per process rather
     * than once per update matters on a short watch interval.
     */
    public function testMemoisesTheZoneId(): void
    {
        $this->aws()
            ->queueZoneLookup([['Z123', 'example.com']])
            ->queueRecordSets([])
            ->queueRecordSets([]);

        $provider = $this->provider();
        $provider->findRecord($this->hostname(), RecordType::A);
        $provider->findRecord($this->hostname(), RecordType::A);

        self::assertSame(3, $this->aws()->callCount(), 'The zone should be looked up only once.');
    }

    /**
     * An IAM policy scoped to one zone often cannot list zones at all.
     */
    public function testSkipsTheLookupWhenAZoneIdIsConfigured(): void
    {
        $this->aws()->queueRecordSets([]);

        $this->provider(zoneId: 'Z999')->findRecord($this->hostname(), RecordType::A);

        self::assertSame(['ListResourceRecordSets'], $this->aws()->operations());
        self::assertSame('Z999', $this->aws()->argAt(0, 'HostedZoneId'));
    }

    public function testAcceptsAConfiguredZoneIdInItsPrefixedForm(): void
    {
        $this->aws()->queueRecordSets([]);

        $this->provider(zoneId: '/hostedzone/Z999')->findRecord($this->hostname(), RecordType::A);

        self::assertSame('Z999', $this->aws()->argAt(0, 'HostedZoneId'));
    }

    /**
     * The listing is ordered by name and runs past the zone asked for, so an
     * exact match is required rather than taking the first result.
     */
    public function testIgnoresZonesWhoseNameDoesNotMatchExactly(): void
    {
        $this->aws()->queueZoneLookup([['Z1', 'example.com.au'], ['Z2', 'example.computer']]);

        $this->expectException(ZoneNotFound::class);

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    public function testThrowsZoneNotFoundWhenNoZoneMatches(): void
    {
        $this->aws()->queueZoneLookup([]);

        $this->expectException(ZoneNotFound::class);
        $this->expectExceptionMessage('example.com');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * In a split-horizon account the public zone is the one a dynamic DNS
     * record is normally meant to reach.
     */
    public function testPrefersThePublicZoneOverAPrivateOneOfTheSameName(): void
    {
        $this->aws()
            ->queueZoneLookup([['ZPRIVATE', 'example.com', true], ['ZPUBLIC', 'example.com', false]])
            ->queueRecordSets([]);

        $this->provider()->findRecord($this->hostname(), RecordType::A);

        self::assertSame('ZPUBLIC', $this->aws()->argAt(1, 'HostedZoneId'));
    }

    public function testCanTargetThePrivateZoneWhenAsked(): void
    {
        $this->aws()
            ->queueZoneLookup([['ZPRIVATE', 'example.com', true], ['ZPUBLIC', 'example.com', false]])
            ->queueRecordSets([]);

        $this->provider(privateZone: true)->findRecord($this->hostname(), RecordType::A);

        self::assertSame('ZPRIVATE', $this->aws()->argAt(1, 'HostedZoneId'));
    }

    /**
     * AWS allows duplicate zone names. Picking one arbitrarily would update a
     * zone the user never intended.
     */
    public function testRefusesToGuessBetweenTwoZonesOfTheSameName(): void
    {
        $this->aws()->queueZoneLookup([['ZA', 'example.com'], ['ZB', 'example.com']]);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('Set "zone_id"');

        $this->provider()->findRecord($this->hostname(), RecordType::A);
    }

    // ----------------------------------------------------------- find record

    public function testSendsTheExpectedLookupArguments(): void
    {
        $this->aws()->queueRecordSets([]);

        $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::AAAA);

        self::assertSame('home.example.com.', $this->aws()->argAt(0, 'StartRecordName'));
        self::assertSame('AAAA', $this->aws()->argAt(0, 'StartRecordType'));
        // The API declares MaxItems as a string; an int fails SDK validation.
        self::assertSame('1', $this->aws()->argAt(0, 'MaxItems'));
    }

    /**
     * Route53 returns the *next* record set when the one asked for is absent,
     * so the response has to be checked rather than trusted by position.
     */
    public function testReturnsNullWhenTheApiAnswersWithADifferentRecord(): void
    {
        $this->aws()->queueRecordSets([$this->recordSet('office.example.com.', 'A', '198.51.100.1', 60)]);

        self::assertNull($this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A));
    }

    public function testIgnoresARecordOfADifferentType(): void
    {
        $this->aws()->queueRecordSets([$this->recordSet('home.example.com.', 'AAAA', '2001:db8::1', 60)]);

        self::assertNull($this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A));
    }

    public function testReturnsNullForAnEmptyZone(): void
    {
        $this->aws()->queueRecordSets([]);

        self::assertNull($this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A));
    }

    public function testMatchesTheApex(): void
    {
        $this->aws()->queueRecordSets([$this->recordSet('example.com.', 'A', '203.0.113.7', 300)]);

        $record = $this->provider(zoneId: 'Z1')->findRecord(Hostname::create('example.com', '@'), RecordType::A);

        self::assertSame('203.0.113.7', $record?->value());
    }

    /**
     * Route53 escapes special characters as three-digit octal, so a wildcard
     * comes back as `\052.example.com.`.
     */
    public function testDecodesOctalEscapesInReturnedNames(): void
    {
        $this->aws()->queueRecordSets([$this->recordSet('\\052.example.com.', 'A', '203.0.113.7', 60)]);

        $record = $this->provider(zoneId: 'Z1')->findRecord($this->hostname('*'), RecordType::A);

        self::assertSame('203.0.113.7', $record?->value());
    }

    /**
     * Replacing an alias with a plain address record would silently destroy a
     * CloudFront or load balancer target.
     */
    public function testRefusesToTouchAnAliasRecord(): void
    {
        $this->aws()->queueRecordSets([[
            'Name' => 'home.example.com.',
            'Type' => 'A',
            'AliasTarget' => [
                'HostedZoneId' => 'Z2FDTNDATAQYW2',
                'DNSName' => 'd111111abcdef8.cloudfront.net.',
                'EvaluateTargetHealth' => false,
            ],
        ]]);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('is an alias record');

        $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * Weighted and latency record sets share a name and type; an UPSERT that
     * omits SetIdentifier would be rejected by AWS.
     */
    public function testRefusesRecordsThatUseARoutingPolicy(): void
    {
        $this->aws()->queueRecordSets([
            ['SetIdentifier' => 'eu-west-1', 'Weight' => 10]
            + $this->recordSet('home.example.com.', 'A', '203.0.113.7', 60),
        ]);

        $this->expectException(RecordOperationFailed::class);
        $this->expectExceptionMessage('routing policy');

        $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * A multi-value set reports its first value, so it compares as out of date
     * and the next write collapses it to one address - the DDNS intent.
     */
    public function testReportsTheFirstValueOfAMultiValueRecordSet(): void
    {
        $this->aws()->queueRecordSets([[
            'Name' => 'home.example.com.',
            'Type' => 'A',
            'TTL' => 60,
            'ResourceRecords' => [['Value' => '203.0.113.7'], ['Value' => '203.0.113.8']],
        ]]);

        $record = $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);

        self::assertNotNull($record);
        self::assertSame('203.0.113.7', $record->value());
        self::assertFalse($record->isUpToDate(IpAddress::fromString('203.0.113.8'), 60));
    }

    // --------------------------------------------------------- create/update

    public function testCreatesARecordWithUpsert(): void
    {
        $this->aws()->queueChangeAccepted();

        $record = $this->provider(zoneId: 'Z1')->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame('203.0.113.7', $record->value());
        self::assertSame(60, $record->ttl());

        self::assertSame('ChangeResourceRecordSets', $this->aws()->operationAt(0));
        self::assertSame('Z1', $this->aws()->argAt(0, 'HostedZoneId'));

        $set = 'ChangeBatch.Changes.0.ResourceRecordSet';
        self::assertSame('UPSERT', $this->aws()->argAt(0, 'ChangeBatch.Changes.0.Action'));
        self::assertSame('home.example.com.', $this->aws()->argAt(0, $set . '.Name'));
        self::assertSame('A', $this->aws()->argAt(0, $set . '.Type'));
        self::assertSame(60, $this->aws()->argAt(0, $set . '.TTL'));
        self::assertSame([['Value' => '203.0.113.7']], $this->aws()->argAt(0, $set . '.ResourceRecords'));
    }

    /**
     * Route53 has no separate update call: UPSERT covers both, so the two
     * operations must issue the same request.
     */
    public function testUpdateIssuesTheSameUpsertAsCreate(): void
    {
        $this->aws()->queueChangeAccepted()->queueChangeAccepted();

        $provider = $this->provider(zoneId: 'Z1');
        $ip = IpAddress::fromString('203.0.113.9');

        $provider->createRecord($this->hostname(), RecordType::A, $ip, 120);
        $provider->updateRecord(
            new DnsRecord('home.example.com/A', $this->hostname(), RecordType::A, '198.51.100.1', 120),
            $ip,
            120,
        );

        self::assertSame($this->aws()->argumentsAt(0), $this->aws()->argumentsAt(1));
    }

    public function testUpdateReturnsTheNewValue(): void
    {
        $this->aws()->queueChangeAccepted();

        $updated = $this->provider(zoneId: 'Z1')->updateRecord(
            new DnsRecord('home.example.com/A', $this->hostname(), RecordType::A, '198.51.100.1', 60),
            IpAddress::fromString('203.0.113.7'),
            300,
        );

        self::assertSame('203.0.113.7', $updated->value());
        self::assertSame(300, $updated->ttl());
    }

    public function testWritesIpv6ToAnAaaaRecord(): void
    {
        $this->aws()->queueChangeAccepted();

        $this->provider(zoneId: 'Z1')->createRecord(
            $this->hostname(),
            RecordType::AAAA,
            IpAddress::fromString('2001:db8::1'),
            60,
        );

        $set = 'ChangeBatch.Changes.0.ResourceRecordSet';

        self::assertSame('AAAA', $this->aws()->argAt(0, $set . '.Type'));
        self::assertSame([['Value' => '2001:db8::1']], $this->aws()->argAt(0, $set . '.ResourceRecords'));
    }

    public function testWritesTheApexWithoutAnAtSign(): void
    {
        $this->aws()->queueChangeAccepted();

        $this->provider(zoneId: 'Z1')->createRecord(
            Hostname::create('example.com', '@'),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );

        self::assertSame(
            'example.com.',
            $this->aws()->argAt(0, 'ChangeBatch.Changes.0.ResourceRecordSet.Name'),
        );
    }

    // -------------------------------------------------------- error mapping

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('awsErrorCodes')]
    public function testTranslatesAwsErrorCodes(string $code, int $status, string $expected): void
    {
        $this->aws()->queueError($code, $status);

        $this->expectException($expected);

        $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);
    }

    /**
     * @return iterable<string, array{string, int, class-string}>
     */
    public static function awsErrorCodes(): iterable
    {
        yield 'access denied' => ['AccessDenied', 403, AuthenticationFailed::class];
        yield 'bad key id' => ['InvalidClientTokenId', 403, AuthenticationFailed::class];
        yield 'bad signature' => ['SignatureDoesNotMatch', 403, AuthenticationFailed::class];
        yield 'expired sts token' => ['ExpiredToken', 403, AuthenticationFailed::class];
        yield 'throttled' => ['Throttling', 400, RateLimited::class];
        yield 'change in flight' => ['PriorRequestNotComplete', 400, RateLimited::class];
        yield 'missing zone' => ['NoSuchHostedZone', 404, ZoneNotFound::class];
        yield 'anything else' => ['InvalidChangeBatch', 400, RecordOperationFailed::class];
    }

    public function testARateLimitSuggests429(): void
    {
        $this->aws()->queueError('Throttling');

        try {
            $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected a RateLimited exception.');
        } catch (RateLimited $e) {
            self::assertSame(429, $e->suggestedHttpStatus());
        }
    }

    /**
     * A rejected AWS credential is our problem, not the API client's, so it
     * must not be reported to them as an authentication failure.
     */
    public function testACredentialFailureSuggests502(): void
    {
        $this->aws()->queueError('AccessDenied', 403);

        try {
            $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected an AuthenticationFailed exception.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(502, $e->suggestedHttpStatus());
        }
    }

    public function testErrorsCarryTheDriverName(): void
    {
        $this->aws()->queueError('InvalidChangeBatch');

        try {
            $this->provider(zoneId: 'Z1')->findRecord($this->hostname(), RecordType::A);
            self::fail('Expected a RecordOperationFailed exception.');
        } catch (RecordOperationFailed $e) {
            self::assertSame('route53', $e->driver());
            self::assertStringContainsString('InvalidChangeBatch', $e->getMessage());
        }
    }

    public function testUpsertFailuresAreTranslatedToo(): void
    {
        $this->aws()->queueError('InvalidChangeBatch');

        $this->expectException(RecordOperationFailed::class);

        $this->provider(zoneId: 'Z1')->createRecord(
            $this->hostname(),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );
    }

    public function testReportsTheDriverName(): void
    {
        self::assertSame('route53', $this->provider(zoneId: 'Z1')->driver());
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSet(string $name, string $type, string $value, int $ttl): array
    {
        return [
            'Name' => $name,
            'Type' => $type,
            'TTL' => $ttl,
            'ResourceRecords' => [['Value' => $value]],
        ];
    }
}
