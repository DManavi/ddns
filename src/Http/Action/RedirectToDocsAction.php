<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /`
 *
 * Sends a browser landing on the root to the documentation.
 *
 * Temporary rather than permanent on purpose: a 301 is cached by browsers
 * indefinitely and is painful to undo, and the root is a reasonable place for
 * something else to live later. A 302 costs one extra request and keeps that
 * decision reversible.
 */
final class RedirectToDocsAction
{
    public const TARGET = '/api';

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(302)
            // A path rather than an absolute URL: behind a TLS-terminating
            // proxy the request's own scheme and host are the proxy's inward
            // view, and guessing the outward one sends the client somewhere
            // that may not exist.
            ->withHeader('Location', $this->target($request))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function target(ServerRequestInterface $request): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        return ($path === '' ? '' : $path) . self::TARGET;
    }
}
