<?php

declare(strict_types=1);

namespace Ddns\Domain\Record;

/**
 * A hostname expressed as a zone plus a relative name.
 *
 * Providers disagree about how records are addressed: DigitalOcean and Vultr
 * want the relative name (`home`, or `@` for the zone apex), while Cloudflare
 * and Route53 want the fully qualified name. Keeping both halves lets each
 * adapter ask for whichever form it needs without re-parsing strings.
 */
final class Hostname implements \Stringable
{
    public const APEX = '@';

    private function __construct(
        private readonly string $zone,
        private readonly string $name,
    ) {
    }

    /**
     * Build a hostname from a zone and a name relative to it.
     *
     * The name is forgiving on input: `@`, an empty string, the zone itself and
     * a fully qualified name inside the zone all resolve correctly.
     *
     * @throws \InvalidArgumentException when the zone or name is malformed, or
     *                                   when the name falls outside the zone
     */
    public static function create(string $zone, string $name = self::APEX): self
    {
        $zone = self::normalise($zone);

        if ($zone === '') {
            throw new \InvalidArgumentException('A DNS zone cannot be empty.');
        }

        self::assertValidDomain($zone, 'zone');

        $name = self::normalise($name);

        if ($name === '' || $name === self::APEX || $name === $zone) {
            return new self($zone, self::APEX);
        }

        // Accept a fully qualified name and reduce it to the relative label(s).
        $suffix = '.' . $zone;

        if (str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        } elseif (self::looksAbsolute($name)) {
            throw new \InvalidArgumentException(sprintf(
                'Hostname "%s" looks like a fully qualified name but does not belong to zone "%s". '
                . 'Names are relative to the zone by default; write the whole name including the zone '
                . '(for example "%s.%s") if that is what you meant.',
                $name,
                $zone,
                $name,
                $zone,
            ));
        }

        if ($name === '') {
            return new self($zone, self::APEX);
        }

        self::assertValidDomain($name, 'record name');

        return new self($zone, $name);
    }

    /**
     * The zone the record lives in, without a trailing dot.
     */
    public function zone(): string
    {
        return $this->zone;
    }

    /**
     * The name relative to the zone, or `@` for the apex.
     */
    public function name(): string
    {
        return $this->name;
    }

    public function isApex(): bool
    {
        return $this->name === self::APEX;
    }

    /**
     * The fully qualified name, without a trailing dot.
     */
    public function fqdn(): string
    {
        return $this->isApex() ? $this->zone : $this->name . '.' . $this->zone;
    }

    /**
     * The fully qualified name with a trailing dot, as Route53 and zone files use.
     */
    public function fqdnWithRoot(): string
    {
        return $this->fqdn() . '.';
    }

    /**
     * Compare against a name returned by a provider, tolerating case
     * differences, trailing dots, and relative-versus-absolute forms.
     */
    public function matchesProviderName(string $candidate): bool
    {
        $candidate = self::normalise($candidate);

        if ($candidate === '') {
            return $this->isApex();
        }

        return $candidate === $this->name
            || $candidate === $this->fqdn()
            || ($this->isApex() && $candidate === $this->zone);
    }

    public function equals(self $other): bool
    {
        return $this->zone === $other->zone && $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->fqdn();
    }

    /**
     * Lower-case, trim surrounding whitespace and drop any trailing root dot.
     */
    private static function normalise(string $value): string
    {
        return rtrim(strtolower(trim($value)), '.');
    }

    /**
     * Whether a name looks like an absolute domain rather than a relative label.
     *
     * A multi-label name ending in something TLD-shaped is treated as absolute.
     * Combined with the caller's zone-suffix check, this turns the common typo
     * of naming a host in the wrong zone into an error instead of quietly
     * creating "home.example.org.example.com".
     *
     * There is always an escape hatch: a relative name whose last label happens
     * to look like a TLD can be written out in full, including the zone.
     */
    private static function looksAbsolute(string $name): bool
    {
        $labels = explode('.', $name);

        if (count($labels) < 2) {
            return false;
        }

        return preg_match('/^[a-z]{2,}$/', end($labels)) === 1;
    }

    private static function assertValidDomain(string $value, string $subject): void
    {
        if (strlen($value) > 253) {
            throw new \InvalidArgumentException(sprintf('The %s "%s" exceeds 253 characters.', $subject, $value));
        }

        foreach (explode('.', $value) as $label) {
            // `*` is allowed as a whole label so wildcard records remain expressible.
            if ($label === '*') {
                continue;
            }

            if (preg_match('/^(?!-)[a-z0-9_-]{1,63}(?<!-)$/', $label) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s "%s" contains an invalid label "%s".',
                    $subject,
                    $value,
                    $label,
                ));
            }
        }
    }
}
