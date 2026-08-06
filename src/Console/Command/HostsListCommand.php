<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\HostConfig;
use Ddns\Console\AbstractDdnsCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'hosts:list',
    description: 'List every configured host, with tokens redacted.',
)]
final class HostsListCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON instead of a table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hosts = $this->configuration()->hosts();

        if ($hosts === []) {
            $io->warning('No hosts are configured.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (HostConfig $host): array => $host->toRedactedArray(),
            array_values($hosts),
        );

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $io->table(
            ['Host', 'FQDN', 'Provider', 'Types', 'TTL', 'Token'],
            array_map(
                static fn (array $row): array => [
                    $row['name'],
                    $row['fqdn'],
                    $row['provider'],
                    implode(', ', $row['types']),
                    (string) $row['ttl'],
                    $row['token'],
                ],
                $rows,
            ),
        );

        return self::SUCCESS;
    }
}
