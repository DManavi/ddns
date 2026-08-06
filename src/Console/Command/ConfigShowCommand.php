<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\ConfigFile;
use Ddns\Console\AbstractDdnsCommand;
use Ddns\Console\ConfigRedaction;
use Ddns\Provider\ProviderFactories;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows the configuration, with secrets masked.
 *
 * Two views, because there are two questions. By default the effective
 * configuration - what the application actually concluded, with defaults
 * filled in - which answers "why is it behaving like this?". With `--raw`,
 * the file as written, placeholders unexpanded, which answers "what would I
 * be editing?".
 */
#[AsCommand(
    name: 'config:show',
    description: 'Show the configuration, with secrets masked.',
)]
final class ConfigShowCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of a human-readable summary.');

        $this
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Show the file as written, rather than the effective configuration.')
            ->setHelp(<<<'HELP'
                Secrets are masked in both views, so the output is safe to paste
                into a bug report.

                The effective view shows what the application concluded, with
                defaults applied. The raw view shows the file itself, with
                ${VAR} placeholders left alone - which is what config:set edits.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $input->getOption('raw') === true
            ? $this->showRaw($input, $output)
            : $this->showEffective($input, $output);
    }

    private function showRaw(InputInterface $input, OutputInterface $output): int
    {
        $data = ConfigRedaction::mask(
            ConfigFile::read($this->configPath()),
            ConfigRedaction::secretKeys($this->service(ProviderFactories::class)),
        );

        if ($this->wantsJson($input)) {
            $this->json($output)->document(['path' => $this->configPath(), 'config' => $data]);

            return self::SUCCESS;
        }

        $output->write(ConfigFile::render($data), false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function showEffective(InputInterface $input, OutputInterface $output): int
    {
        $configuration = $this->configuration();

        $payload = [
            'path' => $this->configPath(),
            'server' => $configuration->server()->toArray(),
            'providers' => array_map(
                static fn ($provider): array => $provider->toRedactedArray(),
                array_values($configuration->providers()),
            ),
            'hosts' => array_map(
                static fn ($host): array => $host->toRedactedArray(),
                array_values($configuration->hosts()),
            ),
        ];

        if ($this->wantsJson($input)) {
            $this->json($output)->document($payload);

            return self::SUCCESS;
        }

        $io = $this->style($input, $output);

        $io->section('Server');
        $io->definitionList(...array_map(
            static fn (string $key, mixed $value): array => [$key => self::describe($value)],
            array_keys($payload['server']),
            array_values($payload['server']),
        ));

        $io->section('Providers');
        $io->table(
            ['Name', 'Driver', 'Token', 'Options'],
            array_map(static fn (array $p): array => [
                self::describe($p['name'] ?? ''),
                self::describe($p['driver'] ?? ''),
                self::describe($p['token'] ?? ''),
                self::describe($p['options'] ?? []),
            ], $payload['providers']),
        );

        $io->section('Hosts');
        $io->table(
            ['Name', 'FQDN', 'Provider', 'Types', 'TTL', 'Token'],
            array_map(static fn (array $h): array => [
                self::describe($h['name'] ?? ''),
                self::describe($h['fqdn'] ?? ''),
                self::describe($h['provider'] ?? ''),
                self::describe($h['types'] ?? []),
                self::describe($h['ttl'] ?? ''),
                self::describe($h['token'] ?? ''),
            ], $payload['hosts']),
        );

        $io->writeln(sprintf('<comment>%s</comment>', $this->configPath()));

        return self::SUCCESS;
    }

    private static function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return $value === [] ? '-' : implode(', ', array_map(self::describe(...), $value));
        }

        if ($value === null) {
            return '-';
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
