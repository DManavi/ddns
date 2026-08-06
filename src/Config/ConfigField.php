<?php

declare(strict_types=1);

namespace Ddns\Config;

/**
 * One value a driver needs, described well enough to be asked for.
 *
 * Drivers differ in what they need and in what it is called: most take a
 * bearer token, Azure needs a subscription and resource group, Route53 may
 * need nothing at all. Rather than teach the wizard about each of them, every
 * factory describes its own fields and the wizard renders whatever it is
 * given. Adding a provider therefore still means writing one driver and one
 * factory, with no third place to forget.
 *
 * Lives in Config, not Console, because the provider layer already depends on
 * Config and must not depend on the console.
 */
final class ConfigField
{
    /**
     * @param string      $key      the config key, e.g. `token` or `subscription_id`
     * @param string      $label    what to call it when asking
     * @param bool        $required whether the driver refuses to work without it
     * @param bool        $secret   whether it must be hidden while typing and kept out of the config file
     * @param string|null $help     one line of context, shown before asking
     * @param string|null $default  offered as the answer when the user just presses enter
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = true,
        public readonly bool $secret = false,
        public readonly ?string $help = null,
        public readonly ?string $default = null,
    ) {
    }

    public static function secret(string $key, string $label, ?string $help = null): self
    {
        return new self($key, $label, true, true, $help);
    }

    public static function optional(string $key, string $label, ?string $help = null, ?string $default = null): self
    {
        return new self($key, $label, false, false, $help, $default);
    }

    public static function optionalSecret(string $key, string $label, ?string $help = null): self
    {
        return new self($key, $label, false, true, $help);
    }
}
