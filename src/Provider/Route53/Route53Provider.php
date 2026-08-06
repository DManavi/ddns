<?php

declare(strict_types=1);

namespace Ddns\Provider\Route53;

use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\ProviderNotImplemented;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;

/**
 * AWS Route53 driver placeholder.
 *
 * Route53 needs SigV4 request signing and the AWS SDK, which is a much heavier
 * dependency than the plain-REST drivers. It is registered rather than omitted
 * so that `ddns providers:list` reports it as a known-but-unavailable driver
 * and the configuration validator produces a precise error instead of
 * "unknown driver".
 */
final class Route53Provider implements DnsProvider
{
    public const DRIVER = 'route53';

    public const REASON = 'it requires AWS SigV4 request signing via aws-sdk-php, which is not a dependency yet';

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        throw ProviderNotImplemented::for(self::DRIVER, self::REASON);
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        throw ProviderNotImplemented::for(self::DRIVER, self::REASON);
    }

    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord
    {
        throw ProviderNotImplemented::for(self::DRIVER, self::REASON);
    }
}
