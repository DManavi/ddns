<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Config;

use Ddns\Config\Exception\ConfigurationError;
use Ddns\Domain\Record\RecordType;
use Ddns\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadsAValidConfiguration(): void
    {
        $configuration = Fixtures::configuration(Fixtures::rawConfig());

        self::assertCount(1, $configuration->providers());
        self::assertCount(1, $configuration->hosts());

        $host = $configuration->host('home');
        self::assertSame('home.example.com', $host->hostname()->fqdn());
        self::assertSame([RecordType::A], $host->recordTypes());
        self::assertSame(60, $host->ttl());
    }

    public function testAppliesTheServerDefaultTtlWhenAHostOmitsIt(): void
    {
        $raw = Fixtures::rawConfig();
        unset($raw['hosts']['home']['ttl']);
        $raw['server']['default_ttl'] = 900;

        self::assertSame(900, Fixtures::configuration($raw)->host('home')->ttl());
    }

    public function testDefaultsToAnARecordWhenNoTypesAreGiven(): void
    {
        $raw = Fixtures::rawConfig();
        unset($raw['hosts']['home']['types']);

        self::assertSame([RecordType::A], Fixtures::configuration($raw)->host('home')->recordTypes());
    }

    public function testAcceptsLowercaseRecordTypes(): void
    {
        $configuration = Fixtures::configuration(Fixtures::rawConfig(['types' => ['a', 'aaaa']]));

        self::assertSame([RecordType::A, RecordType::AAAA], $configuration->host('home')->recordTypes());
    }

    public function testDeduplicatesRepeatedRecordTypes(): void
    {
        $configuration = Fixtures::configuration(Fixtures::rawConfig(['types' => ['A', 'A']]));

        self::assertSame([RecordType::A], $configuration->host('home')->recordTypes());
    }

    public function testDefaultsToNoTrustedProxies(): void
    {
        $configuration = Fixtures::configuration(Fixtures::rawConfig());

        self::assertSame([], $configuration->server()->trustedProxies());
        self::assertFalse($configuration->server()->trustsAnyProxy());
    }

    public function testRefusesPrivateAddressesByDefault(): void
    {
        self::assertFalse(Fixtures::configuration(Fixtures::rawConfig())->server()->allowPrivateIps());
    }

    public function testSuppliesDefaultIpLookupServices(): void
    {
        $server = Fixtures::configuration(Fixtures::rawConfig())->server();

        self::assertNotEmpty($server->ipv4Services());
        self::assertNotEmpty($server->ipv6Services());
    }

    /**
     * Every problem should be reported in one pass, not one run at a time.
     */
    public function testReportsEveryProblemAtOnce(): void
    {
        try {
            Fixtures::configuration([
                'providers' => ['p' => ['driver' => 'nope', 'token' => 'x']],
                'hosts' => ['h' => ['provider' => 'missing', 'zone' => 'example.com', 'token' => 'short']],
            ]);
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $e) {
            self::assertStringContainsString('not a known driver', $e->getMessage());
            self::assertStringContainsString('not defined under "providers"', $e->getMessage());
        }
    }

    public function testRejectsAnEmptyProvidersSection(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('At least one provider must be configured');

        Fixtures::configuration(['hosts' => []]);
    }

    public function testRejectsAnEmptyHostsSection(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('At least one host must be configured');

        Fixtures::configuration(['providers' => ['p1' => ['driver' => 'vultr', 'token' => 't']]]);
    }

    public function testRejectsAnUnknownDriver(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['providers']['p1']['driver'] = 'azure';

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('Available drivers: digitalocean, vultr, cloudflare, route53');

        Fixtures::configuration($raw);
    }

    public function testRejectsAnEmptyProviderToken(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['providers']['p1']['token'] = '';

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('providers.p1.token');

        Fixtures::configuration($raw);
    }

    /**
     * The host key ends up in a URL path, so it has to stay URL safe.
     */
    public function testRejectsAHostKeyThatIsNotUrlSafe(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['hosts']['has space'] = $raw['hosts']['home'];

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('not usable in a URL');

        Fixtures::configuration($raw);
    }

    /**
     * A short token is an auth secret, not a nickname.
     */
    public function testRejectsAShortHostToken(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('at least 12 characters');

        Fixtures::configuration(Fixtures::rawConfig(['token' => 'abc']));
    }

    public function testRejectsAMissingHostToken(): void
    {
        $raw = Fixtures::rawConfig();
        unset($raw['hosts']['home']['token']);

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('is the secret an HTTP client presents');

        Fixtures::configuration($raw);
    }

    public function testRejectsAnOutOfRangeTtl(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('must be between 1 and 604800');

        Fixtures::configuration(Fixtures::rawConfig(['ttl' => 0]));
    }

    public function testRejectsAnInvalidTrustedProxyRange(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['server']['trusted_proxies'] = ['10.0.0.0/8', 'garbage'];

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('not a valid IP address or CIDR range');

        Fixtures::configuration($raw);
    }

    public function testRejectsAnInvalidRecordType(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('Unsupported record type "MX"');

        Fixtures::configuration(Fixtures::rawConfig(['types' => ['MX']]));
    }

    /**
     * Two hosts writing the same record would fight on every poll and flap the
     * zone, so the configuration is refused outright.
     */
    public function testRejectsTwoHostsThatManageTheSameRecord(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['hosts']['duplicate'] = $raw['hosts']['home'];

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('they would overwrite each other');

        Fixtures::configuration($raw);
    }

    public function testAllowsTheSameNameInDifferentZones(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['hosts']['other'] = [...$raw['hosts']['home'], 'zone' => 'example.org'];

        self::assertCount(2, Fixtures::configuration($raw)->hosts());
    }

    public function testAllowsTheSameNameForDifferentRecordTypes(): void
    {
        $raw = Fixtures::rawConfig();
        $raw['hosts']['home']['types'] = ['A'];
        $raw['hosts']['home6'] = [...$raw['hosts']['home'], 'types' => ['AAAA']];

        self::assertCount(2, Fixtures::configuration($raw)->hosts());
    }

    public function testAcceptsNumericStringsFromTheEnvironmentForIntegers(): void
    {
        $raw = Fixtures::rawConfig(['ttl' => '${TTL}']);

        self::assertSame(120, Fixtures::configuration($raw, ['TTL' => '120'])->host('home')->ttl());
    }

    public function testUnreadableFileProducesAHelpfulError(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        Fixtures::loader()->load('/nonexistent/ddns.yaml');
    }

    public function testParsesAYamlFileFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddns-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, <<<'YAML'
            providers:
              p1:
                driver: cloudflare
                token: ${CF_TOKEN}
            hosts:
              home:
                provider: p1
                zone: example.com
                name: home
                token: ${HOME_TOKEN}
            YAML);

        try {
            $configuration = Fixtures::loader([
                'CF_TOKEN' => 'cf-secret',
                'HOME_TOKEN' => 'host-token-abcdef',
            ])->load($path);

            self::assertSame('cf-secret', $configuration->provider('p1')->token());
            self::assertSame('host-token-abcdef', $configuration->host('home')->token());
        } finally {
            unlink($path);
        }
    }

    public function testRejectsMalformedYaml(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddns-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, "providers:\n  - [unbalanced\n");

        try {
            $this->expectException(ConfigurationError::class);
            $this->expectExceptionMessage('could not be parsed');

            Fixtures::loader()->load($path);
        } finally {
            unlink($path);
        }
    }

    public function testRejectsAnEmptyFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ddns-') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, '');

        try {
            $this->expectException(ConfigurationError::class);
            $this->expectExceptionMessage('the file is empty');

            Fixtures::loader()->load($path);
        } finally {
            unlink($path);
        }
    }

    public function testUnknownHostLookupThrows(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('No host named "nope" is configured.');

        Fixtures::configuration(Fixtures::rawConfig())->host('nope');
    }

    public function testFindHostReturnsNullForAnUnknownName(): void
    {
        self::assertNull(Fixtures::configuration(Fixtures::rawConfig())->findHost('nope'));
    }
}
