<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class UpdateEndpointTest extends HttpTestCase
{
    /**
     * The headline use case: a bare request from behind the router is enough.
     */
    public function testUsesTheRequestSourceAddressWhenNoIpIsSupplied(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth(), remoteAddr: '203.0.113.7');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('created', $this->at($response, 'status'));
        self::assertSame('203.0.113.7', $this->at($response, 'client_ip'));
        self::assertSame('203.0.113.7', $this->upstream()->bodyOf(1)['data'] ?? null);
    }

    public function testAnExplicitIpParameterWinsOverTheSourceAddress(): void
    {
        $this->expectCreateFlow('198.51.100.5');

        $response = $this->request(
            'GET',
            '/v1/hosts/home/update?ip=198.51.100.5',
            $this->auth(),
            remoteAddr: '203.0.113.7',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('198.51.100.5', $this->upstream()->bodyOf(1)['data'] ?? null);
        self::assertSame('203.0.113.7', $this->at($response, 'client_ip'));
    }

    public function testAcceptsTheMyipAlias(): void
    {
        $this->expectCreateFlow('198.51.100.5');

        $this->request('GET', '/v1/hosts/home/update?myip=198.51.100.5', $this->auth());

        self::assertSame('198.51.100.5', $this->upstream()->bodyOf(1)['data'] ?? null);
    }

    /**
     * `auto` is a common convention for "work it out yourself".
     */
    public function testTreatsAutoAsNoAddressSupplied(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $this->request('GET', '/v1/hosts/home/update?ip=auto', $this->auth(), remoteAddr: '203.0.113.7');

        self::assertSame('203.0.113.7', $this->upstream()->bodyOf(1)['data'] ?? null);
    }

    public function testReportsUnchangedWithoutWritingWhenTheRecordIsAlreadyCorrect(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth(), remoteAddr: '203.0.113.7');

        self::assertSame('unchanged', $this->at($response, 'status'));
        self::assertFalse($this->at($response, 'changed'));
        self::assertSame(1, $this->upstream()->requestCount(), 'An unchanged record must cost exactly one lookup.');
    }

    public function testUpdatesAStaleRecord(): void
    {
        $this->upstream()
            ->queue(200, ['domain_records' => [
                ['id' => 7, 'type' => 'A', 'name' => 'home', 'data' => '198.51.100.1', 'ttl' => 60],
            ]])
            ->queue(200, ['domain_record' => [
                'id' => 7, 'type' => 'A', 'name' => 'home', 'data' => '203.0.113.7', 'ttl' => 60,
            ]]);

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth(), remoteAddr: '203.0.113.7');

        self::assertSame('updated', $this->at($response, 'status'));
        self::assertSame('198.51.100.1', $this->at($response, 'records.0.previous'));
        self::assertSame('PUT', $this->upstream()->request(1)->getMethod());
    }

    public function testAcceptsPost(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        self::assertSame(
            200,
            $this->request('POST', '/v1/hosts/home/update', $this->auth(), remoteAddr: '203.0.113.7')
                ->getStatusCode(),
        );
    }

    public function testAcceptsAJsonBody(): void
    {
        $this->expectCreateFlow('198.51.100.5');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://ddns.test/v1/hosts/home/update', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', 'Bearer ' . self::HOST_TOKEN)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode(['ip' => '198.51.100.5'], JSON_THROW_ON_ERROR)));

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('198.51.100.5', $this->upstream()->bodyOf(1)['data'] ?? null);
    }

    public function testDryRunReportsTheChangeWithoutWriting(): void
    {
        $this->upstream()->queue(200, ['domain_records' => []]);

        $response = $this->request('GET', '/v1/hosts/home/update?dry_run=1', $this->auth());

        self::assertSame('created', $this->at($response, 'status'));
        self::assertTrue($this->at($response, 'records.0.dry_run'));
        self::assertSame(1, $this->upstream()->requestCount(), 'A dry run must only read.');
    }

    public function testRejectsAMalformedIpParameter(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update?ip=not-an-ip', $this->auth());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_ip', $this->at($response, 'error.code'));
        self::assertSame(0, $this->upstream()->requestCount());
    }

    /**
     * A private address in a public zone is nearly always a misconfigured
     * proxy, so it is refused rather than published.
     */
    public function testRefusesAPrivateAddressByDefault(): void
    {
        $response = $this->request('GET', '/v1/hosts/home/update?ip=192.168.1.10', $this->auth());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('non-public', $this->atString($response, 'records.0.reason'));
        self::assertSame(0, $this->upstream()->requestCount());
    }

    public function testPublishesAPrivateAddressWhenAllowed(): void
    {
        $this->expectCreateFlow('192.168.1.10');

        $config = $this->defaultConfig(extraServer: '  allow_private_ips: true');

        $response = $this->request(
            'GET',
            '/v1/hosts/home/update?ip=192.168.1.10',
            $this->auth(),
            configYaml: $config,
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * With trusted proxies configured, the forwarded address is used.
     */
    public function testHonoursForwardedForFromATrustedProxy(): void
    {
        $this->expectCreateFlow('198.51.100.42');

        $config = $this->defaultConfig(extraServer: "  trusted_proxies: ['10.0.0.0/8']");

        $response = $this->request(
            'GET',
            '/v1/hosts/home/update',
            [...$this->auth(), 'X-Forwarded-For' => '198.51.100.42'],
            remoteAddr: '10.0.0.5',
            configYaml: $config,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('198.51.100.42', $this->at($response, 'client_ip'));
        self::assertSame('198.51.100.42', $this->upstream()->bodyOf(1)['data'] ?? null);
    }

    /**
     * The whole point of trusted_proxies: a forged header from an untrusted
     * caller must not be able to point the record elsewhere.
     */
    public function testIgnoresAForgedForwardedForFromAnUntrustedCaller(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $response = $this->request(
            'GET',
            '/v1/hosts/home/update',
            [...$this->auth(), 'X-Forwarded-For' => '198.51.100.42'],
            remoteAddr: '203.0.113.7',
        );

        self::assertSame('203.0.113.7', $this->at($response, 'client_ip'));
        self::assertSame('203.0.113.7', $this->upstream()->bodyOf(1)['data'] ?? null);
    }

    public function testShowHostReturnsRedactedConfiguration(): void
    {
        $response = $this->request('GET', '/v1/hosts/home', $this->auth(), remoteAddr: '203.0.113.7');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('home.example.com', $this->at($response, 'host.fqdn'));
        self::assertSame('203.0.113.7', $this->at($response, 'client_ip'));
        self::assertStringNotContainsString(self::HOST_TOKEN, $this->body($response));
    }

    public function testResponsesAreNotCacheable(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $response = $this->request('GET', '/v1/hosts/home/update', $this->auth());

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . self::HOST_TOKEN];
    }
}
