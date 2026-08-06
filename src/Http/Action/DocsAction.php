<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Ddns\Bootstrap;
use Ddns\Http\OpenApi\SwaggerUiAssets;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api`
 *
 * Renders the OpenAPI description with Swagger UI, so the API can be read - and
 * called - from a browser.
 *
 * The page is a thin shell: it carries no documentation of its own, only a
 * pointer at `/openapi.json`, which is generated from the application. Anything
 * written here would be a second copy free to disagree with the first.
 */
final class DocsAction
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly SwaggerUiAssets $assets,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // A fresh nonce per response: a fixed one would let any page that could
        // guess it inject a script the policy then trusts.
        $nonce = base64_encode(random_bytes(16));
        $basePath = $this->basePath($request);

        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', $this->assets->contentSecurityPolicy($nonce))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'public, max-age=300');

        $response->getBody()->write($this->render($basePath, $nonce));

        return $response;
    }

    /**
     * Everything the request path has above `/api`, so the page still works
     * when the application is mounted in a subdirectory.
     */
    private function basePath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        return substr($path, 0, max(0, strrpos($path, '/api') ?: 0));
    }

    private function render(string $basePath, string $nonce): string
    {
        $specUrl = $basePath . '/openapi.json';

        $css = $this->tag('link', [
            'rel' => 'stylesheet',
            'href' => $this->assets->cssUrl($basePath),
            'integrity' => $this->assets->cssIntegrity(),
            'crossorigin' => $this->assets->isLocal() ? null : 'anonymous',
        ]);

        $script = $this->tag('script', [
            'src' => $this->assets->scriptUrl($basePath),
            'integrity' => $this->assets->scriptIntegrity(),
            'crossorigin' => $this->assets->isLocal() ? null : 'anonymous',
        ], true);

        $version = $this->escape(Bootstrap::VERSION);
        $specJson = json_encode($specUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $safeNonce = $this->escape($nonce);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>ddns {$version} &mdash; API</title>
            {$css}
            <style>
              body { margin: 0; background: #fafafa; }
              .swagger-ui .topbar { display: none; }
              noscript { display: block; padding: 2rem; font-family: system-ui, sans-serif; }
            </style>
            </head>
            <body>
            <noscript>
              This page renders the API description with Swagger UI, which needs JavaScript.
              The description itself is plain text: <a href="{$specUrl}">openapi.json</a>.
            </noscript>
            <div id="swagger-ui"></div>
            {$script}
            <script nonce="{$safeNonce}">
              window.ui = SwaggerUIBundle({
                url: {$specJson},
                dom_id: '#swagger-ui',
                deepLinking: true,
                // Sorted, so the page reads the same way every time rather than
                // in whatever order the document happens to list things.
                operationsSorter: 'alpha',
                tagsSorter: 'alpha',
                defaultModelsExpandDepth: 2,
                docExpansion: 'list',
                // Every operation needs a token, so offer the field up front.
                persistAuthorization: true,
                tryItOutEnabled: true
              });
            </script>
            </body>
            </html>
            HTML;
    }

    /**
     * @param array<string, string|null> $attributes null values are omitted
     */
    private function tag(string $name, array $attributes, bool $paired = false): string
    {
        $rendered = '';

        foreach ($attributes as $attribute => $value) {
            if ($value !== null) {
                $rendered .= sprintf(' %s="%s"', $attribute, $this->escape($value));
            }
        }

        return $paired
            ? sprintf('<%1$s%2$s></%1$s>', $name, $rendered)
            : sprintf('<%s%s>', $name, $rendered);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
