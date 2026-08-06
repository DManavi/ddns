<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Provider;

use Ddns\Config\ProviderConfig;
use Ddns\Domain\Provider\Exception\ProviderNotImplemented;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Route53\Route53Provider;
use Ddns\Provider\Route53\Route53ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * Route53 is registered but not implemented. These tests pin the contract of
 * that seam so the driver stays discoverable rather than silently missing.
 */
final class Route53ProviderTest extends TestCase
{
    public function testTheFactoryReportsItselfAsUnavailableWithAReason(): void
    {
        $factory = new Route53ProviderFactory();

        self::assertSame('route53', $factory->driver());
        self::assertFalse($factory->isAvailable());
        self::assertStringContainsString('SigV4', $factory->unavailableReason());
    }

    public function testTheFactoryStillBuildsAnInstance(): void
    {
        $provider = (new Route53ProviderFactory())->create(new ProviderConfig('r', 'route53', 'token'));

        self::assertInstanceOf(Route53Provider::class, $provider);
        self::assertSame('route53', $provider->driver());
    }

    public function testFindThrowsNotImplemented(): void
    {
        $this->expectException(ProviderNotImplemented::class);

        (new Route53Provider())->findRecord(Hostname::create('example.com', 'home'), RecordType::A);
    }

    public function testCreateThrowsNotImplemented(): void
    {
        $this->expectException(ProviderNotImplemented::class);

        (new Route53Provider())->createRecord(
            Hostname::create('example.com', 'home'),
            RecordType::A,
            IpAddress::fromString('203.0.113.7'),
            60,
        );
    }

    public function testUpdateThrowsNotImplemented(): void
    {
        $this->expectException(ProviderNotImplemented::class);

        (new Route53Provider())->updateRecord(
            new DnsRecord('1', Hostname::create('example.com', 'home'), RecordType::A, '203.0.113.7', 60),
            IpAddress::fromString('203.0.113.9'),
            60,
        );
    }

    /**
     * 501 rather than 502: this is our gap, not an upstream failure.
     */
    public function testSuggestsA501Status(): void
    {
        self::assertSame(501, ProviderNotImplemented::for('route53', 'reason')->suggestedHttpStatus());
    }
}
