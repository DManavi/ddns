<?php

declare(strict_types=1);

use Ddns\Http\Action\HealthAction;
use Ddns\Http\Action\ShowHostAction;
use Ddns\Http\Action\UpdateAction;
use Ddns\Http\Middleware\AuthenticationMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // Unauthenticated: container and load balancer probes need this to work
    // before any client credentials exist.
    $app->get('/health', HealthAction::class);

    // Not a static closure: Slim binds route group callables to the container.
    $app->group('/v1/hosts/{host}', function (RouteCollectorProxy $group): void {
        $group->get('', ShowHostAction::class);

        // GET is offered alongside POST because many routers and embedded
        // clients can only be configured with a plain URL to fetch.
        $group->map(['GET', 'POST'], '/update', UpdateAction::class);
    })->add(AuthenticationMiddleware::class);
};
