<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Bootstrap;
use Ddns\Domain\Record\RecordType;
use Ddns\Domain\Update\UpdateOutcome;
use Ddns\Http\OpenApi\OpenApiDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI description.
 *
 * A specification is only worth publishing if it is true, so these tests
 * compare it against the application rather than against itself: the documented
 * paths and methods are checked against the routes Slim actually registers, and
 * the documented response shapes against what the actions actually return.
 * Nothing here would notice a typo in a description - everything here would
 * notice a route, field or status code that stopped matching.
 */
#[CoversNothing]
final class OpenApiTest extends HttpTestCase
{
    /**
     * Routes that are served but deliberately left out of the description.
     *
     * All of these exist to deliver the documentation, or to report on the
     * server itself, rather than to manage DNS: the root redirect, the page
     * at `/api`, the two formats of this document, the assets behind that
     * page, and the health probe. Describing the machinery that serves the
     * description is noise to whoever is reading it to write a client.
     *
     * An explicit list rather than a pattern, so adding to it is a decision
     * somebody makes rather than one that happens quietly.
     *
     * @var list<string>
     */
    private const NOT_API_SURFACE = [
        'get /',
        'get /api',
        'get /health',
        'get /openapi.json',
        'get /openapi.yaml',
        'get /vendor/swagger-ui/{file}',
    ];

    #[Test]
    public function it_is_served_as_json(): void
    {
        $response = $this->request('GET', '/openapi.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(OpenApiDocument::VERSION, $this->at($response, 'openapi'));
    }

    #[Test]
    public function it_is_served_as_yaml(): void
    {
        $response = $this->request('GET', '/openapi.yaml');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/yaml', $response->getHeaderLine('Content-Type'));
        self::assertSame(OpenApiDocument::VERSION, $this->yaml($response)['openapi'] ?? null);
    }

    #[Test]
    public function both_formats_describe_the_same_api(): void
    {
        // Two representations of one structure. YAML writes `200:` as an
        // integer key where JSON can only have strings, so key types are
        // normalised before comparing; nothing else may differ.
        $json = $this->json($this->request('GET', '/openapi.json'));
        $yaml = $this->yaml($this->request('GET', '/openapi.yaml'));

        self::assertSame(self::normaliseKeys($json), self::normaliseKeys($yaml));
    }

    #[Test]
    public function the_yaml_form_writes_empty_arrays_as_arrays(): void
    {
        // Regression: dumped naively, an empty `[]` becomes `{}`, and a
        // document whose security requirements are objects rather than arrays
        // is rejected outright by OpenAPI validators. The scheme entries under
        // the root `security` are the empty arrays that exercise this.
        $body = $this->body($this->request('GET', '/openapi.yaml'));

        self::assertStringContainsString('bearerAuth: []', $body);
        self::assertStringNotContainsString('bearerAuth: {  }', $body);
    }

    #[Test]
    public function it_needs_no_credentials(): void
    {
        // Documentation a client cannot read until it is already authenticated
        // is of no use to whoever is trying to write that client.
        $response = $this->request('GET', '/openapi.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('paths', $this->json($response));
    }

    #[Test]
    public function it_may_be_cached_but_pins_its_own_type(): void
    {
        $response = $this->request('GET', '/openapi.json');

        self::assertStringContainsString('max-age', $response->getHeaderLine('Cache-Control'));
        // The document echoes the request's own host back under `servers`, so
        // a browser must not be free to sniff it as something executable.
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function it_reports_the_url_it_was_fetched_from(): void
    {
        $servers = $this->at($this->request('GET', '/openapi.json'), 'servers');

        self::assertIsArray($servers);

        $first = $servers[0] ?? null;

        self::assertIsArray($first);
        self::assertSame('https://ddns.test', $first['url'] ?? null);
    }

    #[Test]
    public function every_registered_route_is_documented(): void
    {
        $documented = $this->documentedOperations();

        foreach ($this->registeredOperations() as $operation) {
            if (in_array($operation, self::NOT_API_SURFACE, true)) {
                continue;
            }

            self::assertContains($operation, $documented, sprintf(
                '%s is routed but missing from the OpenAPI document.',
                $operation,
            ));
        }
    }

    #[Test]
    public function every_documented_operation_is_routed(): void
    {
        // The other direction, which is the one that rots quietly: a path left
        // behind in the document after a route is renamed sends clients at an
        // endpoint that answers 404.
        $registered = $this->registeredOperations();

        foreach ($this->documentedOperations() as $operation) {
            self::assertContains($operation, $registered, sprintf(
                '%s is documented but not routed.',
                $operation,
            ));
        }
    }

    #[Test]
    public function the_exemptions_are_all_still_routed(): void
    {
        // An exemption for a route that no longer exists is a licence for the
        // next one with that name to go undocumented.
        foreach (self::NOT_API_SURFACE as $operation) {
            self::assertContains($operation, $this->registeredOperations(), sprintf(
                '%s is exempted from documentation but is not routed.',
                $operation,
            ));
        }
    }

    #[Test]
    public function the_documented_host_response_matches_the_real_one(): void
    {
        $response = $this->request('GET', '/v1/hosts/home', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);

        $body = $this->json($response);

        self::assertSame($this->requiredProperties('HostView'), array_keys($body));

        $host = $body['host'] ?? null;

        self::assertIsArray($host);
        self::assertSame($this->requiredProperties('Host'), array_keys($host));
    }

    #[Test]
    public function the_documented_update_response_matches_the_real_one(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $response = $this->request('POST', '/v1/hosts/home/update?ip=203.0.113.7', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);

        $body = $this->json($response);

        // client_ip is documented but not required, since it is absent when no
        // source address could be determined.
        self::assertSame([...$this->requiredProperties('UpdateReport'), 'client_ip'], array_keys($body));

        $records = $body['records'] ?? null;

        self::assertIsArray($records);

        $first = $records[0] ?? null;

        self::assertIsArray($first);
        self::assertSame($this->requiredProperties('RecordUpdate'), array_keys($first));
    }

    #[Test]
    public function the_documented_error_shape_matches_the_real_one(): void
    {
        $response = $this->request('GET', '/v1/hosts/home');

        $body = $this->json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame($this->requiredProperties('Error'), array_keys($body));

        $error = $body['error'] ?? null;

        self::assertIsArray($error);
        self::assertSame(['code', 'message'], array_keys($error));
    }

    #[Test]
    public function the_documented_unauthorised_response_offers_the_header_it_claims(): void
    {
        $response = $this->request('GET', '/v1/hosts/home');

        $documented = $this->at($this->request('GET', '/openapi.json'), 'components.responses.Unauthorised.headers');

        self::assertIsArray($documented);
        self::assertArrayHasKey('WWW-Authenticate', $documented);
        self::assertNotSame('', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function the_documented_enums_come_from_the_domain(): void
    {
        // Hard-coded lists here would drift the moment a record type or an
        // outcome was added.
        $document = $this->json($this->request('GET', '/openapi.json'));

        self::assertSame(
            array_map(static fn (RecordType $t): string => $t->value, RecordType::cases()),
            $this->path($document, 'components.schemas.RecordType.enum'),
        );
        self::assertSame(
            array_map(static fn (UpdateOutcome $o): string => $o->value, UpdateOutcome::cases()),
            $this->path($document, 'components.schemas.Outcome.enum'),
        );
    }

    #[Test]
    public function the_documented_version_is_the_application_version(): void
    {
        self::assertSame(Bootstrap::VERSION, $this->at($this->request('GET', '/openapi.json'), 'info.version'));
    }

    #[Test]
    public function the_document_carries_what_the_specification_requires(): void
    {
        // The invariants an OpenAPI linter checks first, so a malformed
        // document fails here rather than in whatever tool a user reaches for.
        $document = $this->json($this->request('GET', '/openapi.json'));

        foreach (['title', 'version'] as $field) {
            self::assertArrayHasKey($field, (array) $this->path($document, 'info'));
        }

        $paths = (array) $this->path($document, 'paths');

        self::assertNotSame([], $paths);

        foreach ($paths as $path => $item) {
            foreach ((array) $item as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                $where = sprintf('%s %s', $method, $path);
                $responses = $this->path((array) $operation, 'responses');

                self::assertIsArray($responses, $where . ' has no responses.');
                self::assertNotSame([], $responses, $where . ' documents no responses.');
                self::assertArrayHasKey('operationId', (array) $operation, $where . ' has no operationId.');

                foreach ($responses as $status => $response) {
                    $response = (array) $response;

                    // A $ref stands in for a shared response that carries its
                    // own description.
                    if (array_key_exists('$ref', $response)) {
                        continue;
                    }

                    self::assertArrayHasKey(
                        'description',
                        $response,
                        sprintf('%s response %s has no description.', $where, $status),
                    );
                }
            }
        }
    }

    #[Test]
    public function operation_ids_are_unique(): void
    {
        // Client generators name methods after them, so a duplicate silently
        // drops an endpoint from the generated client.
        $document = $this->json($this->request('GET', '/openapi.json'));
        $ids = [];

        foreach ((array) $this->path($document, 'paths') as $item) {
            foreach ((array) $item as $method => $operation) {
                $operation = (array) $operation;
                $id = $operation['operationId'] ?? null;

                if ($method !== 'parameters' && is_string($id)) {
                    $ids[] = $id;
                }
            }
        }

        self::assertSame(array_unique($ids), $ids, 'Duplicate operationId.');
        self::assertNotSame([], $ids);
    }

    #[Test]
    public function every_reference_resolves(): void
    {
        // A $ref to a component that was renamed produces a document that
        // parses but cannot be used.
        $document = $this->json($this->request('GET', '/openapi.json'));

        foreach (self::collectRefs($document) as $ref) {
            self::assertStringStartsWith('#/components/', $ref);

            $target = str_replace('/', '.', substr($ref, 2));

            self::assertNotNull($this->path($document, $target), sprintf('%s does not resolve.', $ref));
        }
    }

    #[Test]
    public function the_documented_status_codes_are_ones_the_server_actually_returns(): void
    {
        $document = $this->json($this->request('GET', '/openapi.json'));

        $documented = array_keys((array) $this->path($document, 'paths./v1/hosts/{host}/update.get.responses'));

        // Sampled rather than exhaustive: 429/501/502 need a provider to
        // misbehave and are covered by the provider tests.
        $codes = array_map(static fn (int|string $c): string => (string) $c, $documented);

        self::assertContains('401', $codes);
        self::assertContains('422', $codes);

        $unauthorised = $this->request('GET', '/v1/hosts/home/update');
        self::assertSame(401, $unauthorised->getStatusCode());

        $malformed = $this->request('GET', '/v1/hosts/home/update?ip=nonsense', [
            'Authorization' => 'Bearer ' . self::HOST_TOKEN,
        ]);
        self::assertSame(422, $malformed->getStatusCode());
    }

    /**
     * The operations Slim will actually serve, as `METHOD /path`.
     *
     * @return list<string>
     */
    private function registeredOperations(): array
    {
        $routes = $this->app($this->defaultConfig())->getRouteCollector()->getRoutes();
        $operations = [];

        foreach ($routes as $route) {
            foreach ($route->getMethods() as $method) {
                if ($method === 'OPTIONS' || $method === 'HEAD') {
                    continue;
                }

                $operations[] = strtolower($method) . ' ' . $route->getPattern();
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $paths = $this->at($this->request('GET', '/openapi.json'), 'paths');

        self::assertIsArray($paths);

        $operations = [];

        foreach ($paths as $path => $item) {
            self::assertIsArray($item);

            foreach (array_keys($item) as $method) {
                if ($method === 'parameters') {
                    continue;
                }

                $operations[] = $method . ' ' . $path;
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function requiredProperties(string $schema): array
    {
        $required = $this->at($this->request('GET', '/openapi.json'), 'components.schemas.' . $schema . '.required');

        self::assertIsArray($required);

        return array_values(array_map(static fn (mixed $r): string => is_scalar($r) ? (string) $r : '', $required));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function yaml(ResponseInterface $response): array
    {
        $parsed = Yaml::parse($this->body($response));

        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function path(array $document, string $path): mixed
    {
        $current = $document;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private static function collectRefs(array $document): array
    {
        $refs = [];

        foreach ($document as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            } elseif (is_array($value)) {
                $refs = [...$refs, ...self::collectRefs($value)];
            }
        }

        return $refs;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function normaliseKeys(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = is_array($item) ? self::normaliseKeys($item) : $item;
        }

        return $out;
    }
}
