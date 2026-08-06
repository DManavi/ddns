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
     * Route53 is registered but unimplemented; that must surface as a clear
     * 501 rather than an opaque crash.
     */
    public function testAnUnimplementedDriverReports501(): void
    {
        $config = <<<YAML
            providers:
              aws:
                driver: route53
                token: placeholder
            hosts:
              home:
                provider: aws
                zone: example.com
                name: home
                token: {$this->hostToken()}
            YAML;

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth(), configYaml: $config);

        self::assertSame(501, $response->getStatusCode());
        self::assertStringContainsString('not implemented yet', $this->atString($response, 'records.0.reason'));
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
