<?php

declare(strict_types=1);

namespace Ddns\Console;

/**
 * A secret gathered by the wizard, and the variable it will be read from.
 *
 * The wizard never writes a credential into the YAML file. It writes a
 * `${NAME}` placeholder and puts the value in `.env`, so the configuration
 * stays committable and the secret stays in one gitignored place.
 */
final class SecretPlaceholder
{
    public function __construct(
        public readonly string $variable,
        public readonly string $value,
    ) {
    }

    public function reference(): string
    {
        return '${' . $this->variable . '}';
    }
}
