<?php

declare(strict_types=1);

namespace Ddns\Http\Middleware;

use Ddns\Domain\Record\IpAddress;
use Ddns\Ip\ClientIpDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the request's real source address once, up front.
 *
 * Runs before authentication so the detected address is available to
 * everything downstream, including error logging for rejected requests.
 */
final class TrustedProxyMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'ddns.client_ip';

    public function __construct(private readonly ClientIpDetector $detector)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle(
            $request->withAttribute(self::ATTRIBUTE, $this->detector->detect($request)),
        );
    }

    public static function clientIpFrom(ServerRequestInterface $request): ?IpAddress
    {
        $ip = $request->getAttribute(self::ATTRIBUTE);

        return $ip instanceof IpAddress ? $ip : null;
    }
}
