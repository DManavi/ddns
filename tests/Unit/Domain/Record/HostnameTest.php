<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Domain\Record;

use Ddns\Domain\Record\Hostname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostnameTest extends TestCase
{
    public function testSplitsZoneAndRelativeName(): void
    {
        $hostname = Hostname::create('example.com', 'home');

        self::assertSame('example.com', $hostname->zone());
        self::assertSame('home', $hostname->name());
        self::assertSame('home.example.com', $hostname->fqdn());
        self::assertFalse($hostname->isApex());
    }

    #[DataProvider('apexSpellings')]
    public function testRecognisesTheApexHoweverItIsWritten(string $name): void
    {
        $hostname = Hostname::create('example.com', $name);

        self::assertTrue($hostname->isApex(), sprintf('"%s" should resolve to the apex', $name));
        self::assertSame('@', $hostname->name());
        self::assertSame('example.com', $hostname->fqdn());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apexSpellings(): iterable
    {
        yield 'at sign' => ['@'];
        yield 'empty string' => [''];
        yield 'the zone itself' => ['example.com'];
        yield 'zone with trailing dot' => ['example.com.'];
    }

    public function testAcceptsAFullyQualifiedNameAndReducesItToRelative(): void
    {
        $hostname = Hostname::create('example.com', 'home.example.com');

        self::assertSame('home', $hostname->name());
        self::assertSame('home.example.com', $hostname->fqdn());
    }

    public function testKeepsMultiLabelRelativeNames(): void
    {
        $hostname = Hostname::create('example.com', 'a.b');

        self::assertSame('a.b', $hostname->name());
        self::assertSame('a.b.example.com', $hostname->fqdn());
    }

    public function testNormalisesCaseAndTrailingDots(): void
    {
        $hostname = Hostname::create('EXAMPLE.COM.', 'HOME');

        self::assertSame('example.com', $hostname->zone());
        self::assertSame('home', $hostname->name());
    }

    public function testRejectsANameFromADifferentZone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to zone');

        Hostname::create('example.com', 'home.example.org');
    }

    public function testRejectsAnEmptyZone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Hostname::create('');
    }

    #[DataProvider('malformedNames')]
    public function testRejectsMalformedLabels(string $zone, string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Hostname::create($zone, $name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedNames(): iterable
    {
        yield 'leading dash' => ['example.com', '-bad'];
        yield 'trailing dash' => ['example.com', 'bad-'];
        yield 'slash in name' => ['example.com', 'a/b'];
        yield 'space in name' => ['example.com', 'a b'];
        yield 'path traversal' => ['example.com', '../etc'];
        yield 'bad zone' => ['exa mple.com', 'home'];
    }

    public function testAllowsWildcardRecords(): void
    {
        self::assertSame('*.example.com', Hostname::create('example.com', '*')->fqdn());
    }

    public function testFqdnWithRootAppendsTheTrailingDot(): void
    {
        self::assertSame('home.example.com.', Hostname::create('example.com', 'home')->fqdnWithRoot());
    }

    /**
     * Providers disagree on how they spell a record's name, so matching has to
     * tolerate all of the common forms.
     */
    #[DataProvider('providerNameSpellings')]
    public function testMatchesProviderName(string $record, string $candidate, bool $expected): void
    {
        self::assertSame(
            $expected,
            Hostname::create('example.com', $record)->matchesProviderName($candidate),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function providerNameSpellings(): iterable
    {
        yield 'digitalocean relative' => ['home', 'home', true];
        yield 'cloudflare fqdn' => ['home', 'home.example.com', true];
        yield 'fqdn with trailing dot' => ['home', 'home.example.com.', true];
        yield 'different case' => ['home', 'HOME', true];
        yield 'digitalocean apex' => ['@', '@', true];
        yield 'vultr apex is empty' => ['@', '', true];
        yield 'cloudflare apex is the zone' => ['@', 'example.com', true];
        yield 'a different record' => ['home', 'office', false];
        yield 'sibling fqdn' => ['home', 'office.example.com', false];
        yield 'apex does not match a subdomain' => ['@', 'home.example.com', false];
    }

    public function testEqualsComparesZoneAndName(): void
    {
        self::assertTrue(
            Hostname::create('example.com', 'home')->equals(Hostname::create('example.com', 'home')),
        );
        self::assertFalse(
            Hostname::create('example.com', 'home')->equals(Hostname::create('example.org', 'home')),
        );
    }
}
