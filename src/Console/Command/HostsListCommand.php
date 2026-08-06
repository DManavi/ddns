<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\HostConfig;
use Ddns\Console\AbstractDdnsCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hosts:list',
    description: 'List every configured host, with tokens redacted.',
)]
final class HostsListCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of a table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $json = $this->wantsJson($input);
        $hosts = $this->configuration()->hosts();

        if ($hosts === []) {
            // An empty list is still a valid answer, so JSON callers get one
            // rather than a warning that would corrupt the document.
            if ($json) {
                $this->json($output)->document(['hosts' => []]);
            } else {
                $io->warning('No hosts are configured.');
            }

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (HostConfig $host): array => $host->toRedactedArray(),
            array_values($hosts),
        );

        if ($json) {
            $this->json($output)->document(['hosts' => $rows]);

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
