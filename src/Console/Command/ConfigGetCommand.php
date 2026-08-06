<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\ConfigFile;
use Ddns\Config\ConfigPath;
use Ddns\Console\AbstractDdnsCommand;
use Ddns\Console\ConfigRedaction;
use Ddns\Provider\ProviderFactories;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads one value out of the configuration file by dotted path.
 *
 * Reads the file rather than the loaded configuration, so what comes back is
 * what is written there - a `${VAR}` placeholder stays a placeholder. That is
 * both the honest answer for someone about to edit the file and the safe one,
 * since resolving it would print the secret.
 */
#[AsCommand(
    name: 'config:get',
    description: 'Read one value from the configuration file.',
)]
final class ConfigGetCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of a bare value.');

        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Dotted path, for example hosts.home.ttl.')
            ->setHelp(<<<'HELP'
                Prints the value as written in the file, so a ${VAR} placeholder
                is shown rather than the secret it resolves to.

                Exits 1 when the key is not present, so a script can tell an
                absent setting from an empty one.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $key = $this->stringArgument($input, 'key');

        $raw = ConfigRedaction::mask(
            ConfigFile::read($this->configPath()),
            ConfigRedaction::secretKeys($this->service(ProviderFactories::class)),
        );

        $result = ConfigPath::get($raw, $key);

        if (!$result['found']) {
            if ($this->wantsJson($input)) {
                $this->json($output)->document(['key' => $key, 'found' => false, 'value' => null]);
            } else {
                $io->error(sprintf('"%s" is not set.', $key));
                $this->suggest($io, $raw, $key);
            }

            return self::FAILURE;
        }

        if ($this->wantsJson($input)) {
            $this->json($output)->document(['key' => $key, 'found' => true, 'value' => $result['value']]);

            return self::SUCCESS;
        }

        $output->writeln($this->format($result['value']), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function suggest(SymfonyStyle $io, array $raw, string $key): void
    {
        $leaves = ConfigPath::leaves($raw);
        $prefix = implode('.', array_slice(explode('.', $key), 0, -1));

        $near = array_values(array_filter(
            $leaves,
            static fn (string $leaf): bool => $prefix !== '' && str_starts_with($leaf, $prefix . '.'),
        ));

        if ($near !== []) {
            $io->text(sprintf('Available under "%s": %s', $prefix, implode(', ', $near)));

            return;
        }

        $io->text('Run `ddns config:show --raw` to see what is set.');
    }

    /**
     * A scalar prints bare so it composes with other tools; anything with
     * structure prints as the YAML it is, which is also what config:set
     * accepts back.
     */
    private function format(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return trim(Yaml::dump($value, 2, 2, Yaml::DUMP_NULL_AS_TILDE));
    }
}
