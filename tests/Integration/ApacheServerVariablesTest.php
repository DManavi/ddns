<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Factory\ServerRequestCreatorFactory;

/**
 * Apache does not hand the `Authorization` header to PHP-FPM by default, so a
 * Bearer token silently disappears and every update comes back 401. The README
 * documents two workarounds; these tests pin that they actually work, so the
 * documented setup cannot rot unnoticed.
 *
 * Requests are built from `$_SERVER` through Slim's own creator, which is what
 * `$app->run()` does, rather than being assembled by hand - the whole point is
 * what survives that step.
 */
final class ApacheServerVariablesTest extends HttpTestCase
{
    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;

        parent::tearDown();
    }

    /**
     * @param array<string, string> $serverParams
     */
    #[DataProvider('workingSetups')]
    public function testCredentialsSurviveTheWebServer(string $_label, array $serverParams): void
    {
        self::assertSame(200, $this->handleWith($serverParams)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function workingSetups(): iterable
    {
        // mod_php performs Basic authentication itself and exposes the result
        // as PHP_AUTH_*, which Slim turns back into an Authorization header.
        yield 'mod_php basic auth' => ['mod_php', [
            'PHP_AUTH_USER' => 'home',
            'PHP_AUTH_PW' => self::HOST_TOKEN,
        ]];

        // `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in the vhost.
        yield 'php-fpm with SetEnvIf' => ['setenvif', [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::HOST_TOKEN,
        ]];

        // `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]` in .htaccess, which
        // arrives prefixed because it was set during a rewrite.
        yield 'php-fpm with rewrite' => ['rewrite', [
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::HOST_TOKEN,
        ]];

        // Legacy php-cgi behind mod_cgi or mod_cgid. Captured from a real
        // Apache running the CGI SAPI: the Action directive performs an
        // internal redirect, which is why so much arrives duplicated under a
        // REDIRECT_ prefix.
        yield 'legacy php-cgi' => ['cgi', [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::HOST_TOKEN,
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::HOST_TOKEN,
            'REDIRECT_STATUS' => '200',
            'REDIRECT_HANDLER' => 'application/x-httpd-php',
            'REDIRECT_URL' => '/v1/hosts/home',
            'ORIG_PATH_INFO' => '/index.php',
            'ORIG_SCRIPT_NAME' => '/cgi-bin/php',
            'SCRIPT_NAME' => '/index.php',
        ]];
    }

    /**
     * Under CGI the Action directive redirects internally, so `SetEnv` values
     * arrive both plainly and REDIRECT_-prefixed. Bootstrap reads the plain
     * name, which Apache does still set - verified against a real php-cgi.
     */
    public function testCgiStillExposesConfigurationEnvironmentVariables(): void
    {
        $response = $this->handleWith([
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::HOST_TOKEN,
            'REDIRECT_STATUS' => '200',
            'DDNS_LOG_LEVEL' => 'ERROR',
            'REDIRECT_DDNS_LOG_LEVEL' => 'ERROR',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The failure the README warns about: with neither workaround in place the
     * header never reaches PHP, and the request is rejected even though the
     * client sent a perfectly good token.
     */
    public function testWithoutAWorkaroundPhpFpmLosesTheHeaderEntirely(): void
    {
        $response = $this->handleWith([]);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Which is why the query parameter exists: it survives any web server
     * configuration, because it is part of the URL rather than a header.
     */
    public function testTheQueryParameterSurvivesEvenAMisconfiguredServer(): void
    {
        $response = $this->handleWith([], '/v1/hosts/home?token=' . self::HOST_TOKEN);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * With mod_php or mod_proxy_fcgi on the same host, Apache passes the real
     * client address through, so no trusted proxy configuration is needed and
     * none should be added - doing so would open a spoofing surface for
     * nothing.
     */
    public function testApacheAlonePassesTheRealClientAddress(): void
    {
        $response = $this->handleWith(['HTTP_AUTHORIZATION' => 'Bearer ' . self::HOST_TOKEN]);

        self::assertSame('198.51.100.23', $this->at($response, 'client_ip'));
    }

    /**
     * @param array<string, string> $serverParams
     */
    private function handleWith(array $serverParams, string $uri = '/v1/hosts/home'): \Psr\Http\Message\ResponseInterface
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'ddns.test',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => '198.51.100.23',
            'HTTPS' => 'on',
            ...$serverParams,
        ];

        return $this->app($this->defaultConfig())
            ->handle(ServerRequestCreatorFactory::create()->createServerRequestFromGlobals());
    }
}
