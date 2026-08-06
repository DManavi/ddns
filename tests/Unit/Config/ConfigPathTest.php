<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Config;

use Ddns\Config\ConfigPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigPath::class)]
final class ConfigPathTest extends TestCase
{
    /**
     * @return array<array-key, mixed>
     */
    private static function config(): array
    {
        return [
            'server' => ['default_ttl' => 300, 'trusted_proxies' => []],
            'hosts' => ['home' => ['ttl' => 60, 'types' => ['A']]],
        ];
    }

    #[Test]
    public function it_reads_a_nested_value(): void
    {
        self::assertSame(['found' => true, 'value' => 60], ConfigPath::get(self::config(), 'hosts.home.ttl'));
    }

    #[Test]
    public function it_reads_a_whole_branch(): void
    {
        self::assertSame(
            ['found' => true, 'value' => ['ttl' => 60, 'types' => ['A']]],
            ConfigPath::get(self::config(), 'hosts.home'),
        );
    }

    #[Test]
    public function a_missing_path_is_reported_rather_than_returned_as_null(): void
    {
        // A value that is genuinely null and one that is absent are different
        // answers, and callers act on the difference.
        self::assertSame(['found' => false, 'value' => null], ConfigPath::get(self::config(), 'hosts.away.ttl'));
        self::assertSame(['found' => false, 'value' => null], ConfigPath::get(['a' => null], 'a.b'));
        self::assertSame(['found' => true, 'value' => null], ConfigPath::get(['a' => null], 'a'));
    }

    #[Test]
    public function it_writes_a_nested_value_without_touching_its_siblings(): void
    {
        $result = ConfigPath::set(self::config(), 'hosts.home.ttl', 600);

        self::assertSame([
            'server' => ['default_ttl' => 300, 'trusted_proxies' => []],
            'hosts' => ['home' => ['ttl' => 600, 'types' => ['A']]],
        ], $result);
    }

    #[Test]
    public function it_creates_the_levels_it_needs(): void
    {
        $result = ConfigPath::set([], 'hosts.home.ttl', 60);

        self::assertSame(['hosts' => ['home' => ['ttl' => 60]]], $result);
    }

    #[Test]
    public function the_input_is_left_alone(): void
    {
        // By-reference traversal makes accidental mutation of the caller's
        // array easy, and a config:set that failed validation would then have
        // already changed what the caller holds.
        $original = self::config();
        $updated = ConfigPath::set($original, 'hosts.home.ttl', 999);

        self::assertSame(self::config(), $original);
        self::assertNotSame($original, $updated);
    }

    #[Test]
    public function writing_through_a_scalar_is_reported_rather_than_done(): void
    {
        // hosts.home.ttl is a number, so hosts.home.ttl.nested would replace it
        // with a mapping and silently discard 60.
        self::assertSame('hosts.home.ttl', ConfigPath::blockedBy(self::config(), 'hosts.home.ttl.nested'));
        self::assertNull(ConfigPath::blockedBy(self::config(), 'hosts.home.ttl'));
        self::assertNull(ConfigPath::blockedBy(self::config(), 'hosts.away.ttl'));
    }

    #[Test]
    public function it_lists_the_paths_that_hold_a_value(): void
    {
        // Lists count as values rather than as levels to descend into, since
        // "hosts.home.types.0" is not how anyone refers to a record type.
        self::assertSame([
            'server.default_ttl',
            'server.trusted_proxies',
            'hosts.home.ttl',
            'hosts.home.types',
        ], ConfigPath::leaves(self::config()));
    }

    #[Test]
    public function empty_segments_are_ignored(): void
    {
        self::assertSame(['found' => true, 'value' => 60], ConfigPath::get(self::config(), 'hosts..home.ttl'));
        self::assertSame(['found' => true, 'value' => 60], ConfigPath::get(self::config(), '.hosts.home.ttl.'));
    }
}
