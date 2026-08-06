<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\ConfigLoader;
use Ddns\Config\Exception\ConfigurationError;
use Ddns\Console\AbstractDdnsCommand;
use Ddns\Domain\Record\RecordType;
use Ddns\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'config:validate',
    description: 'Parse and validate the configuration file without contacting any provider.',
)]
final class ConfigValidateCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit a JSON report instead of human-readable output.');

        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Config file to check; defaults to the discovered one.')
            ->setHelp(
                'Reports every problem it finds in one pass, so a broken file does not have to be '
                . "fixed one error at a time.\n"
                . 'Contacts no provider and reveals no secrets, so it is safe to run anywhere.',
            );
    }

    /**
     * The error message is a heading followed by one `  - ` bullet per problem.
     * Splitting it back out gives a caller a list it can iterate.
     *
     * @return list<string>
     */
    private static function splitProblems(string $message): array
    {
        $problems = [];

        foreach (explode("\n", $message) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '- ')) {
                $problems[] = substr($line, 2);
            }
        }

        return $problems === [] ? [trim($message)] : $problems;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $json = $this->wantsJson($input);

        $file = $input->getArgument('file');
        $path = is_string($file) && $file !== ''
            ? $file
            : Services::string($this->container, 'config.path');

        try {
            $configuration = $this->service(ConfigLoader::class)->load($path);
        } catch (ConfigurationError $e) {
            if ($json) {
                // The problems are what a caller wants: report them as a list
                // rather than one blob of prose.
                $this->json($output)->document([
                    'file' => $path,
                    'valid' => false,
                    'problems' => self::splitProblems($e->getMessage()),
                ]);
            } else {
                $io->error($e->getMessage());
            }

            return self::INVALID;
        }

        if ($json) {
            $this->json($output)->document([
                'file' => $path,
                'valid' => true,
                'problems' => [],
                'server' => $configuration->server()->toArray(),
                'providers' => array_map(
                    static fn ($provider): array => $provider->toRedactedArray(),
                    array_values($configuration->providers()),
                ),
                'hosts' => array_map(
                    static fn ($host): array => $host->toRedactedArray(),
                    array_values($configuration->hosts()),
                ),
            ]);

            return self::SUCCESS;
        }

        $io->success(sprintf('%s is valid.', $path));

        $io->definitionList(
            ['Providers' => (string) count($configuration->providers())],
            ['Hosts' => (string) count($configuration->hosts())],
            ['Default TTL' => $configuration->server()->defaultTtl() . 's'],
            [
                'Trusted proxies' => $configuration->server()->trustsAnyProxy()
                    ? implode(', ', $configuration->server()->trustedProxies())
                    : 'none (X-Forwarded-For is ignored)',
            ],
            ['Private IPs' => $configuration->server()->allowPrivateIps() ? 'allowed' : 'refused'],
        );

        $rows = [];

        foreach ($configuration->hosts() as $host) {
            $rows[] = [
                $host->name(),
                $host->hostname()->fqdn(),
                $host->providerName(),
                implode(', ', array_map(static fn (RecordType $t): string => $t->value, $host->recordTypes())),
                $host->ttl() . 's',
            ];
        }

        $io->table(['Host', 'FQDN', 'Provider', 'Types', 'TTL'], $rows);

        if (!$configuration->server()->trustsAnyProxy()) {
            $io->note(
                'No trusted proxies configured. If this server sits behind a reverse proxy, set '
                . 'server.trusted_proxies or every update will record the proxy address instead of the client.',
            );
        }

        return self::SUCCESS;
    }
}
