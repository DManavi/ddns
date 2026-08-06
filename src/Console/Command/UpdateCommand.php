<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Console\AbstractDdnsCommand;
use Ddns\Domain\Update\DdnsUpdater;
use Ddns\Domain\Update\UpdateReport;
use Ddns\Ip\ChainIpResolver;
use Ddns\Ip\HttpIpResolver;
use Ddns\Ip\IpResolver;
use Ddns\Ip\StaticIpResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'update',
    description: 'Update one or more hosts to the current public IP address.',
)]
final class UpdateCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit a JSON report instead of a table.');

        $this
            ->addArgument('host', InputArgument::IS_ARRAY, 'Host names to update; omit to update all.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Update every configured host.')
            ->addOption(
                'ip',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Use this address instead of looking one up. Repeat for both address families.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything.')
            ->setHelp(<<<'HELP'
                Reconciles each configured record with the current public address.

                Records already pointing at the right address are left alone, so this is
                safe to run on a tight schedule.

                  <info>ddns update</info>                       update every host
                  <info>ddns update home office</info>           update two specific hosts
                  <info>ddns update home --ip 203.0.113.7</info> force a specific address
                  <info>ddns update --all --dry-run</info>       preview without writing
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $json = $this->wantsJson($input);

        $hosts = $this->selectHosts($input, $io);

        if ($hosts === null) {
            return self::INVALID;
        }

        if ($hosts === []) {
            if ($json) {
                // Still a valid, parseable result: nothing to do is not an error.
                $this->json($output)->document(['hosts' => [], 'changed' => false, 'failed' => false]);
            } else {
                $io->warning('No hosts are configured.');
            }

            return self::SUCCESS;
        }

        try {
            $resolver = $this->resolver($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return self::INVALID;
        }

        $dryRun = $input->getOption('dry-run') === true;

        if ($dryRun) {
            $io->note('Dry run: no changes will be sent to any provider.');
        }

        $reports = $this->service(DdnsUpdater::class)->updateMany($hosts, $resolver, $dryRun);

        if ($json) {
            $this->renderJson($output, $reports, $dryRun);
        } else {
            $this->render($io, $reports);
        }

        foreach ($reports as $report) {
            if (!$report->isSuccessful()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function resolver(InputInterface $input): IpResolver
    {
        $explicit = $this->stringListOption($input, 'ip');

        if ($explicit !== []) {
            return StaticIpResolver::fromStrings($explicit);
        }

        return new ChainIpResolver($this->service(HttpIpResolver::class));
    }

    /**
     * The same report shape the HTTP API returns, so a script consuming one
     * front-end can consume the other without a second parser.
     *
     * @param list<UpdateReport> $reports
     */
    private function renderJson(OutputInterface $output, array $reports, bool $dryRun): void
    {
        $failed = false;
        $changed = false;

        foreach ($reports as $report) {
            $failed = $failed || !$report->isSuccessful();
            $changed = $changed || $report->hasChanges();
        }

        $this->json($output)->document([
            // Two summary flags so a caller can branch without walking the
            // list; the exit code carries the same verdict as `failed`.
            'changed' => $changed,
            'failed' => $failed,
            'dry_run' => $dryRun,
            'hosts' => array_map(static fn (UpdateReport $r): array => $r->toArray(), $reports),
        ]);
    }

    /**
     * @param list<UpdateReport> $reports
     */
    private function render(SymfonyStyle $io, array $reports): void
    {
        $rows = [];

        foreach ($reports as $report) {
            foreach ($report->records() as $record) {
                $rows[] = [
                    $report->host(),
                    $report->fqdn(),
                    $record->type()->value,
                    $this->colour($record->outcome()->value),
                    $record->ip()?->value() ?? '-',
                    $record->reason() ?? '',
                ];
            }
        }

        $io->table(['Host', 'FQDN', 'Type', 'Status', 'Address', 'Detail'], $rows);
    }

    private function colour(string $status): string
    {
        return match ($status) {
            'created', 'updated' => sprintf('<info>%s</info>', $status),
            'failed' => sprintf('<error>%s</error>', $status),
            'skipped' => sprintf('<comment>%s</comment>', $status),
            default => $status,
        };
    }
}
