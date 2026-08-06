<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Domain\Record;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function testParsesIpv4AsAnARecord(): void
    {
        $address = IpAddress::fromString('203.0.113.7');

        self::assertSame('203.0.113.7', $address->value());
        self::assertSame(RecordType::A, $address->recordType());
        self::assertSame(4, $address->version());
    }

    public function testParsesIpv6AsAnAaaaRecord(): void
    {
        $address = IpAddress::fromString('2001:db8::1');

        self::assertSame(RecordType::AAAA, $address->recordType());
        self::assertSame(6, $address->version());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('203.0.113.7', IpAddress::fromString(" 203.0.113.7\n")->value());
    }

    /**
     * Echo services and provider APIs write IPv6 inconsistently. Without
     * canonicalisation every poll would look like a change and would burn
     * provider rate limit for nothing.
     */
    public function testCanonicalisesIpv6SoEquivalentFormsCompareEqual(): void
    {
        $expanded = IpAddress::fromString('2001:0db8:0000:0000:0000:0000:0000:0001');
        $compressed = IpAddress::fromString('2001:db8::1');

        self::assertSame($compressed->value(), $expanded->value());
        self::assertTrue($expanded->equals($compressed));
    }

    public function testUppercaseIpv6IsNormalised(): void
    {
        self::assertTrue(
            IpAddress::fromString('2001:DB8::1')->equals(IpAddress::fromString('2001:db8::1')),
        );
    }

    public function testDifferentFamiliesAreNeverEqual(): void
    {
        self::assertFalse(
            IpAddress::fromString('203.0.113.7')->equals(IpAddress::fromString('2001:db8::1')),
        );
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidValues(string $value): void
    {
        self::assertNull(IpAddress::tryFromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'hostname' => ['example.com'];
        yield 'truncated v4' => ['203.0.113'];
        yield 'out of range octet' => ['203.0.113.999'];
        yield 'v4 with port' => ['203.0.113.7:80'];
        yield 'injection attempt' => ['203.0.113.7; rm -rf /'];
        yield 'nonsense v6' => ['2001:db8::zz'];
    }

    public function testFromStringThrowsOnInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"nope" is not a valid IP address.');

        IpAddress::fromString('nope');
    }

    #[DataProvider('nonPublicAddresses')]
    public function testRecognisesNonPublicAddresses(string $value): void
    {
        self::assertFalse(IpAddress::fromString($value)->isPublic());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicAddresses(): iterable
    {
        yield 'rfc1918 10/8' => ['10.1.2.3'];
        yield 'rfc1918 192.168/16' => ['192.168.1.1'];
        yield 'rfc1918 172.16/12' => ['172.16.0.1'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'link local' => ['169.254.1.1'];
        yield 'v6 loopback' => ['::1'];
        yield 'v6 unspecified' => ['::'];
        yield 'v6 unique local' => ['fd00::1'];
        yield 'v6 link local' => ['fe80::1'];
        yield 'v6 multicast' => ['ff02::1'];
        yield 'v6 ipv4-mapped' => ['::ffff:192.168.1.1'];
        yield 'v4 this-network' => ['0.0.0.0'];
        yield 'v4 multicast' => ['224.0.0.1'];
        yield 'v4 broadcast' => ['255.255.255.255'];
    }

    #[DataProvider('publicAddresses')]
    public function testRecognisesPublicAddresses(string $value): void
    {
        self::assertTrue(IpAddress::fromString($value)->isPublic());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'ordinary v4' => ['8.8.8.8'];
        yield 'ordinary v6' => ['2001:4860:4860::8888'];
        // The documentation ranges. Treated as routable on purpose: they are
        // what RFC 5737 and RFC 3849 exist for, and this project's own examples
        // and tests are written with them.
        yield 'v4 TEST-NET-1' => ['192.0.2.1'];
        yield 'v4 TEST-NET-2' => ['198.51.100.1'];
        yield 'v4 TEST-NET-3' => ['203.0.113.7'];
        yield 'v6 documentation' => ['2001:db8::1'];
    }

    /**
     * PHP's FILTER_FLAG_NO_RES_RANGE disagrees with itself across versions -
     * 8.2 calls 2001:db8::/32 reserved, 8.3 does not - so the same address
     * would be published on one runtime and refused on another. This pins the
     * answer regardless of which PHP is running.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3849
     */
    public function testTheVerdictDoesNotDependOnThePhpVersion(): void
    {
        // Every value whose PHP-filter classification has moved between
        // versions, asserted against our own explicit range list.
        self::assertTrue(IpAddress::fromString('2001:db8::1')->isPublic());
        self::assertTrue(IpAddress::fromString('2001:db8:abcd::1')->isPublic());
        self::assertTrue(IpAddress::fromString('192.0.2.1')->isPublic());
    }

    /**
     * A client behind carrier-grade NAT has no address of its own to publish,
     * so a record pointing at one would never resolve back to them. PHP's
     * filters treat this range as routable, which is why it is listed
     * explicitly.
     *
     * @see https://www.rfc-editor.org/rfc/rfc6598
     */
    public function testRefusesCarrierGradeNatAddresses(): void
    {
        self::assertFalse(IpAddress::fromString('100.64.0.1')->isPublic());
        self::assertFalse(IpAddress::fromString('100.127.255.255')->isPublic());
        // Just outside the /10, and genuinely routable.
        self::assertTrue(IpAddress::fromString('100.128.0.1')->isPublic());
    }

    public function testMatchesReportsFamilyAgreement(): void
    {
        $v4 = IpAddress::fromString('203.0.113.7');

        self::assertTrue($v4->matches(RecordType::A));
        self::assertFalse($v4->matches(RecordType::AAAA));
    }
}
