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
        yield 'v6 unique local' => ['fd00::1'];
    }

    public function testRecognisesPublicAddresses(): void
    {
        self::assertTrue(IpAddress::fromString('203.0.113.7')->isPublic());
        self::assertTrue(IpAddress::fromString('2001:db8::1')->isPublic());
    }

    public function testMatchesReportsFamilyAgreement(): void
    {
        $v4 = IpAddress::fromString('203.0.113.7');

        self::assertTrue($v4->matches(RecordType::A));
        self::assertFalse($v4->matches(RecordType::AAAA));
    }
}
