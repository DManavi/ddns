<?php

declare(strict_types=1);

namespace Ddns\Domain\Record;

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
     * Whether the address is globally routable.
     *
     * Pointing a public DNS record at an RFC1918 or loopback address is almost
     * always a misconfiguration (typically a reverse proxy that was trusted
     * when it should not have been), so callers can use this to warn or refuse.
     */
    public function isPublic(): bool
    {
        return filter_var(
            $this->value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
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
