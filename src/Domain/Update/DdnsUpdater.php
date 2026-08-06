<?php

declare(strict_types=1);

namespace Ddns\Domain\Update;

use Ddns\Config\Configuration;
use Ddns\Config\HostConfig;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\ProviderException;
use Ddns\Domain\Provider\ProviderLocator;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Ip\Exception\IpResolutionFailed;
use Ddns\Ip\IpResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The single use case behind both the HTTP API and the CLI.
 *
 * Everything that makes dynamic DNS correct lives here rather than in any
 * provider: deciding between create and update, refusing to write when nothing
 * changed, defaulting the TTL, and handling a host that tracks both address
 * families. A provider only has to know how to talk to its own API.
 */
final class DdnsUpdater
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly ProviderLocator $providers,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Reconcile every record type configured for a host.
     *
     * A failure on one record type does not abort the others: an IPv4-only
     * connection should still keep the A record fresh even though the AAAA
     * lookup came up empty.
     */
    public function update(HostConfig $host, IpResolver $resolver, bool $dryRun = false): UpdateReport
    {
        $records = [];

        try {
            $provider = $this->providers->forProvider($host->providerName());
        } catch (ProviderException $e) {
            foreach ($host->recordTypes() as $type) {
                $records[] = RecordUpdate::failed($type, $e->getMessage(), $e->suggestedHttpStatus());
            }

            return new UpdateReport($host->name(), $host->hostname()->fqdn(), $records);
        }

        foreach ($host->recordTypes() as $type) {
            $records[] = $this->reconcile($provider, $host, $type, $resolver, $dryRun);
        }

        $report = new UpdateReport($host->name(), $host->hostname()->fqdn(), $records);

        $this->log($report);

        return $report;
    }

    /**
     * @param list<HostConfig> $hosts
     *
     * @return list<UpdateReport>
     */
    public function updateMany(array $hosts, IpResolver $resolver, bool $dryRun = false): array
    {
        return array_map(
            fn (HostConfig $host): UpdateReport => $this->update($host, $resolver, $dryRun),
            $hosts,
        );
    }

    private function reconcile(
        DnsProvider $provider,
        HostConfig $host,
        RecordType $type,
        IpResolver $resolver,
        bool $dryRun,
    ): RecordUpdate {
        try {
            $ip = $resolver->tryResolve($type);
        } catch (IpResolutionFailed $e) {
            return RecordUpdate::skipped($type, $e->getMessage());
        }

        if ($ip === null) {
            return RecordUpdate::skipped($type, sprintf(
                'no IPv%d address was available for this client',
                $type->ipVersion(),
            ));
        }

        if ($rejection = $this->rejectNonPublic($ip)) {
            return RecordUpdate::failed($type, $rejection, 422);
        }

        try {
            $existing = $provider->findRecord($host->hostname(), $type);

            if ($existing !== null && $existing->isUpToDate($ip, $host->ttl())) {
                return RecordUpdate::unchanged($type, $ip);
            }

            if ($existing === null) {
                if (!$dryRun) {
                    $provider->createRecord($host->hostname(), $type, $ip, $host->ttl());
                }

                return RecordUpdate::created($type, $ip, $dryRun);
            }

            if (!$dryRun) {
                $provider->updateRecord($existing, $ip, $host->ttl());
            }

            return RecordUpdate::updated($type, $ip, $existing->value(), $dryRun);
        } catch (ProviderException $e) {
            return RecordUpdate::failed($type, $e->getMessage(), $e->suggestedHttpStatus());
        }
    }

    /**
     * Publishing an RFC1918, loopback or link-local address to a public zone is
     * almost always a symptom of a misconfigured reverse proxy, so it is
     * refused unless explicitly permitted.
     */
    private function rejectNonPublic(IpAddress $ip): ?string
    {
        if ($ip->isPublic() || $this->configuration->server()->allowPrivateIps()) {
            return null;
        }

        return sprintf(
            'refusing to publish the non-public address %s; if this is intentional, '
            . 'set server.allow_private_ips to true',
            $ip->value(),
        );
    }

    private function log(UpdateReport $report): void
    {
        foreach ($report->records() as $record) {
            $context = [
                'host' => $report->host(),
                'fqdn' => $report->fqdn(),
                'type' => $record->type()->value,
                'ip' => $record->ip()?->value(),
            ];

            match (true) {
                $record->outcome()->isFailure() => $this->logger->error($record->describe(), $context),
                $record->outcome()->isChange() => $this->logger->info($record->describe(), $context),
                default => $this->logger->debug($record->describe(), $context),
            };
        }
    }
}
