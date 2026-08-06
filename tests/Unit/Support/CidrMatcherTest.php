<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Support;

use Ddns\Support\CidrMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CidrMatcherTest extends TestCase
{
    #[DataProvider('validRanges')]
    public function testAcceptsValidRanges(string $range): void
    {
        self::assertTrue(CidrMatcher::isValidRange($range));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validRanges(): iterable
    {
        yield 'bare v4' => ['10.0.0.1'];
        yield 'v4 cidr' => ['10.0.0.0/8'];
        yield 'v4 host route' => ['10.0.0.1/32'];
        yield 'v4 default route' => ['0.0.0.0/0'];
        yield 'bare v6' => ['2001:db8::1'];
        yield 'v6 cidr' => ['2001:db8::/32'];
        yield 'v6 host route' => ['::1/128'];
    }

    #[DataProvider('invalidRanges')]
    public function testRejectsInvalidRanges(string $range): void
    {
        self::assertFalse(CidrMatcher::isValidRange($range));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'empty' => [''];
        yield 'nonsense' => ['not-a-cidr'];
        yield 'prefix too long for v4' => ['10.0.0.0/33'];
        yield 'prefix too long for v6' => ['2001:db8::/129'];
        yield 'non numeric prefix' => ['10.0.0.0/eight'];
        yield 'hostname' => ['example.com/24'];
    }

    #[DataProvider('containment')]
    public function testMatchesContainment(string $ip, string $range, bool $expected): void
    {
        self::assertSame($expected, CidrMatcher::matches($ip, $range));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function containment(): iterable
    {
        yield 'inside /8' => ['10.1.2.3', '10.0.0.0/8', true];
        yield 'outside /8' => ['11.1.2.3', '10.0.0.0/8', false];
        yield 'inside /12 lower bound' => ['172.16.0.1', '172.16.0.0/12', true];
        yield 'inside /12 upper bound' => ['172.31.255.254', '172.16.0.0/12', true];
        yield 'just outside /12' => ['172.32.0.1', '172.16.0.0/12', false];
        yield 'non byte aligned prefix inside' => ['192.168.1.5', '192.168.1.0/29', true];
        yield 'non byte aligned prefix outside' => ['192.168.1.9', '192.168.1.0/29', false];
        yield 'exact host match' => ['203.0.113.7', '203.0.113.7', true];
        yield 'different host' => ['203.0.113.8', '203.0.113.7', false];
        yield 'default route matches everything' => ['203.0.113.7', '0.0.0.0/0', true];
        yield 'v6 inside' => ['2001:db8::1', '2001:db8::/32', true];
        yield 'v6 outside' => ['2001:dba::1', '2001:db8::/32', false];
    }

    /**
     * A v4 address must never be considered inside a v6 range, or vice versa.
     */
    public function testDoesNotMatchAcrossAddressFamilies(): void
    {
        self::assertFalse(CidrMatcher::matches('10.0.0.1', '::/0'));
        self::assertFalse(CidrMatcher::matches('2001:db8::1', '0.0.0.0/0'));
    }

    public function testMatchesAnyChecksEveryRange(): void
    {
        $ranges = ['192.168.0.0/16', '10.0.0.0/8'];

        self::assertTrue(CidrMatcher::matchesAny('10.1.1.1', $ranges));
        self::assertTrue(CidrMatcher::matchesAny('192.168.5.5', $ranges));
        self::assertFalse(CidrMatcher::matchesAny('203.0.113.7', $ranges));
    }

    public function testMatchesAnyIsFalseForAnEmptyRangeList(): void
    {
        self::assertFalse(CidrMatcher::matchesAny('10.0.0.1', []));
    }

    public function testMalformedInputNeverMatches(): void
    {
        self::assertFalse(CidrMatcher::matches('garbage', '10.0.0.0/8'));
        self::assertFalse(CidrMatcher::matches('10.0.0.1', 'garbage'));
    }
}
