<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

final class AuthenticationTest extends HttpTestCase
{
    public function testAcceptsABearerToken(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Basic auth is what most consumer routers can actually send.
     */
    public function testAcceptsHttpBasicWithTheTokenAsThePassword(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Basic ' . base64_encode('home:' . self::HOST_TOKEN),
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsHttpBasicWithAnyUsername(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Basic ' . base64_encode('anything:' . self::HOST_TOKEN),
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * For clients that can only be handed a URL.
     */
    public function testAcceptsATokenQueryParameter(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update?token=' . self::HOST_TOKEN);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * When several credentials arrive at once, the query string is the one
     * used. It is the only transport the caller has to add deliberately;
     * a Basic credential can be re-sent by a browser that once answered this
     * server's WWW-Authenticate challenge, without anyone intending it.
     */
    public function testTheQueryStringWinsOverBothHeaders(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update?token=' . self::HOST_TOKEN, [
            'Authorization' => 'Bearer the-wrong-token',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheQueryStringWinsOverBasicAuth(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update?token=' . self::HOST_TOKEN, [
            'Authorization' => 'Basic ' . base64_encode('home:the-wrong-token'),
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheAuthorizationHeaderWinsOverBasicAuth(): void
    {
        // Only one Authorization header can be sent, so this is Bearer against
        // the Basic password a server may have surfaced separately.
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->requestWithServerParams(
            'GET',
            '/v1/hosts/home/update',
            ['Authorization' => 'Bearer ' . self::HOST_TOKEN],
            ['PHP_AUTH_USER' => 'home', 'PHP_AUTH_PW' => 'the-wrong-token'],
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Swagger UI applies every scheme that has been authorised, and remembers
     * them between reloads, so a value left in one transport travels along
     * with the one actually being used. A stale credential must not mask a
     * correct one: all of them carry the same secret for the same host, and
     * the host comes from the route, so there is nothing to gain by insisting
     * on the transport that happened to be checked first.
     */
    public function testAStaleQueryTokenDoesNotMaskAValidHeader(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update?token=stale', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAStaleBearerTokenDoesNotMaskValidBasicCredentials(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->requestWithServerParams(
            'GET',
            '/v1/hosts/home/update',
            ['Authorization' => 'Bearer the-wrong-token'],
            ['PHP_AUTH_USER' => 'home', 'PHP_AUTH_PW' => self::HOST_TOKEN],
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Wrong everywhere is still wrong.
     */
    public function testSeveralWrongCredentialsAreStillRejected(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update?token=wrong-one', [
            'Authorization' => 'Bearer wrong-two',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * An empty parameter is not a credential, so it must not consume the turn
     * and lock out the header that follows it.
     */
    public function testAnEmptyQueryTokenFallsThroughToTheHeader(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update?token=', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsARequestWithNoCredentials(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorised', $this->at($response, 'error.code'));
        self::assertSame(0, $this->upstream()->requestCount(), 'No provider call may happen before authentication.');
    }

    public function testRejectsAWrongBearerToken(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Bearer wrong-token-value-here',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->upstream()->requestCount());
    }

    public function testRejectsAWrongBasicPassword(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Basic ' . base64_encode('home:wrong'),
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testRejectsAWrongQueryToken(): void
    {
        self::assertSame(401, $this->request('GET', '/v1/hosts/home/update?token=wrong')->getStatusCode());
    }

    /**
     * A token that is valid for one host must not work for another.
     */
    public function testATokenDoesNotAuthenticateADifferentHost(): void
    {
        $config = $this->defaultConfig() . <<<'YAML'

              office:
                provider: p1
                zone: example.com
                name: office
                types: [A]
                ttl: 60
                token: office-token-0123456789abc
            YAML;

        $response = $this->request(
            'GET',
            '/v1/hosts/office/update',
            ['Authorization' => 'Bearer ' . self::HOST_TOKEN],
            configYaml: $config,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->upstream()->requestCount());
    }

    /**
     * An unknown host must be indistinguishable from a wrong token, so the API
     * cannot be used to enumerate configured host names.
     */
    public function testAnUnknownHostIsIndistinguishableFromABadToken(): void
    {
        $unknown = $this->request('GET', '/v1/hosts/nosuchhost/update', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);

        $badToken = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Bearer definitely-the-wrong-token',
        ]);

        self::assertSame($badToken->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($this->json($badToken), $this->json($unknown));
    }

    /**
     * A browser shown `WWW-Authenticate` opens its own credential dialog over
     * whatever page made the request, which is what happens to anyone driving
     * the API from the documentation page at /api. Swagger UI asks for JSON,
     * and there is no human behind an XHR to answer a dialog anyway.
     */
    public function testDoesNotChallengeAClientThatAskedForJson(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update', ['Accept' => 'application/json']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        // The error is still readable; only the browser-facing header is gone.
        self::assertSame('unauthorised', $this->at($response, 'error.code'));
    }

    public function testStillChallengesABrowserBeingPointedAtTheUrl(): void
    {
        // This is what makes a router's "enter a URL, a username and a
        // password" screen work, so it has to survive.
        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        self::assertStringStartsWith('Basic ', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testStillChallengesAClientThatStatedNoPreference(): void
    {
        // curl and most scripts send */* or nothing; assume a person may be
        // behind it rather than withhold a header they might need.
        foreach ([[], ['Accept' => '*/*']] as $headers) {
            $response = $this->request('GET', '/v1/hosts/home/update', $headers);

            self::assertStringStartsWith('Basic ', $response->getHeaderLine('WWW-Authenticate'));
        }
    }

    public function testChallengesWithWwwAuthenticateSoBasicAuthClientsCanRetry(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update');

        self::assertStringContainsString('Basic', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAMalformedBasicHeaderIsRejectedRatherThanCrashing(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Basic !!!not-base64!!!',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnUnknownAuthorizationSchemeIsRejected(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update', [
            'Authorization' => 'Digest something',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheErrorBodyNeverEchoesTheSuppliedToken(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update?token=my-secret-attempt');

        self::assertStringNotContainsString('my-secret-attempt', $this->body($response));
    }

    public function testHealthNeedsNoAuthentication(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->at($response, 'status'));
    }

    /**
     * The unauthenticated probe must not disclose which hosts exist.
     */
    public function testHealthDoesNotDiscloseHostNames(): void
    {
        $response = $this->request('GET', '/health');

        $body = $this->body($response);

        self::assertStringNotContainsString('home', $body);
        self::assertStringNotContainsString('example.com', $body);
        self::assertSame(1, $this->at($response, 'hosts_configured'));
    }
}
