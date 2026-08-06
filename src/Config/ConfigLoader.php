<?php

declare(strict_types=1);

namespace Ddns\Config;

use Ddns\Config\Exception\ConfigurationError;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;
use Ddns\Support\CidrMatcher;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the YAML configuration file into validated, typed objects.
 *
 * Validation collects every problem before throwing, so a misconfigured file
 * reports all of its errors in one pass instead of one per run.
 */
final class ConfigLoader
{
    private const MINIMUM_TOKEN_LENGTH = 12;
    private const MAX_TTL = 604800;

    /** @var list<string> */
    private const DEFAULT_IPV4_SERVICES = [
        'https://api.ipify.org',
        'https://ipv4.icanhazip.com',
        'https://v4.ident.me',
    ];

    /** @var list<string> */
    private const DEFAULT_IPV6_SERVICES = [
        'https://api6.ipify.org',
        'https://ipv6.icanhazip.com',
        'https://v6.ident.me',
    ];

    private ConfigProblems $problems;

    /**
     * @param list<string> $knownDrivers driver identifiers the registry can build
     */
    public function __construct(
        private readonly EnvInterpolator $interpolator,
        private readonly array $knownDrivers,
    ) {
        $this->problems = new ConfigProblems();
    }

    /**
     * @throws ConfigurationError
     */
    public function load(string $path): Configuration
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ConfigurationError::unreadable($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw ConfigurationError::unreadable($path);
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw ConfigurationError::malformed($path, $e->getMessage());
        }

        if ($parsed === null) {
            throw ConfigurationError::malformed($path, 'the file is empty.');
        }

        if (!is_array($parsed)) {
            throw ConfigurationError::malformed($path, 'the top level must be a mapping.');
        }

        return $this->fromArray($parsed);
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws ConfigurationError
     */
    public function fromArray(array $raw): Configuration
    {
        $this->problems = new ConfigProblems();

        $data = $this->interpolator->interpolate($raw);

        $server = $this->buildServer($this->section($data, 'server'));
        $providers = $this->buildProviders($this->section($data, 'providers'));
        $hosts = $this->buildHosts($this->section($data, 'hosts'), $providers, $server);

        if ($this->problems->any()) {
            throw ConfigurationError::invalid($this->problems->all());
        }

        return new Configuration($server, $providers, $hosts);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function section(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value)) {
            $this->problems->addf('"%s" must be a mapping.', $key);

            return [];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function buildServer(array $raw): ServerConfig
    {
        $defaultTtl = $this->intValue($raw['default_ttl'] ?? 300, 'server.default_ttl', 300);

        if ($defaultTtl < 1 || $defaultTtl > self::MAX_TTL) {
            $this->problems->addf(
                'server.default_ttl must be between 1 and %d seconds, got %d.',
                self::MAX_TTL,
                $defaultTtl,
            );
            $defaultTtl = 300;
        }

        $trustedProxies = $this->stringList($raw['trusted_proxies'] ?? [], 'server.trusted_proxies');

        foreach ($trustedProxies as $cidr) {
            if (!CidrMatcher::isValidRange($cidr)) {
                $this->problems->addf(
                    'server.trusted_proxies contains "%s", which is not a valid IP address or CIDR range.',
                    $cidr,
                );
            }
        }

        $ipServices = $raw['ip_services'] ?? [];
        $ipServices = is_array($ipServices) ? $ipServices : [];

        $ipv4 = $this->stringList($ipServices['v4'] ?? self::DEFAULT_IPV4_SERVICES, 'server.ip_services.v4');
        $ipv6 = $this->stringList($ipServices['v6'] ?? self::DEFAULT_IPV6_SERVICES, 'server.ip_services.v6');

        $timeout = $this->floatValue($raw['ip_lookup_timeout'] ?? 5.0, 'server.ip_lookup_timeout', 5.0);

        if ($timeout <= 0.0) {
            $this->problems->add('server.ip_lookup_timeout must be greater than zero.');
            $timeout = 5.0;
        }

        return new ServerConfig(
            defaultTtl: $defaultTtl,
            trustedProxies: $trustedProxies,
            ipv4Services: $ipv4 === [] ? self::DEFAULT_IPV4_SERVICES : $ipv4,
            ipv6Services: $ipv6 === [] ? self::DEFAULT_IPV6_SERVICES : $ipv6,
            ipLookupTimeout: $timeout,
            allowPrivateIps: $this->boolValue($raw['allow_private_ips'] ?? false, 'server.allow_private_ips'),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, ProviderConfig>
     */
    private function buildProviders(array $raw): array
    {
        if ($raw === []) {
            $this->problems->add('At least one provider must be configured under "providers".');

            return [];
        }

        $providers = [];

        foreach ($raw as $name => $definition) {
            $name = (string) $name;
            $path = 'providers.' . $name;

            if (!is_array($definition)) {
                $this->problems->addf('"%s" must be a mapping.', $path);

                continue;
            }

            $driver = $this->stringValue($definition['driver'] ?? null, $path . '.driver');

            if ($driver === '') {
                $this->problems->addf('"%s.driver" is required.', $path);

                continue;
            }

            if (!in_array($driver, $this->knownDrivers, true)) {
                $this->problems->addf(
                    '"%s.driver" is "%s", which is not a known driver. Available drivers: %s.',
                    $path,
                    $driver,
                    implode(', ', $this->knownDrivers),
                );

                continue;
            }

            $token = $this->stringValue($definition['token'] ?? null, $path . '.token');

            if ($token === '') {
                $this->problems->addf(
                    '"%s.token" is required and must not be empty. If it comes from the environment, '
                    . 'check that the variable is exported.',
                    $path,
                );
            }

            $options = $definition;
            unset($options['driver'], $options['token']);

            /** @var array<string, mixed> $options */
            $providers[$name] = new ProviderConfig($name, $driver, $token, $options);
        }

        return $providers;
    }

    /**
     * @param array<array-key, mixed>       $raw
     * @param array<string, ProviderConfig> $providers
     *
     * @return array<string, HostConfig>
     */
    private function buildHosts(array $raw, array $providers, ServerConfig $server): array
    {
        if ($raw === []) {
            $this->problems->add('At least one host must be configured under "hosts".');

            return [];
        }

        $hosts = [];

        foreach ($raw as $name => $definition) {
            $name = (string) $name;
            $path = 'hosts.' . $name;

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
                $this->problems->addf(
                    'Host key "%s" is not usable in a URL. Use letters, digits, dots, dashes and underscores.',
                    $name,
                );

                continue;
            }

            if (!is_array($definition)) {
                $this->problems->addf('"%s" must be a mapping.', $path);

                continue;
            }

            $providerName = $this->stringValue($definition['provider'] ?? null, $path . '.provider');

            if ($providerName === '') {
                $this->problems->addf('"%s.provider" is required.', $path);

                continue;
            }

            if (!isset($providers[$providerName])) {
                $this->problems->addf(
                    '"%s.provider" refers to "%s", which is not defined under "providers".',
                    $path,
                    $providerName,
                );

                continue;
            }

            $zone = $this->stringValue($definition['zone'] ?? null, $path . '.zone');

            if ($zone === '') {
                $this->problems->addf('"%s.zone" is required.', $path);

                continue;
            }

            $recordName = $this->stringValue($definition['name'] ?? Hostname::APEX, $path . '.name');

            try {
                $hostname = Hostname::create($zone, $recordName === '' ? Hostname::APEX : $recordName);
            } catch (\InvalidArgumentException $e) {
                $this->problems->addf('"%s": %s', $path, $e->getMessage());

                continue;
            }

            $types = $this->recordTypes($definition['types'] ?? ['A'], $path . '.types');

            if ($types === []) {
                continue;
            }

            $ttl = $this->intValue($definition['ttl'] ?? $server->defaultTtl(), $path . '.ttl', $server->defaultTtl());

            if ($ttl < 1 || $ttl > self::MAX_TTL) {
                $this->problems->addf(
                    '"%s.ttl" must be between 1 and %d seconds, got %d.',
                    $path,
                    self::MAX_TTL,
                    $ttl,
                );

                continue;
            }

            $token = $this->stringValue($definition['token'] ?? null, $path . '.token');

            if ($token === '') {
                $this->problems->addf(
                    '"%s.token" is required: it is the secret an HTTP client presents to update this host.',
                    $path,
                );

                continue;
            }

            if (mb_strlen($token) < self::MINIMUM_TOKEN_LENGTH) {
                $this->problems->addf(
                    '"%s.token" must be at least %d characters. Generate one with: openssl rand -hex 24',
                    $path,
                    self::MINIMUM_TOKEN_LENGTH,
                );

                continue;
            }

            $hosts[$name] = new HostConfig($name, $providerName, $hostname, $types, $ttl, $token);
        }

        $this->assertNoDuplicateTargets($hosts);

        return $hosts;
    }

    /**
     * Two hosts writing the same FQDN and record type would fight each other on
     * every poll, so refuse the configuration rather than flap the record.
     *
     * @param array<string, HostConfig> $hosts
     */
    private function assertNoDuplicateTargets(array $hosts): void
    {
        /** @var array<string, array{fqdn: string, type: string, owners: list<string>}> $seen */
        $seen = [];

        foreach ($hosts as $host) {
            foreach ($host->recordTypes() as $type) {
                $key = $host->providerName() . '|' . $host->hostname()->fqdn() . '|' . $type->value;

                $seen[$key] ??= [
                    'fqdn' => $host->hostname()->fqdn(),
                    'type' => $type->value,
                    'owners' => [],
                ];
                $seen[$key]['owners'][] = $host->name();
            }
        }

        foreach ($seen as $target) {
            if (count($target['owners']) > 1) {
                $this->problems->addf(
                    'Hosts %s all manage the %s record for "%s" on the same provider; they would overwrite each other.',
                    implode(', ', array_map(static fn (string $o): string => '"' . $o . '"', $target['owners'])),
                    $target['type'],
                    $target['fqdn'],
                );
            }
        }
    }

    /**
     * @return list<RecordType>
     */
    private function recordTypes(mixed $value, string $path): array
    {
        $names = is_array($value) ? $value : [$value];
        $types = [];

        foreach ($names as $name) {
            if (!is_string($name)) {
                $this->problems->addf('"%s" must be a list of record type names.', $path);

                return [];
            }

            try {
                $type = RecordType::fromInput($name);
            } catch (\InvalidArgumentException $e) {
                $this->problems->addf('"%s": %s', $path, $e->getMessage());

                return [];
            }

            if (!in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        if ($types === []) {
            $this->problems->addf('"%s" must list at least one record type.', $path);
        }

        return $types;
    }

    private function stringValue(mixed $value, string $path): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $this->problems->addf('"%s" must be a string.', $path);

        return '';
    }

    private function intValue(mixed $value, string $path, int $fallback): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        $this->problems->addf('"%s" must be an integer.', $path);

        return $fallback;
    }

    private function floatValue(mixed $value, string $path, float $fallback): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        $this->problems->addf('"%s" must be a number.', $path);

        return $fallback;
    }

    private function boolValue(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        $this->problems->addf('"%s" must be a boolean.', $path);

        return false;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            $this->problems->addf('"%s" must be a list of strings.', $path);

            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                $this->problems->addf('"%s" must be a list of strings.', $path);

                return [];
            }

            $trimmed = trim($item);

            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}
