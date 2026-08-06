<?php

declare(strict_types=1);

namespace Ddns\Ip;

use Ddns\Config\ServerConfig;
use Ddns\Domain\Record\IpAddress;
use Ddns\Support\CidrMatcher;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Works out which address a request actually came from.
 *
 * Forwarding headers are trivially forgeable, so they are only consulted when
 * the immediate peer is itself a configured trusted proxy. With no trusted
 * proxies configured - the default - the socket peer address is used and
 * headers are ignored completely. Anything else would let a caller point
 * someone else's record wherever they liked.
 */
final class ClientIpDetector
{
    private const FORWARDED_HEADERS = ['X-Forwarded-For', 'X-Real-IP'];

    public function __construct(private readonly ServerConfig $server)
    {
    }

    public function detect(ServerRequestInterface $request): ?IpAddress
    {
        $peer = $this->peerAddress($request);

        if ($peer === null) {
            return null;
        }

        if (!$this->server->trustsAnyProxy() || !$this->isTrustedProxy($peer)) {
            return IpAddress::tryFromString($peer);
        }

        return $this->fromForwardedHeaders($request) ?? IpAddress::tryFromString($peer);
    }

    private function peerAddress(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();
        $remote = $params['REMOTE_ADDR'] ?? null;

        if (!is_string($remote) || trim($remote) === '') {
            return null;
        }

        return $this->stripPort(trim($remote));
    }

    /**
     * Walk `X-Forwarded-For` from right to left and take the first address that
     * is not one of our own proxies. The rightmost entries were appended by
     * infrastructure we control and are therefore the trustworthy ones; the
     * leftmost entry is whatever the original client claimed.
     */
    private function fromForwardedHeaders(ServerRequestInterface $request): ?IpAddress
    {
        foreach (self::FORWARDED_HEADERS as $header) {
            $value = $request->getHeaderLine($header);

            if ($value === '') {
                continue;
            }

            $candidates = array_reverse(array_map(
                fn (string $part): string => $this->stripPort(trim($part)),
                explode(',', $value),
            ));

            foreach ($candidates as $candidate) {
                if ($candidate === '' || $this->isTrustedProxy($candidate)) {
                    continue;
                }

                $address = IpAddress::tryFromString($candidate);

                if ($address !== null) {
                    return $address;
                }
            }
        }

        return null;
    }

    private function isTrustedProxy(string $ip): bool
    {
        return CidrMatcher::matchesAny($ip, $this->server->trustedProxies());
    }

    /**
     * Accepts `1.2.3.4:5678`, `[2001:db8::1]:5678` and bare addresses.
     */
    private function stripPort(string $value): string
    {
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');

            return $end === false ? $value : substr($value, 1, $end - 1);
        }

        // Only strip a port from IPv4: a bare IPv6 address is full of colons.
        if (substr_count($value, ':') === 1) {
            return substr($value, 0, (int) strpos($value, ':'));
        }

        return $value;
    }
}
