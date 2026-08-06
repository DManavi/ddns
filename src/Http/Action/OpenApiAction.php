<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Ddns\Bootstrap;
use Ddns\Http\OpenApi\OpenApiDocument;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * `GET /openapi.json` and `GET /openapi.yaml`
 *
 * Serves the API description. Unauthenticated, because it describes shapes and
 * status codes rather than data - all of it already published in the README -
 * and because a client that cannot read the description before authenticating
 * gains nothing from it.
 *
 * Both formats come from the same structure, so they cannot disagree. YAML is
 * offered because most OpenAPI tooling and every hand-editing workflow expects
 * it; JSON because everything else does.
 */
final class OpenApiAction
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;

    /** Deep enough for components.schemas.*.properties.*.items, the deepest nesting here. */
    private const YAML_INLINE_DEPTH = 12;

    /**
     * DUMP_EMPTY_ARRAY_AS_SEQUENCE is not cosmetic: without it an empty
     * `security: []` is written as `{}`, and a document whose security
     * requirements are objects rather than arrays is rejected outright by
     * OpenAPI validators.
     */
    private const YAML_FLAGS = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Two routes, one action: the extension chosen decides the format, so
        // there is no content negotiation to get wrong and a plain browser or
        // curl gets what the URL promised.
        $format = str_ends_with($request->getUri()->getPath(), '.yaml') ? 'yaml' : 'json';
        $document = (new OpenApiDocument(Bootstrap::VERSION, $this->serverUrl($request)))->toArray();

        [$body, $contentType] = $format === 'yaml'
            ? [Yaml::dump($document, self::YAML_INLINE_DEPTH, 2, self::YAML_FLAGS), 'application/yaml']
            : [json_encode($document, self::JSON_FLAGS | JSON_THROW_ON_ERROR), 'application/json'];

        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            // The document changes only with the deployed version, so it is
            // worth caching - but not for long enough to outlive an upgrade.
            ->withHeader('Cache-Control', 'public, max-age=300')
            // The document echoes the request's own host back in `servers`, so
            // pin the type rather than let a browser sniff it as something
            // executable.
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write($body);

        return $response;
    }

    /**
     * The URL this server is reachable at, so a tool reading the document can
     * call the API without being told where it lives.
     */
    private function serverUrl(ServerRequestInterface $request): ?string
    {
        $uri = $request->getUri();
        // Built from the parts rather than from getAuthority(), which would
        // carry userinfo - a credential, and not part of where the API lives.
        $host = $uri->getHost();

        if ($host === '') {
            return null;
        }

        $port = $uri->getPort();
        $scheme = $uri->getScheme() === '' ? 'https' : $uri->getScheme();

        return sprintf('%s://%s%s', $scheme, $host, $port === null ? '' : ':' . $port);
    }
}
