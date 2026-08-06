<?php

declare(strict_types=1);

namespace Ddns\Ip;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;
use Ddns\Ip\Exception\IpResolutionFailed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Discovers the machine's public address by asking an echo service.
 *
 * Several endpoints are tried in order so a single provider outage does not
 * stop updates. Results are memoised for the lifetime of the instance, which is
 * what keeps a tight `watch` interval from hammering the echo services.
 */
final class HttpIpResolver implements IpResolver
{
    /** @var array<string, IpAddress|null> */
    private array $cache = [];

    /**
     * @param list<string> $ipv4Services
     * @param list<string> $ipv6Services
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly array $ipv4Services,
        private readonly array $ipv6Services,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function resolve(RecordType $type): IpAddress
    {
        $address = $this->tryResolve($type);

        if ($address === null) {
            throw IpResolutionFailed::allServicesFailed($type, $this->servicesFor($type));
        }

        return $address;
    }

    public function tryResolve(RecordType $type): ?IpAddress
    {
        if (array_key_exists($type->value, $this->cache)) {
            return $this->cache[$type->value];
        }

        return $this->cache[$type->value] = $this->lookup($type);
    }

    /**
     * Drop memoised results so the next call performs a fresh lookup.
     *
     * The `watch` loop calls this each cycle: caching within a cycle avoids
     * duplicate lookups across hosts, but caching across cycles would defeat
     * the point of watching.
     */
    public function forget(): void
    {
        $this->cache = [];
    }

    private function lookup(RecordType $type): ?IpAddress
    {
        foreach ($this->servicesFor($type) as $service) {
            $address = $this->query($service, $type);

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    private function query(string $service, RecordType $type): ?IpAddress
    {
        try {
            $request = $this->requestFactory
                ->createRequest('GET', $service)
                ->withHeader('Accept', 'text/plain')
                ->withHeader('User-Agent', 'ddns/1.0 (+https://github.com/DManavi/ddns)');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->debug('IP lookup service returned a non-200 status.', [
                    'service' => $service,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            $address = IpAddress::tryFromString($response->getBody()->getContents());

            if ($address === null) {
                $this->logger->debug('IP lookup service returned an unparseable body.', ['service' => $service]);

                return null;
            }

            // An IPv6-capable endpoint may answer with IPv4 on a dual-stack
            // host; returning it for an AAAA record would create a broken record.
            if (!$address->matches($type)) {
                $this->logger->debug('IP lookup service returned the wrong address family.', [
                    'service' => $service,
                    'expected' => $type->value,
                    'got' => $address->recordType()->value,
                ]);

                return null;
            }

            return $address;
        } catch (ClientExceptionInterface $e) {
            $this->logger->debug('IP lookup service was unreachable.', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function servicesFor(RecordType $type): array
    {
        return $type->isIpv6() ? $this->ipv6Services : $this->ipv4Services;
    }
}
