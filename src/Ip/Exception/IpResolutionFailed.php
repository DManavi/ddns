<?php

declare(strict_types=1);

namespace Ddns\Ip\Exception;

use Ddns\Domain\Record\RecordType;

/**
 * The caller's public address could not be determined.
 */
final class IpResolutionFailed extends \RuntimeException
{
    public static function noAddressFor(RecordType $type, string $reason = ''): self
    {
        return new self(trim(sprintf(
            'Could not determine a public IPv%d address. %s',
            $type->ipVersion(),
            $reason,
        )));
    }

    /**
     * @param list<string> $attempted
     */
    public static function allServicesFailed(RecordType $type, array $attempted): self
    {
        return new self(sprintf(
            'Could not determine a public IPv%d address: all %d lookup service(s) failed (%s).',
            $type->ipVersion(),
            count($attempted),
            implode(', ', $attempted),
        ));
    }
}
