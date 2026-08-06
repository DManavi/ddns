<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Domain\Update;

use Ddns\Config\Configuration;
use Ddns\Config\ServerConfig;
use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Domain\Provider\Exception\RateLimited;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Domain\Update\DdnsUpdater;
use Ddns\Domain\Update\RecordUpdate;
use Ddns\Domain\Update\UpdateOutcome;
use Ddns\Domain\Update\UpdateReport;
use Ddns\Ip\StaticIpResolver;
use Ddns\Tests\Support\FakeDnsProvider;
use Ddns\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The decision matrix that makes dynamic DNS correct: create, update, or leave
 * well alone.
 */
final class DdnsUpdaterTest extends TestCase
{
    public function testCreatesAMissingRecord(): void
    {
        $provider = new FakeDnsProvider();
        $host = Fixtures::host(ttl: 60);

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'));

        self::assertSame(UpdateOutcome::Created, $report->status());
        self::assertTrue($report->hasChanges());
        self::assertSame(['create:home.example.com:A:203.0.113.7:60'], $provider->writeCalls());
    }

    public function testUpdatesAStaleRecord(): void
    {
        $host = Fixtures::host(ttl: 60);
        $provider = (new FakeDnsProvider())->seed($host->hostname(), RecordType::A, '198.51.100.1', 60);

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'));

        self::assertSame(UpdateOutcome::Updated, $report->status());
        self::assertSame('198.51.100.1', $this->record($report)->previousValue());
        self::assertCount(1, $provider->writeCalls());
        self::assertSame('203.0.113.7', $provider->currentValue($host->hostname(), RecordType::A));
    }

    /**
     * The property that makes a tight poll interval safe: an already-correct
     * record must cost zero writes.
     */
    public function testWritesNothingWhenTheRecordIsAlreadyCorrect(): void
    {
        $host = Fixtures::host(ttl: 60);
        $provider = (new FakeDnsProvider())->seed($host->hostname(), RecordType::A, '203.0.113.7', 60);

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'));

        self::assertSame(UpdateOutcome::Unchanged, $report->status());
        self::assertFalse($report->hasChanges());
        self::assertSame([], $provider->writeCalls());
    }

    public function testRewritesWhenOnlyTheTtlDiffers(): void
    {
        $host = Fixtures::host(ttl: 60);
        $provider = (new FakeDnsProvider())->seed($host->hostname(), RecordType::A, '203.0.113.7', 3600);

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'));

        self::assertSame(UpdateOutcome::Updated, $report->status());
    }

    /**
     * A provider that does not report TTLs must not be treated as permanently
     * out of date, or every poll would rewrite the record.
     */
    public function testTreatsAnAbsentTtlAsSatisfied(): void
    {
        $host = Fixtures::host(ttl: 60);
        $provider = (new FakeDnsProvider())->seed($host->hostname(), RecordType::A, '203.0.113.7', null);

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'));

        self::assertSame(UpdateOutcome::Unchanged, $report->status());
        self::assertSame([], $provider->writeCalls());
    }

    public function testComparesIpv6Canonically(): void
    {
        $host = Fixtures::host(types: [RecordType::AAAA], ttl: 60);
        $provider = (new FakeDnsProvider())->seed(
            $host->hostname(),
            RecordType::AAAA,
            '2001:0db8:0000:0000:0000:0000:0000:0001',
            60,
        );

        $report = $this->updater($provider)->update($host, $this->resolver('2001:db8::1'));

        self::assertSame(UpdateOutcome::Unchanged, $report->status());
        self::assertSame([], $provider->writeCalls());
    }

    public function testDryRunReportsTheChangeWithoutWriting(): void
    {
        $host = Fixtures::host(ttl: 60);
        $provider = (new FakeDnsProvider())->seed($host->hostname(), RecordType::A, '198.51.100.1', 60);

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'), dryRun: true);

        self::assertSame(UpdateOutcome::Updated, $report->status());
        self::assertTrue($this->record($report)->isDryRun());
        self::assertSame([], $provider->writeCalls(), 'A dry run must not write.');
        self::assertSame('198.51.100.1', $provider->currentValue($host->hostname(), RecordType::A));
    }

    public function testDryRunOfAMissingRecordReportsCreateWithoutWriting(): void
    {
        $provider = new FakeDnsProvider();

        $report = $this->updater($provider)->update(Fixtures::host(), $this->resolver('203.0.113.7'), dryRun: true);

        self::assertSame(UpdateOutcome::Created, $report->status());
        self::assertSame([], $provider->writeCalls());
    }

    /**
     * An IPv4-only link must still keep the A record fresh; the AAAA record is
     * reported as skipped rather than failing the whole host.
     */
    public function testSkipsAFamilyWithNoAvailableAddressButStillUpdatesTheOther(): void
    {
        $host = Fixtures::host(types: [RecordType::A, RecordType::AAAA], ttl: 60);
        $provider = new FakeDnsProvider();

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'));

        self::assertTrue($report->isSuccessful());
        self::assertSame(UpdateOutcome::Created, $this->record($report)->outcome());
        self::assertSame(UpdateOutcome::Skipped, $this->record($report, 1)->outcome());
        self::assertStringContainsString('no IPv6 address', (string) $this->record($report, 1)->reason());
        self::assertSame(['create:home.example.com:A:203.0.113.7:60'], $provider->writeCalls());
    }

    public function testHandlesBothFamiliesWhenBothAreAvailable(): void
    {
        $host = Fixtures::host(types: [RecordType::A, RecordType::AAAA], ttl: 60);
        $provider = new FakeDnsProvider();

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7', '2001:db8::1'));

        self::assertTrue($report->isSuccessful());
        self::assertCount(2, $provider->writeCalls());
    }

    public function testRefusesToPublishAPrivateAddressByDefault(): void
    {
        $provider = new FakeDnsProvider();

        $report = $this->updater($provider)->update(Fixtures::host(), $this->resolver('192.168.1.10'));

        self::assertFalse($report->isSuccessful());
        self::assertSame(UpdateOutcome::Failed, $report->status());
        self::assertSame(422, $report->suggestedHttpStatus());
        self::assertStringContainsString('non-public address', (string) $this->record($report)->reason());
        self::assertSame([], $provider->writeCalls());
    }

    public function testPublishesAPrivateAddressWhenExplicitlyAllowed(): void
    {
        $provider = new FakeDnsProvider();
        $updater = $this->updater($provider, new ServerConfig(allowPrivateIps: true));

        $report = $updater->update(Fixtures::host(), $this->resolver('192.168.1.10'));

        self::assertTrue($report->isSuccessful());
        self::assertCount(1, $provider->writeCalls());
    }

    public function testReportsAProviderFailureWithItsSuggestedStatus(): void
    {
        $provider = (new FakeDnsProvider())->failWith(AuthenticationFailed::for('fake'));

        $report = $this->updater($provider)->update(Fixtures::host(), $this->resolver('203.0.113.7'));

        self::assertFalse($report->isSuccessful());
        self::assertSame(502, $report->suggestedHttpStatus());
    }

    public function testPropagatesARateLimitAs429(): void
    {
        $provider = (new FakeDnsProvider())->failWith(RateLimited::for('fake', 30));

        $report = $this->updater($provider)->update(Fixtures::host(), $this->resolver('203.0.113.7'));

        self::assertSame(429, $report->suggestedHttpStatus());
    }

    /**
     * One family failing must not prevent the other from being attempted.
     */
    public function testAFailureOnOneRecordDoesNotAbortTheOthers(): void
    {
        $host = Fixtures::host(types: [RecordType::A, RecordType::AAAA], ttl: 60);
        $provider = (new FakeDnsProvider())->failWith(AuthenticationFailed::for('fake'));

        $report = $this->updater($provider)->update($host, $this->resolver('203.0.113.7', '2001:db8::1'));

        self::assertCount(2, $report->records());
        self::assertCount(2, $report->failures());
    }

    public function testSerialisesToTheApiShape(): void
    {
        $host = Fixtures::host(ttl: 60);
        $provider = (new FakeDnsProvider())->seed($host->hostname(), RecordType::A, '198.51.100.1', 60);

        $payload = $this->updater($provider)->update($host, $this->resolver('203.0.113.7'))->toArray();

        self::assertSame('home', $payload['host']);
        self::assertSame('home.example.com', $payload['fqdn']);
        self::assertSame('updated', $payload['status']);
        self::assertTrue($payload['changed']);
        self::assertSame('203.0.113.7', $payload['records'][0]['ip'] ?? null);
        self::assertSame('198.51.100.1', $payload['records'][0]['previous'] ?? null);
    }

    public function testUpdateManyReturnsOneReportPerHost(): void
    {
        $provider = new FakeDnsProvider();
        $hosts = [
            Fixtures::host(name: 'home', record: 'home'),
            Fixtures::host(name: 'office', record: 'office'),
        ];

        $reports = $this->updater($provider)->updateMany($hosts, $this->resolver('203.0.113.7'));

        self::assertCount(2, $reports);
        self::assertSame(['home', 'office'], array_map(
            static fn (UpdateReport $r): string => $r->host(),
            $reports,
        ));
    }

    /**
     * The record at an index, failing the test rather than tripping over null.
     */
    private function record(UpdateReport $report, int $index = 0): RecordUpdate
    {
        return $report->records()[$index] ?? self::fail(sprintf('No record at index %d.', $index));
    }

    private function updater(FakeDnsProvider $provider, ?ServerConfig $server = null): DdnsUpdater
    {
        return new DdnsUpdater(
            new Configuration($server ?? new ServerConfig(), [], []),
            $provider,
        );
    }

    private function resolver(string ...$addresses): StaticIpResolver
    {
        return new StaticIpResolver(...array_map(
            static fn (string $value): IpAddress => IpAddress::fromString($value),
            $addresses,
        ));
    }
}
