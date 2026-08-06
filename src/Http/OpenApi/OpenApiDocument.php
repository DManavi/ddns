<?php

declare(strict_types=1);

namespace Ddns\Http\OpenApi;

use Ddns\Domain\Record\RecordType;
use Ddns\Domain\Update\UpdateOutcome;

/**
 * The OpenAPI description of this server's HTTP API.
 *
 * Built in code rather than kept as a static file so the parts that already
 * exist as types stay in step: the record types and update outcomes are read
 * from their enums, and a test checks the documented paths against the routes
 * the application actually registers. A checked-in YAML file would describe
 * whatever was true when it was last edited.
 *
 * The document contains no secrets - it describes shapes and status codes, all
 * of which are in the public README - so it is served without authentication.
 */
final class OpenApiDocument
{
    public const VERSION = '3.1.0';

    public function __construct(
        private readonly string $apiVersion,
        private readonly ?string $serverUrl = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'openapi' => self::VERSION,
            'info' => $this->info(),
            'servers' => $this->servers(),
            'tags' => [
                ['name' => 'Records', 'description' => 'Keeping a hostname pointed at the current address.'],
                ['name' => 'Service', 'description' => 'Probes and machine-readable documentation.'],
            ],
            'paths' => $this->paths(),
            'components' => [
                'securitySchemes' => $this->securitySchemes(),
                'schemas' => $this->schemas(),
                'responses' => $this->responses(),
            ],
            // Applied to every operation unless overridden. The three schemes
            // are alternatives: any one of them authenticates a request. When
            // several are sent at once the precedence is documented in
            // `info.description`, since OpenAPI has no way to express it.
            'security' => [
                ['bearerAuth' => []],
                ['basicAuth' => []],
                ['tokenQuery' => []],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        return [
            'title' => 'ddns',
            'version' => $this->apiVersion,
            'summary' => 'Self-hosted dynamic DNS over a simplified, provider-agnostic API.',
            'description' => <<<'MARKDOWN'
                Keeps DNS records pointed at a changing address, wrapping DigitalOcean,
                Vultr, Cloudflare, Azure DNS, Azure Private DNS and AWS Route53 behind
                one interface.

                Every request is authenticated with a **per-host token**. A token grants
                access to exactly one host, so a compromised client cannot touch any
                other record, and there is deliberately no endpoint that lists the hosts
                a server knows about.

                Updates are idempotent. When a record already holds the requested
                address nothing is sent to the provider and the outcome is `unchanged`,
                which is what makes polling on a short interval safe.

                Any one of the three security schemes authenticates a request. If more
                than one is sent, they are tried in the order `token` query parameter,
                `Authorization` header, then HTTP Basic — and the first one found is
                the only one tried, so a valid header alongside a stale query token is
                refused rather than falling back.
                MARKDOWN,
            'license' => ['name' => 'MIT', 'identifier' => 'MIT'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servers(): array
    {
        // Reflecting the URL the document was fetched from means a client can
        // point a tool straight at it; without one, tools default to the host
        // serving the spec anyway, so an absent value is not a problem.
        return $this->serverUrl === null
            ? [['url' => '/', 'description' => 'This server.']]
            : [['url' => $this->serverUrl, 'description' => 'This server.']];
    }

    /**
     * @return array<string, mixed>
     */
    private function securitySchemes(): array
    {
        return [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'The host token as a bearer credential. Preferred where the client allows a custom header.',
            ],
            'basicAuth' => [
                'type' => 'http',
                'scheme' => 'basic',
                'description' => 'The host token as the password. The username is ignored, though the host name is the '
                    . 'conventional choice. This is what most consumer routers can send.',
            ],
            'tokenQuery' => [
                'type' => 'apiKey',
                'in' => 'query',
                'name' => 'token',
                'description' => 'The host token in the query string, for clients that can only be handed a URL. '
                    . 'It will appear in access logs, so prefer one of the header forms where possible.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        return [
            '/' => ['get' => $this->root()],
            '/api' => ['get' => $this->docs()],
            '/health' => ['get' => $this->health()],
            '/openapi.json' => ['get' => $this->openapi('json')],
            '/openapi.yaml' => ['get' => $this->openapi('yaml')],
            '/v1/hosts/{host}' => [
                'parameters' => [$this->hostParameter()],
                'get' => $this->showHost(),
            ],
            '/v1/hosts/{host}/update' => [
                'parameters' => [$this->hostParameter()],
                'get' => $this->update('get'),
                'post' => $this->update('post'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function root(): array
    {
        return [
            'operationId' => 'root',
            'tags' => ['Service'],
            'summary' => 'Redirect to the documentation.',
            'description' => 'Nothing is served at the root, and a browser arriving there is looking for the '
                . 'documentation. Temporary rather than permanent, so the root stays free for something else later.',
            'security' => [],
            'responses' => [
                '302' => [
                    'description' => 'Redirect to `/api`.',
                    'headers' => [
                        'Location' => [
                            'description' => 'The documentation, as a path relative to this server.',
                            'schema' => ['type' => 'string', 'example' => '/api'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function docs(): array
    {
        return [
            'operationId' => 'docs',
            'tags' => ['Service'],
            'summary' => 'This description, rendered with Swagger UI.',
            'description' => 'A browsable version of the OpenAPI document, from which the API can also be called. '
                . 'The page holds no documentation of its own - it reads `/openapi.json`.',
            'security' => [],
            'responses' => [
                '200' => [
                    'description' => 'The documentation page.',
                    'content' => ['text/html' => ['schema' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function health(): array
    {
        return [
            'operationId' => 'health',
            'tags' => ['Service'],
            'summary' => 'Liveness probe.',
            'description' => 'Reports that the configuration loaded and how much of it there is. '
                . 'Counts only - never host names, which would be disclosure on an unauthenticated endpoint.',
            // Probes run before any credential exists.
            'security' => [],
            'responses' => [
                '200' => [
                    'description' => 'The server is up and its configuration is valid.',
                    'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Health'],
                    ]],
                ],
                '500' => ['$ref' => '#/components/responses/ConfigurationError'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openapi(string $format): array
    {
        $isJson = $format === 'json';

        return [
            'operationId' => 'openapi' . ucfirst($format),
            'tags' => ['Service'],
            'summary' => sprintf('This document, as %s.', strtoupper($format)),
            'security' => [],
            'responses' => [
                '200' => [
                    'description' => 'The OpenAPI description of this server.',
                    'content' => [
                        $isJson ? 'application/json' : 'application/yaml' => [
                            'schema' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function showHost(): array
    {
        return [
            'operationId' => 'showHost',
            'tags' => ['Records'],
            'summary' => "Show a host's own configuration.",
            'description' => 'Returns the host this token authenticates for, with the token redacted, together with '
                . 'the address the server believes the caller is coming from. Reading `client_ip` back is the '
                . 'quickest way to confirm a reverse-proxy setup before trusting an update.',
            'responses' => [
                '200' => [
                    'description' => 'The host, as configured.',
                    'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/HostView'],
                    ]],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorised'],
                '500' => ['$ref' => '#/components/responses/ConfigurationError'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $method): array
    {
        $operation = [
            'operationId' => $method === 'get' ? 'updateHostViaGet' : 'updateHost',
            'tags' => ['Records'],
            'summary' => 'Point a host at an address.',
            'description' => $this->updateDescription($method),
            'parameters' => [
                ...$this->addressParameters(),
                [
                    'name' => 'dry_run',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Report what would change without writing anything to the provider.',
                    'schema' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The request was handled. Read `status` to find out what happened - '
                        . 'a record that was already correct reports `unchanged`, which is not a failure.',
                    'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/UpdateReport'],
                        'examples' => $this->updateExamples(),
                    ]],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorised'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/Unprocessable'],
                '429' => ['$ref' => '#/components/responses/RateLimited'],
                '500' => ['$ref' => '#/components/responses/ConfigurationError'],
                '501' => ['$ref' => '#/components/responses/NotImplemented'],
                '502' => ['$ref' => '#/components/responses/ProviderError'],
            ],
        ];

        if ($method === 'post') {
            $operation['requestBody'] = [
                'required' => false,
                'description' => 'The same fields accepted in the query string. Query values are read too, '
                    . 'and both sources are combined.',
                'content' => [
                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/UpdateRequest']],
                    'application/x-www-form-urlencoded' => ['schema' => ['$ref' => '#/components/schemas/UpdateRequest']],
                ],
            ];
        }

        return $operation;
    }

    private function updateDescription(string $method): string
    {
        $shared = <<<'MARKDOWN'
            An explicitly supplied address wins; otherwise the source address of the
            request is used, which is what makes a bare `curl` from behind the router
            enough to keep a record current.

            The address may be given as `ip`, `myip`, `ipv4` or `ipv6` - all are
            equivalent, and several may be supplied, repeated or comma separated, so a
            dual-stack client can send both families in one request. `auto` is treated
            as though nothing had been supplied.

            Only the record types configured for the host are touched. A host that
            lists `AAAA` but has no IPv6 address available reports that record as
            `skipped` rather than failing the request.
            MARKDOWN;

        if ($method === 'get') {
            return $shared . "\n\n"
                . 'GET is offered alongside POST because many routers and embedded clients can only be '
                . 'configured with a plain URL to fetch. It is not safe in the HTTP sense - it changes state.';
        }

        return $shared;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addressParameters(): array
    {
        $descriptions = [
            'ip' => 'The address to publish. Accepts IPv4 and IPv6.',
            'myip' => 'Alias of `ip`, for compatibility with clients written against other DDNS services.',
            'ipv4' => 'Alias of `ip`. Naming a family is a hint to the reader; the address itself decides the record type.',
            'ipv6' => 'Alias of `ip`.',
        ];

        $parameters = [];

        foreach ($descriptions as $name => $description) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'description' => $description,
                'schema' => ['type' => 'string'],
                'examples' => [
                    'ipv4' => ['value' => '203.0.113.7'],
                    'ipv6' => ['value' => '2001:db8::1'],
                    'both' => ['summary' => 'Both families at once', 'value' => '203.0.113.7,2001:db8::1'],
                    'auto' => ['summary' => 'Use the source address', 'value' => 'auto'],
                ],
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function hostParameter(): array
    {
        return [
            'name' => 'host',
            'in' => 'path',
            'required' => true,
            'description' => "The host key from the configuration file. The token presented must be that host's own.",
            'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$'],
            'example' => 'home',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateExamples(): array
    {
        return [
            'updated' => [
                'summary' => 'The address changed',
                'value' => [
                    'host' => 'home',
                    'fqdn' => 'home.example.com',
                    'status' => 'updated',
                    'changed' => true,
                    'records' => [[
                        'type' => 'A',
                        'status' => 'updated',
                        'ip' => '203.0.113.7',
                        'previous' => '203.0.113.1',
                        'reason' => null,
                        'dry_run' => false,
                    ]],
                    'client_ip' => '203.0.113.7',
                ],
            ],
            'unchanged' => [
                'summary' => 'Already correct, so nothing was sent to the provider',
                'value' => [
                    'host' => 'home',
                    'fqdn' => 'home.example.com',
                    'status' => 'unchanged',
                    'changed' => false,
                    'records' => [[
                        'type' => 'A',
                        'status' => 'unchanged',
                        'ip' => '203.0.113.7',
                        'previous' => '203.0.113.7',
                        'reason' => null,
                        'dry_run' => false,
                    ]],
                    'client_ip' => '203.0.113.7',
                ],
            ],
            'skipped' => [
                'summary' => 'A configured family had no address available',
                'value' => [
                    'host' => 'home',
                    'fqdn' => 'home.example.com',
                    'status' => 'unchanged',
                    'changed' => false,
                    'records' => [
                        [
                            'type' => 'A',
                            'status' => 'unchanged',
                            'ip' => '203.0.113.7',
                            'previous' => '203.0.113.7',
                            'reason' => null,
                            'dry_run' => false,
                        ],
                        [
                            'type' => 'AAAA',
                            'status' => 'skipped',
                            'ip' => null,
                            'previous' => null,
                            'reason' => 'no IPv6 address was available for this client',
                            'dry_run' => false,
                        ],
                    ],
                    'client_ip' => '203.0.113.7',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'Health' => [
                'type' => 'object',
                'required' => ['status', 'hosts_configured', 'providers_configured'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['ok']],
                    'hosts_configured' => ['type' => 'integer', 'minimum' => 0],
                    'providers_configured' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'HostView' => [
                'type' => 'object',
                'required' => ['host', 'client_ip'],
                'properties' => [
                    'host' => ['$ref' => '#/components/schemas/Host'],
                    'client_ip' => [
                        'type' => ['string', 'null'],
                        'description' => 'The address the server attributes to this request, after applying `trusted_proxies`.',
                    ],
                ],
            ],
            'Host' => [
                'type' => 'object',
                'required' => ['name', 'fqdn', 'zone', 'record', 'provider', 'types', 'ttl', 'token'],
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The host key, as used in this URL.'],
                    'fqdn' => ['type' => 'string', 'example' => 'home.example.com'],
                    'zone' => ['type' => 'string', 'example' => 'example.com'],
                    'record' => ['type' => 'string', 'description' => 'The name within the zone; `@` for the apex.'],
                    'provider' => ['type' => 'string', 'description' => 'The configured provider account backing this host.'],
                    'types' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/RecordType'],
                    ],
                    'ttl' => ['type' => 'integer', 'minimum' => 1],
                    'token' => [
                        'type' => 'string',
                        'description' => 'Redacted. At most the last four characters are shown, and nothing at all for a short secret.',
                        'example' => '****4567',
                    ],
                ],
            ],
            'UpdateRequest' => [
                'type' => 'object',
                'properties' => [
                    'ip' => [
                        'type' => 'string',
                        'description' => 'The address to publish. `myip`, `ipv4` and `ipv6` are accepted as aliases.',
                    ],
                    'myip' => ['type' => 'string'],
                    'ipv4' => ['type' => 'string'],
                    'ipv6' => ['type' => 'string'],
                    'dry_run' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            'UpdateReport' => [
                'type' => 'object',
                'required' => ['host', 'fqdn', 'status', 'changed', 'records'],
                'description' => 'The same structure the CLI reports under `hosts` with `--json`, plus `client_ip`.',
                'properties' => [
                    'host' => ['type' => 'string'],
                    'fqdn' => ['type' => 'string'],
                    'status' => [
                        'allOf' => [['$ref' => '#/components/schemas/Outcome']],
                        'description' => 'The worst outcome across the records: `failed` if any failed, '
                            . 'otherwise a change if anything changed.',
                    ],
                    'changed' => [
                        'type' => 'boolean',
                        'description' => 'Whether anything was actually written. Branch on this rather than on `status` '
                            . 'if all you need to know is whether the address moved.',
                    ],
                    'records' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/RecordUpdate'],
                    ],
                    'client_ip' => [
                        'type' => ['string', 'null'],
                        'description' => 'The address the server attributed to this request.',
                    ],
                ],
            ],
            'RecordUpdate' => [
                'type' => 'object',
                'required' => ['type', 'status', 'ip', 'previous', 'reason', 'dry_run'],
                'properties' => [
                    'type' => ['$ref' => '#/components/schemas/RecordType'],
                    'status' => ['$ref' => '#/components/schemas/Outcome'],
                    'ip' => ['type' => ['string', 'null'], 'description' => 'The address now published, if any.'],
                    'previous' => ['type' => ['string', 'null'], 'description' => 'What the record held before, when it existed.'],
                    'reason' => ['type' => ['string', 'null'], 'description' => 'Why a record was skipped or failed.'],
                    'dry_run' => ['type' => 'boolean', 'description' => 'True when the change was reported but not written.'],
                ],
            ],
            'RecordType' => [
                'type' => 'string',
                'enum' => array_map(static fn (RecordType $t): string => $t->value, RecordType::cases()),
            ],
            'Outcome' => [
                'type' => 'string',
                'enum' => array_map(static fn (UpdateOutcome $o): string => $o->value, UpdateOutcome::cases()),
                'description' => 'created: the record did not exist. updated: it pointed elsewhere. '
                    . 'unchanged: it was already correct and nothing was sent. '
                    . 'skipped: no address of that family was available. failed: the provider refused.',
            ],
            'Error' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'description' => 'A stable identifier to branch on. The message is for humans and may change.',
                                'example' => 'unauthorised',
                            ],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(): array
    {
        return [
            'Unauthorised' => $this->errorResponse(
                'No token, or the wrong one. An unknown host is answered identically to a wrong token, '
                . 'so this endpoint cannot be used to discover which hosts exist.',
                'unauthorised',
                ['WWW-Authenticate' => [
                    'description' => 'Offers HTTP Basic, so a browser or router prompts for credentials.',
                    'schema' => ['type' => 'string'],
                ]],
            ),
            'NotFound' => $this->errorResponse(
                'No such endpoint, or the zone does not exist on the provider.',
                'not_found',
            ),
            'Unprocessable' => $this->errorResponse(
                'The address was malformed, or was private while `allow_private_ips` is false, '
                . 'or none could be determined at all.',
                'invalid_ip',
            ),
            'RateLimited' => $this->errorResponse('The provider is rate limiting this server.', 'provider_error'),
            'NotImplemented' => $this->errorResponse(
                "The configured driver's optional dependency is missing from this build.",
                'provider_error',
            ),
            'ProviderError' => $this->errorResponse(
                "The provider rejected this server's credentials, or failed. Not the caller's fault, "
                . 'which is why it is reported as a gateway error.',
                'provider_error',
            ),
            'ConfigurationError' => $this->errorResponse(
                'The server is misconfigured. Details naming filesystem paths are withheld and logged instead.',
                'configuration_error',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function errorResponse(string $description, string $code, array $headers = []): array
    {
        $response = [
            'description' => $description,
            'content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/Error'],
                'example' => ['error' => ['code' => $code, 'message' => $description]],
            ]],
        ];

        if ($headers !== []) {
            $response['headers'] = $headers;
        }

        return $response;
    }
}
