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
cp config/ddns.example.yaml config/ddns.yaml   # edit: zone, record, provider
cp .env.example .env                          # edit: API token, host token

docker compose up -d                    # HTTP endpoint
docker compose --profile watcher up -d  # or poll from inside your network
```

The server binds to `127.0.0.1:8080` by default, on the assumption that a
reverse proxy terminates TLS in front of it. Set `DDNS_HTTP_BIND=0.0.0.0:8080`
to expose it directly. The container runs as uid 1000 with a read-only root
filesystem and all capabilities dropped.

**Development:** `docker compose -f compose.dev.yaml up`, which needs no setup
at all — see [Running it locally](#running-it-locally).

The two stacks use different Compose project names (`ddns` and `ddns-dev`), so
a dev stack can never collide with a production one on the same host.

> `dev` is the last stage in the `Dockerfile`, so a plain `docker build .`
> produces the development image. Pass `--target runtime` for production.

### Bare metal

Requires PHP 8.2+ with `curl`, `mbstring` and `xml`. `pcntl` is optional and
enables graceful shutdown of `watch`.

```bash
composer install --no-dev

./bin/ddns config:init          # answer a few questions
./bin/ddns update --all         # one-shot
./bin/ddns watch --all          # keep running
```

`config:init` asks which provider hosts your zone, what it needs, and which
name to keep in sync. See [the wizard](#the-wizard) for what it writes and
where. To configure by hand instead:

```bash
cp config/ddns.example.yaml config/ddns.yaml
cp .env.example .env
$EDITOR config/ddns.yaml

./bin/ddns config:validate      # check before going near a provider
```

To serve HTTP, point any web server at `public/`:

```bash
php -S 0.0.0.0:8080 -t public public/index.php
```

Then open <http://localhost:8080/> — it redirects to the
[browsable API documentation](#openapi).

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
    # vendor/ and your config/ all live above it and stay unreachable.
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
env[DDNS_CONFIG] = /srv/ddns/config/ddns.yaml
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
sudo chown root:www-data /srv/ddns/config/ddns.yaml && sudo chmod 640 /srv/ddns/config/ddns.yaml
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
    ddns/                 <- upload the whole project here, including vendor/
        config/ddns.yaml  <- your config, with real tokens
    public_html/     <- the document root you were given
        index.php    <- one line, below
        .htaccess    <- copied from ddns/public/.htaccess
```

`public_html/index.php` only has to hand over to the real front controller:

```php
<?php require '/home/you/ddns/public/index.php';
```

That works because the application resolves its own paths from where its source
lives, not from the entry point — so it finds `vendor/` and `config/ddns.yaml`
without being told. Copy `ddns/public/.htaccess` alongside it and
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
        .htaccess            Require all denied
        config/ddns.yaml     your config
```

> The deny rule is not optional here. Without it `ddns-app/config/ddns.yaml` is an
> ordinary static file: requesting it returns `200` and your provider token in
> the response body. Confirm it after deploying — the first command must return
> `403`, the second `200`:
>
> ```bash
> curl -sI https://you.example.com/ddns-app/config/ddns.yaml | head -1
> curl -sI https://you.example.com/health | head -1
> ```

**Choosing the PHP version.** Most control panels have a PHP selector; this
needs 8.2 or newer with `curl`, `mbstring` and `xml`. The CLI binary is often
versioned, so `php` may be 7.4 while `php83` is what you want — check with
`php83 -v` and use that name in the cron line below.

**Keeping records fresh.** There is no daemon, so `watch` is not the tool here;
use the host's cron scheduler to run one-shot updates instead:

```cron
*/10 * * * * DDNS_CONFIG=/home/you/ddns/config/ddns.yaml /usr/local/bin/php83 /home/you/ddns/bin/ddns update --all >/dev/null 2>&1
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
`$DDNS_CONFIG` — which may be set in `.env` — then `./config/ddns.yaml`,
`./config/ddns.yml`, `./ddns.yaml`, `./ddns.yml`; `--config` overrides all of
them. `$DDNS_CONFIG_FALLBACK` is consulted only when none of those exist, and
is meant for development; see [VS Code](#vs-code).

`config/` is where `config:init` writes and where the container expects the
file mounted, so the same path means the same thing on the host and in the
image. The project root is still searched, for installations that predate
that; if a file is found there while one also exists under `config/`, the
`config/` one wins and `config:init` says so rather than leaving you with a
file nothing reads.

### The wizard

```console
$ ddns config:init [--config=PATH] [--env=PATH] [--force]
```

Asks which provider hosts the zone, whatever that provider needs, and which
name to keep in sync. The questions come from the driver itself, so each one is
asked for exactly what it uses — a subscription and resource group for Azure,
nothing at all for Route53 when the AWS credential chain will supply it.

Two things it will not do:

- **Write a credential into the configuration file.** Each secret becomes a
  `${VAR}` placeholder and the value is appended to `.env`, so the
  configuration stays safe to commit. Both files are written `0600`.
- **Produce a file that does not load.** The answers are validated before
  anything is written, so `config:validate` cannot then reject the result.

The token an HTTP client will present is generated rather than asked for —
it is a secret nobody has a reason to choose. Re-running is safe: it refuses to
replace an existing configuration without `--force`, and asks before changing
a value already in `.env`.

`--env` writes the secrets somewhere other than the project root. Only the
project's own `.env` is loaded at runtime, so use it for review rather than for
a live deployment.

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
| `GET` | `/v1/hosts/{host}` | yes | The host's own config, token redacted |
| `GET` `POST` | `/v1/hosts/{host}/update` | yes | Update the record |
| `GET` | `/` | none | Temporary redirect to `/api` |
| `GET` | `/api` | none | [Browsable documentation](#openapi) |
| `GET` | `/health` | none | Liveness probe |
| `GET` | `/openapi.json` | none | [OpenAPI description](#openapi) |
| `GET` | `/openapi.yaml` | none | The same, as YAML |

The two `/v1` routes are the API; the rest deliver the documentation or report
on the server, and are the ones left out of the OpenAPI description.

### OpenAPI

Open **`/api`** in a browser. The server renders its own API description with
Swagger UI, and the endpoints can be called from the page — press *Authorize*,
paste a host token, and *Execute*. The root redirects there, temporarily, so
there is something to find at the bare hostname.

The description itself is served as a document too:

```console
$ curl https://ddns.example.com/openapi.json
$ curl https://ddns.example.com/openapi.yaml
```

Both are the same document in two formats. `servers` reports the URL it was
fetched from, so a tool can call the API straight away:

```bash
# Browse it
npx @redocly/cli preview-docs https://ddns.example.com/openapi.yaml

# Generate a client
npx @openapitools/openapi-generator-cli generate \
    -i https://ddns.example.com/openapi.json -g python -o ./ddns-client
```

It describes the `/v1` endpoints and nothing else. The rest of what this server
answers — the root redirect, `/api` itself, the two spec formats and `/health` —
is still served, and still documented [above](#endpoints); it is simply left out
of the description, because the machinery that delivers a description is noise
to whoever is reading it to write a client.

The document is generated from the application rather than maintained beside
it: record types and outcomes are read from the same enums the server uses, and
the test suite checks the documented paths and methods against the routes Slim
actually registers, in both directions, and the documented response shapes
against real responses. A documented endpoint that stopped existing, or a field
that was renamed, fails the build. The exclusions are an explicit list, so
adding one is a decision somebody makes rather than one that happens quietly.

#### Where Swagger UI comes from

The page loads Swagger UI from a CDN, pinned to an exact version and carrying a
[subresource integrity](https://developer.mozilla.org/docs/Web/Security/Subresource_Integrity)
hash, so a browser will refuse to run anything but the bytes this project
checked — the same reasoning as the pinned `composer.lock`.

To keep every request on your own network, or to serve the page on a host with
no route to the internet, drop the assets into `public/vendor/swagger-ui/` and
they are used instead. No configuration, and the content security policy tightens
to `'self'` automatically:

```bash
mkdir -p public/vendor/swagger-ui && cd $_
V=5.32.12
curl -O "https://cdn.jsdelivr.net/npm/swagger-ui-dist@$V/swagger-ui.css"
curl -O "https://cdn.jsdelivr.net/npm/swagger-ui-dist@$V/swagger-ui-bundle.js"
```

Both files must be present; a half-finished copy falls back to the CDN rather
than serving a page with no styles. The directory is gitignored, and the
application serves the files itself where the web server does not — PHP's
built-in server hands every request to the router script, so the Docker image
and the quick start work the same way as Apache or nginx.

#### Withholding the documentation

Everything above is served without authentication. It describes shapes and
status codes — all of it on this page — and holds no configuration, no host
names and no secrets. To withhold it anyway, deny the paths at the web server:

```apache
<LocationMatch "^/(api|openapi\.(json|yaml))$">
    Require all denied
</LocationMatch>
```

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

A client may send more than one — Swagger UI applies every scheme you have
authorised, and remembers them between reloads. Each is checked, so a stale
value in one transport cannot mask a correct one in another. The order decides
which is used when several are valid:

1. the `token` query parameter;
2. the `Authorization` header;
3. HTTP Basic.

The query string comes first because it is the only transport a caller has to
add deliberately.

Surrounding whitespace is ignored, so a token pasted with a stray space behaves
the same in every transport.

**A token belongs to one host.** The commonest cause of a `401` is not the
token at all — it is a host name in the URL that is not the one the token
belongs to, or is not configured. Comparison is constant-time and an unknown
host is answered identically to a wrong token, so the API cannot be used to
enumerate configured host names; the response therefore cannot tell you which
of the two was wrong. The server log can:

```
Rejected an unauthenticated request. {"host":"myhost","reason":"no such host is configured", ...}
```

A rejected request answers `401` with `WWW-Authenticate: Basic`, which is what
makes a router's "enter a URL, a username and a password" screen work. It is
withheld from clients that ask for `application/json`: a browser shown that
header opens its own credential dialog over whatever page made the request,
which would otherwise happen to anyone driving the API from
[`/api`](#openapi).

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

### Endpoints

#### `GET /health`

Unauthenticated liveness probe. Reports counts only — never host names, which
would be disclosure on an endpoint with no authentication.

```console
$ curl -s https://ddns.example.com/health
{
    "status": "ok",
    "hosts_configured": 1,
    "providers_configured": 1
}
```

Returns `500` when the configuration is missing or invalid, which is what makes
it useful as a container health check.

#### `GET /v1/hosts/{host}`

The host the presented token authenticates for, with the token redacted, plus
the address the server attributes to the caller. Reading `client_ip` back is the
quickest way to confirm a reverse-proxy setup before trusting an update.

```console
$ curl -s -H "Authorization: Bearer $TOKEN" https://ddns.example.com/v1/hosts/home
{
    "host": {
        "name": "home",
        "fqdn": "home.example.com",
        "zone": "example.com",
        "record": "home",
        "provider": "do-personal",
        "types": ["A", "AAAA"],
        "ttl": 60,
        "token": "****4567"
    },
    "client_ip": "203.0.113.7"
}
```

There is deliberately no endpoint that lists every host: a token grants access
to exactly one.

#### `GET` `POST` `/v1/hosts/{host}/update`

Points the host at an address. Parameters may be supplied in the query string
or, for `POST`, in a JSON or form-encoded body — both sources are read.

| Parameter | Type | Meaning |
| --- | --- | --- |
| `ip` | string | The address to publish. `myip`, `ipv4`, `ipv6` are aliases |
| `dry_run` | boolean | Report what would change without writing |

```console
$ curl -s -H "Authorization: Bearer $TOKEN" \
       'https://ddns.example.com/v1/hosts/home/update?ip=203.0.113.7'
{
    "host": "home",
    "fqdn": "home.example.com",
    "status": "updated",
    "changed": true,
    "records": [
        {
            "type": "A",
            "status": "updated",
            "ip": "203.0.113.7",
            "previous": "203.0.113.1",
            "reason": null,
            "dry_run": false
        }
    ],
    "client_ip": "203.0.113.7"
}
```

`status` is the worst outcome across the records — `failed` if any failed,
otherwise a change if anything changed. Branch on `changed` if all you need to
know is whether the address moved. Each record reports its own outcome:

| Outcome | Meaning |
| --- | --- |
| `created` | The record did not exist and was added |
| `updated` | It pointed elsewhere and was corrected |
| `unchanged` | It was already correct; nothing was sent to the provider |
| `skipped` | No address of that family was available; `reason` says so |
| `failed` | The provider refused; `reason` says why |

A host listing `AAAA` on an IPv4-only link reports that record as `skipped`
rather than failing the request. `unchanged` is what makes polling on a short
interval safe.

Errors share one envelope, with a stable `code` to branch on and a `message`
for humans:

```json
{
    "error": {
        "code": "invalid_ip",
        "message": "\"nonsense\" is not a valid IP address."
    }
}
```

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
$ ddns config:init [--config=PATH] [--env=PATH] [--force]
$ ddns update [<host>...] [--all] [--ip=IP]... [--dry-run] [--json]
$ ddns watch  [<host>...] [--all] [--interval=300] [--force-after=12] [--once] [--json]
$ ddns hosts:list [--json]
$ ddns hosts:add <name> [--provider=P] [--zone=Z] [--record=R] [--type=T]... [--ttl=N] [--token=T] [--force] [--json]
$ ddns hosts:update <name> [--provider=P] [--zone=Z] [--record=R] [--type=T]... [--ttl=N] [--token=T] [--rotate-token] [--force] [--json]
$ ddns hosts:remove <name> [--force] [--json]
$ ddns providers:list [--json]

$ ddns config:show [--raw] [--json]
$ ddns config:get <key> [--json]
$ ddns config:set <key> <value> [--force]
$ ddns config:path [--json]
$ ddns config:validate [<file>] [--json]
```

`config:init` is [the setup wizard](#the-wizard). Everything else needs a
configuration file to exist, and says so — naming the wizard rather than
failing with a stack trace:

```console
$ ddns hosts:list
 [ERROR] No configuration file found.

         Create one with:
           ddns config:init
...
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

### Managing hosts

Adding a host by hand means editing YAML and inventing a token. These do it for
you:

```console
$ ddns hosts:add nas --provider do-personal --zone example.com
 [OK] Added "nas" for nas.example.com.

The token for this host, which is not recoverable from the configuration:
  6f1c2a…

$ ddns hosts:update nas --ttl 300 --type A --type AAAA
$ ddns hosts:remove nas
```

Anything you leave out is asked for, so `ddns hosts:add nas` on its own works
too; pass every option to script it. `--record` defaults to the host name, and
`@` means the zone apex.

**The token is generated, not chosen.** It goes to `.env` behind a `${VAR}`
placeholder, so the configuration file holds no secrets and stays committable.
It is printed once, because that is the only moment it exists in plain text —
the file only ever holds a reference to it.

`hosts:update` changes only the fields you name and leaves everything else
exactly as written. `--rotate-token` issues a new secret behind the same
placeholder, so the configuration file does not change and every client using
the old token stops working immediately.

`hosts:remove` **does not touch the DNS record** — this server simply stops
keeping it up to date, which is deliberately not the same as deleting it at the
provider. The token in `.env` is left alone too, since that file is
hand-edited and a variable may be shared; the one that is now unused is named
so you can remove it yourself.

All three validate the result before writing, so a mistake leaves the file as
it was, and all three ask before discarding comments. Removing the last host is
refused, since at least one is required.

### Managing the configuration

Four commands for inspecting and changing the file without opening an editor.

```console
$ ddns config:path                       # which file is in use
/srv/ddns/config/ddns.yaml

$ ddns config:get hosts.home.ttl         # one value, as written
60

$ ddns config:set hosts.home.ttl 600     # change it
 [OK] hosts.home.ttl: 60 -> 600

$ ddns config:show                       # everything, secrets masked
```

`config:show` has two views. By default it shows the **effective**
configuration — what the application concluded, with defaults filled in, which
answers "why is it behaving like this?". With `--raw` it shows the file as
written, which is what `config:set` edits.

Values are read and written exactly as they appear in the file, so a `${VAR}`
placeholder stays a placeholder rather than being resolved to the secret behind
it. Secrets written literally are masked in every view, so the output is safe to
paste into a bug report.

`config:set` parses the value as YAML, so types come out right rather than
everything becoming a string:

```bash
ddns config:set server.default_ttl 600          # number
ddns config:set server.allow_private_ips true   # boolean
ddns config:set hosts.home.types '[A, AAAA]'    # list
ddns config:set hosts.home.name '@'             # string
```

A value that is not valid YAML is taken literally — `@` opens the zone apex
rather than a parse error — unless it starts a list or a mapping, where a
malformed `[A, AAAA` is reported instead of quietly becoming a string.

Two things it will not do:

- **Write a file that does not load.** The result is validated first, so a typo
  cannot leave a configuration the server refuses to start with. On failure
  nothing is written.
- **Discard your comments silently.** Rewriting the file loses them, so it asks
  first — `--force`, or `--no-interaction`, to skip. A file written by
  `config:init` needs no confirmation, since its header is re-emitted.

`config:get` exits `1` when the key is not set, so a script can tell an absent
setting from an empty one, and lists what *is* available at that level.

`config:path` is the exception to the rule above: it answers even when the file
is missing, because that is when you need it.

```bash
$EDITOR "$(ddns config:path)"
```

### JSON output

Every command takes `--json`, for scripting and monitoring.

In JSON mode **stdout carries nothing but the payload**. Progress notes,
warnings, errors and log lines all go to stderr, so the output can be piped
straight into a parser without filtering:

```console
$ ddns update --all --json | jq -r '.hosts[].fqdn'
```

Each entry under `hosts` is the same structure [the HTTP API](#http-api)
returns for that host, so a script written against one front-end works with the
other. (The HTTP response additionally carries `client_ip`, which has no CLI
equivalent.)

```json
{
  "changed": true,
  "failed": false,
  "dry_run": false,
  "hosts": [
    {
      "host": "home",
      "fqdn": "home.example.com",
      "status": "updated",
      "changed": true,
      "records": [
        {
          "type": "A",
          "status": "updated",
          "ip": "203.0.113.99",
          "previous": "203.0.113.7",
          "reason": null,
          "dry_run": false
        }
      ]
    }
  ]
}
```

The `changed` and `failed` flags summarise the run, so a caller can branch
without walking the list. A failure is still valid JSON — the `reason` is where
the explanation lives, and the exit code carries the same verdict as `failed`:

```bash
#!/bin/bash
result=$(ddns update --all --json) || echo "update failed" >&2

if [ "$(jq -r .changed <<<"$result")" = "true" ]; then
    jq -r '.hosts[] | "\(.fqdn) -> \(.records[].ip)"' <<<"$result" | mail -s 'IP changed' me@example.com
fi
```

Tokens are redacted in `hosts:list --json` and `config:validate --json`, so the
output is safe to log.

`config:validate --json` returns problems as a list rather than one block of
prose, which is what makes it usable in a deployment check:

```console
$ ddns config:validate --json | jq -r 'select(.valid | not) | .problems[]'
"providers.p1.driver" is "nosuchdriver", which is not a known driver. ...
```

#### Streaming events from `watch`

`watch` runs indefinitely, so it emits [NDJSON](https://ndjson.org) instead: one
self-contained object per line, flushed as it happens. That suits a reader
consuming the stream a line at a time.

```console
$ ddns watch --all --json
{"event":"started","hosts":["home"],"types":["A"],"interval":300,"once":false}
{"event":"updated","host":"home","fqdn":"home.example.com","type":"A","status":"updated","ip":"203.0.113.99","previous":"203.0.113.7","reason":null,"dry_run":false}
```

| Event | When |
| --- | --- |
| `started` | Once at startup, listing what is being watched. |
| `created` | A record did not exist and was added. |
| `updated` | A record pointed elsewhere and was corrected. |
| `failed` | The provider rejected the write; `reason` explains why. |
| `unchanged` | A poll found nothing to do. Only with `-v`, since it is otherwise constant noise. |
| `stopped` | Shutdown, with a `reason`. |

Piping it into a log shipper or an alerting rule needs no parsing beyond
reading one line at a time:

```bash
ddns watch --all --json | while read -r line; do
    [ "$(jq -r .event <<<"$line")" = "failed" ] && alert "$(jq -r .reason <<<"$line")"
done
```

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

### Running it locally

```bash
docker compose -f compose.dev.yaml up
```

Then open <http://localhost:8080/>, which redirects to the
[browsable API](#openapi). That is the whole setup — there is nothing to copy
or edit first, because `ddns.dev.yaml` is committed with placeholder
credentials, and the host token in it is committed too:

```bash
curl -H "Authorization: Bearer dev-token-0123456789abcdef" \
     http://localhost:8080/v1/hosts/home
```

Editing anything under `src/`, `tests/`, `config/`, `public/` or `bin/` takes
effect on the next request: those directories are bind-mounted, and the dev
image turns opcache timestamp validation back on. Rebuild only when
`composer.json` or the `Dockerfile` changes:

```bash
docker compose -f compose.dev.yaml build
```

Other things you will want:

```bash
# Follow the logs
docker compose -f compose.dev.yaml logs -f server

# Run the CLI in the same image
docker compose -f compose.dev.yaml run --rm cli hosts:list
docker compose -f compose.dev.yaml run --rm cli config:validate

# Run the whole toolchain there too — no PHP needed on the host
docker compose -f compose.dev.yaml run --rm tools composer check
docker compose -f compose.dev.yaml run --rm tools composer test

# Watch the polling loop work, on a 30-second interval with verbose output.
# It sits behind a profile, so a plain `up` does not start it.
docker compose -f compose.dev.yaml --profile watcher up watcher

# Somewhere else if 8080 is taken
DDNS_DEV_PORT=9090 docker compose -f compose.dev.yaml up

# Stop and clean up
docker compose -f compose.dev.yaml down
```

#### What works without any credentials

Booting, `/health`, `/api`, `config:validate`, `hosts:list` and the entire test
suite — no test touches the network.

An **update** does not. Even `--dry-run` reads the current record from the
provider before deciding what would change, so with the placeholder token it
comes back with a clear rejection:

```console
$ docker compose -f compose.dev.yaml run --rm cli update --all --dry-run
  Host   FQDN               Type   Status   Address   Detail
  home   home.example.com   A      failed   -         Provider "digitalocean" rejected the configured
                                                      API credentials. Unable to authenticate you
```

To make updates real, put a token in `.env` — `ddns.dev.yaml` reads
`${DO_TOKEN:-dev-placeholder-token}`, so it is picked up with no further
change:

```bash
echo 'DO_TOKEN=dop_v1_...' >> .env
docker compose -f compose.dev.yaml up
```

Use a throwaway zone. `.env` is gitignored; `ddns.dev.yaml` is not, so never
put a real credential in it.

### Running it without Docker

Requires PHP 8.2+ with `curl`, `mbstring` and `xml`.

```bash
composer install
DDNS_CONFIG=ddns.dev.yaml php -S 127.0.0.1:8080 -t public public/index.php
```

The same dev configuration, so the same URLs and the same committed token work.
For the CLI, `DDNS_CONFIG=ddns.dev.yaml ./bin/ddns hosts:list`.

### VS Code

`.vscode/launch.json` is committed, so **Run and Debug** is populated the
moment the repository is opened, and it needs no setup: the profiles fall back
to the committed `ddns.dev.yaml` when you have no configuration of your own.
As soon as `config:init` has written one, that is used instead — so what you
debug is what you configured, and a fresh clone still runs on the first press.

The fallback is `DDNS_CONFIG_FALLBACK`, which nothing sets in production;
without it, a server with no configuration refuses to start rather than
quietly answering with a sample whose host token is published in this
repository.

| Profile | What it does |
| --- | --- |
| **Serve (php -S)** | Starts the built-in server on `127.0.0.1:8080` and opens `/api` |
| **Serve (php -S, sample config)** | The same, against the committed `ddns.dev.yaml` — for a clone with no configuration yet |
| **Serve (php -S, random port)** | The same, on a port the system picks, for when 8080 is taken |
| **CLI: …** | `hosts:list`, `config:validate`, `update --all --dry-run`, `watch --all`, the `config:init` wizard |
| **PHPUnit: …** | The whole suite, the file you have open, or a `--filter` you are prompted for |
| **Listen for Xdebug** | Attaches to a request handled elsewhere — the dev container, Apache, PHP-FPM |

Breakpoints need the [PHP Debug][phpdebug] extension and Xdebug in whichever
PHP you are using; `.vscode/extensions.json` recommends it, so VS Code offers
to install it. Without Xdebug the profiles still work under **Run Without
Debugging** (`Ctrl+F5`) — the `-dxdebug.*` flags are ignored when the extension
is not loaded.

`Listen for Xdebug` maps `/app` back to the workspace, which is where the dev
image puts the application. To use it against the container, Xdebug there needs
to point back at the host:

```ini
xdebug.mode = debug
xdebug.client_host = host.docker.internal
```

Every profile sets `DDNS_LOG_LEVEL=DEBUG`, and the application logs which
configuration file it loaded as it starts:

```
ddns.DEBUG:  Loaded configuration. {"path":"/…/config/ddns.yaml","hosts":1}
ddns.NOTICE: Loaded a fallback configuration; run `ddns config:init` to create your own.
```

That is the first line to check whenever a token works in one place and not
another — it usually means two things are reading two different files. The
fallback notice is a `NOTICE` rather than a `DEBUG`, so it shows without
turning the logs up.

Personal settings stay out of the repository: `.vscode/` is gitignored apart
from `launch.json` and `extensions.json`.

[phpdebug]: https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug

### The toolchain

```bash
composer test          # PHPUnit
composer analyse       # PHPStan, level max, no baseline
composer fmt           # PHP-CS-Fixer, writes
composer fmt:check     # PHP-CS-Fixer, reports only
composer check         # all of the above, in the order CI runs them
```

While working on one thing, run one thing:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --filter DocsTest
```

No test touches the network, so the suite runs the same offline and takes about
a second. CI runs the same checks individually, on PHP 8.2, 8.3 and 8.4 — the
lower bound is the one that catches portability bugs, so a failure there is
worth reproducing on 8.2 rather than only on whatever you have installed.

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
