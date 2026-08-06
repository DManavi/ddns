<?php

declare(strict_types=1);

namespace Ddns\Domain\Update;

/**
 * Everything that happened to one host during an update.
 */
final class UpdateReport
{
    /**
     * @param list<RecordUpdate> $records
     */
    public function __construct(
        private readonly string $host,
        private readonly string $fqdn,
        private readonly array $records,
    ) {
    }

    public function host(): string
    {
        return $this->host;
    }

    public function fqdn(): string
    {
        return $this->fqdn;
    }

    /**
     * @return list<RecordUpdate>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function isSuccessful(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return list<RecordUpdate>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (RecordUpdate $record): bool => $record->outcome()->isFailure(),
        ));
    }

    public function hasChanges(): bool
    {
        foreach ($this->records as $record) {
            if ($record->outcome()->isChange()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single status that best represents the whole host.
     *
     * Failure dominates, then any change, then a skip, and finally unchanged.
     */
    public function status(): UpdateOutcome
    {
        $best = null;

        foreach ($this->records as $record) {
            $outcome = $record->outcome();

            if ($outcome->isFailure()) {
                return UpdateOutcome::Failed;
            }

            $best = match (true) {
                $outcome->isChange() => $outcome,
                $best === null || $best === UpdateOutcome::Skipped => $outcome,
                default => $best,
            };
        }

        return $best ?? UpdateOutcome::Skipped;
    }

    /**
     * The HTTP status the web layer should return for this host.
     */
    public function suggestedHttpStatus(): int
    {
        $status = 200;

        foreach ($this->failures() as $failure) {
            $status = max($status, $failure->suggestedHttpStatus());
        }

        return $status;
    }

    /**
     * @return array{host: string, fqdn: string, status: string, changed: bool, records: list<array{type: string, status: string, ip: string|null, previous: string|null, reason: string|null, dry_run: bool}>}
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'fqdn' => $this->fqdn,
            'status' => $this->status()->value,
            'changed' => $this->hasChanges(),
            'records' => array_map(static fn (RecordUpdate $r): array => $r->toArray(), $this->records),
        ];
    }
}
