# AGENTS.md

A self-hosted dynamic DNS server in PHP. It wraps six DNS providers behind one
interface so a router, a NAS or a cron job can keep a record pointing at a
changing address, over HTTP or from the command line.

`README.md` documents how to *use* this. This file covers how to *work on* it:
the invariants, the checks, and the handful of things that have caught people
out. Read it before changing code; most of it is not obvious from any single
file.

## Setup

```bash
composer install
```

PHP 8.2 or newer, with `json` and `mbstring`. `pcntl` is optional — without it
`ddns watch` warns and keeps running, but cannot shut down cleanly on a signal.

There is no build step and no database. `bin/ddns` is the CLI entry point,
`public/index.php` the HTTP one.

## Commands

```bash
composer test          # phpunit
composer analyse       # phpstan, level max
composer fmt           # php-cs-fixer, writes
composer fmt:check     # php-cs-fixer, reports
composer audit         # known advisories in the installed dependencies
composer check         # all of the above, in the order CI runs them
```

`composer check` is the gate. Run it before every commit — CI runs the same
checks on PHP 8.2, 8.3 and 8.4, so a pass here is a good predictor of a pass
there.

Narrower runs while iterating:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --filter HostsManagementCommandTest
vendor/bin/phpunit tests/Unit/Domain/Update/DdnsUpdaterTest.php
```

## The one architectural rule

**One use case, two front-ends.** `Ddns\Domain\Update\DdnsUpdater` owns
everything that decides what happens to a DNS record. The Slim actions in
`src/Http/Action` and the Symfony commands in `src/Console/Command` are thin
adapters: they parse input, call the updater, and render the result.

Two consequences, both enforced:

- **`src/Domain` imports no framework.** No Slim, no Symfony Console, no Guzzle.
  If the domain needs something from the outside it takes an interface.
- **Behaviour goes in the domain, not in an adapter.** A rule implemented in an
  action is a rule the CLI does not have, and vice versa. If you find yourself
  writing an `if` in an adapter that changes what gets written to DNS, it
  belongs in the updater.

**Providers are deliberately thin.** `Ddns\Domain\Provider\DnsProvider` is three
methods — `findRecord`, `createRecord`, `updateRecord` — plus `driver()`. Upsert
logic, no-change detection, TTL defaulting and dual-stack handling all live in
`DdnsUpdater`, once, rather than six times. When adding a provider, resist the
urge to be clever in it; if something seems to need provider-specific logic
above that level, that is a design discussion, not a quiet exception.

The `unchanged` short-circuit — comparing the current record before writing — is
what makes a short `watch` interval safe against rate limits. Do not remove it
as an optimisation; it *is* the optimisation.

## Layout

```
bin/ddns              CLI entry point
public/index.php      HTTP entry point
config/container.php  PHP-DI definitions — the wiring for both front-ends
src/Bootstrap.php     Config discovery, .env loading, container construction
src/Config/           Loading and validating YAML; ${VAR} resolution
src/Console/          Symfony Console adapters
src/Domain/           The use case. Framework-free.
src/Http/             Slim actions, middleware, OpenAPI, error rendering
src/Ip/               Client address resolution
src/Provider/         The six drivers and their factories
src/Support/          Small shared helpers
tests/Unit/           Fast, isolated
tests/Integration/    Whole front-ends, still offline
tests/Support/        Fakes and harnesses
```

## Code style

PSR-12 plus strict types everywhere, enforced by php-cs-fixer — run
`composer fmt` rather than formatting by hand. Single quotes, no unused imports,
strict comparisons (`===`) and strict parameters.

PHPStan runs at **level max with no baseline**, across `src`, `tests`, `config`
and `public`. Keep it that way: a baseline turns a hard failure into a
suggestion, and this codebase is small enough to fix properly. Adding
`@phpstan-ignore` needs a comment saying why the analyser is wrong.

Comments explain **why**, not what. The code already says what it does. Most
comments in this repo exist because something surprising is going on — a
provider quirk, a spec requirement, a bug that came back. Match that: if a line
would be safe to delete, delete it instead of writing it.

## Testing

**The suite never touches the network.** Every provider is exercised through
fakes in `tests/Support` — `MockHttpClient`, `StaticHttpClient`,
`FakeDnsProvider`, `FakeRoute53` — driven by recorded response shapes. A test
that needs the internet is a test that fails in CI, on a plane, and in a
firewalled build. You can verify the guarantee holds:

```bash
HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 composer test
```

Useful harnesses:

- `tests/Integration/HttpTestCase.php` — builds the real Slim app against a fake
  provider and returns PSR-7 responses.
- `tests/Integration/ConsoleTestCase.php` — runs real commands. Its `SplitOutput`
  captures **stdout and stderr separately**, which matters: the `--json`
  contract is that stdout carries nothing but the payload, and a merged buffer
  cannot detect a violation.

Two habits worth keeping:

**Mutation-test a guarantee before you trust the test.** Break the thing on
purpose — delete the validation call, write the secret to the wrong file — and
confirm a test goes red. Tests here have passed with the behaviour they claimed
to cover removed entirely.

**Do not assert on repository files.** The Docker test image ships only
`bin config public src tests vendor` plus four root config files, so a test that
reads `.vscode/launch.json` or `compose.dev.yaml` passes locally and fails in
CI. This has happened twice. Prefer a temp file exercising the mechanism; where
a real checkout is genuinely required, skip on the *directory* being absent so a
deletion still fails the test.

You can reproduce the image layout in seconds:

```bash
rm -rf /tmp/imgsim && mkdir -p /tmp/imgsim
cp -r bin config public src tests vendor /tmp/imgsim/
cp composer.json phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php /tmp/imgsim/
rm -f /tmp/imgsim/config/ddns.yaml
cd /tmp/imgsim && vendor/bin/phpunit
```

## Configuration and secrets

Discovery order, in `src/Bootstrap.php`:

1. `$DDNS_CONFIG`
2. `config/ddns.yaml`

That is the whole list, and it is deliberately short. Nothing stands in for a
missing file: with no configuration the application refuses to start. There used
to be four candidate paths and a `$DDNS_CONFIG_FALLBACK` behind them pointing at
a committed `ddns.dev.yaml`, which meant the server could be answering from a
sample nobody had chosen, with a host token published in this repository. Do not
reintroduce a fallback; `BootstrapTest` fails if one appears.

`config/ddns.yaml` is generated, never committed. `ddns config:init` asks
questions; `ddns config:init --sample` asks none and writes a local-development
configuration with generated credentials. The dev Compose stack and the editor
profiles both expect one of those to have been run.

**No secret belongs in the YAML.** Configuration holds `${VAR}` placeholders;
values live in `.env`, written `0600`. A command that generates a token prints
it once because the *configuration* cannot give it back — `.env` can, which is
what `--sample` points at rather than echoing two credentials nobody asked to
see.

Two container bindings are the seams for redirecting I/O — `config.path` and
`env.path`. Tests override both. If you add a command that writes somewhere new,
bind the path rather than computing it, or the suite will start editing the
developer's own files.

Anything that rewrites configuration should extend
`Ddns\Console\AbstractConfigMutationCommand`, which supplies the three
guarantees: validate before writing, confirm before discarding comments, and
route secrets to `.env`.

## Traps

- **phpdotenv populates `$_ENV` and `$_SERVER`, not `getenv()`.** Read
  environment variables through `Ddns\Config\Environment`, whose `fromGlobals()`
  checks all three. Calling `getenv()` directly silently ignores anything set in
  `.env` — which has now caused this bug twice, in `DDNS_CONFIG` and
  `DDNS_LOG_LEVEL`.
- **A `.env` written during a process is not re-read by it.** Tests simulating a
  restart must seed `$_ENV` themselves.
- **`composer validate --strict` rejects exact version constraints**, and this
  project pins deliberately, so CI runs plain `validate`.
- **PHPStan needs `--memory-limit=512M`** or a worker dies partway through.
- **`$app->add()` in Symfony Console prepends**, so middleware order is the
  reverse of what the calls read like.
- **`ArrayInput` is interactive by default** — pass `--no-interaction` in tests
  or a prompt will hang the suite.
- **`GLOB_BRACE` is not available on every PHP build.** Use `scandir`.
- **Route53 has no record IDs**, so the driver synthesises `fqdn/TYPE`. Its
  `MaxItems` is a string, and names arrive octal-escaped.
- **Azure public and private DNS differ in JSON casing** (`TTL`/`ARecords`
  versus `ttl`/`aRecords`). The wrong casing is *accepted* and silently stores
  an empty record set — there is no error to catch.
- **Verify an edit actually landed.** Some tooling masks credential-like strings
  when displaying files, so text copied from a rendered view may not match what
  is on disk, and a replacement can no-op silently. Grep for the result.

## Commits and CI

[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). Scopes
in use name the surface that changed — `cli`, `http`, `config`, `openapi`,
`docker`, `vscode` — or the driver, as in `route53` and `azuredns`.

Write the subject as what changed from the user's point of view, and use the
body to explain **why** — the constraint, the bug, the thing that turned out not
to be true. `git log` here is written to be read later; match it.

**The type is load-bearing.** It decides whether a push to `main` releases at
all: a `!` or a `BREAKING CHANGE:` footer, `feat`, `fix` and friends all
release; a push made only of `docs`, `chore`, `ci`, `style` or `test` commits
does not. It no longer decides what the release is *called* — every release
is tagged `1.<date>.<time>` regardless of how large the change was.
Mislabelling `feat` as `chore` does not just read wrong, it withholds a
release. The rules are in `.github/scripts/next-version.php`, unchanged from
when they drove the version number directly: `release.yml` feeds it the
commits a push actually introduced — a merge commit itself is excluded, so a
merge-commit merge, a squash, a rebase and a direct push are all read the same
way. It still carries its own cases; run
`php .github/scripts/next-version.php --self-test` after touching it.

CI runs seven jobs: PHP 8.2, 8.3 and 8.4 (validate, audit, format, analyse,
test, a config-validation smoke test, the release rules' self-test, and a check
that the README's PHP badge still matches `composer.json`), a coverage run, a
Docker image build, a Compose file check, and — on `main` only, so six on a
pull request — a job that commits the coverage badge. `composer check` locally
covers the formatting, analysis and test steps.

**Coverage is measured, never asserted.** `.github/badges/coverage.json` is
generated by CI and rendered by shields.io; do not hand-edit it, and do not put
a number in the README. `composer test:coverage` reproduces it locally, but
needs pcov or Xdebug — `composer check` deliberately leaves it out, because
without a driver PHPUnit silently measures nothing.

The release and publish workflows are committed but **switched off**: every
job in `release.yml`, `publish-ghcr.yml`, `publish-dockerhub.yml` and
`publish-packagist.yml` is gated on a `RELEASE_ENABLED` repository variable
that does not exist yet. `release.yml` rewrites `Bootstrap::VERSION` before
tagging — so if you rename that constant, it has to change with it. CI checks
the constant is still in the shape the release rewrites, rather than letting a
release be where that is discovered. Publishing to ghcr.io, Docker Hub and
Packagist happens in the other three workflows, each its own run triggered by
the GitHub release `release.yml` creates, not by anything in the same job —
ghcr.io and Docker Hub are two of those rather than one workflow pushing to
both, so a Docker Hub credential problem cannot also stop ghcr.io, which needs
no credentials of its own.

None of the workflow files are covered by the PHP toolchain. `actionlint` with
`shellcheck` is what catches mistakes in them; both are worth running by hand
after an edit, since nothing in CI does it for you.

For the authoritative state of a CI run use `gh run list` and `gh run watch`.
The GitHub status page has lagged reality by more than thirty minutes.
