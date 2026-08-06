<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Update\DdnsUpdater;
use Ddns\Http\Middleware\AuthenticationMiddleware;
use Ddns\Http\Middleware\TrustedProxyMiddleware;
use Ddns\Http\Responder\JsonResponder;
use Ddns\Ip\ChainIpResolver;
use Ddns\Ip\StaticIpResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET|POST /v1/hosts/{host}/update`
 *
 * An explicitly supplied address wins; otherwise the request's own source
 * address is used, which is what makes a bare `curl` from behind the router
 * enough to keep a record current.
 */
final class UpdateAction
{
    /** Accepted aliases for the address parameter, in precedence order. */
    private const IP_PARAMETERS = ['ip', 'myip', 'ipv4', 'ipv6'];

    public function __construct(
        private readonly DdnsUpdater $updater,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $host = AuthenticationMiddleware::hostFrom($request);

        try {
            $explicit = $this->explicitAddresses($request);
        } catch (\InvalidArgumentException $e) {
            return $this->responder->error($e->getMessage(), 422, 'invalid_ip');
        }

        $resolvers = [];

        if ($explicit !== []) {
            $resolvers[] = new StaticIpResolver(...$explicit);
        }

        $detected = TrustedProxyMiddleware::clientIpFrom($request);

        if ($detected !== null) {
            $resolvers[] = new StaticIpResolver($detected);
        }

        if ($resolvers === []) {
            return $this->responder->error(
                'No address could be determined: none was supplied and the request source address is unavailable.',
                422,
                'no_ip',
            );
        }

        $report = $this->updater->update(
            $host,
            new ChainIpResolver(...$resolvers),
            $this->isDryRun($request),
        );

        return $this->responder->respond([
            ...$report->toArray(),
            'client_ip' => $detected?->value(),
        ], $report->suggestedHttpStatus());
    }

    /**
     * Collect caller-supplied addresses from the query string or JSON body.
     *
     * Values may repeat or be comma separated so a dual-stack client can send
     * both families in one request.
     *
     * @return list<IpAddress>
     *
     * @throws \InvalidArgumentException on a malformed address
     */
    private function explicitAddresses(ServerRequestInterface $request): array
    {
        $raw = [];

        foreach ([$request->getQueryParams(), $this->body($request)] as $source) {
            foreach (self::IP_PARAMETERS as $parameter) {
                $value = $source[$parameter] ?? null;

                if (is_string($value)) {
                    $raw = [...$raw, ...explode(',', $value)];
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_string($item)) {
                            $raw[] = $item;
                        }
                    }
                }
            }
        }

        $addresses = [];

        foreach ($raw as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            // `auto` is a common convention for "work it out yourself"; treat it
            // as though no address had been supplied at all.
            if (strcasecmp($candidate, 'auto') === 0) {
                continue;
            }

            $address = IpAddress::tryFromString($candidate);

            if ($address === null) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a valid IP address.', $candidate));
            }

            $addresses[] = $address;
        }

        return $addresses;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return [];
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    private function isDryRun(ServerRequestInterface $request): bool
    {
        $value = $request->getQueryParams()['dry_run'] ?? $this->body($request)['dry_run'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
