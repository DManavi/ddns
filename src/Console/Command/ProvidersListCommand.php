<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Console\AbstractDdnsCommand;
use Ddns\Provider\ProviderFactories;
use Ddns\Provider\ProviderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'providers:list',
    description: 'List every DNS driver this build knows about.',
)]
final class ProvidersListCommand extends AbstractDdnsCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $factories = $this->service(ProviderFactories::class)->all();

        $rows = array_map(
            static fn (ProviderFactory $factory): array => [
                $factory->driver(),
                $factory->description(),
                $factory->isAvailable() ? '<info>available</info>' : '<comment>unavailable</comment>',
                $factory->unavailableReason() ?? '',
            ],
            $factories,
        );

        $io->table(['Driver', 'Description', 'Status', 'Reason'], $rows);
        $io->writeln('Use the driver name as <info>driver:</info> under <info>providers:</info> in your config file.');

        return self::SUCCESS;
    }
}
