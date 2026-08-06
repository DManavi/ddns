<?php

declare(strict_types=1);

namespace Ddns\Http;

use Ddns\Config\Exception\ConfigurationError;
use Ddns\Domain\Provider\Exception\ProviderException;
use Ddns\Http\Responder\JsonResponder;
use Ddns\Ip\Exception\IpResolutionFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;

/**
 * Turns any thrown exception into a JSON error response.
 *
 * Details are logged, not returned: an unauthenticated caller must not be able
 * to learn about provider credentials, file paths, or stack frames from an
 * error body.
 */
final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        [$status, $code, $message] = $this->classify($exception);

        if ($status >= 500) {
            $this->logger->error($exception->getMessage(), [
                'exception' => $exception::class,
                'path' => $request->getUri()->getPath(),
                'trace' => $exception->getTraceAsString(),
            ]);
        } else {
            $this->logger->info($exception->getMessage(), [
                'exception' => $exception::class,
                'path' => $request->getUri()->getPath(),
            ]);
        }

        return $this->responder->error($message, $status, $code);
    }

    /**
     * @return array{0: int, 1: string, 2: string} status, machine code, safe message
     */
    private function classify(\Throwable $exception): array
    {
        return match (true) {
            $exception instanceof HttpNotFoundException => [
                404,
                'not_found',
                'No such endpoint. Available: GET /health, GET /v1/hosts/{host}, '
                . 'GET|POST /v1/hosts/{host}/update.',
            ],
            $exception instanceof HttpMethodNotAllowedException => [
                405,
                'method_not_allowed',
                'That method is not allowed on this endpoint.',
            ],
            $exception instanceof ProviderException => [
                $exception->suggestedHttpStatus(),
                'provider_error',
                $exception->getMessage(),
            ],
            $exception instanceof IpResolutionFailed => [
                422,
                'ip_resolution_failed',
                $exception->getMessage(),
            ],
            // A message naming config keys is worth surfacing plainly to
            // whoever is deploying this. One naming filesystem paths is not:
            // it reaches the client before any token can be checked, since
            // the tokens themselves live in the configuration.
            $exception instanceof ConfigurationError => [
                500,
                'configuration_error',
                $exception->namesPaths
                    ? 'The server is not configured. Check the server logs for details.'
                    : $exception->getMessage(),
            ],
            default => [
                500,
                'internal_error',
                'An unexpected error occurred. Check the server logs for details.',
            ],
        };
    }
}
