<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\HostConfig;
use Ddns\Console\AbstractDdnsCommand;
use Ddns\Domain\Record\RecordType;
use Ddns\Domain\Update\DdnsUpdater;
use Ddns\Ip\HttpIpResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'watch',
    description: 'Poll for public IP changes and update records when one is detected.',
)]
final class WatchCommand extends AbstractDdnsCommand
{
    private bool $stopRequested = false;

    protected function configure(): void
    {
        $this->addJsonOption('Emit one JSON object per line (NDJSON) as events happen.');

        $this
            ->addArgument('host', InputArgument::IS_ARRAY, 'Host names to watch; omit to watch all.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Watch every configured host.')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between polls.', '300')
            ->addOption(
                'force-after',
                null,
                InputOption::VALUE_REQUIRED,
                'Reconcile with the provider after this many unchanged polls, to repair drift.',
                '12',
            )
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run a single cycle and exit. Useful for cron.')
            ->setHelp(<<<'HELP'
                Watches the public IP address and only contacts the provider when it changes.

                Between changes each poll costs one lookup against an echo service and no
                provider API calls at all, so a short interval stays well clear of provider
                rate limits. Every <info>--force-after</info> unchanged polls the records are
                reconciled anyway, so drift introduced elsewhere is repaired.

                State is held in memory only, so a restart always performs one reconcile.

                  <info>ddns watch --all --interval 60</info>
                  <info>ddns watch home --once</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $json = $this->wantsJson($input) ? $this->json($output) : null;

        $hosts = $this->selectHosts($input, $io);

        if ($hosts === null) {
            return self::INVALID;
        }

        if ($hosts === []) {
            $json?->event(['event' => 'stopped', 'reason' => 'no-hosts']);
            $io->warning('No hosts are configured.');

            return self::SUCCESS;
        }

        $interval = max(5, $this->intOption($input, 'interval', 300));
        $forceAfter = max(0, $this->intOption($input, 'force-after', 12));
        $once = $input->getOption('once') === true;

        $resolver = $this->service(HttpIpResolver::class);
        $updater = $this->service(DdnsUpdater::class);

        $types = $this->watchedTypes($hosts);

        $this->installSignalHandlers($io);

        if ($json !== null) {
            // A leading event tells a consumer the watcher is up and what it
            // is tracking, without it having to parse the human banner.
            $json->event([
                'event' => 'started',
                'hosts' => array_map(static fn (HostConfig $h): string => $h->name(), $hosts),
                'types' => array_map(static fn (RecordType $t): string => $t->value, $types),
                'interval' => $interval,
                'once' => $once,
            ]);
        } else {
            $io->success(sprintf(
                'Watching %d host(s) every %ds. Tracking: %s. Press Ctrl+C to stop.',
                count($hosts),
                $interval,
                implode(', ', array_map(static fn (RecordType $t): string => $t->value, $types)),
            ));
        }

        /** @var array<string, string> $lastSeen */
        $lastSeen = [];
        $unchangedPolls = 0;
        $consecutiveFailures = 0;

        while (!$this->stopRequested) {
            // Memoisation is per cycle: it stops several hosts triggering
            // several lookups, without hiding a change from the next cycle.
            $resolver->forget();

            $current = [];

            foreach ($types as $type) {
                $address = $resolver->tryResolve($type);

                if ($address !== null) {
                    $current[$type->value] = $address->value();
                }
            }

            $changed = $current !== $lastSeen;
            $forced = $forceAfter > 0 && $unchangedPolls >= $forceAfter;

            if ($changed || $forced) {
                $reports = $updater->updateMany($hosts, $resolver);
                $failed = false;

                foreach ($reports as $report) {
                    foreach ($report->records() as $record) {
                        $isFailure = $record->outcome()->isFailure();

                        if ($isFailure) {
                            $failed = true;
                        }

                        if (!$isFailure && !$record->outcome()->isChange()) {
                            continue;
                        }

                        if ($json !== null) {
                            $json->event([
                                'event' => $record->outcome()->value,
                                'host' => $report->host(),
                                'fqdn' => $report->fqdn(),
                            ] + $record->toArray());

                            continue;
                        }

                        $io->writeln(sprintf(
                            '<%1$s>%2$s %3$s %4$s</%1$s>',
                            $isFailure ? 'error' : 'info',
                            $this->timestamp(),
                            $report->host(),
                            $record->describe(),
                        ));
                    }
                }

                if ($failed) {
                    // Leave $lastSeen untouched so the next cycle retries
                    // instead of assuming the change landed.
                    ++$consecutiveFailures;
                } else {
                    $lastSeen = $current;
                    $unchangedPolls = 0;
                    $consecutiveFailures = 0;
                }
            } else {
                ++$unchangedPolls;

                // Quiet by default either way: a poll that found nothing is
                // noise unless you asked to see it.
                if ($json !== null) {
                    if ($output->isVerbose()) {
                        $json->event(['event' => 'unchanged', 'addresses' => $current]);
                    }
                } else {
                    $io->writeln(
                        sprintf('%s no change (%s)', $this->timestamp(), $this->describe($current)),
                        OutputInterface::VERBOSITY_VERBOSE,
                    );
                }
            }

            if ($once) {
                return $consecutiveFailures > 0 ? self::FAILURE : self::SUCCESS;
            }

            $this->sleep($this->delay($interval, $consecutiveFailures));
        }

        if ($json !== null) {
            $json->event(['event' => 'stopped', 'reason' => 'signal']);
        } else {
            $io->writeln('');
            $io->success('Stopped.');
        }

        return self::SUCCESS;
    }

    /**
     * Back off exponentially while a provider keeps failing, capped so recovery
     * is never more than an hour away.
     */
    private function delay(int $interval, int $consecutiveFailures): int
    {
        if ($consecutiveFailures === 0) {
            return $interval;
        }

        return (int) min(3600, $interval * 2 ** min($consecutiveFailures, 6));
    }

    /**
     * Sleep in short slices so a signal is acted on promptly rather than at the
     * end of a potentially very long interval.
     */
    private function sleep(int $seconds): void
    {
        $deadline = time() + $seconds;

        while (!$this->stopRequested && time() < $deadline) {
            sleep(1);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    private function installSignalHandlers(SymfonyStyle $io): void
    {
        if (!function_exists('pcntl_signal')) {
            $io->note('ext-pcntl is unavailable, so shutdown will not be graceful.');

            return;
        }

        $handler = function (): void {
            $this->stopRequested = true;
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    /**
     * The union of the record types any watched host cares about.
     *
     * @param list<HostConfig> $hosts
     *
     * @return list<RecordType>
     */
    private function watchedTypes(array $hosts): array
    {
        $types = [];

        foreach ($hosts as $host) {
            foreach ($host->recordTypes() as $type) {
                if (!in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * @param array<string, string> $addresses
     */
    private function describe(array $addresses): string
    {
        if ($addresses === []) {
            return 'no address available';
        }

        $parts = [];

        foreach ($addresses as $type => $value) {
            $parts[] = $type . '=' . $value;
        }

        return implode(' ', $parts);
    }

    private function timestamp(): string
    {
        return '[' . date('Y-m-d H:i:s') . ']';
    }
}
