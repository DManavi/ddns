<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Ip;

use Ddns\Config\ServerConfig;
use Ddns\Ip\ClientIpDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpDetectorTest extends TestCase
{
    public function testUsesTheSocketPeerAddressWhenNoProxiesAreTrusted(): void
    {
        $detector = new ClientIpDetector(new ServerConfig());

        $address = $detector->detect($this->request('203.0.113.7'));

        self::assertSame('203.0.113.7', $address?->value());
    }

    /**
     * The security property this whole class exists for: a caller that is not
     * a configured proxy must not be able to claim a different source address.
     */
    #[DataProvider('spoofHeaders')]
    public function testIgnoresForwardingHeadersFromAnUntrustedPeer(string $header, string $value): void
    {
        $detector = new ClientIpDetector(new ServerConfig());

        $address = $detector->detect($this->request('203.0.113.7', [$header => $value]));

        self::assertSame(
            '203.0.113.7',
            $address?->value(),
            'A forged forwarding header was believed from an untrusted peer.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spoofHeaders(): iterable
    {
        yield 'x-forwarded-for' => ['X-Forwarded-For', '198.51.100.1'];
        yield 'x-real-ip' => ['X-Real-IP', '198.51.100.1'];
        yield 'chained spoof' => ['X-Forwarded-For', '198.51.100.1, 198.51.100.2'];
    }

    /**
     * Even with trusted proxies configured, a peer outside those ranges must
     * still not have its headers believed.
     */
    public function testIgnoresForwardingHeadersFromAPeerOutsideTheTrustedRanges(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect(
            $this->request('203.0.113.99', ['X-Forwarded-For' => '198.51.100.1']),
        );

        self::assertSame('203.0.113.99', $address?->value());
    }

    public function testHonoursForwardedForFromATrustedProxy(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect(
            $this->request('10.0.0.5', ['X-Forwarded-For' => '203.0.113.7']),
        );

        self::assertSame('203.0.113.7', $address?->value());
    }

    /**
     * With a chain of our own proxies, the rightmost non-proxy entry is the
     * real client: entries further left were supplied by the client itself.
     */
    public function testWalksAChainRightToLeftAndStopsAtTheFirstUntrustedHop(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect($this->request('10.0.0.5', [
            'X-Forwarded-For' => '198.51.100.66, 203.0.113.7, 10.0.0.9',
        ]));

        self::assertSame(
            '203.0.113.7',
            $address?->value(),
            'The client-supplied leftmost entry should not win over the hop our proxy recorded.',
        );
    }

    public function testFallsBackToThePeerWhenEveryForwardedEntryIsATrustedProxy(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect($this->request('10.0.0.5', ['X-Forwarded-For' => '10.0.0.9']));

        self::assertSame('10.0.0.5', $address?->value());
    }

    public function testFallsBackToThePeerWhenTheForwardedValueIsGarbage(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect($this->request('10.0.0.5', ['X-Forwarded-For' => 'not-an-ip']));

        self::assertSame('10.0.0.5', $address?->value());
    }

    public function testPrefersXForwardedForOverXRealIp(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect($this->request('10.0.0.5', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Real-IP' => '198.51.100.1',
        ]));

        self::assertSame('203.0.113.7', $address?->value());
    }

    public function testUsesXRealIpWhenForwardedForIsAbsent(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['10.0.0.0/8']));

        $address = $detector->detect($this->request('10.0.0.5', ['X-Real-IP' => '203.0.113.7']));

        self::assertSame('203.0.113.7', $address?->value());
    }

    public function testStripsAPortFromAnIpv4PeerAddress(): void
    {
        $detector = new ClientIpDetector(new ServerConfig());

        self::assertSame('203.0.113.7', $detector->detect($this->request('203.0.113.7:54321'))?->value());
    }

    public function testStripsAPortFromABracketedIpv6PeerAddress(): void
    {
        $detector = new ClientIpDetector(new ServerConfig());

        self::assertSame('2001:db8::1', $detector->detect($this->request('[2001:db8::1]:54321'))?->value());
    }

    public function testKeepsABareIpv6PeerAddressIntact(): void
    {
        $detector = new ClientIpDetector(new ServerConfig());

        self::assertSame('2001:db8::1', $detector->detect($this->request('2001:db8::1'))?->value());
    }

    public function testHonoursAnIpv6ProxyRange(): void
    {
        $detector = new ClientIpDetector(new ServerConfig(trustedProxies: ['2001:db8::/32']));

        $address = $detector->detect(
            $this->request('2001:db8::5', ['X-Forwarded-For' => '203.0.113.7']),
        );

        self::assertSame('203.0.113.7', $address?->value());
    }

    public function testReturnsNullWhenThereIsNoPeerAddress(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://ddns.test/', []);

        self::assertNull((new ClientIpDetector(new ServerConfig()))->detect($request));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $remoteAddr, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ddns.test/v1/hosts/home/update',
            ['REMOTE_ADDR' => $remoteAddr],
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
