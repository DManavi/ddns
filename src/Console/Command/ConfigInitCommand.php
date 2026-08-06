<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Bootstrap;
use Ddns\Config\ConfigField;
use Ddns\Config\ConfigFile;
use Ddns\Config\ConfigLoader;
use Ddns\Config\EnvInterpolator;
use Ddns\Config\Environment;
use Ddns\Config\Exception\ConfigurationError;
use Ddns\Console\AbstractDdnsCommand;
use Ddns\Console\EnvFileWriter;
use Ddns\Console\SecretPlaceholder;
use Ddns\Domain\Record\Hostname;
use Ddns\Domain\Record\RecordType;
use Ddns\Provider\Azure\AzureZoneKind;
use Ddns\Provider\ProviderFactories;
use Ddns\Provider\ProviderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a working configuration file by asking for what it cannot infer.
 *
 * The questions come from the provider factories rather than from a table kept
 * here, so a newly added driver is offered by the wizard with its own fields
 * and no change to this command.
 *
 * Two guarantees shape the implementation. The file it writes always loads:
 * the answers are validated through {@see ConfigLoader} before anything
 * touches the disk, so the wizard cannot produce a file that
 * `config:validate` then rejects. And no secret is written into it: every
 * credential becomes a `${VAR}` placeholder with the value appended to `.env`.
 */
#[AsCommand(
    name: 'config:init',
    description: 'Create a configuration file interactively.',
)]
final class ConfigInitCommand extends AbstractDdnsCommand
{
    private const DEFAULT_TTL = 300;

    /** 24 bytes hex-encoded, comfortably above the loader's 12-character floor. */
    private const TOKEN_BYTES = 24;

    /** Re-asks allowed for one field before giving up on the input stream. */
    private const MAX_ATTEMPTS = 3;

    /** @var list<SecretPlaceholder> */
    private array $secrets = [];

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Where to write the file.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing configuration file.')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Where to write the secrets. Defaults to the .env the application reads.')
            ->setHelp(<<<'HELP'
                Asks for a provider account and a first hostname, then writes a
                configuration file that is ready to use.

                Credentials are never written into the configuration. Each one
                becomes a ${VAR} placeholder and the value is appended to .env,
                so the configuration file stays safe to commit.

                The result is validated before it is written, so the file this
                produces always loads.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('config:init is interactive. Run it from a terminal, or write the file yourself starting from config/ddns.example.yaml.');

            return self::INVALID;
        }

        $path = $this->targetPath($input);

        if (is_file($path) && $input->getOption('force') !== true) {
            $io->error(sprintf('%s already exists. Pass --force to replace it, or edit it directly.', $path));

            return self::INVALID;
        }

        try {
            return $this->interview($io, $input, $path);
        } catch (\RuntimeException $e) {
            // Includes Symfony's own MissingInputException, thrown when the
            // stream ends at a question that has no default.
            $io->error($e->getMessage());
            $io->note('Nothing was written.');

            return self::FAILURE;
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function interview(SymfonyStyle $io, InputInterface $input, string $path): int
    {
        $io->title('ddns configuration');
        $io->text([
            'This creates a configuration file with one provider account and one hostname.',
            'Add more later by editing the file; see config/ddns.example.yaml for every option.',
        ]);

        $factory = $this->chooseDriver($io);

        if ($factory === null) {
            return self::FAILURE;
        }

        $providerName = $this->askProviderName($io, $factory);
        $provider = ['driver' => $factory->driver()] + $this->askFields($io, $factory, $providerName);

        $host = $this->askHost($io, $providerName);
        $server = $this->askServer($io, $factory);

        /** @var array<string, mixed> $config */
        $config = array_filter([
            'server' => $server,
            'providers' => [$providerName => $provider],
            'hosts' => $host,
        ], static fn (array $section): bool => $section !== []);

        // The whole point of a wizard is that what it produces works, so prove
        // it before writing rather than leaving the user to discover otherwise.
        $problem = $this->validate($config);

        if ($problem !== null) {
            $io->error($problem);
            $io->note('Nothing was written. This is a bug in the wizard - please report it.');

            return self::FAILURE;
        }

        return $this->save($io, $input, $path, $config);
    }

    /**
     * @param array<string, string> $choices
     */
    private function askChoice(SymfonyStyle $io, string $question, array $choices, string $default): string
    {
        $answer = $io->choice($question, $choices, $default);

        return is_string($answer) ? $answer : $default;
    }

    private function askSecret(SymfonyStyle $io, string $label): string
    {
        $answer = $io->askHidden($label);

        return is_string($answer) ? trim($answer) : '';
    }

    /**
     * Ask for a string, re-asking until the validator accepts it.
     *
     * Wraps SymfonyStyle::ask so validators can be written against a string and
     * the answer arrives typed, rather than as mixed cast at every call site.
     *
     * @param callable(string): string $validate
     */
    private function askString(SymfonyStyle $io, string $question, ?string $default, callable $validate): string
    {
        $answer = $io->ask($question, $default, static function (mixed $value) use ($validate): string {
            return $validate(is_scalar($value) ? trim((string) $value) : '');
        });

        return is_string($answer) ? $answer : '';
    }

    /**
     * Dotenv only ever reads the project root, so that is where a placeholder
     * can actually be resolved from - even when the configuration file itself
     * is being written elsewhere.
     */
    private function envPath(InputInterface $input): string
    {
        $explicit = $input->getOption('env');

        return is_string($explicit) && $explicit !== '' ? $explicit : Bootstrap::projectRoot() . '/.env';
    }

    private function targetPath(InputInterface $input): string
    {
        $explicit = $input->getOption('config');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $fromEnv = getenv('DDNS_CONFIG');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return Bootstrap::projectRoot() . '/ddns.yaml';
    }

    private function chooseDriver(SymfonyStyle $io): ?ProviderFactory
    {
        $factories = $this->service(ProviderFactories::class)->all();
        $available = array_values(array_filter($factories, static fn (ProviderFactory $f): bool => $f->isAvailable()));

        if ($available === []) {
            $io->error('No DNS providers are available in this build.');

            return null;
        }

        $choices = [];

        foreach ($available as $candidate) {
            $choices[$candidate->driver()] = $candidate->description();
        }

        foreach ($factories as $candidate) {
            if (!$candidate->isAvailable()) {
                $io->warning(sprintf('%s is unavailable: %s', $candidate->driver(), (string) $candidate->unavailableReason()));
            }
        }

        $io->section('DNS provider');

        $driver = $this->askChoice($io, 'Which DNS provider hosts the zone?', $choices, (string) array_key_first($choices));

        foreach ($available as $candidate) {
            if ($candidate->driver() === $driver) {
                return $candidate;
            }
        }

        return null;
    }

    private function askProviderName(SymfonyStyle $io, ProviderFactory $factory): string
    {
        return $this->askString(
            $io,
            'Name this provider account (you can add a second one later)',
            $factory->driver(),
            static function (string $value): string {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) !== 1) {
                    throw new \InvalidArgumentException('Use letters, digits, dots, dashes and underscores.');
                }

                return $value;
            },
        );
    }

    /**
     * Ask for whatever the chosen driver says it needs.
     *
     * Mandatory values first, then an offer to fill in the rest. Asking about
     * the optional ones up front reads as a question about the driver as a
     * whole, and is easy to answer for the wrong thing.
     *
     * @return array<string, string>
     */
    private function askFields(SymfonyStyle $io, ProviderFactory $factory, string $providerName): array
    {
        $fields = $factory->configFields();
        $values = [];

        foreach ($fields as $field) {
            if ($field->required) {
                $answer = $this->askField($io, $field, $providerName);

                if ($answer !== null) {
                    $values[$field->key] = $answer;
                }
            }
        }

        $optional = array_values(array_filter($fields, static fn (ConfigField $f): bool => !$f->required));

        if ($optional === []) {
            return $values;
        }

        $question = sprintf(
            'Configure the optional %s settings (%s)?',
            $factory->driver(),
            implode(', ', array_map(static fn (ConfigField $f): string => $f->key, $optional)),
        );

        // Defaults to no: every optional field either has a working default or
        // is deliberately absent, as with the AWS credential chain.
        if (!$io->confirm($question, false)) {
            return $values;
        }

        foreach ($optional as $field) {
            $answer = $this->askField($io, $field, $providerName);

            if ($answer !== null) {
                $values[$field->key] = $answer;
            }
        }

        return $values;
    }

    /**
     * @throws \RuntimeException when the answer stream ends before a required value arrives
     */
    private function askField(SymfonyStyle $io, ConfigField $field, string $providerName): ?string
    {
        if ($field->help !== null) {
            $io->text($field->help);
        }

        $label = $field->required ? $field->label : $field->label . ' (optional, blank to skip)';

        // Bounded, because a closed or exhausted input stream answers every
        // question with an empty string: without a limit a required field
        // would re-ask forever rather than failing.
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $value = $field->secret
                ? $this->askSecret($io, $label)
                : $this->askString($io, $label, $field->default, static fn (string $answer): string => $answer);

            if ($value !== '') {
                if (!$field->secret) {
                    return $value;
                }

                $secret = new SecretPlaceholder($this->variableName($providerName, $field->key), $value);
                $this->secrets[] = $secret;

                return $secret->reference();
            }

            if (!$field->required) {
                return null;
            }

            $io->warning(sprintf('%s is required.', $field->label));
        }

        throw new \RuntimeException(sprintf('No value was given for "%s".', $field->label));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function askHost(SymfonyStyle $io, string $providerName): array
    {
        $io->section('Hostname');
        $io->text('This is the record kept in sync. The key is used in the URL and on the command line.');

        $zone = $this->askString($io, 'Zone (the domain as your provider knows it)', null, static function (string $value): string {
            if ($value === '') {
                throw new \InvalidArgumentException('A zone is required, for example example.com.');
            }

            return $value;
        });

        $record = $this->askString(
            $io,
            sprintf('Record name within %s, or @ for the zone itself', $zone),
            'home',
            static function (string $value) use ($zone): string {
                $value = $value === '' ? Hostname::APEX : $value;

                // Reject here rather than at the end: the loader would refuse
                // "home.example.org" inside "example.com", and a wizard that
                // collected six more answers before saying so would be rude.
                Hostname::create($zone, $value);

                return $value;
            },
        );

        $key = $record === Hostname::APEX ? 'apex' : str_replace('.', '-', $record);
        $key = $this->askString($io, 'Name for this host', $key, static function (string $value): string {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) !== 1) {
                throw new \InvalidArgumentException('Must be usable in a URL: letters, digits, dots, dashes, underscores.');
            }

            return $value;
        });

        $families = $this->askChoice($io, 'Which address families?', [
            'A' => 'IPv4 only',
            'AAAA' => 'IPv6 only',
            'A,AAAA' => 'Both (IPv6 is reported as skipped when unavailable)',
        ], 'A');

        $types = array_map(
            static fn (string $t): string => RecordType::fromInput($t)->value,
            explode(',', $families),
        );

        $ttl = (int) $this->askString($io, 'TTL in seconds', (string) self::DEFAULT_TTL, static function (string $value): string {
            if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                throw new \InvalidArgumentException('TTL must be a positive whole number of seconds.');
            }

            return $value;
        });

        // Generated rather than asked for: this is a secret the user has no
        // reason to choose, and one they would otherwise pick badly.
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $secret = new SecretPlaceholder($this->variableName($key, 'token'), $token);
        $this->secrets[] = $secret;

        return [
            $key => [
                'provider' => $providerName,
                'zone' => $zone,
                'name' => $record,
                'types' => $types,
                'ttl' => $ttl,
                'token' => $secret->reference(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function askServer(SymfonyStyle $io, ProviderFactory $factory): array
    {
        // Only worth asking about where it is genuinely likely: a private zone
        // exists to hold internal addresses, which the default refuses.
        if ($factory->driver() !== AzureZoneKind::Private->driver()) {
            return [];
        }

        $io->section('Private addresses');

        $allow = $io->confirm(
            'A private zone usually holds internal addresses, which are refused by default. Allow them?',
            true,
        );

        return $allow ? ['allow_private_ips' => true] : [];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return string|null the reason it would not load, or null when it will
     */
    private function validate(array $config): ?string
    {
        $environment = Environment::fromGlobals()->with($this->secretValues());

        $loader = new ConfigLoader(
            new EnvInterpolator($environment),
            $this->service(ProviderFactories::class)->catalog(),
        );

        try {
            $loader->fromArray($config);
        } catch (ConfigurationError $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function save(SymfonyStyle $io, InputInterface $input, string $path, array $config): int
    {
        $envPath = $this->envPath($input);

        try {
            $result = EnvFileWriter::apply($envPath, $this->secretValues(), $this->confirmReplacements($io, $envPath));

            ConfigFile::write($path, $config);
        } catch (ConfigurationError $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Wrote %s', $path));

        foreach ([
            'written' => 'Secrets written to %s: %s',
            'replaced' => 'Secrets replaced in %s: %s',
        ] as $key => $template) {
            if ($result[$key] !== []) {
                $io->text(sprintf($template, $envPath, implode(', ', $result[$key])));
            }
        }

        $io->section('Next steps');
        $io->listing([
            'ddns config:validate      check the file loads',
            'ddns update --all         set the records now',
            'ddns watch --all          keep them in sync',
        ]);

        return self::SUCCESS;
    }

    /**
     * `do-personal` + `token` becomes `DO_PERSONAL_TOKEN`.
     *
     * Suffixed when that name is already taken, because a provider and a host
     * may share a name - `home` and `home` both reduce to `HOME_TOKEN` - and
     * two secrets behind one variable would silently give one of them the
     * other's value.
     */
    private function variableName(string $context, string $key): string
    {
        $base = trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $context . '_' . $key) ?? ''), '_');
        $taken = array_map(static fn (SecretPlaceholder $s): string => $s->variable, $this->secrets);

        if (!in_array($base, $taken, true)) {
            return $base;
        }

        for ($suffix = 2; ; ++$suffix) {
            $candidate = $base . '_' . $suffix;

            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * A re-run produces new values for variables that already exist, so ask
     * before touching them: replacing a working credential without asking, and
     * leaving a stale one behind the placeholder that was just written, are
     * both worse than a question.
     *
     * @return list<string>
     */
    private function confirmReplacements(SymfonyStyle $io, string $envPath): array
    {
        $existing = EnvFileWriter::read($envPath);
        $conflicts = [];

        foreach ($this->secretValues() as $name => $value) {
            if (array_key_exists($name, $existing) && $existing[$name] !== $value) {
                $conflicts[] = $name;
            }
        }

        if ($conflicts === []) {
            return [];
        }

        $io->warning(sprintf(
            '%s already defines %s with a different value.',
            $envPath,
            implode(', ', $conflicts),
        ));

        return $io->confirm('Replace them with the values just entered?', true) ? $conflicts : [];
    }

    /**
     * @return array<string, string>
     */
    private function secretValues(): array
    {
        $values = [];

        foreach ($this->secrets as $secret) {
            $values[$secret->variable] = $secret->value;
        }

        return $values;
    }
}
