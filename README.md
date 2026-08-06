# ddns

A self-hosted dynamic DNS server. It wraps several DNS provider APIs behind one
simplified interface and exposes that through **two interchangeable front-ends**:

- **HTTP** — a router, cron job, or container calls an endpoint and the server
  works out the caller's address and updates the record.
- **CLI** — the same operation locally, either one-shot or as a polling loop.

Both go through the same code path, so behaviour never diverges between them.

```console
$ curl -H "Authorization: Bearer $TOKEN" https://ddns.example.com/v1/hosts/home/update
{
    "host": "home",
    "fqdn": "home.example.com",
    "status": "updated",
    "changed": true,
    "records": [
        { "type": "A", "status": "updated", "ip": "203.0.113.99", "previous": "203.0.113.77" }
    ],
    "client_ip": "203.0.113.99"
}
```

## Why

Most DDNS clients are tied to one provider and one delivery mechanism. This one
separates the three concerns:

- **Providers are thin.** A driver implements three methods — find, create,
  update. Nothing else.
- **The logic lives once.** Deciding between create and update, refusing to
  write when nothing changed, TTL handling, dual-stack hosts: all of it sits in
  a single `DdnsUpdater`, shared by HTTP and CLI.
- **State is a file.** No database. A YAML file with `${ENV_VAR}` placeholders
  is the whole configuration, so it can be version-controlled and the secrets
  injected at runtime.

The **unchanged** short-circuit matters most in practice: a record already
pointing at the right address costs one read and zero writes, which is what
makes a 60-second poll interval safe against provider rate limits.

## Providers

| Driver | Status | Notes |
| --- | --- | --- |
| `digitalocean` | Available | Domain Records API, fully paginated |
| `vultr` | Available | DNS API v2, cursor pagination |
| `cloudflare` | Available | API v4; zone IDs resolved once and cached |
| `route53` | Not implemented | Registered and discoverable; needs AWS SigV4 |

```console
$ ddns providers:list
```

Adding one is a single class plus a factory — see [Adding a provider](#adding-a-provider).

## Quick start

### Docker

```bash
cp config/ddns.example.yaml ddns.yaml   # edit: zone, record name, provider
cp .env.example .env                    # edit: API token, host token

docker compose up server                # HTTP endpoint on :8080
# or
docker compose up watcher               # polling loop, no ports exposed
```

### Bare metal

Requires PHP 8.2+ with `curl`, `mbstring` and `xml`. `pcntl` is optional and
enables graceful shutdown of `watch`.

```bash
composer install --no-dev
cp config/ddns.example.yaml ddns.yaml
cp .env.example .env

./bin/ddns config:validate      # check before going near a provider
./bin/ddns update --all         # one-shot
./bin/ddns watch --all          # keep running
```

To serve HTTP, point any web server at `public/`:

```bash
php -S 0.0.0.0:8080 -t public public/index.php
```

> The built-in server is single-threaded. It is fine for a DDNS endpoint
> receiving a handful of requests a day, but put nginx, Caddy or Apache in front
> of it if you want TLS — and if you do, set `server.trusted_proxies`.

## Configuration

One YAML file is the entire source of truth. `${VAR}` and `${VAR:-default}` are
expanded from the environment or a `.env` file, so the file itself holds no
secrets and can be committed.

```yaml
server:
  default_ttl: 300
  trusted_proxies: []          # see the security note below
  allow_private_ips: false
  ip_lookup_timeout: 5

providers:
  do-personal:
    driver: digitalocean
    token: ${DO_TOKEN}

hosts:
  home:
    provider: do-personal
    zone: example.com
    name: home                 # '@' for the zone apex
    types: [A, AAAA]
    ttl: 60
    token: ${HOME_TOKEN}       # the secret an HTTP client presents
```

`config/ddns.example.yaml` is fully annotated. The file is discovered from
`$DDNS_CONFIG`, then `./ddns.yaml`, `./ddns.yml`, `./config/ddns.yaml`,
`./config/ddns.yml`; `--config` overrides all of them.

### Reference

**`server`**

| Key | Default | Meaning |
| --- | --- | --- |
| `default_ttl` | `300` | TTL for hosts that do not set their own |
| `trusted_proxies` | `[]` | CIDRs whose `X-Forwarded-For` is believed |
| `allow_private_ips` | `false` | Permit publishing RFC1918 / loopback addresses |
| `ip_lookup_timeout` | `5` | Seconds before trying the next echo service |
| `ip_services.v4` / `.v6` | ipify, icanhazip, ident.me | Echo endpoints, tried in order |

**`providers.<name>`**

| Key | Required | Meaning |
| --- | --- | --- |
| `driver` | yes | One of the drivers above |
| `token` | yes | API credential |
| `zone_id` | no | Cloudflare only: skip the zone lookup for a zone-scoped token |
| `base_uri` | no | Override the API endpoint, mainly for testing |

**`hosts.<name>`** — the key is used both as the URL segment and the CLI
argument, so keep it URL-safe.

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `provider` | yes | | Which provider entry to use |
| `zone` | yes | | The DNS zone |
| `name` | no | `@` | Name relative to the zone |
| `types` | no | `[A]` | Address families to keep in sync |
| `ttl` | no | `server.default_ttl` | Record TTL |
| `token` | yes | | Client secret, minimum 12 characters |

`config:validate` reports every problem in one pass:

```console
$ ddns config:validate
[ERROR] The DDNS configuration is invalid:
  - "providers.p1.driver" is "azure", which is not a known driver. Available drivers: digitalocean, vultr, cloudflare, route53.
  - "hosts.home.token" must be at least 12 characters. Generate one with: openssl rand -hex 24
```

## HTTP API

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/health` | none | Liveness probe |
| `GET` | `/v1/hosts/{host}` | yes | The host's own config, token redacted |
| `GET` `POST` | `/v1/hosts/{host}/update` | yes | Update the record |

### Authentication

A per-host token, accepted three ways so almost any client can be made to work:

```bash
# Bearer header
curl -H "Authorization: Bearer $TOKEN" https://ddns.example.com/v1/hosts/home/update

# HTTP Basic — the token is the password; any username works
curl -u "home:$TOKEN" https://ddns.example.com/v1/hosts/home/update

# Query parameter, for clients that can only be handed a URL
curl "https://ddns.example.com/v1/hosts/home/update?token=$TOKEN"
```

Comparison is constant-time, and an unknown host is indistinguishable from a
wrong token, so the API cannot be used to enumerate configured host names.

### Choosing the address

An explicit address wins; otherwise the request's source address is used.

```bash
# use the source address
curl -H "Authorization: Bearer $TOKEN" .../v1/hosts/home/update

# state it explicitly (`ip`, `myip`, `ipv4`, `ipv6` all work)
curl -H "Authorization: Bearer $TOKEN" '.../v1/hosts/home/update?ip=203.0.113.7'

# both families at once
curl -H "Authorization: Bearer $TOKEN" '.../v1/hosts/home/update?ip=203.0.113.7,2001:db8::1'

# preview
curl -H "Authorization: Bearer $TOKEN" '.../v1/hosts/home/update?dry_run=1'
```

`ip=auto` is treated as though nothing were supplied.

### Status codes

| Code | Meaning |
| --- | --- |
| `200` | Handled; see `status` for `created` / `updated` / `unchanged` |
| `401` | Missing or wrong token |
| `404` | No such endpoint, or the zone does not exist on the provider |
| `422` | Malformed address, or a private address with `allow_private_ips: false` |
| `429` | The provider is rate limiting us |
| `501` | The configured driver is not implemented yet |
| `502` | The provider rejected our credentials or failed |

### Router setup

Most routers with a "custom DDNS" option need a URL, a username and a password.
Use the update URL, the host name as username, and the token as password:

```
Server:   ddns.example.com
Path:     /v1/hosts/home/update
Username: home
Password: <the host token>
```

For routers that only accept a full URL, use the query-parameter form.

## CLI

```console
$ ddns update [<host>...] [--all] [--ip=IP]... [--dry-run]
$ ddns watch  [<host>...] [--all] [--interval=300] [--force-after=12] [--once]
$ ddns hosts:list [--json]
$ ddns config:validate [<file>]
$ ddns providers:list
```

```console
$ ddns update --all
 ------ ------------------ ------ ----------- -------------- --------
  Host   FQDN               Type   Status      Address        Detail
 ------ ------------------ ------ ----------- -------------- --------
  home   home.example.com   A      updated     203.0.113.99
  home   home.example.com   AAAA   skipped                    no IPv6 address was available for this client
 ------ ------------------ ------ ----------- -------------- --------
```

Exit codes: `0` success, `1` at least one record failed, `2` invalid
configuration or arguments.

### `watch`

Polls for address changes and only contacts the provider when one is detected.
Between changes a poll costs one echo-service lookup and no provider API calls.
Every `--force-after` unchanged polls the records are reconciled anyway, so
drift introduced elsewhere gets repaired.

Provider failures back off exponentially, capped at an hour. `SIGINT` and
`SIGTERM` shut down cleanly when `ext-pcntl` is available. State is in memory
only, so a restart always performs one reconcile.

Prefer cron? Use `update`, or `watch --once`.

## Security notes

- **`trusted_proxies` is empty by default.** With no entries the socket peer
  address is used and forwarding headers are ignored entirely. Trusting
  `X-Forwarded-For` unconditionally would let any caller point someone else's
  record wherever they liked. Set it only to the CIDRs of proxies you actually
  run — and if you do run one, you must set it, or every update will record the
  proxy's address instead of the client's.
- **Give each host its own token.** A token authenticates exactly one host.
- **Private addresses are refused by default.** A private address in a public
  zone almost always means a misconfigured reverse proxy.
- **Tokens are never disclosed.** They are redacted in `hosts:list`, in
  `/v1/hosts/{host}`, and in all log output. Error bodies carry no stack traces
  or provider credentials.

## Development

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan, level max
composer fmt           # PHP-CS-Fixer
composer check         # all three
```

### Layout

```
bin/ddns            CLI entrypoint
public/index.php    HTTP entrypoint
config/             container definitions, routes, example config
src/
  Domain/           framework-free core: records, provider contract, DdnsUpdater
  Config/           YAML loading, env interpolation, validation
  Provider/         one directory per driver, plus the shared REST transport
  Ip/               address resolution and trusted-proxy handling
  Http/             Slim actions, middleware, error translation
  Console/          Symfony Console commands
tests/              Unit/ and Integration/; no test touches the network
```

`src/Domain` imports neither Slim nor Symfony Console. That boundary is what
keeps the same use case usable from both front-ends.

### Adding a provider

1. Implement `Ddns\Domain\Provider\DnsProvider` — `findRecord`, `createRecord`,
   `updateRecord`. Use `Ddns\Provider\Http\RestClient` for transport and error
   mapping.
2. Add a factory. Extend `BearerTokenProviderFactory` if the API takes a bearer
   token.
3. Register it in the `ProviderFactories` definition in `config/container.php`.
   The config validator picks up the new `driver:` value automatically.
4. Add a test using `Ddns\Tests\Support\MockHttpClient`, following one of the
   existing provider tests.

Upsert semantics, no-change detection and dual-stack handling are already done
for you.

## Not implemented

- RFC 2136 DNS UPDATE and the dyndns2 de-facto protocol.
- Any database, persistent update history, or audit log.
- A web UI. API and CLI only.
- Runtime management of hosts — the config file is the source of truth.

## License

MIT. See [LICENSE](LICENSE).
