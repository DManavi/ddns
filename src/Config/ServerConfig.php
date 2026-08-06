<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * Global server behaviour that is not tied to a single host or provider.
 */
final class ServerConfig
{
    /**
     * @param list<string> $trustedProxies CIDR ranges whose `X-Forwarded-For` we honour
     * @param list<string> $ipv4Services   echo endpoints returning the caller's IPv4
     * @param list<string> $ipv6Services   echo endpoints returning the caller's IPv6
     */
    public function __construct(
        private readonly int $defaultTtl = 300,
        private readonly array $trustedProxies = [],
        private readonly array $ipv4Services = [],
        private readonly array $ipv6Services = [],
        private readonly float $ipLookupTimeout = 5.0,
        private readonly bool $allowPrivateIps = false,
    ) {
    }

    public function defaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Empty by default: with no trusted proxies configured we use the socket
     * peer address and ignore forwarding headers entirely. Trusting
     * `X-Forwarded-For` unconditionally would let any caller point a record
     * anywhere, so this has to be opt-in.
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        return $this->trustedProxies;
    }

    public function trustsAnyProxy(): bool
    {
        return $this->trustedProxies !== [];
    }

    /**
     * @return list<string>
     */
    public function ipv4Services(): array
    {
        return $this->ipv4Services;
    }

    /**
     * @return list<string>
     */
    public function ipv6Services(): array
    {
        return $this->ipv6Services;
    }

    public function ipLookupTimeout(): float
    {
        return $this->ipLookupTimeout;
    }

    /**
     * Whether records may point at RFC1918 / loopback / link-local addresses.
     *
     * Off by default, because a private address in a public zone almost always
     * means a misconfigured reverse proxy rather than an intentional choice.
     */
    public function allowPrivateIps(): bool
    {
        return $this->allowPrivateIps;
    }

    /**
     * @return array{default_ttl: int, trusted_proxies: list<string>, allow_private_ips: bool, ip_lookup_timeout: float}
     */
    public function toArray(): array
    {
        return [
            'default_ttl' => $this->defaultTtl,
            'trusted_proxies' => $this->trustedProxies,
            'allow_private_ips' => $this->allowPrivateIps,
            'ip_lookup_timeout' => $this->ipLookupTimeout,
        ];
    }
}
