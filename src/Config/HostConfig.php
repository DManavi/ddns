<?php

declare(strict_types=1);

namespace Ddns\Config;

use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;

/**
 * One dynamically updated hostname.
 *
 * The host key doubles as the URL segment (`/v1/hosts/{host}/update`) and as
 * the CLI argument, which is why it is restricted to URL-safe characters.
 */
final class HostConfig
{
    /**
     * @param list<RecordType> $recordTypes the address families to keep in sync
     * @param string           $token       the shared secret an HTTP client must present
     */
    public function __construct(
        private readonly string $name,
        private readonly string $providerName,
        private readonly Hostname $hostname,
        private readonly array $recordTypes,
        private readonly int $ttl,
        private readonly string $token,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function providerName(): string
    {
        return $this->providerName;
    }

    public function hostname(): Hostname
    {
        return $this->hostname;
    }

    /**
     * @return list<RecordType>
     */
    public function recordTypes(): array
    {
        return $this->recordTypes;
    }

    public function wants(RecordType $type): bool
    {
        return in_array($type, $this->recordTypes, true);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * The expected client token. Never log or serialise this value.
     */
    public function token(): string
    {
        return $this->token;
    }

    /**
     * Constant-time token comparison.
     *
     * Uses `hash_equals` unconditionally so that a wrong token costs the same
     * as a right one, and so the caller cannot infer anything from timing.
     */
    public function tokenMatches(string $candidate): bool
    {
        return hash_equals($this->token, $candidate);
    }

    /**
     * A representation safe to print in CLI tables and API responses.
     *
     * @return array{name: string, fqdn: string, zone: string, record: string, provider: string, types: list<string>, ttl: int, token: string}
     */
    public function toRedactedArray(): array
    {
        return [
            'name' => $this->name,
            'fqdn' => $this->hostname->fqdn(),
            'zone' => $this->hostname->zone(),
            'record' => $this->hostname->name(),
            'provider' => $this->providerName,
            'types' => array_map(static fn (RecordType $t): string => $t->value, $this->recordTypes),
            'ttl' => $this->ttl,
            'token' => Redactor::redact($this->token),
        ];
    }
}
