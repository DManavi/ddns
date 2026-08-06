<?php

declare(strict_types=1);

namespace Ddns\Provider\Http;

use Ddns\Domain\Provider\Exception\RecordOperationFailed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Minimal JSON-over-HTTP transport shared by every REST driver.
 *
 * Being PSR-18 based is deliberate: it is what lets the whole provider suite be
 * tested without a network, and keeps Guzzle swappable.
 */
final class RestClient
{
    /**
     * @param array<string, string> $defaultHeaders typically the Authorization header
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $driver,
        private readonly string $baseUri,
        private readonly array $defaultHeaders = [],
    ) {
    }

    /**
     * @param array<string, string|int> $query
     */
    public function get(string $path, array $query = []): RestResponse
    {
        return $this->send('GET', $path, $query, null);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(string $path, array $body): RestResponse
    {
        return $this->send('POST', $path, [], $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function put(string $path, array $body): RestResponse
    {
        return $this->send('PUT', $path, [], $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function patch(string $path, array $body): RestResponse
    {
        return $this->send('PATCH', $path, [], $body);
    }

    /**
     * Follow an absolute or relative URL returned by the API itself, used for
     * cursor and link based pagination.
     */
    public function follow(string $url): RestResponse
    {
        return $this->send('GET', $url, [], null);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, array $query, ?array $body): RestResponse
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->url($path, $query))
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'ddns/1.0 (+https://github.com/DManavi/ddns)');

        foreach ($this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encoded));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw RecordOperationFailed::for(
                $this->driver,
                strtolower($method) . ' ' . $path,
                'the API could not be reached: ' . $e->getMessage(),
            );
        }

        return RestResponse::fromPsrResponse($this->driver, $response);
    }

    /**
     * @param array<string, string|int> $query
     */
    private function url(string $path, array $query): string
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');

        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
}
