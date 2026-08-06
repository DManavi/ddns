<?php

declare(strict_types=1);

namespace Ddns\Http\Responder;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Renders every API response, so the envelope stays consistent.
 */
final class JsonResponder
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function respond(array $payload, int $status = 200): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            // Update responses must never be cached by an intermediary; a
            // cached "unchanged" would hide a real IP change from the client.
            ->withHeader('Cache-Control', 'no-store');

        $response->getBody()->write(json_encode($payload, self::FLAGS | JSON_THROW_ON_ERROR));

        return $response;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function error(string $message, int $status, string $code = 'error', array $extra = []): ResponseInterface
    {
        return $this->respond([
            'error' => [
                'code' => $code,
                'message' => $message,
                ...$extra,
            ],
        ], $status);
    }
}
