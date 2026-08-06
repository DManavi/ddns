<?php

declare(strict_types=1);

namespace Ddns\Http;

use Ddns\Bootstrap;
use Ddns\Http\Middleware\TrustedProxyMiddleware;
use Ddns\Http\Responder\JsonResponder;
use Ddns\Support\Services;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Assembles the Slim application.
 *
 * Kept out of `public/index.php` so the integration tests can build the exact
 * same application and drive it in process.
 */
final class AppFactoryBuilder
{
    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(ContainerInterface $container): App
    {
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Order matters: the source address has to be resolved before routing
        // so that authentication failures can be logged with the client IP.
        $app->add(TrustedProxyMiddleware::class);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(false, true, true, Services::get($container, LoggerInterface::class));
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler(
            Services::get($container, JsonResponder::class),
            Services::get($container, LoggerInterface::class),
        ));

        /** @var callable(App<ContainerInterface|null>): void $routes */
        $routes = require Bootstrap::projectRoot() . '/config/routes.php';
        $routes($app);

        return $app;
    }
}
