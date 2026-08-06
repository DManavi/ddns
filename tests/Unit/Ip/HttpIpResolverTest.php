<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Ip;

use Ddns\Domain\Record\RecordType;
use Ddns\Ip\Exception\IpResolutionFailed;
use Ddns\Ip\HttpIpResolver;
use Ddns\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Slim\Psr7\Factory\RequestFactory;

final class HttpIpResolverTest extends TestCase
{
    public function testReturnsTheAddressFromTheFirstService(): void
    {
        $http = (new MockHttpClient())->queue(200, '203.0.113.7');

        self::assertSame('203.0.113.7', $this->resolver($http)->resolve(RecordType::A)->value());
        self::assertSame(1, $http->requestCount());
    }

    public function testTrimsWhitespaceFromTheResponseBody(): void
    {
        $http = (new MockHttpClient())->queue(200, "203.0.113.7\n");

        self::assertSame('203.0.113.7', $this->resolver($http)->resolve(RecordType::A)->value());
    }

    /**
     * A single echo service being down must not stop updates.
     */
    public function testFallsBackToTheNextServiceOnANon200(): void
    {
        $http = (new MockHttpClient())
            ->queue(503, 'unavailable')
            ->queue(200, '203.0.113.7');

        self::assertSame('203.0.113.7', $this->resolver($http)->resolve(RecordType::A)->value());
        self::assertSame(2, $http->requestCount());
    }

    public function testFallsBackWhenAServiceIsUnreachable(): void
    {
        $http = (new MockHttpClient())
            ->queueFailure($this->networkFailure())
            ->queue(200, '203.0.113.7');

        self::assertSame('203.0.113.7', $this->resolver($http)->resolve(RecordType::A)->value());
    }

    public function testFallsBackWhenAServiceReturnsGarbage(): void
    {
        $http = (new MockHttpClient())
            ->queue(200, '<html>error</html>')
            ->queue(200, '203.0.113.7');

        self::assertSame('203.0.113.7', $this->resolver($http)->resolve(RecordType::A)->value());
    }

    /**
     * On a dual-stack host an "IPv6" endpoint can answer over IPv4. Accepting
     * that would put an A address into an AAAA record.
     */
    public function testRejectsAnAddressOfTheWrongFamily(): void
    {
        $http = (new MockHttpClient())
            ->queue(200, '203.0.113.7')
            ->queue(200, '2001:db8::1');

        self::assertSame('2001:db8::1', $this->resolver($http)->resolve(RecordType::AAAA)->value());
    }

    public function testTryResolveReturnsNullWhenEveryServiceFails(): void
    {
        $http = (new MockHttpClient())->queue(500, '')->queue(500, '');

        self::assertNull($this->resolver($http)->tryResolve(RecordType::A));
    }

    public function testResolveThrowsWhenEveryServiceFails(): void
    {
        $http = (new MockHttpClient())->queue(500, '')->queue(500, '');

        $this->expectException(IpResolutionFailed::class);
        $this->expectExceptionMessage('IPv4');

        $this->resolver($http)->resolve(RecordType::A);
    }

    /**
     * Several hosts in one cycle must not mean several lookups.
     */
    public function testMemoisesTheResultWithinACycle(): void
    {
        $http = (new MockHttpClient())->queue(200, '203.0.113.7');
        $resolver = $this->resolver($http);

        $resolver->resolve(RecordType::A);
        $resolver->resolve(RecordType::A);

        self::assertSame(1, $http->requestCount());
    }

    public function testMemoisesFailuresToo(): void
    {
        $http = (new MockHttpClient())->queue(500, '')->queue(500, '');
        $resolver = $this->resolver($http);

        $resolver->tryResolve(RecordType::A);
        $resolver->tryResolve(RecordType::A);

        self::assertSame(2, $http->requestCount(), 'Both services tried once, then cached.');
    }

    /**
     * Caching across cycles would defeat the point of watching, so the loop
     * clears it each time round.
     */
    public function testForgetForcesAFreshLookup(): void
    {
        $http = (new MockHttpClient())
            ->queue(200, '203.0.113.7')
            ->queue(200, '203.0.113.9');

        $resolver = $this->resolver($http);

        self::assertSame('203.0.113.7', $resolver->resolve(RecordType::A)->value());
        $resolver->forget();
        self::assertSame('203.0.113.9', $resolver->resolve(RecordType::A)->value());
    }

    public function testUsesTheConfiguredServiceUrls(): void
    {
        $http = (new MockHttpClient())->queue(200, '203.0.113.7');

        $this->resolver($http)->resolve(RecordType::A);

        self::assertSame('https://v4-a.test/', (string) $http->request(0)->getUri());
    }

    private function resolver(MockHttpClient $http): HttpIpResolver
    {
        return new HttpIpResolver(
            $http,
            new RequestFactory(),
            ['https://v4-a.test/', 'https://v4-b.test/'],
            ['https://v6-a.test/', 'https://v6-b.test/'],
        );
    }

    private function networkFailure(): ClientExceptionInterface
    {
        return new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
    }
}
