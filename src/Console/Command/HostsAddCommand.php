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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `hosts:add` — start keeping another name in sync.
 *
 * The token is generated rather than asked for: it is a secret nobody has a
 * reason to choose, and it is the one value a person would pick badly. It goes
 * to `.env` behind a `${VAR}` placeholder, so the configuration stays
 * committable.
 */
#[AsCommand(
    name: 'hosts:add',
    description: 'Add a host to the configuration.',
)]
final class HostsAddCommand extends AbstractConfigMutationCommand
{
    private const DEFAULT_TYPES = 'A';

    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of human-readable output.');
        $this->addForceOption();

        $this
            ->addArgument('name', InputArgument::REQUIRED, 'What to call this host; used in the URL and on the command line.')
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED, 'The configured provider account to use.')
            ->addOption('zone', 'z', InputOption::VALUE_REQUIRED, 'The domain, as your provider knows it.')
            ->addOption('record', 'r', InputOption::VALUE_REQUIRED, 'Name within the zone; "@" for the apex. Defaults to the host name.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Record type; repeat for more than one. Defaults to A.')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'TTL in seconds. Defaults to the server default.')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Use this token instead of generating one. A ${VAR} placeholder is kept as written.')
            ->setHelp(<<<'HELP'
                Anything not given is asked for, so this works both ways:

                  ddns hosts:add nas --provider do-personal --zone example.com
                  ddns hosts:add nas

                The token is generated and written to .env as a ${VAR}
                placeholder, so the configuration file holds no secrets. It is
                printed once, because that is the only time it is available in
                plain text.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);

        $name = trim($this->stringArgument($input, 'name'));
        $raw = $this->readRawConfig();

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
            $io->error(sprintf('"%s" is not usable in a URL. Use letters, digits, dots, dashes and underscores.', $name));

            return self::INVALID;
        }

        if (ConfigPath::get($raw, 'hosts.' . $name)['found']) {
            $io->error(sprintf('A host called "%s" already exists. Change it with `ddns hosts:update %s`.', $name, $name));

            return self::INVALID;
        }

        $providers = $this->providerNames($raw);

        if ($providers === []) {
            $io->error('No providers are configured, so there is nothing for a host to use. Run `ddns config:init` first.');

            return self::INVALID;
        }

        $provider = $this->chooseProvider($io, $input, $providers);

        if ($provider === null) {
            return self::INVALID;
        }

        $zone = $this->requireValue($io, $input, 'zone', 'Zone (the domain as your provider knows it)');

        if ($zone === null) {
            return self::INVALID;
        }

        $record = $this->optionalValue($input, 'record') ?? $name;

        try {
            Hostname::create($zone, $record);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return self::INVALID;
        }

        $types = $this->recordTypes($input);

        if ($types === null) {
            $io->error('Record types must be A, AAAA, or both.');

            return self::INVALID;
        }

        $host = ['provider' => $provider, 'zone' => $zone, 'name' => $record, 'types' => $types];

        $ttl = $this->optionalValue($input, 'ttl');

        if ($ttl !== null) {
            if (preg_match('/^\d+$/', $ttl) !== 1) {
                $io->error('TTL must be a whole number of seconds.');

                return self::INVALID;
            }

            $host['ttl'] = (int) $ttl;
        }

        [$host['token'], $secrets, $plain] = $this->resolveToken($input, $name);

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

        $this->saveConfig($updated, $secrets);

        return $this->report($input, $output, $io, $name, $host, $plain);
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: string|null} placeholder, secrets to write, the token in plain text
     */
    private function resolveToken(InputInterface $input, string $name): array
    {
        $supplied = $this->optionalValue($input, 'token');

        if ($supplied !== null) {
            // A placeholder is a reference to a secret, not a secret; anything
            // else the caller chose to write literally is their decision.
            return [$supplied, [], null];
        }

        $token = $this->generateToken();
        $variable = $this->environmentVariableName($name, 'token');
        $placeholder = new SecretPlaceholder($variable, $token);

        return [$placeholder->reference(), [$variable => $token], $token];
    }

    /**
     * @param array<string, mixed> $host
     */
    private function report(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $name,
        array $host,
        ?string $plain,
    ): int {
        $zone = is_string($host['zone'] ?? null) ? $host['zone'] : '';
        $record = is_string($host['name'] ?? null) ? $host['name'] : '';
        $fqdn = Hostname::create($zone, $record)->fqdn();

        if ($this->wantsJson($input)) {
            $this->json($output)->document([
                'host' => $name,
                'fqdn' => $fqdn,
                'provider' => $host['provider'] ?? null,
                'types' => $host['types'] ?? [],
                // Printed once. There is no other moment at which this exists
                // in plain text, since the file only ever holds a reference.
                'token' => $plain,
            ]);

            return self::SUCCESS;
        }

        $io->success(sprintf('Added "%s" for %s.', $name, $fqdn));

        if ($plain !== null) {
            $io->writeln('The token for this host, which is not recoverable from the configuration:');
            $io->writeln(sprintf('  <info>%s</info>', $plain));
            $io->writeln('');
        }

        $io->text(sprintf('Try it with: ddns update %s --dry-run', $name));

        return self::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return list<string>
     */
    private function providerNames(array $raw): array
    {
        $providers = $raw['providers'] ?? null;

        if (!is_array($providers)) {
            return [];
        }

        return array_values(array_filter(array_map(strval(...), array_keys($providers))));
    }

    /**
     * @param list<string> $providers
     */
    private function chooseProvider(SymfonyStyle $io, InputInterface $input, array $providers): ?string
    {
        $given = $this->optionalValue($input, 'provider');

        if ($given !== null) {
            if (!in_array($given, $providers, true)) {
                $io->error(sprintf(
                    '"%s" is not a configured provider. Available: %s.',
                    $given,
                    implode(', ', $providers),
                ));

                return null;
            }

            return $given;
        }

        $first = $providers[0] ?? null;

        // Only one to choose from, so choosing is not a question worth asking.
        if ($first !== null && count($providers) === 1) {
            return $first;
        }

        if (!$input->isInteractive()) {
            $io->error(sprintf('--provider is required. Available: %s.', implode(', ', $providers)));

            return null;
        }

        $answer = $io->choice('Which provider account?', $providers, $first);

        return is_string($answer) ? $answer : null;
    }

    private function requireValue(SymfonyStyle $io, InputInterface $input, string $option, string $question): ?string
    {
        $given = $this->optionalValue($input, $option);

        if ($given !== null) {
            return $given;
        }

        if (!$input->isInteractive()) {
            $io->error(sprintf('--%s is required.', $option));

            return null;
        }

        $answer = $io->ask($question, null, static function (mixed $value): string {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '') {
                throw new \InvalidArgumentException('A value is required.');
            }

            return $value;
        });

        return is_string($answer) ? $answer : null;
    }

    private function optionalValue(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return list<string>|null null when one of them is not a record type
     */
    private function recordTypes(InputInterface $input): ?array
    {
        $given = $this->stringListOption($input, 'type');

        if ($given === []) {
            $given = [self::DEFAULT_TYPES];
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
                    return null;
                }

                if (!in_array($type->value, $types, true)) {
                    $types[] = $type->value;
                }
            }
        }

        return $types === [] ? null : $types;
    }
}
