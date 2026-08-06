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
| `azuredns` | Available | Azure public DNS zones, via the management REST API |
| `azureprivatedns` | Available | Azure Private DNS zones |
| `route53` | Available | AWS SDK for PHP; supports the full AWS credential chain |

```console
$ ddns providers:list
```

Adding one is a single class plus a factory — see [Adding a provider](#adding-a-provider).

## Quick start

### Docker

Two stacks, one image:

| File | Purpose |
| --- | --- |
| `compose.yaml` | Production — hardened, restart policies, no source mounts |
| `compose.dev.yaml` | Development — source bind-mounted, dev dependencies, toolchain |

Requires **Compose v2.24 or newer** (for `env_file: required:`), invoked as
`docker compose`. The standalone `docker-compose` v1 reached end of life in
July 2023 and cannot parse these files.

> **On the file names.** `compose.yaml` is the canonical name in the
> [Compose Specification][spec]; Docker's docs list it as *preferred* and treat
> `docker-compose.yml` as supported only "for backwards compatibility of
> earlier versions"[^compose-naming]. Compose finds `compose.yaml` by default,
> which is why the production commands below need no `-f`.

[spec]: https://github.com/compose-spec/compose-spec
[^compose-naming]: [How Compose works — The Compose file](https://docs.docker.com/compose/intro/compose-application-model/)

**Production:**

```bash
cp config/ddns.example.yaml ddns.yaml   # edit: zone, record name, provider
cp .env.example .env                    # edit: API token, host token

docker compose up -d                    # HTTP endpoint
docker compose --profile watcher up -d  # or poll from inside your network
```

The server binds to `127.0.0.1:8080` by default, on the assumption that a
reverse proxy terminates TLS in front of it. Set `DDNS_HTTP_BIND=0.0.0.0:8080`
to expose it directly. The container runs as uid 1000 with a read-only root
filesystem and all capabilities dropped.

**Development:**

```bash
docker compose -f compose.dev.yaml up
```

That works with no setup at all — `ddns.dev.yaml` is committed with placeholder
credentials, enough to boot and answer `/health`. `src/`, `tests/`, `config/`,
`public/` and `bin/` are bind-mounted, so edits take effect on the next request.
Rebuild only when `composer.json` or the `Dockerfile` changes.

Run the toolchain in the same image the application runs in:

```bash
docker compose -f compose.dev.yaml run --rm tools composer check
docker compose -f compose.dev.yaml run --rm tools composer test
docker compose -f compose.dev.yaml run --rm cli hosts:list
```

The two stacks use different Compose project names (`ddns` and `ddns-dev`), so
a dev stack can never collide with a production one on the same host.

> `dev` is the last stage in the `Dockerfile`, so a plain `docker build .`
> produces the development image. Pass `--target runtime` for production.

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
> receiving a handful of requests a day, but use a real web server for anything
> else — see [Apache](#apache) below, or put nginx or Caddy in front for TLS.

### Apache

> **The one thing that will catch you out:** Apache does not pass the
> `Authorization` header to PHP-FPM. Without the workaround below, every Bearer
> token is silently discarded and every request returns `401` — with no clue as
> to why. This is the single most common cause of "my token is right but it
> won't authenticate".
>
> It does not affect `mod_php`, which performs Basic auth itself, or the
> `?token=` query parameter, which is part of the URL.

Enable the modules, then point a virtual host at `public/`:

```bash
sudo a2enmod rewrite setenvif proxy_fcgi
sudo a2enconf php8.3-fpm          # or: sudo a2enmod php8.3
```

```apache
<VirtualHost *:443>
    ServerName ddns.example.com

    # public/ is the only directory that may ever be served. src/, config/,
    # vendor/ and your ddns.yaml all live above it and stay unreachable.
    DocumentRoot /srv/ddns/public

    <Directory /srv/ddns/public>
        # AllowOverride None is faster: Apache then never looks for .htaccess.
        # The directives below replace the shipped public/.htaccess.
        AllowOverride None
        Require all granted
        Options -Indexes +FollowSymLinks

        # Route everything that is not a real file to the front controller.
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    # Hand the Authorization header to PHP. Without this, Bearer tokens never
    # arrive. Note that CGIPassAuth does *not* help here: it applies to
    # mod_cgi, not to mod_proxy_fcgi.
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/ddns.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/ddns.example.com/privkey.pem

    ErrorLog  ${APACHE_LOG_DIR}/ddns-error.log
    CustomLog ${APACHE_LOG_DIR}/ddns-access.log combined
</VirtualHost>
```

The application logs to `stderr`, which PHP-FPM forwards to its own error log
rather than to `ddns-error.log`. Set `DDNS_LOG_LEVEL` in the pool config:

```ini
; /etc/php/8.3/fpm/pool.d/ddns.conf
env[DDNS_CONFIG] = /srv/ddns/ddns.yaml
env[DDNS_LOG_LEVEL] = INFO
catch_workers_output = yes
```

Then check it works — the second command is the one that proves the
`Authorization` workaround is in place:

```bash
curl -fsS https://ddns.example.com/health
curl -fsS -H "Authorization: Bearer $TOKEN" https://ddns.example.com/v1/hosts/home
```

If the first succeeds and the second returns `401`, the header is being
stripped: check that `mod_setenvif` is enabled and the `SetEnvIf` line is
inside the right virtual host.

**Shared hosting.** If you cannot edit the virtual host, the shipped
`public/.htaccess` already contains the equivalent directives. See
[Shared hosting](#shared-hosting) for the full layout.

**Permissions.** The application never writes to disk, so the web user needs
read access only. Keep the config file out of reach of everything else, since
it names your provider credentials:

```bash
sudo chown root:www-data /srv/ddns/ddns.yaml && sudo chmod 640 /srv/ddns/ddns.yaml
```

**Do not set `server.trusted_proxies` for Apache alone.** Whether you use
`mod_php` or `mod_proxy_fcgi`, Apache passes the real client address through,
so it is already correct. Only set it if something else sits in front — a CDN
or load balancer — and then only with that thing's address ranges.

**Keeping records fresh.** The HTTP endpoint only updates when something calls
it. If nothing does, run the CLI alongside it with a systemd timer, or a unit
running `ddns watch --all`.

#### Legacy CGI

Older shared hosting often runs PHP as a CGI binary rather than through FPM.
That works, and for this application it is a perfectly reasonable choice: CGI
forks a PHP process per request, which would be hopeless for a busy site but is
irrelevant for an endpoint a router calls a few times a day.

If your host already runs PHP as CGI, **the shipped `public/.htaccess` is
enough** — point the document root at `public/` and there is nothing else to
do. The header workaround in it covers CGI as well as FPM.

To configure it yourself:

```apache
# Prefork MPM uses mod_cgi; the event and worker MPMs need mod_cgid instead.
LoadModule cgid_module modules/mod_cgid.so

ScriptAlias /cgi-bin/ /usr/lib/cgi-bin/
Action application/x-httpd-php /cgi-bin/php-cgi
AddType application/x-httpd-php .php

<Directory /usr/lib/cgi-bin>
    Require all granted
    Options +ExecCGI

    # CGIPassAuth belongs *here*, in the directory holding the CGI binary —
    # not in the document root, where it silently does nothing. Apache 2.4.13+.
    CGIPassAuth On
</Directory>

<Directory /srv/ddns/public>
    AllowOverride None
    Require all granted
    Options -Indexes +FollowSymLinks

    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</Directory>
```

> **`CGIPassAuth On` placement is the trap here.** Putting it in the document
> root's `<Directory>` — the intuitive place, and what most advice implies —
> leaves Bearer tokens being dropped and every request returning `401`. It has
> to be in the `<Directory>` containing the CGI binary. At server level Apache
> refuses to start at all, with `CGIPassAuth not allowed here`.
>
> If your Apache predates 2.4.13, or you would rather not think about it, the
> `SetEnvIf` / `RewriteRule` workaround in `public/.htaccess` achieves the same
> thing and works everywhere.

Two other things worth knowing about CGI:

- **`Action` performs an internal redirect**, so anything set with `SetEnv`
  arrives twice — as `DDNS_CONFIG` and again as `REDIRECT_DDNS_CONFIG`. The
  plain name is still set, so `SetEnv DDNS_CONFIG …` works as it does elsewhere.
- **Keep `Options +ExecCGI` scoped to `cgi-bin`.** `public/` holds no scripts
  Apache should execute and needs no `ExecCGI` of its own. PHP's
  `cgi.force_redirect`, on by default, separately refuses to run the binary
  when it is requested directly.

### Shared hosting

Shared hosting usually means three constraints at once: no shell, a document
root you cannot move, and no way to run a daemon. All three are workable — this
is a small application whose HTTP side is stateless and whose CLI side only
needs to run occasionally.

**Put the application outside the document root.** It contains your provider
credentials; nothing in it should be reachable over HTTP.

```
/home/you/
    ddns/            <- upload the whole project here, including vendor/
        ddns.yaml    <- your config, with real tokens
    public_html/     <- the document root you were given
        index.php    <- one line, below
        .htaccess    <- copied from ddns/public/.htaccess
```

`public_html/index.php` only has to hand over to the real front controller:

```php
<?php require '/home/you/ddns/public/index.php';
```

That works because the application resolves its own paths from where its source
lives, not from the entry point — so it finds `vendor/`, `config/` and
`ddns.yaml` without being told. Copy `ddns/public/.htaccess` alongside it and
Apache is fully configured; see [Apache](#apache) for what that file does.

**No shell or Composer?** Run `composer install --no-dev` on your own machine
and upload `vendor/` with everything else. The lock file pins exact versions, so
what you upload is what was tested.

**If you cannot get above the document root**, put the application in a
subdirectory of it and deny access to that subdirectory:

```
public_html/
    index.php            <?php require __DIR__ . '/ddns-app/public/index.php';
    .htaccess            copied from ddns-app/public/.htaccess
    ddns-app/
        .htaccess        Require all denied
        ddns.yaml        your config
```

> The deny rule is not optional here. Without it `ddns-app/ddns.yaml` is an
> ordinary static file: requesting it returns `200` and your provider token in
> the response body. Confirm it after deploying — the first command must return
> `403`, the second `200`:
>
> ```bash
> curl -sI https://you.example.com/ddns-app/ddns.yaml | head -1
> curl -sI https://you.example.com/health | head -1
> ```

**Choosing the PHP version.** Most control panels have a PHP selector; this
needs 8.2 or newer with `curl`, `mbstring` and `xml`. The CLI binary is often
versioned, so `php` may be 7.4 while `php83` is what you want — check with
`php83 -v` and use that name in the cron line below.

**Keeping records fresh.** There is no daemon, so `watch` is not the tool here;
use the host's cron scheduler to run one-shot updates instead:

```cron
*/10 * * * * DDNS_CONFIG=/home/you/ddns/ddns.yaml /usr/local/bin/php83 /home/you/ddns/bin/ddns update --all >/dev/null 2>&1
```

Both an absolute `bin/ddns` path and `DDNS_CONFIG` work from any working
directory, which is what cron gives you. Records already pointing at the right
address cost one read and no writes, so a ten-minute interval is not wasteful.

**Check it afterwards:**

```bash
curl -fsS https://you.example.com/health
curl -fsS -H "Authorization: Bearer $TOKEN" https://you.example.com/v1/hosts/home
```

If the first works and the second returns `401`, Apache is stripping the
`Authorization` header — see the [Apache](#apache) section, though the shipped
`.htaccess` normally handles it.

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
| `zone_id` | no | Cloudflare and Route53: skip the zone lookup for a scoped token |
| `base_uri` | no | Override the API endpoint, mainly for testing |

**Route53 specifics.** `token` is not required: with no credentials in the file
the AWS default chain runs, picking them up from the environment,
`~/.aws/credentials`, an EC2 instance profile, an ECS task role or IRSA — the
recommended way to run on AWS.

| Key | Meaning |
| --- | --- |
| `key` / `access_key_id` | Static access key. Falls back to the chain if absent |
| `secret` / `secret_access_key` | Static secret. Both must be set, or neither |
| `session_token` | For temporary STS credentials. `token` also works |
| `profile` | Named profile from `~/.aws/credentials`. Ignored if `key` is set |
| `region` | Defaults to `us-east-1`; only matters for GovCloud and China |
| `zone_id` | Skip the zone lookup, for an IAM policy scoped to one zone |
| `private_zone` | Manage the private hosted zone instead of the public one |

The IAM policy needs `route53:ListHostedZonesByName`,
`route53:ListResourceRecordSets` and `route53:ChangeResourceRecordSets`.
`ListHostedZonesByName` can be omitted if `zone_id` is set.

Route53 records are written with `UPSERT`, so create and update are the same
call. The driver refuses to touch alias records and records that use a routing
policy, rather than silently replacing a CloudFront or load balancer target.

**Azure DNS specifics.** Two drivers share one implementation: `azuredns` for
public zones and `azureprivatedns` for private ones. They are separate Azure
resource types with separate API versions, so they are separate drivers rather
than a flag; configuration is otherwise identical. Microsoft archived the Azure
SDK for PHP in 2023, so both call the management REST API directly and add no
dependencies.

`token` is not required, but `subscription_id` and `resource_group` are —
`config:validate` reports either if missing.

| Key | Required | Meaning |
| --- | --- | --- |
| `subscription_id` | yes | The Azure subscription holding the zone |
| `resource_group` | yes | The resource group holding the zone |
| `client_secret` | no | Present: service principal. Absent: managed identity |
| `tenant_id` | for SP | Directory (tenant) ID |
| `client_id` | for SP | Application (client) ID. Also selects a *user-assigned* managed identity |
| `authority` | no | Sovereign clouds, e.g. `https://login.microsoftonline.us` |
| `endpoint` | no | Sovereign clouds, e.g. `https://management.usgovcloudapi.net` |

Two ways to authenticate:

```yaml
# Service principal — anywhere.
azure:
  driver: azuredns
  subscription_id: ${AZURE_SUBSCRIPTION_ID}
  resource_group: my-rg
  tenant_id: ${AZURE_TENANT_ID}
  client_id: ${AZURE_CLIENT_ID}
  client_secret: ${AZURE_CLIENT_SECRET}

# Managed identity — on an Azure VM, App Service or container.
# No secret in the file at all.
azure:
  driver: azuredns
  subscription_id: ${AZURE_SUBSCRIPTION_ID}
  resource_group: my-rg
```

The identity needs the **DNS Zone Contributor** role on the zone or its
resource group. Missing RBAC is the most common cause of a config that looks
right but returns 403.

Access tokens are cached until shortly before they expire, so a `watch` loop
does not re-authenticate on every poll. Records are written with `PUT`, which is
create-or-update. Alias records (`targetResource`) are refused rather than
replaced, so a Traffic Manager or CDN target cannot be silently detached.

**Private zones** (`azureprivatedns`) additionally:

- Hold internal addresses, so you will almost certainly need
  `server.allow_private_ips: true`, which is off by default.
- May contain records Azure **auto-registers** for VMs on a linked virtual
  network. Those cannot be changed by anyone — Azure rejects the write — so the
  driver refuses them with an explanation rather than letting the update fail
  with a platform error. Use a different hostname, or disable auto-registration
  on the VNet link.

```yaml
server:
  # Private zones resolve to internal addresses.
  allow_private_ips: true

providers:
  azure-internal:
    driver: azureprivatedns
    subscription_id: ${AZURE_SUBSCRIPTION_ID}
    resource_group: my-resource-group
    # Same two auth options as the public driver.
```

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
| `501` | The driver's optional dependency is missing from this build |
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

### Dependencies

Four things guard the dependency chain, in rough order of how much they matter:

- **`composer.lock` is committed and authoritative.** It pins all 88 packages —
  direct and transitive — to exact versions with dist hashes. Every install and
  every CI run uses `composer install`, so what gets built is byte-for-byte what
  was reviewed.
- **Plugins are refused.** `allow-plugins: false` means no dependency can run
  code during installation, which is the usual way a compromised package does
  damage. No current dependency ships one; a future one would need a deliberate
  change here to start.
- **Direct dependencies are pinned to exact versions.** `composer update` cannot
  move them without an edit to `composer.json`. Note that transitive packages
  are only held by the lock file, not by these constraints.
- **`composer audit --locked` runs in CI**, against what is actually installed.
  This is the counterweight to pinning: fixed versions do not update themselves,
  so something has to say when one becomes vulnerable. Abandoned packages are
  reported too, since an unmaintained package is how ownership quietly changes
  hands.

Updating is therefore deliberate:

```bash
composer audit                       # what, if anything, needs attention
composer require vendor/pkg:1.2.4    # edit the pin and the lock together
composer check                       # audit, formatting, static analysis, tests
```

`composer validate` runs in CI but **not** `--strict`, which rejects exact
constraints on principle. Plain `validate` still fails when `composer.json` and
`composer.lock` disagree, which is the check worth having: it stops a dependency
being changed without the lock being regenerated.

## Development

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan, level max
composer fmt           # PHP-CS-Fixer
composer check         # all three
```

Or without installing PHP at all:

```bash
docker compose -f compose.dev.yaml run --rm tools composer check
```

### Layout

```
bin/ddns            CLI entrypoint
public/index.php    HTTP entrypoint
compose.yaml        production stack
compose.dev.yaml    development stack
config/             container definitions, routes, example config
src/
  Domain/           framework-free core: records, provider contract, DdnsUpdater
  Config/           YAML loading, env interpolation, validation
  Provider/         one directory per driver, plus the shared REST transport
                    (Azure/Auth holds its OAuth2 token providers)
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
   token; implement `ProviderFactory` directly if it authenticates some other
   way and return `false` from `requiresToken()`, as Route53 and Azure do. Name
   anything else the driver cannot work without in `requiredOptions()`, and
   `config:validate` will check it.
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
