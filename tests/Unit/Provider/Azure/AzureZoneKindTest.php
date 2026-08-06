<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider\Azure;

use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Azure\AzureZoneKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public/private delta, since every one of these values is a silent
 * failure if it drifts: a wrongly cased body is accepted by Azure and stores a
 * record set with no addresses in it.
 */
final class AzureZoneKindTest extends TestCase
{
    public function testPublicZonesUseTheirOwnResourceTypeAndVersion(): void
    {
        self::assertSame('azuredns', AzureZoneKind::Public->driver());
        self::assertSame('dnsZones', AzureZoneKind::Public->resourceType());
        self::assertSame('2018-05-01', AzureZoneKind::Public->apiVersion());
        self::assertSame('TTL', AzureZoneKind::Public->ttlKey());
    }

    public function testPrivateZonesUseTheirOwnResourceTypeAndVersion(): void
    {
        self::assertSame('azureprivatedns', AzureZoneKind::Private->driver());
        self::assertSame('privateDnsZones', AzureZoneKind::Private->resourceType());
        self::assertSame('2018-09-01', AzureZoneKind::Private->apiVersion());
        self::assertSame('ttl', AzureZoneKind::Private->ttlKey());
    }

    #[DataProvider('recordsKeys')]
    public function testRecordsKeyCasingDiffersByZoneKind(
        AzureZoneKind $kind,
        RecordType $type,
        string $expected,
    ): void {
        self::assertSame($expected, $kind->recordsKey($type));
    }

    /**
     * @return iterable<string, array{AzureZoneKind, RecordType, string}>
     */
    public static function recordsKeys(): iterable
    {
        yield 'public A' => [AzureZoneKind::Public, RecordType::A, 'ARecords'];
        yield 'public AAAA' => [AzureZoneKind::Public, RecordType::AAAA, 'AAAARecords'];
        yield 'private A' => [AzureZoneKind::Private, RecordType::A, 'aRecords'];
        yield 'private AAAA' => [AzureZoneKind::Private, RecordType::AAAA, 'aaaaRecords'];
    }

    /**
     * The one thing both kinds agree on.
     */
    #[DataProvider('addressKeys')]
    public function testAddressKeyIsTheSameForBothKinds(RecordType $type, string $expected): void
    {
        self::assertSame($expected, AzureZoneKind::Public->addressKey($type));
        self::assertSame($expected, AzureZoneKind::Private->addressKey($type));
    }

    /**
     * @return iterable<string, array{RecordType, string}>
     */
    public static function addressKeys(): iterable
    {
        yield 'A' => [RecordType::A, 'ipv4Address'];
        yield 'AAAA' => [RecordType::AAAA, 'ipv6Address'];
    }

    public function testOnlyPublicZonesHaveAliasRecords(): void
    {
        self::assertTrue(AzureZoneKind::Public->supportsAliasRecords());
        self::assertFalse(AzureZoneKind::Private->supportsAliasRecords());
    }

    public function testOnlyPrivateZonesAutoRegister(): void
    {
        self::assertFalse(AzureZoneKind::Public->supportsAutoRegistration());
        self::assertTrue(AzureZoneKind::Private->supportsAutoRegistration());
    }

    public function testTheTwoKindsShareNoDistinguishingValue(): void
    {
        $public = AzureZoneKind::Public;
        $private = AzureZoneKind::Private;

        self::assertNotSame($public->driver(), $private->driver());
        self::assertNotSame($public->description(), $private->description());
        self::assertNotSame($public->resourceType(), $private->resourceType());
        self::assertNotSame($public->apiVersion(), $private->apiVersion());
        self::assertNotSame($public->ttlKey(), $private->ttlKey());
        self::assertNotSame($public->recordsKey(RecordType::A), $private->recordsKey(RecordType::A));
    }

    /**
     * The two resource types look similar but differ in case as well as
     * prefix, so a case-sensitive check cannot confuse one for the other.
     */
    public function testThePublicResourceTypeIsNotASubstringOfThePrivateOne(): void
    {
        self::assertStringNotContainsString(
            AzureZoneKind::Public->resourceType(),
            AzureZoneKind::Private->resourceType(),
            'privateDnsZones capitalises the D, so "dnsZones" is not a substring of it.',
        );
        self::assertStringContainsStringIgnoringCase(
            AzureZoneKind::Public->resourceType(),
            AzureZoneKind::Private->resourceType(),
            'They are still the same word ignoring case, so any matching must stay case-sensitive.',
        );
    }

    public function testIsPrivateReportsTheKind(): void
    {
        self::assertFalse(AzureZoneKind::Public->isPrivate());
        self::assertTrue(AzureZoneKind::Private->isPrivate());
    }
}
