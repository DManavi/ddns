<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\ConfigPath;
use Ddns\Console\AbstractConfigMutationCommand;
use Ddns\Console\ConfigRedaction;
use Ddns\Provider\ProviderFactories;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Changes one value in the configuration file.
 *
 * The value is parsed as YAML, so it arrives with the type the file needs
 * rather than as a string: `600` is a number, `true` is a boolean and
 * `[A, AAAA]` is a list. Quoting forces a string when that is wanted.
 *
 * Two safeguards. The result is validated before it is written, so a typo
 * cannot leave behind a file the server refuses to start with. And rewriting
 * the file loses its comments, which is destructive enough to confirm first -
 * `config/ddns.example.yaml` is mostly comments, and someone who started from
 * it would not expect a TTL change to strip them.
 */
#[AsCommand(
    name: 'config:set',
    description: 'Change one value in the configuration file.',
)]
final class ConfigSetCommand extends AbstractConfigMutationCommand
{
    protected function configure(): void
    {
        $this->addForceOption();

        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Dotted path, for example hosts.home.ttl.')
            ->addArgument('value', InputArgument::REQUIRED, 'The new value, parsed as YAML.')
            ->setHelp(<<<'HELP'
                Values are parsed as YAML, so types come out right:

                  ddns config:set server.default_ttl 600
                  ddns config:set server.allow_private_ips true
                  ddns config:set hosts.home.types '[A, AAAA]'
                  ddns config:set hosts.home.name '@'

                The file is rewritten, which discards its comments, so you are
                asked to confirm unless --force is given.

                Secrets belong in .env: pass a placeholder such as
                '${HOME_TOKEN}' rather than the value itself.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = $this->stringArgument($input, 'key');
        $path = $this->configPath();
        $raw = $this->readRawConfig();

        try {
            $value = $this->parse($this->stringArgument($input, 'value'));
        } catch (ParseException $e) {
            $io->error(sprintf('The value could not be parsed as YAML: %s', $e->getMessage()));

            return self::INVALID;
        }

        $blocked = ConfigPath::blockedBy($raw, $key);

        if ($blocked !== null) {
            // Writing anyway would replace a scalar with a mapping and quietly
            // discard whatever was there.
            $io->error(sprintf('Cannot set "%s" because "%s" is not a mapping.', $key, $blocked));

            return self::INVALID;
        }

        $updated = ConfigPath::set($raw, $key, $value);
        $before = ConfigPath::get($raw, $key);

        if ($before['found'] && $before['value'] === $value) {
            $io->success(sprintf('"%s" is already %s.', $key, $this->describe($value)));

            return self::SUCCESS;
        }

        $problem = $this->validationProblem($updated);

        if ($problem !== null) {
            $io->error($problem);
            $io->note('Nothing was written.');

            return self::INVALID;
        }

        if (!$this->confirmCommentLoss($io, $input, $path)) {
            return self::INVALID;
        }

        $this->saveConfig($updated);

        $io->success(sprintf(
            '%s: %s -> %s',
            $key,
            $before['found'] ? $this->describe($before['value']) : '(unset)',
            $this->describe($value),
        ));

        $this->warnAboutLiteralSecret($io, $key, $value);

        return self::SUCCESS;
    }

    /**
     * Interpret the value the way the file would.
     *
     * Plain YAML parsing is not quite enough on its own: `@` is a reserved
     * indicator that cannot begin a plain scalar, and it is also the value
     * that means "the zone apex" - so the most obvious thing anyone would
     * type would be rejected. A value that fails to parse is therefore taken
     * literally, unless it opens a structure, where the intent was clearly a
     * list or a mapping and a silent string would hide the mistake.
     *
     * @throws ParseException when a structure was intended but is malformed
     */
    private function parse(string $value): mixed
    {
        try {
            return Yaml::parse($value);
        } catch (ParseException $e) {
            if (str_starts_with(ltrim($value), '[') || str_starts_with(ltrim($value), '{')) {
                throw $e;
            }

            return $value;
        }
    }



    /**
     * A literal credential in the configuration file is a file that can no
     * longer be committed, which is easy to do by accident and hard to notice.
     */
    private function warnAboutLiteralSecret(SymfonyStyle $io, string $key, mixed $value): void
    {
        if (!is_string($value) || ConfigRedaction::isPlaceholder($value)) {
            return;
        }

        $leaf = (string) (array_slice(explode('.', $key), -1)[0] ?? '');
        $secrets = ConfigRedaction::secretKeys($this->service(ProviderFactories::class));

        if (!in_array($leaf, $secrets, true)) {
            return;
        }

        $io->warning(sprintf(
            'A secret is now written into %s in plain text. Consider putting it in .env and '
            . 'setting this to a ${VAR} placeholder instead.',
            $this->configPath(),
        ));
    }

    private function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            // Flow style, so the before/after fits on the one line it is
            // reported on.
            return trim(Yaml::dump($value, 0, 2, Yaml::DUMP_NULL_AS_TILDE));
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
