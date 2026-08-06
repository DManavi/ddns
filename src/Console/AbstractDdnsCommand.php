<?php

declare(strict_types=1);

namespace Ddns\Console;

use Ddns\Config\Configuration;
use Ddns\Config\HostConfig;
use Ddns\Support\Services;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared host-selection behaviour for the commands that act on hosts.
 *
 * Services are pulled from the container inside `execute()` rather than
 * injected into the constructor: building a command must not require a valid
 * configuration file, otherwise `ddns config:validate` could never report that
 * the file is broken.
 */
abstract class AbstractDdnsCommand extends Command
{
    public function __construct(protected readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configuration(): Configuration
    {
        return Services::get($this->container, Configuration::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        return Services::get($this->container, $id);
    }

    /**
     * An integer option, falling back when it is absent or not numeric.
     */
    protected function intOption(InputInterface $input, string $name, int $fallback): int
    {
        $value = $input->getOption($name);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) $value : $fallback;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Resolve the hosts named on the command line, or all of them with `--all`.
     *
     * @return list<HostConfig>|null null when the selection was invalid, having
     *                               already reported the problem
     */
    protected function selectHosts(InputInterface $input, SymfonyStyle $io): ?array
    {
        $configuration = $this->configuration();

        $argument = $input->getArgument('host');
        $names = is_array($argument) ? array_values(array_filter($argument, 'is_string')) : [];
        $all = $input->getOption('all') === true;

        if ($all && $names !== []) {
            $io->error('Pass either specific host names or --all, not both.');

            return null;
        }

        if ($all || $names === []) {
            if (!$all) {
                $io->writeln(
                    '<comment>No hosts given; updating all configured hosts. Pass --all to be explicit.</comment>',
                );
            }

            return array_values($configuration->hosts());
        }

        $hosts = [];
        $unknown = [];

        foreach ($names as $name) {
            $host = $configuration->findHost($name);

            if ($host === null) {
                $unknown[] = $name;

                continue;
            }

            $hosts[] = $host;
        }

        if ($unknown !== []) {
            $io->error(sprintf(
                'Unknown host(s): %s. Configured hosts: %s.',
                implode(', ', $unknown),
                implode(', ', $configuration->hostNames()) ?: '(none)',
            ));

            return null;
        }

        return $hosts;
    }
}
