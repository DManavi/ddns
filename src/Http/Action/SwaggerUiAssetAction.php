<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Ddns\Http\OpenApi\SwaggerUiAssets;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * `GET /vendor/swagger-ui/{file}`
 *
 * Serves a locally installed copy of the Swagger UI assets.
 *
 * Apache and nginx serve these themselves, since the files really are on disk
 * where the URL says they are, and never reach this route. PHP's built-in
 * server does not: given a router script it hands every request to it, so
 * without this the assets would 404 in the Docker image and in the quick start
 * - exactly the setups where someone is most likely to be trying them.
 *
 * The file name is matched against a fixed list rather than joined onto a
 * path. There is then no traversal to defend against, and no way to reach
 * anything else in the document root - `public/.htaccess` above all, which a
 * naive static passthrough would happily disclose.
 */
final class SwaggerUiAssetAction
{
    /** @var array<string, string> filename => content type */
    private const SERVABLE = [
        'swagger-ui.css' => 'text/css',
        'swagger-ui-bundle.js' => 'text/javascript',
    ];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly SwaggerUiAssets $assets,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $file = $args['file'] ?? '';
        $path = self::SERVABLE[$file] ?? null;

        if ($path === null) {
            return $this->responseFactory->createResponse(404);
        }

        $absolute = $this->assets->localPath($file);

        if ($absolute === null) {
            return $this->responseFactory->createResponse(404);
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $path)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Pinned by filename to a version the operator installed, so it is
            // safe to cache for a long time; an upgrade replaces the files.
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withBody($this->streamFactory->createStreamFromFile($absolute));
    }
}
