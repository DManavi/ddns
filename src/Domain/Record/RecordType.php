<?php

declare(strict_types=1);

namespace Ddns\Domain\Record;

/**
 * The DNS record types this application is able to manage.
 *
 * Dynamic DNS only ever needs address records, so the set is deliberately
 * limited to A (IPv4) and AAAA (IPv6).
 */
enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';

    /**
     * The IP protocol version this record type carries.
     */
    public function ipVersion(): int
    {
        return match ($this) {
            self::A => 4,
            self::AAAA => 6,
        };
    }

    public function isIpv4(): bool
    {
        return $this === self::A;
    }

    public function isIpv6(): bool
    {
        return $this === self::AAAA;
    }

    /**
     * Parse a record type from user input, accepting any casing.
     *
     * @throws \InvalidArgumentException when the value is not a supported type
     */
    public static function fromInput(string $value): self
    {
        $type = self::tryFrom(strtoupper(trim($value)));

        if ($type === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported record type "%s". Supported types are: %s.',
                $value,
                implode(', ', self::names()),
            ));
        }

        return $type;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
