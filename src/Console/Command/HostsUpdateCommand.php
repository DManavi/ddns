<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\ConfigPath;
use Ddns\Console\AbstractConfigMutationCommand;
use Ddns\Console\SecretPlaceholder;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `hosts:update` — change a host that already exists.
 *
 * Only what is named on the command line changes. Everything else is left
 * exactly as written, including any comment-free formatting choices the file
 * already carries, because a command that rewrites fields it was not asked
 * about is one you cannot use with confidence.
 */
#[AsCommand(
    name: 'hosts:update',
    description: 'Change a host in the configuration.',
)]
final class HostsUpdateCommand extends AbstractConfigMutationCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of human-readable output.');
        $this->addForceOption();

        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The host to change.')
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED, 'Use a different configured provider account.')
            ->addOption('zone', 'z', InputOption::VALUE_REQUIRED, 'Change the zone.')
            ->addOption('record', 'r', InputOption::VALUE_REQUIRED, 'Change the name within the zone; "@" for the apex.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Replace the record types; repeat for more than one.')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Change the TTL, in seconds.')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Set the token, or a ${VAR} placeholder, explicitly.')
            ->addOption('rotate-token', null, InputOption::VALUE_NONE, 'Generate a new token and write it to .env.')
            ->setHelp(<<<'HELP'
                Only the fields you name change:

                  ddns hosts:update nas --ttl 300
                  ddns hosts:update nas --type A --type AAAA
                  ddns hosts:update nas --rotate-token

                Rotating prints the new token once and replaces the value
                behind its ${VAR} placeholder, so the configuration file is
                unchanged and every client using the old one stops working
                immediately.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);

        $name = trim($this->stringArgument($input, 'name'));
        $raw = $this->readRawConfig();
        $existing = ConfigPath::get($raw, 'hosts.' . $name);

        if (!$existing['found'] || !is_array($existing['value'])) {
            $io->error(sprintf('No host called "%s". Add it with `ddns hosts:add %s`.', $name, $name));

            return self::FAILURE;
        }

        /** @var array<string, mixed> $host */
        $host = $existing['value'];
        $before = $host;
        $changes = [];

        foreach (['provider' => 'provider', 'zone' => 'zone', 'record' => 'name'] as $option => $key) {
            $value = $this->optionalValue($input, $option);

            if ($value !== null) {
                $host[$key] = $value;
                $changes[$key] = $value;
            }
        }

        $ttl = $this->optionalValue($input, 'ttl');

        if ($ttl !== null) {
            if (preg_match('/^\d+$/', $ttl) !== 1) {
                $io->error('TTL must be a whole number of seconds.');

                return self::INVALID;
            }

            $host['ttl'] = (int) $ttl;
            $changes['ttl'] = (int) $ttl;
        }

        $types = $this->recordTypes($input);

        if ($types === false) {
            $io->error('Record types must be A, AAAA, or both.');

            return self::INVALID;
        }

        if ($types !== null) {
            $host['types'] = $types;
            $changes['types'] = $types;
        }

        $secrets = [];
        $plain = null;
        $supplied = $this->optionalValue($input, 'token');
        $rotate = $input->getOption('rotate-token') === true;

        if ($supplied !== null && $rotate) {
            $io->error('Pass either --token or --rotate-token, not both.');

            return self::INVALID;
        }

        if ($supplied !== null) {
            $host['token'] = $supplied;
            $changes['token'] = '(set)';
        }

        if ($rotate) {
            [$host['token'], $secrets, $plain] = $this->rotate($name, $host['token'] ?? null);
            $changes['token'] = '(rotated)';
        }

        if ($changes === []) {
            $io->warning(sprintf('Nothing to change. Name at least one of --provider, --zone, --record, --type, --ttl, --token or --rotate-token.'));

            return self::INVALID;
        }

        if ($host === $before && $secrets === []) {
            $io->success(sprintf('"%s" already has those values.', $name));

            return self::SUCCESS;
        }

        $this->assertHostnameIsUsable($host);

        $updated = ConfigPath::set($raw, 'hosts.' . $name, $host);
        $problem = $this->validationProblem($updated, $secrets);

        if ($problem !== null) {
            $io->error($problem);
            $io->note('Nothing was written.');

            return self::INVALID;
        }

        if (!$this->confirmCommentLoss($io, $input, $this->configPath())) {
            return self::INVALID;
        }

        // Rotation exists to change the value, so the variable behind it is
        // explicitly replaceable rather than merely offered.
        $this->saveConfig($updated, $secrets, array_keys($secrets));

        if ($this->wantsJson($input)) {
            $this->json($output)->document([
                'host' => $name,
                'changed' => array_keys($changes),
                'token' => $plain,
            ]);

            return self::SUCCESS;
        }

        $io->success(sprintf('Updated "%s": %s.', $name, implode(', ', array_keys($changes))));

        if ($plain !== null) {
            $io->writeln('The new token, which is not recoverable from the configuration:');
            $io->writeln(sprintf('  <info>%s</info>', $plain));
        }

        return self::SUCCESS;
    }

    /**
     * Replace the value behind the existing placeholder where there is one, so
     * the configuration file does not change and anything already reading that
     * variable picks the new secret up.
     *
     * @return array{0: string, 1: array<string, string>, 2: string} placeholder, secrets, the token in plain text
     */
    private function rotate(string $name, mixed $current): array
    {
        $token = $this->generateToken();

        if (is_string($current) && preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/', trim($current), $matches) === 1) {
            return [trim($current), [$matches[1] => $token], $token];
        }

        $variable = $this->environmentVariableName($name, 'token');
        $placeholder = new SecretPlaceholder($variable, $token);

        return [$placeholder->reference(), [$variable => $token], $token];
    }

    /**
     * @param array<string, mixed> $host
     */
    private function assertHostnameIsUsable(array $host): void
    {
        $zone = is_string($host['zone'] ?? null) ? $host['zone'] : '';
        $record = is_string($host['name'] ?? null) ? $host['name'] : Hostname::APEX;

        // Reported here rather than as a validation failure, so the message
        // names the hostname rather than the config key path.
        Hostname::create($zone, $record);
    }

    private function optionalValue(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return list<string>|null|false null when none were given, false when one is not a record type
     */
    private function recordTypes(InputInterface $input): array|null|false
    {
        $given = $this->stringListOption($input, 'type');

        if ($given === []) {
            return null;
        }

        $types = [];

        foreach ($given as $name) {
            foreach (explode(',', $name) as $part) {
                if (trim($part) === '') {
                    continue;
                }

                try {
                    $type = RecordType::fromInput(trim($part));
                } catch (\InvalidArgumentException) {
                    return false;
                }

                if (!in_array($type->value, $types, true)) {
                    $types[] = $type->value;
                }
            }
        }

        return $types === [] ? false : $types;
    }
}
