<?php

declare(strict_types=1);

namespace Ddns\Domain\Record;

use Ddns\Support\CidrMatcher;

/**
 * A validated IP address that knows which DNS record type it belongs in.
 *
 * IPv6 values are stored in their canonical compressed form so that two
 * addresses written differently (for example `2001:db8::1` and
 * `2001:0db8:0000:0000:0000:0000:0000:0001`) compare as equal. Without this,
 * every poll would look like a change and would burn provider rate limit.
 */
final class IpAddress implements \Stringable
{
    /**
     * Ranges that must never end up in a public DNS record.
     *
     * @var list<string>
     */
    private const NON_ROUTABLE_RANGES = [
        // IPv4
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // RFC 1918 private
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, and the cloud metadata address
        '172.16.0.0/12',      // RFC 1918 private
        '192.168.0.0/16',     // RFC 1918 private
        // Carrier-grade NAT. A client behind it has no address of its own to
        // publish, so a record pointing here would never resolve to them - a
        // real and easily missed dynamic DNS failure.
        '100.64.0.0/10',
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including the broadcast address

        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped
        'fc00::/7',           // unique local, the RFC 1918 equivalent
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    private function __construct(
        private readonly string $value,
        private readonly RecordType $recordType,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the value is not a valid IP address
     */
    public static function fromString(string $value): self
    {
        $address = self::tryFromString($value);

        if ($address === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid IP address.', $value));
        }

        return $address;
    }

    /**
     * Non-throwing counterpart to {@see self::fromString()}.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $candidate = trim($value);

        if ($candidate === '') {
            return null;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return new self($candidate, RecordType::A);
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return new self(self::canonicalise($candidate), RecordType::AAAA);
        }

        return null;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function recordType(): RecordType
    {
        return $this->recordType;
    }

    public function version(): int
    {
        return $this->recordType->ipVersion();
    }

    /**
     * Whether the address is usable as the target of a public DNS record.
     *
     * Pointing a record at an RFC1918 or loopback address is almost always a
     * misconfiguration - typically a reverse proxy that was trusted when it
     * should not have been - so callers use this to warn or refuse.
     *
     * The ranges are listed explicitly rather than delegating to
     * FILTER_FLAG_NO_RES_RANGE, because that flag is not stable across PHP
     * versions: PHP 8.2 treats the documentation prefix 2001:db8::/32 as
     * reserved while 8.3 does not, so the same address would be accepted on one
     * runtime and refused on another. It is also inconsistent between address
     * families, treating the IPv4 documentation ranges as routable but not the
     * IPv6 one. Deciding here keeps the answer the same everywhere.
     *
     * Documentation ranges are deliberately treated as routable. They are what
     * RFC 5737 and RFC 3849 exist for, this project's own examples and tests
     * use them, and nobody is issued one as a real address by accident - unlike
     * everything below, which is exactly what a misconfiguration looks like.
     */
    public function isPublic(): bool
    {
        return !CidrMatcher::matchesAny($this->value, self::NON_ROUTABLE_RANGES);
    }

    public function equals(self $other): bool
    {
        return $this->recordType === $other->recordType && $this->value === $other->value;
    }

    /**
     * Whether this address could satisfy the given record type.
     */
    public function matches(RecordType $type): bool
    {
        return $this->recordType === $type;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function canonicalise(string $ipv6): string
    {
        $packed = @inet_pton($ipv6);

        if ($packed === false) {
            return $ipv6;
        }

        $normalised = @inet_ntop($packed);

        return $normalised === false ? $ipv6 : $normalised;
    }
}
