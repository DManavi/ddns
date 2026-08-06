<?php

declare(strict_types=1);

namespace Ddns\Domain\Record;

/**
 * An address record as it currently exists at a provider.
 */
final class DnsRecord
{
    /**
     * @param string   $id  provider-assigned identifier used for updates
     * @param int|null $ttl null when the provider does not expose a TTL
     */
    public function __construct(
        private readonly string $id,
        private readonly Hostname $hostname,
        private readonly RecordType $type,
        private readonly string $value,
        private readonly ?int $ttl = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hostname(): Hostname
    {
        return $this->hostname;
    }

    public function type(): RecordType
    {
        return $this->type;
    }

    /**
     * The record's raw value as reported by the provider.
     */
    public function value(): string
    {
        return $this->value;
    }

    public function ttl(): ?int
    {
        return $this->ttl;
    }

    /**
     * The record value parsed as an IP address, or null if it is not one.
     */
    public function ipAddress(): ?IpAddress
    {
        return IpAddress::tryFromString($this->value);
    }

    /**
     * Whether the record already resolves to the given address.
     */
    public function pointsTo(IpAddress $ip): bool
    {
        $current = $this->ipAddress();

        return $current !== null && $current->equals($ip);
    }

    /**
     * Whether the record already has the requested TTL.
     *
     * A provider that does not report TTLs is treated as satisfied, so a
     * missing TTL never forces a pointless write on every poll.
     */
    public function hasTtl(int $ttl): bool
    {
        return $this->ttl === null || $this->ttl === $ttl;
    }

    /**
     * Whether the record is already in the desired state.
     */
    public function isUpToDate(IpAddress $ip, int $ttl): bool
    {
        return $this->pointsTo($ip) && $this->hasTtl($ttl);
    }

    public function withValue(IpAddress $ip, int $ttl): self
    {
        return new self($this->id, $this->hostname, $this->type, $ip->value(), $ttl);
    }
}
