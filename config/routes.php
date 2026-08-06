<?php

declare(strict_types=1);

use Ddns\Http\Action\DocsAction;
use Ddns\Http\Action\HealthAction;
use Ddns\Http\Action\OpenApiAction;
use Ddns\Http\Action\RedirectToDocsAction;
use Ddns\Http\Action\ShowHostAction;
use Ddns\Http\Action\SwaggerUiAssetAction;
use Ddns\Http\Action\UpdateAction;
use Ddns\Http\Middleware\AuthenticationMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // There is nothing to serve at the root, and a browser that arrives there
    // is looking for the documentation.
    $app->get('/', RedirectToDocsAction::class);

    // Unauthenticated: container and load balancer probes need this to work
    // before any client credentials exist.
    $app->get('/health', HealthAction::class);

    // Also unauthenticated: the description names shapes and status codes, all
    // of which are published in the README, and a client that cannot read it
    // before authenticating gains nothing from it.
    $app->get('/openapi.json', OpenApiAction::class);
    $app->get('/openapi.yaml', OpenApiAction::class);

    // The same description, rendered for a human.
    $app->get('/api', DocsAction::class);

    // Only reached under PHP's built-in server, which hands every request to
    // the router script; a real web server serves these from disk itself.
    $app->get('/vendor/swagger-ui/{file}', SwaggerUiAssetAction::class);

    // Not a static closure: Slim binds route group callables to the container.
    $app->group('/v1/hosts/{host}', function (RouteCollectorProxy $group): void {
        $group->get('', ShowHostAction::class);

        // GET is offered alongside POST because many routers and embedded
        // clients can only be configured with a plain URL to fetch.
        $group->map(['GET', 'POST'], '/update', UpdateAction::class);
    })->add(AuthenticationMiddleware::class);
};
