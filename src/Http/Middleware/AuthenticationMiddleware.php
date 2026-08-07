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
 * scripts rather than browsers. A client may present several at once - Swagger
 * UI applies every scheme that has been authorised - so each is checked, and
 * the order below only decides which is used when more than one is valid:
 *
 *  1. a `token` query parameter, for clients that can only be given a URL;
 *  2. an `Authorization` header carrying the token as a bearer credential;
 *  3. HTTP Basic, where the password is the token, which is all many consumer
 *     routers can send.
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

    /**
     * Names both things that can be wrong, because only one of them used to be
     * mentioned and it was the less likely one. A caller who has the token right
     * but the host wrong was told to check the token, so they would try every
     * transport in turn and watch all of them fail the same way.
     *
     * Deliberately one message for both causes: saying which of them was wrong
     * would turn this endpoint into a way to discover which hosts exist.
     */
    private const UNAUTHORISED_MESSAGE = 'Authentication failed. Check that the host named in the URL is '
        . 'one you have configured, and that the token is that host\'s own. Supply the token as a bearer '
        . 'credential, as the password with HTTP Basic, or as a "token" query parameter.';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hostName = $this->resolveHostName($request);
        $presented = $this->extractTokens($request);
        $host = $hostName === null ? null : $this->configuration->findHost($hostName);

        $expected = $host?->token() ?? self::DECOY_TOKEN;
        $tokenIsValid = false;

        // Every candidate is compared, with no early exit, so the work done
        // depends on how many credentials the client sent rather than on
        // whether any of them was right.
        foreach ($presented as $candidate) {
            $tokenIsValid = hash_equals($expected, $candidate) || $tokenIsValid;
        }

        if ($host === null || !$tokenIsValid) {
            // The client is told neither of these, but the operator reading
            // the log is: it is the difference between a typo in the URL and a
            // stale token, and there is no oracle in a server-side log.
            $this->logger->warning('Rejected an unauthenticated request.', [
                'host' => $hostName,
                'reason' => $host === null ? 'no such host is configured' : 'no supplied token matched',
                'client_ip' => TrustedProxyMiddleware::clientIpFrom($request)?->value(),
                'credentials_supplied' => count($presented),
            ]);

            return $this->unauthorised($request);
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
     * Every credential this request is presenting, in precedence order.
     *
     * A client may send more than one, and Swagger UI routinely does: it
     * applies every scheme that has been authorised, and remembers them
     * between reloads. Each is checked, so a stale value in one transport
     * cannot mask a correct one in another. Order still decides which is
     * tried first, and therefore which one is used when several are valid.
     *
     * All three carry the same secret for the same host, and the host itself
     * comes from the route, so there is nothing to be gained by insisting on
     * one particular transport - only requests to break.
     *
     * @return list<string>
     */
    private function extractTokens(ServerRequestInterface $request): array
    {
        $candidates = [];

        // The query parameter first: it is the only transport a caller has to
        // add deliberately.
        $query = $request->getQueryParams()['token'] ?? null;

        if (is_string($query) && $query !== '') {
            $candidates[] = $query;
        }

        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) === 1) {
            $candidates[] = $matches[1];
        }

        $basic = $this->decodeBasicAuth($request);

        if ($basic !== null && $basic[1] !== '') {
            $candidates[] = $basic[1];
        }

        // Some server configurations consume the Basic header before PHP sees
        // it, so this is the same tier by another route rather than a fourth.
        $password = $request->getServerParams()['PHP_AUTH_PW'] ?? null;

        if (is_string($password) && $password !== '') {
            $candidates[] = $password;
        }

        // Trimmed in one place so every transport behaves the same. The header
        // form was already trimmed by its own pattern while the query string
        // was not, so a token pasted with a trailing space authenticated as a
        // Bearer credential and was rejected as a query parameter - the same
        // secret, the same host, two different answers.
        //
        // Configured tokens are trimmed by the loader, so this compares like
        // with like rather than loosening what counts as a match.
        $candidates = array_map(trim(...), $candidates);

        // Sending the same secret twice is not two attempts.
        return array_values(array_unique(array_filter($candidates, static fn (string $c): bool => $c !== '')));
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

    private function unauthorised(ServerRequestInterface $request): ResponseInterface
    {
        $response = (new JsonResponder($this->responseFactory))
            ->error(self::UNAUTHORISED_MESSAGE, 401, 'unauthorised');

        // The challenge is what makes a router's "enter a URL, a username and
        // a password" screen work, so it stays for anything that might render
        // it. It is withheld from clients that asked for JSON: a browser shown
        // this header hijacks the page with its own credential dialog, which
        // is what happens to anyone using the documentation page at /api, and
        // there is no human behind an XHR to fill it in anyway.
        return $this->mayRenderAChallenge($request)
            ? $response->withHeader('WWW-Authenticate', 'Basic realm="ddns", charset="UTF-8"')
            : $response;
    }

    /**
     * Whether a `WWW-Authenticate` header could plausibly be acted on.
     *
     * Decided from `Accept`, which is the only thing a request says about what
     * will do the acting: Swagger UI and other scripted clients ask for JSON,
     * a browser being pointed at the URL asks for HTML, and a client with no
     * preference gets the header on the assumption that it might be a person.
     */
    private function mayRenderAChallenge(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        if ($accept === '' || str_contains($accept, 'text/html')) {
            return true;
        }

        return !str_contains($accept, 'application/json');
    }
}
