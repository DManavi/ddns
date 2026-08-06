<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * Masks secrets so they can be shown in listings without being disclosed.
 */
final class Redactor
{
    private const PLACEHOLDER = '****';

    /**
     * Show at most the last four characters, and nothing at all for short
     * secrets where a suffix would leak a meaningful fraction of the value.
     */
    public static function redact(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        if (mb_strlen($secret) < 12) {
            return self::PLACEHOLDER;
        }

        return self::PLACEHOLDER . mb_substr($secret, -4);
    }
}
