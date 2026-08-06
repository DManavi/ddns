<?php

declare(strict_types=1);

namespace Ddns\Http\Action;

use Ddns\Config\Configuration;
use Ddns\Http\Responder\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /health`
 *
 * Unauthenticated liveness probe. Reports only that configuration loaded and
 * how many hosts it defines - never their names, which would be an information
 * leak on an endpoint with no authentication.
 */
final class HealthAction
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond([
            'status' => 'ok',
            'hosts_configured' => count($this->configuration->hosts()),
            'providers_configured' => count($this->configuration->providers()),
        ]);
    }
}
