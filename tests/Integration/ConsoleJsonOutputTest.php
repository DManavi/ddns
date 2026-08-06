<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `--json` contract, from a consumer's point of view.
 *
 * Every test here asserts on stdout alone. That is the whole promise: a script
 * can pipe stdout into a parser and never have to strip a banner, a warning or
 * a log line out of it first. Tests that merely check "the JSON is in there
 * somewhere" would pass even when the output was unusable.
 */
#[CoversNothing]
final class ConsoleJsonOutputTest extends ConsoleTestCase
{
    #[Test]
    public function update_emits_only_json_on_stdout(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $result = $this->runCommand(['command' => 'update', 'host' => ['home'], '--ip' => '203.0.113.7', '--json' => true]);

        $payload = $this->decode($result->stdout);

        self::assertSame(0, $result->exitCode);
        self::assertTrue($this->at($payload, 'changed'));
        self::assertFalse($this->at($payload, 'failed'));
        self::assertFalse($this->at($payload, 'dry_run'));
        self::assertSame('created', $this->at($payload, 'hosts.0.records.0.status'));
    }

    #[Test]
    public function update_json_matches_the_http_api_shape(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $result = $this->runCommand(['command' => 'update', 'host' => ['home'], '--ip' => '203.0.113.7', '--json' => true]);
        $host = $this->atArray($this->decode($result->stdout), 'hosts.0');

        // Same keys the HTTP endpoint returns, so one consumer can read both.
        self::assertSame(['host', 'fqdn', 'status', 'changed', 'records'], array_keys($host));
        self::assertSame(
            ['type', 'status', 'ip', 'previous', 'reason', 'dry_run'],
            array_keys($this->atArray($host, 'records.0')),
        );
    }

    #[Test]
    public function update_json_hosts_match_the_http_response_for_the_same_host(): void
    {
        // The README tells people a script can read either front-end. That only
        // stays true if something fails when the two shapes drift apart.
        $this->expectCreateFlow('203.0.113.7');

        $result = $this->runCommand(['command' => 'update', 'host' => ['home'], '--ip' => '203.0.113.7', '--json' => true]);
        $cli = $this->atArray($this->decode($result->stdout), 'hosts.0');

        $this->expectCreateFlow('203.0.113.7');

        $http = $this->httpUpdate('203.0.113.7');

        // `client_ip` is the one documented difference: there is no client.
        unset($http['client_ip']);

        self::assertSame($http, $cli);
    }

    #[Test]
    public function a_failing_update_still_produces_parseable_json(): void
    {
        // The case that matters most: a consumer needs to read the reason, and
        // an unparseable body would leave it with only an exit code.
        $this->upstream()->queue(401, ['id' => 'unauthorized', 'message' => 'Unable to authenticate you']);

        $result = $this->runCommand(['command' => 'update', 'host' => ['home'], '--ip' => '203.0.113.7', '--json' => true]);

        $payload = $this->decode($result->stdout);

        self::assertSame(1, $result->exitCode);
        self::assertTrue($this->at($payload, 'failed'));
        self::assertSame('failed', $this->at($payload, 'hosts.0.records.0.status'));
        self::assertStringContainsString('authenticate', $this->atString($payload, 'hosts.0.records.0.reason'));
    }

    #[Test]
    public function reasons_containing_angle_brackets_survive_intact(): void
    {
        // Written raw, or the console formatter would eat "<html>" as a tag and
        // hand the caller a truncated reason.
        $this->upstream()->queue(502, '<html><body>Bad Gateway</body></html>', ['Content-Type' => 'text/html']);

        $result = $this->runCommand(['command' => 'update', 'host' => ['home'], '--ip' => '203.0.113.7', '--json' => true]);

        $reason = $this->atString($this->decode($result->stdout), 'hosts.0.records.0.reason');

        self::assertStringContainsString('502', $reason);
        self::assertStringNotContainsString('[warning]', $result->stdout);
    }

    #[Test]
    public function dry_run_notes_go_to_stderr_not_stdout(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $result = $this->runCommand([
            'command' => 'update',
            'host' => ['home'],
            '--ip' => '203.0.113.7',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->decode($result->stdout);

        self::assertTrue($this->at($payload, 'dry_run'));
        self::assertStringNotContainsString('dry run', strtolower($result->stdout));
    }

    #[Test]
    public function hosts_list_emits_a_json_document(): void
    {
        $result = $this->runCommand(['command' => 'hosts:list', '--json' => true]);

        $payload = $this->decode($result->stdout);

        self::assertSame(0, $result->exitCode);
        self::assertSame('home', $this->at($payload, 'hosts.0.name'));
        self::assertSame('home.example.com', $this->at($payload, 'hosts.0.fqdn'));
    }

    #[Test]
    public function hosts_list_redacts_tokens(): void
    {
        $result = $this->runCommand(['command' => 'hosts:list', '--json' => true]);

        self::assertStringNotContainsString('host-token-0123456789abcdef', $result->stdout);
    }

    #[Test]
    public function an_unknown_host_name_fails_without_writing_junk_to_stdout(): void
    {
        $result = $this->runCommand(['command' => 'update', 'host' => ['nope'], '--json' => true]);

        // Nothing useful to report, so nothing is written; the exit code and
        // stderr carry the failure rather than a half-formed document.
        self::assertSame(2, $result->exitCode);
        self::assertSame('', trim($result->stdout));
        self::assertStringContainsString('nope', $result->stderr);
    }

    #[Test]
    public function providers_list_reports_every_driver(): void
    {
        $result = $this->runCommand(['command' => 'providers:list', '--json' => true]);

        $drivers = array_column($this->atArray($this->decode($result->stdout), 'drivers'), 'driver');

        self::assertSame(
            ['digitalocean', 'vultr', 'cloudflare', 'azuredns', 'azureprivatedns', 'route53'],
            $drivers,
        );
    }

    #[Test]
    public function config_validate_reports_success(): void
    {
        $result = $this->runCommand(['command' => 'config:validate', '--json' => true]);

        $payload = $this->decode($result->stdout);

        self::assertSame(0, $result->exitCode);
        self::assertTrue($this->at($payload, 'valid'));
        self::assertSame([], $this->at($payload, 'problems'));
    }

    #[Test]
    public function config_validate_lists_problems_individually(): void
    {
        $config = "providers:\n  p1:\n    driver: nosuchdriver\n    token: t\n"
            . "hosts:\n  home:\n    provider: missing\n    zone: example.com\n    token: host-token-0123456789abcdef\n";

        $result = $this->runCommand(['command' => 'config:validate', '--json' => true], $config);

        $payload = $this->decode($result->stdout);

        self::assertSame(2, $result->exitCode);
        self::assertFalse($this->at($payload, 'valid'));

        $problems = $this->atArray($payload, 'problems');

        // A list, not one blob of prose, so a caller can act per problem.
        self::assertGreaterThanOrEqual(2, count($problems));
        self::assertStringContainsString('nosuchdriver', implode("\n", array_map(static fn (mixed $p): string => (string) (is_scalar($p) ? $p : ''), $problems)));
    }

    #[Test]
    public function config_validate_json_does_not_leak_secrets(): void
    {
        $result = $this->runCommand(['command' => 'config:validate', '--json' => true]);

        self::assertStringNotContainsString('provider-secret', $result->stdout);
        self::assertStringNotContainsString('host-token-0123456789abcdef', $result->stdout);
    }

    #[Test]
    public function watch_emits_newline_delimited_events(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $result = $this->runCommand([
            'command' => 'watch',
            'host' => ['home'],
            '--once' => true,
            '--json' => true,
        ]);

        $events = $this->decodeStream($result->stdout);

        self::assertSame('started', $this->at($events, '0.event'));
        self::assertSame(['home'], $this->at($events, '0.hosts'));
        self::assertSame('created', $this->at($events, '1.event'));
        self::assertSame('home', $this->at($events, '1.host'));
        self::assertSame('203.0.113.7', $this->at($events, '1.ip'));
    }

    #[Test]
    public function watch_stays_quiet_when_nothing_changes(): void
    {
        $this->expectUnchangedFlow('203.0.113.7');

        $result = $this->runCommand(['command' => 'watch', 'host' => ['home'], '--once' => true, '--json' => true]);

        $events = $this->decodeStream($result->stdout);

        // Only the banner: an unchanged poll is noise on a long-running stream.
        self::assertCount(1, $events);
        self::assertSame('started', $this->at($events, '0.event'));
    }

    #[Test]
    public function watch_reports_an_unchanged_poll_when_the_address_cannot_be_resolved(): void
    {
        // No echo service answers, so nothing was learned and there is nothing
        // to write. Reachable on the first cycle precisely because "no address"
        // and "no address last time" compare equal.
        $this->publicIp = 'not-an-address';

        $result = $this->runCommand(
            ['command' => 'watch', 'host' => ['home'], '--once' => true, '--json' => true],
            '',
            OutputInterface::VERBOSITY_VERBOSE,
        );

        $events = $this->decodeStream($result->stdout);

        self::assertSame('started', $this->at($events, '0.event'));
        self::assertSame('unchanged', $this->at($events, '1.event'));
        self::assertSame([], $this->at($events, '1.addresses'));
        self::assertSame(0, $result->exitCode);
    }

    #[Test]
    public function watch_events_are_individually_parseable(): void
    {
        $this->upstream()->queue(401, ['id' => 'unauthorized', 'message' => 'Unable to authenticate you']);

        $result = $this->runCommand(['command' => 'watch', 'host' => ['home'], '--once' => true, '--json' => true]);

        // Each line must stand alone; a pretty-printed document here would
        // break every consumer reading the stream a line at a time.
        foreach (explode("\n", trim($result->stdout)) as $line) {
            self::assertIsArray(json_decode($line, true), sprintf('Not a self-contained JSON line: %s', $line));
        }

        self::assertSame(1, $result->exitCode);
    }

    #[Test]
    public function human_output_is_unchanged_without_the_flag(): void
    {
        $this->expectCreateFlow('203.0.113.7');

        $result = $this->runCommand(['command' => 'update', 'host' => ['home'], '--ip' => '203.0.113.7']);

        self::assertNull(json_decode($result->stdout, true));
        self::assertStringContainsString('home.example.com', $result->stdout);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $stdout): array
    {
        $decoded = json_decode($stdout, true);

        self::assertIsArray(
            $decoded,
            sprintf("stdout was not a single JSON document:\n%s", $stdout),
        );

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeStream(string $stdout): array
    {
        $events = [];

        foreach (explode("\n", trim($stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            self::assertIsArray($decoded, sprintf('Not valid NDJSON: %s', $line));

            $events[] = $decoded;
        }

        return $events;
    }

    /**
     * Read a dotted path out of a decoded payload, failing with the path that
     * was missing rather than a bare "undefined index".
     *
     * @param array<array-key, mixed> $payload
     */
    private function at(array $payload, string $path): mixed
    {
        $current = $payload;

        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($current, sprintf('"%s" is not traversable at "%s".', $path, $segment));
            self::assertArrayHasKey($segment, $current, sprintf('No "%s" in the payload.', $path));

            $current = $current[$segment] ?? null;
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function atString(array $payload, string $path): string
    {
        $value = $this->at($payload, $path);

        self::assertIsString($value, sprintf('"%s" is not a string.', $path));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function atArray(array $payload, string $path): array
    {
        $value = $this->at($payload, $path);

        self::assertIsArray($value, sprintf('"%s" is not an array.', $path));

        return $value;
    }
}
