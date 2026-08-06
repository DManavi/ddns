<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Config;

use Ddns\Config\HostConfig;
use Ddns\Config\ProviderConfig;
use Ddns\Config\Redactor;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testMasksAShortSecretEntirely(): void
    {
        self::assertSame('****', Redactor::redact('short'));
    }

    public function testShowsOnlyTheLastFourCharactersOfALongSecret(): void
    {
        self::assertSame('****cdef', Redactor::redact('dop_v1_0123456789abcdef'));
    }

    public function testLeavesAnEmptyStringEmpty(): void
    {
        self::assertSame('', Redactor::redact(''));
    }

    public function testNeverReturnsTheOriginalSecret(): void
    {
        $secret = 'dop_v1_0123456789abcdef';

        self::assertNotSame($secret, Redactor::redact($secret));
        self::assertStringNotContainsString('0123456789', Redactor::redact($secret));
    }

    /**
     * Listing output must never disclose a token.
     */
    public function testHostSerialisationRedactsTheToken(): void
    {
        $host = new HostConfig(
            'home',
            'p1',
            Hostname::create('example.com', 'home'),
            [RecordType::A],
            60,
            'super-secret-host-token',
        );

        $array = $host->toRedactedArray();

        self::assertStringNotContainsString('super-secret', $array['token']);
        self::assertSame('home.example.com', $array['fqdn']);
    }

    public function testProviderSerialisationRedactsTheToken(): void
    {
        $array = (new ProviderConfig('p1', 'vultr', 'super-secret-api-key'))->toRedactedArray();

        self::assertStringNotContainsString('super-secret', $array['token']);
        self::assertSame('vultr', $array['driver']);
    }
}
