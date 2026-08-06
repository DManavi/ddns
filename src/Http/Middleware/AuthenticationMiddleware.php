<?php

declare(strict_types=1);

namespace Ddns\Http\Middleware;

use Ddns\Config\Configuration;
use Ddns\Config\HostConfig;
use Ddns\Http\Responder\JsonResponder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

/**
 * Authenticates a client against the token configured for the host it targets.
 *
 * Three transports are accepted, because the clients are routers and shell
 * scripts rather than browsers:
 *
 *  - `Authorization: Bearer <token>` for anything modern;
 *  - HTTP Basic, where the password is the token, which is all many consumer
 *    routers can send;
 *  - a `token` query parameter, for clients that can only be given a URL.
 *
 * The comparison is constant time and is performed even when the host does not
 * exist, so an attacker cannot use response timing to enumerate host names.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'ddns.host';

    /**
     * Compared against when the requested host is unknown, so that the failure
     * path performs the same work as the success path.
     */
    private const DECOY_TOKEN = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const UNAUTHORISED_MESSAGE = 'Authentication failed. Provide the host token via an '
        . 'Authorization Bearer header, HTTP Basic password, or the "token" query parameter.';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hostName = $this->resolveHostName($request);
        $presented = $this->extractToken($request);
        $host = $hostName === null ? null : $this->configuration->findHost($hostName);

        $expected = $host?->token() ?? self::DECOY_TOKEN;
        $tokenIsValid = $presented !== null && hash_equals($expected, $presented);

        if ($host === null || !$tokenIsValid) {
            $this->logger->warning('Rejected an unauthenticated update request.', [
                'host' => $hostName,
                'client_ip' => TrustedProxyMiddleware::clientIpFrom($request)?->value(),
                'credentials_supplied' => $presented !== null,
            ]);

            return $this->unauthorised();
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $host));
    }

    /**
     * The authenticated host, for use inside an action.
     *
     * @throws \LogicException when called on a route that is not behind this middleware
     */
    public static function hostFrom(ServerRequestInterface $request): HostConfig
    {
        $host = $request->getAttribute(self::ATTRIBUTE);

        if (!$host instanceof HostConfig) {
            throw new \LogicException(
                'No authenticated host on the request. This route is missing AuthenticationMiddleware.',
            );
        }

        return $host;
    }

    /**
     * Work out which host the caller is trying to act on.
     *
     * The route argument is authoritative; the Basic auth username is accepted
     * as a fallback because that is the natural place for routers to put it.
     */
    private function resolveHostName(ServerRequestInterface $request): ?string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $fromRoute = $route?->getArgument('host');

        if (is_string($fromRoute) && $fromRoute !== '') {
            return $fromRoute;
        }

        $user = $request->getServerParams()['PHP_AUTH_USER'] ?? null;

        if (is_string($user) && $user !== '') {
            return $user;
        }

        $basic = $this->decodeBasicAuth($request);

        return $basic === null || $basic[0] === '' ? null : $basic[0];
    }

    /**
     * The credential this request is presenting.
     *
     * Order matters when more than one arrives, and the first one found is the
     * only one tried - a request carrying a valid header and a stale query
     * token is refused rather than quietly falling back to the one that works.
     * Silently accepting a credential the caller did not mean to use is worse
     * than a 401 they can see.
     *
     * The query string comes first because it is the only transport the caller
     * has to add deliberately. A Basic credential can arrive without anyone
     * intending it: this server answers a 401 with `WWW-Authenticate: Basic`,
     * after which a browser re-sends saved credentials for the realm on every
     * later request, and those would otherwise shadow an explicit `?token=`.
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        $query = $request->getQueryParams()['token'] ?? null;

        if (is_string($query) && $query !== '') {
            return $query;
        }

        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) === 1) {
            return trim($matches[1]);
        }

        $basic = $this->decodeBasicAuth($request);

        if ($basic !== null && $basic[1] !== '') {
            return $basic[1];
        }

        // Some server configurations consume the Basic header before PHP sees
        // it, so this is the same tier by another route rather than a fourth.
        $password = $request->getServerParams()['PHP_AUTH_PW'] ?? null;

        if (is_string($password) && $password !== '') {
            return $password;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null username and password
     */
    private function decodeBasicAuth(ServerRequestInterface $request): ?array
    {
        $header = trim($request->getHeaderLine('Authorization'));

        if (preg_match('/^Basic\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }

        $decoded = base64_decode(trim($matches[1]), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        $parts = explode(':', $decoded, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function unauthorised(): ResponseInterface
    {
        return (new JsonResponder($this->responseFactory))
            ->error(self::UNAUTHORISED_MESSAGE, 401, 'unauthorised')
            ->withHeader('WWW-Authenticate', 'Basic realm="ddns", charset="UTF-8"');
    }
}
