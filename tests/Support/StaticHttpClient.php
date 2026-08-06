<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * A PSR-18 client that answers every request with the same body.
 *
 * Unlike {@see MockHttpClient} there is no queue to exhaust, which is what the
 * IP echo lookup needs: the number of calls depends on how many record types a
 * test happens to watch and how many poll cycles it runs, and a test should not
 * have to predict that.
 *
 * Its real job is keeping the suite offline. {@see \Ddns\Ip\HttpIpResolver}
 * constructs its own Guzzle client in the container, so a test that does not
 * override it will silently reach the public internet and assert against
 * whatever address the machine happens to have.
 */
final class StaticHttpClient implements ClientInterface
{
    private int $calls = 0;

    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        return (new ResponseFactory())
            ->createResponse($this->status)
            ->withBody((new StreamFactory())->createStream($this->body));
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
