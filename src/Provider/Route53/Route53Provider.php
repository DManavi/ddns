<?php

declare(strict_types=1);

namespace Ddns\Provider\Route53;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Result;
use Aws\Route53\Route53Client;
use Ddns\Domain\Provider\DnsProvider;
use Ddns\Domain\Provider\Exception\AuthenticationFailed;
use Ddns\Domain\Provider\Exception\ProviderException;
use Ddns\Domain\Provider\Exception\RateLimited;
use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Ddns\Domain\Provider\Exception\ZoneNotFound;
use Ddns\Domain\Record\DnsRecord;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;

/**
 * AWS Route53 driver, built on aws-sdk-php.
 *
 * Route53 differs from the plain-REST drivers in three ways that shape this
 * class:
 *
 *  - Records have no identifier. A record set is addressed by name and type,
 *    so {@see DnsRecord::id()} carries a synthetic one.
 *  - `UPSERT` creates or replaces in a single call, so create and update are
 *    the same request.
 *  - Zones are addressed by ID, not by name, so a lookup is needed first. IDs
 *    are stable, so the result is memoised for the life of the instance.
 *
 * @see https://docs.aws.amazon.com/Route53/latest/APIReference/API_Operations.html
 */
final class Route53Provider implements DnsProvider
{
    public const DRIVER = 'route53';

    /** Route53 is global; this is the canonical endpoint region. */
    public const DEFAULT_REGION = 'us-east-1';

    /** @var array<string, string> zone name => hosted zone ID */
    private array $zoneIds = [];

    public function __construct(
        private readonly Route53Client $client,
        private readonly ?string $configuredZoneId = null,
        /**
         * Whether to manage a private hosted zone rather than a public one.
         * Public is the default: in a split-horizon setup the public zone is
         * the one a dynamic DNS record is normally meant to reach.
         */
        private readonly bool $privateZone = false,
    ) {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function findRecord(Hostname $hostname, RecordType $type): ?DnsRecord
    {
        $zoneId = $this->zoneId($hostname->zone());

        // Record sets come back ordered, so starting exactly at the name and
        // type we want yields our record first if it exists at all. If it does
        // not, the next record in the zone is returned instead - hence the
        // explicit check below rather than trusting the position.
        $result = $this->call(
            'list DNS records',
            fn (): Result => $this->client->listResourceRecordSets([
                'HostedZoneId' => $zoneId,
                'StartRecordName' => $hostname->fqdnWithRoot(),
                'StartRecordType' => $type->value,
                // The API declares MaxItems as a string; an int fails validation.
                'MaxItems' => '1',
            ]),
        );

        $sets = $result['ResourceRecordSets'] ?? null;

        if (!is_array($sets)) {
            throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'list DNS records');
        }

        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }

            $name = $set['Name'] ?? null;
            $setType = $set['Type'] ?? null;

            if (!is_string($name) || !is_string($setType) || strcasecmp($setType, $type->value) !== 0) {
                continue;
            }

            if (!$hostname->matchesProviderName(self::decodeName($name))) {
                continue;
            }

            return $this->toRecord($hostname, $type, $set);
        }

        return null;
    }

    public function createRecord(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        return $this->upsert($hostname, $type, $ip, $ttl);
    }

    public function updateRecord(DnsRecord $record, IpAddress $ip, int $ttl): DnsRecord
    {
        return $this->upsert($record->hostname(), $record->type(), $ip, $ttl);
    }

    /**
     * Route53's UPSERT creates the record set or replaces it wholesale, so it
     * covers both operations and is safely repeatable.
     */
    private function upsert(Hostname $hostname, RecordType $type, IpAddress $ip, int $ttl): DnsRecord
    {
        $zoneId = $this->zoneId($hostname->zone());

        $this->call(
            'upsert DNS record',
            fn (): Result => $this->client->changeResourceRecordSets([
                'HostedZoneId' => $zoneId,
                'ChangeBatch' => [
                    'Comment' => 'Updated by ddns',
                    'Changes' => [[
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => $hostname->fqdnWithRoot(),
                            'Type' => $type->value,
                            'TTL' => $ttl,
                            'ResourceRecords' => [['Value' => $ip->value()]],
                        ],
                    ]],
                ],
            ]),
        );

        // Route53 answers with a change record rather than the resulting record
        // set, and the change is only eventually consistent, so the outcome is
        // reconstructed locally instead of read back.
        return new DnsRecord(self::syntheticId($hostname, $type), $hostname, $type, $ip->value(), $ttl);
    }

    /**
     * Resolve a zone name to its hosted zone ID.
     */
    private function zoneId(string $zone): string
    {
        if ($this->configuredZoneId !== null && $this->configuredZoneId !== '') {
            return self::normaliseZoneId($this->configuredZoneId);
        }

        if (isset($this->zoneIds[$zone])) {
            return $this->zoneIds[$zone];
        }

        $result = $this->call(
            'look up hosted zone',
            fn (): Result => $this->client->listHostedZonesByName([
                'DNSName' => $zone . '.',
                'MaxItems' => '100',
            ]),
        );

        $zones = $result['HostedZones'] ?? null;

        if (!is_array($zones)) {
            throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'look up hosted zone');
        }

        $matches = [];

        foreach ($zones as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $name = $candidate['Name'] ?? null;
            $id = $candidate['Id'] ?? null;

            if (!is_string($name) || !is_string($id)) {
                continue;
            }

            // The response is ordered by name and may run past the zone we
            // asked for, so an exact comparison is required.
            if (strcasecmp(rtrim($name, '.'), $zone) !== 0) {
                continue;
            }

            $config = $candidate['Config'] ?? [];
            $isPrivate = is_array($config) && ($config['PrivateZone'] ?? false) === true;

            if ($isPrivate !== $this->privateZone) {
                continue;
            }

            $matches[] = self::normaliseZoneId($id);
        }

        if ($matches === []) {
            throw ZoneNotFound::for(self::DRIVER, $this->privateZone ? $zone . ' (private)' : $zone);
        }

        // AWS permits several hosted zones with the same name. Guessing would
        // mean updating an arbitrary one, so ask the user to disambiguate.
        if (count($matches) > 1) {
            throw RecordOperationFailed::for(
                self::DRIVER,
                'look up hosted zone',
                sprintf(
                    'the account has %d hosted zones named "%s". Set "zone_id" on this provider to choose '
                    . 'one explicitly (candidates: %s).',
                    count($matches),
                    $zone,
                    implode(', ', $matches),
                ),
            );
        }

        return $this->zoneIds[$zone] = $matches[0];
    }

    /**
     * @param array<array-key, mixed> $set
     */
    private function toRecord(Hostname $hostname, RecordType $type, array $set): DnsRecord
    {
        // An alias record points at an AWS resource and carries no value of its
        // own. Overwriting one with a plain address record would silently
        // destroy, say, a CloudFront or load balancer target, so refuse.
        if (isset($set['AliasTarget'])) {
            throw RecordOperationFailed::for(
                self::DRIVER,
                'read DNS record',
                sprintf(
                    '"%s" is an alias record. Refusing to replace it with a plain %s record; '
                    . 'point this host at a name that is not an alias.',
                    $hostname->fqdn(),
                    $type->value,
                ),
            );
        }

        // Weighted, latency, geolocation and failover record sets share a name
        // and type and are told apart by SetIdentifier. An UPSERT that omits it
        // would be rejected, so say so now rather than at write time.
        if (isset($set['SetIdentifier'])) {
            $identifier = $set['SetIdentifier'];

            throw RecordOperationFailed::for(
                self::DRIVER,
                'read DNS record',
                sprintf(
                    '"%s" uses a routing policy (SetIdentifier "%s"), which this driver does not manage.',
                    $hostname->fqdn(),
                    is_string($identifier) ? $identifier : '?',
                ),
            );
        }

        $values = [];

        if (is_array($set['ResourceRecords'] ?? null)) {
            foreach ($set['ResourceRecords'] as $resourceRecord) {
                if (is_array($resourceRecord) && is_string($resourceRecord['Value'] ?? null)) {
                    $values[] = $resourceRecord['Value'];
                }
            }
        }

        if ($values === []) {
            throw RecordOperationFailed::unexpectedResponse(self::DRIVER, 'read DNS record');
        }

        $ttl = $set['TTL'] ?? null;

        // A multi-value record set is reported by its first value only. That
        // makes it compare as out of date whenever it holds anything besides
        // our address, so the next update collapses it to a single value -
        // which is what a dynamic DNS record is for.
        return new DnsRecord(
            self::syntheticId($hostname, $type),
            $hostname,
            $type,
            $values[0],
            is_int($ttl) ? $ttl : null,
        );
    }

    /**
     * Run an SDK call, translating AWS failures into the domain's exceptions.
     *
     * @param callable(): Result<string, mixed> $operation
     *
     * @return Result<string, mixed>
     *
     * @throws ProviderException
     */
    private function call(string $description, callable $operation): Result
    {
        try {
            return $operation();
        } catch (CredentialsException $e) {
            throw AuthenticationFailed::for(
                self::DRIVER,
                'No AWS credentials could be resolved. Set "key" and "secret" on the provider, export '
                . 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or attach an instance or task role. ('
                . $e->getMessage() . ')',
            );
        } catch (AwsException $e) {
            throw self::translate($e, $description);
        }
    }

    private static function translate(AwsException $e, string $description): ProviderException
    {
        $code = (string) $e->getAwsErrorCode();
        $detail = $e->getAwsErrorMessage() ?? $e->getMessage();

        return match (true) {
            in_array($code, ['NoSuchHostedZone', 'HostedZoneNotFound'], true) => ZoneNotFound::for(
                self::DRIVER,
                'the hosted zone in this request',
            ),
            in_array($code, [
                'AccessDenied',
                'AccessDeniedException',
                'AuthFailure',
                'InvalidClientTokenId',
                'MissingAuthenticationToken',
                'SignatureDoesNotMatch',
                'UnrecognizedClientException',
                'ExpiredToken',
                'ExpiredTokenException',
            ], true) => AuthenticationFailed::for(self::DRIVER, $detail),
            in_array($code, [
                'Throttling',
                'ThrottlingException',
                'TooManyRequestsException',
                'PriorRequestNotComplete',
                'RequestLimitExceeded',
            ], true) => RateLimited::for(self::DRIVER),
            default => RecordOperationFailed::for(
                self::DRIVER,
                $description,
                $code === '' ? $detail : sprintf('%s - %s', $code, $detail),
            ),
        };
    }

    /**
     * Route53 record sets have no ID, so one is derived from the identity the
     * API actually uses: the name and type.
     */
    private static function syntheticId(Hostname $hostname, RecordType $type): string
    {
        return $hostname->fqdn() . '/' . $type->value;
    }

    /**
     * Route53 returns names with non-printable and special characters escaped
     * as three-digit octal, so a wildcard arrives as `\052.example.com.`.
     *
     * @see https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/DomainNameFormat.html
     */
    private static function decodeName(string $name): string
    {
        if (!str_contains($name, '\\')) {
            return $name;
        }

        return preg_replace_callback(
            '/\\\\(\d{3})/',
            static fn (array $m): string => chr((int) octdec($m[1])),
            $name,
        ) ?? $name;
    }

    /**
     * The API returns `/hostedzone/Z123` but accepts either form; the bare ID
     * is what users see in the console.
     */
    private static function normaliseZoneId(string $id): string
    {
        $position = strrpos($id, '/');

        return $position === false ? $id : substr($id, $position + 1);
    }
}
