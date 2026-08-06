<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Ddns\Http\Middleware\AuthenticationMiddleware;
use Ddns\Http\Middleware\TrustedProxyMiddleware;
use Ddns\Http\Responder\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /v1/hosts/{host}`
 *
 * Reports a host's own configuration, with the token redacted, plus the address
 * the server believes the caller is coming from. Useful for verifying trusted
 * proxy setup without performing a write.
 *
 * A client only ever sees the host its token authenticates for; there is
 * deliberately no endpoint that lists every configured host.
 */
final class ShowHostAction
{
    public function __construct(private readonly JsonResponder $responder)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $host = AuthenticationMiddleware::hostFrom($request);

        return $this->responder->respond([
            'host' => $host->toRedactedArray(),
            'client_ip' => TrustedProxyMiddleware::clientIpFrom($request)?->value(),
        ]);
    }
}
