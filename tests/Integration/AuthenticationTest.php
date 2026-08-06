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
