<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * A PSR-18 client that replays queued responses and records what was sent.
 *
 * Every provider test runs through this, so the suite never touches the network
 * and can assert on the exact request shaping each API expects.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /**
     * @param array<array-key, mixed>|string $body
     * @param array<string, string>          $headers
     */
    public function queue(int $status, array|string $body = [], array $headers = []): self
    {
        $response = (new ResponseFactory())->createResponse($status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $encoded = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        $this->queue[] = $response->withBody((new StreamFactory())->createStream($encoded));

        return $this;
    }

    public function queueFailure(ClientExceptionInterface $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException(sprintf(
                'MockHttpClient received an unexpected %s %s: nothing left in the queue.',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function request(int $index): RequestInterface
    {
        return $this->requests[$index]
            ?? throw new \OutOfBoundsException(sprintf('No request was made at index %d.', $index));
    }

    public function lastRequest(): RequestInterface
    {
        return $this->request($this->requestCount() - 1);
    }

    /**
     * The decoded JSON body of a recorded request.
     *
     * @return array<array-key, mixed>
     */
    public function bodyOf(int $index): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isDrained(): bool
    {
        return $this->queue === [];
    }
}
