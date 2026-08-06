<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

final class ErrorHandlingTest extends HttpTestCase
{
    public function testUnknownRoutesReturnAHelpfulJson404(): void
    {
        $response = $this->request('GET', '/nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->at($response, 'error.code'));
        self::assertStringContainsString('/v1/hosts/{host}/update', $this->atString($response, 'error.message'));
    }

    /**
     * A misconfiguration that names config keys helps whoever is deploying
     * this, so it is reported as-is.
     */
    public function testAnInvalidConfigurationIsReportedPlainly(): void
    {
        $response = $this->request('GET', '/v1/hosts/home', [], '203.0.113.7', <<<'YAML'
            providers:
              p1:
                driver: nosuchdriver
                token: t
            hosts:
              home:
                provider: p1
                zone: example.com
                token: host-token-0123456789abcdef
            YAML);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('configuration_error', $this->at($response, 'error.code'));
        self::assertStringContainsString('nosuchdriver', $this->atString($response, 'error.message'));
    }

    /**
     * A missing file is different: the message names filesystem paths, and it
     * reaches the client before any token can be checked - the tokens live in
     * the configuration that is missing.
     */
    public function testAMissingConfigurationDoesNotDiscloseFilesystemPaths(): void
    {
        $response = $this->requestWithConfigPath('GET', '/v1/hosts/home', '/nonexistent/secret-dir/ddns.yaml');

        $message = $this->atString($response, 'error.message');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('/nonexistent/secret-dir', $message);
        self::assertStringNotContainsString('config:init', $message);
        self::assertStringContainsString('not configured', $message);
    }

    public function testDisallowedMethodsReturn405(): void
    {
        $response = $this->request('DELETE', '/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('method_not_allowed', $this->at($response, 'error.code'));
    }

    /**
     * A rejected upstream credential is our problem, not the caller's, so it
     * must not be reported as a client authentication failure.
     */
    public function testAProviderCredentialFailureBecomesA502NotA401(): void
    {
        $this->upstream()->queue(401, ['id' => 'unauthorized', 'message' => 'Unable to authenticate you.']);

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth());

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('failed', $this->at($response, 'status'));
    }

    public function testAMissingZoneBecomesA404(): void
    {
        $this->upstream()->queue(404, ['message' => 'not found']);

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth());

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('was not found', $this->atString($response, 'records.0.reason'));
    }

    public function testAProviderRateLimitIsPassedThroughAs429(): void
    {
        $this->upstream()->queue(429, ['message' => 'slow down'], ['Retry-After' => '60']);

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth());

        self::assertSame(429, $response->getStatusCode());
    }

    public function testAProviderServerErrorBecomesA502(): void
    {
        $this->upstream()->queue(500, ['message' => 'boom']);

        self::assertSame(
            502,
            $this->request('GET', '/v1/hosts/home/update', $this->auth())->getStatusCode(),
        );
    }

    /**
     * Route53 authenticates with AWS credentials that may come from an
     * instance profile or task role rather than the config file, so a provider
     * with no token must load rather than being rejected by validation.
     *
     * Deliberately does not call /update: that would reach AWS for real.
     */
    public function testARoute53ProviderLoadsWithoutAToken(): void
    {
        $config = <<<YAML
            providers:
              aws:
                driver: route53
            hosts:
              home:
                provider: aws
                zone: example.com
                name: home
                token: {$this->hostToken()}
            YAML;

        $response = $this->request('GET', '/v1/hosts/home', $this->auth(), configYaml: $config);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('home.example.com', $this->at($response, 'host.fqdn'));
        self::assertSame(0, $this->upstream()->requestCount(), 'No provider call should have been made.');
    }

    /**
     * Error bodies must never leak the provider credential.
     */
    public function testErrorBodiesDoNotLeakProviderCredentials(): void
    {
        $this->upstream()->queue(500, ['message' => 'boom']);

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth());

        self::assertStringNotContainsString('provider-secret', $this->body($response));
    }

    public function testErrorBodiesDoNotLeakStackTraces(): void
    {
        $response = $this->request('GET', '/nope');

        $body = $this->body($response);

        self::assertStringNotContainsString('#0 ', $body);
        self::assertStringNotContainsString('/vendor/', $body);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . self::HOST_TOKEN];
    }

    private function hostToken(): string
    {
        return self::HOST_TOKEN;
    }
}
