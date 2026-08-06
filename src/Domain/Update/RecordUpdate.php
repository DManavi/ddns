<?php

declare(strict_types=1);

namespace Ddns\Domain\Update;

use Ddns\Domain\Record\IpAddress;
use Ddns\Domain\Record\RecordType;

/**
 * The result of reconciling a single record type for a host.
 */
final class RecordUpdate
{
    private function __construct(
        private readonly RecordType $type,
        private readonly UpdateOutcome $outcome,
        private readonly ?IpAddress $ip,
        private readonly ?string $previousValue,
        private readonly ?string $reason,
        private readonly bool $dryRun,
        private readonly int $suggestedHttpStatus,
    ) {
    }

    public static function created(RecordType $type, IpAddress $ip, bool $dryRun = false): self
    {
        return new self($type, UpdateOutcome::Created, $ip, null, null, $dryRun, 200);
    }

    public static function updated(RecordType $type, IpAddress $ip, string $previousValue, bool $dryRun = false): self
    {
        return new self($type, UpdateOutcome::Updated, $ip, $previousValue, null, $dryRun, 200);
    }

    public static function unchanged(RecordType $type, IpAddress $ip): self
    {
        return new self($type, UpdateOutcome::Unchanged, $ip, $ip->value(), null, false, 200);
    }

    public static function skipped(RecordType $type, string $reason): self
    {
        return new self($type, UpdateOutcome::Skipped, null, null, $reason, false, 200);
    }

    public static function failed(RecordType $type, string $reason, int $suggestedHttpStatus = 502): self
    {
        return new self($type, UpdateOutcome::Failed, null, null, $reason, false, $suggestedHttpStatus);
    }

    public function type(): RecordType
    {
        return $this->type;
    }

    public function outcome(): UpdateOutcome
    {
        return $this->outcome;
    }

    public function ip(): ?IpAddress
    {
        return $this->ip;
    }

    public function previousValue(): ?string
    {
        return $this->previousValue;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function suggestedHttpStatus(): int
    {
        return $this->suggestedHttpStatus;
    }

    /**
     * A one-line, human-readable summary for CLI output and logs.
     */
    public function describe(): string
    {
        $prefix = $this->dryRun ? 'would ' : '';

        return match ($this->outcome) {
            UpdateOutcome::Created => sprintf('%s: %screate -> %s', $this->type->value, $prefix, (string) $this->ip),
            UpdateOutcome::Updated => sprintf(
                '%s: %supdate %s -> %s',
                $this->type->value,
                $prefix,
                (string) $this->previousValue,
                (string) $this->ip,
            ),
            UpdateOutcome::Unchanged => sprintf('%s: unchanged (%s)', $this->type->value, (string) $this->ip),
            UpdateOutcome::Skipped => sprintf('%s: skipped (%s)', $this->type->value, (string) $this->reason),
            UpdateOutcome::Failed => sprintf('%s: failed (%s)', $this->type->value, (string) $this->reason),
        };
    }

    /**
     * @return array{type: string, status: string, ip: string|null, previous: string|null, reason: string|null, dry_run: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'status' => $this->outcome->value,
            'ip' => $this->ip?->value(),
            'previous' => $this->previousValue,
            'reason' => $this->reason,
            'dry_run' => $this->dryRun,
        ];
    }
}
