<?php

declare(strict_types=1);

namespace Ddns\Domain\Update;

/**
 * What happened to one record during an update.
 */
enum UpdateOutcome: string
{
    /** The record did not exist and was created. */
    case Created = 'created';

    /** The record existed and was repointed. */
    case Updated = 'updated';

    /** The record was already correct; nothing was sent to the provider. */
    case Unchanged = 'unchanged';

    /** No address of this family was available, so the record was left alone. */
    case Skipped = 'skipped';

    /** The provider refused or errored. */
    case Failed = 'failed';

    public function isChange(): bool
    {
        return $this === self::Created || $this === self::Updated;
    }

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }
}
