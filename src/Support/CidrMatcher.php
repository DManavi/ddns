<?php

declare(strict_types=1);

namespace Ddns\Support;

/**
 * IPv4/IPv6 CIDR containment checks.
 *
 * Used to decide whether a request arrived from a proxy we trust, which
 * governs whether its `X-Forwarded-For` header is believed. Getting this wrong
 * would let any caller claim any source address, so the comparison is done on
 * packed binary form rather than on strings.
 */
final class CidrMatcher
{
    /**
     * Whether a string is a bare IP address or a well-formed CIDR range.
     */
    public static function isValidRange(string $range): bool
    {
        return self::parse($range) !== null;
    }

    /**
     * Whether an address falls inside a single range.
     */
    public static function matches(string $ip, string $range): bool
    {
        $parsed = self::parse($range);

        if ($parsed === null) {
            return false;
        }

        [$network, $prefix] = $parsed;

        $address = @inet_pton($ip);

        if ($address === false || strlen($address) !== strlen($network)) {
            return false;
        }

        return self::sharesPrefix($address, $network, $prefix);
    }

    /**
     * @param list<string> $ranges
     */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: int}|null packed network address and prefix length
     */
    private static function parse(string $range): ?array
    {
        $range = trim($range);

        if ($range === '') {
            return null;
        }

        $prefix = null;

        if (str_contains($range, '/')) {
            $parts = explode('/', $range, 2);
            $address = $parts[0];
            $prefixPart = $parts[1] ?? '';

            if (preg_match('/^\d{1,3}$/', $prefixPart) !== 1) {
                return null;
            }

            $prefix = (int) $prefixPart;
        } else {
            $address = $range;
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if ($prefix === null) {
            $prefix = $bits;
        }

        if ($prefix > $bits) {
            return null;
        }

        return [$packed, $prefix];
    }

    private static function sharesPrefix(string $address, string $network, int $prefix): bool
    {
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($address, $network, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
