<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Config\ConfigPath;
use Ddns\Console\AbstractConfigMutationCommand;
use Ddns\Console\EnvFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `hosts:remove` — stop keeping a name in sync.
 *
 * Removes the entry from the configuration and nothing else. The DNS record
 * itself is left exactly as it is: this server stops updating it, which is a
 * different thing from deleting it, and deleting someone's record because they
 * tidied a config file would be unforgivable.
 */
#[AsCommand(
    name: 'hosts:remove',
    description: 'Remove a host from the configuration.',
)]
final class HostsRemoveCommand extends AbstractConfigMutationCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of human-readable output.');
        $this->addForceOption('Do not ask for confirmation, and do not ask before discarding comments.');

        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The host to remove.')
            ->setHelp(<<<'HELP'
                The DNS record is not touched. This server simply stops keeping
                it up to date, which is deliberately not the same as deleting
                it at the provider.

                Any token in .env is left alone too, since that file is
                hand-edited and a variable may be shared. The one that is now
                unused is named, so you can remove it yourself.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);

        $name = trim($this->stringArgument($input, 'name'));
        $raw = $this->readRawConfig();
        $existing = ConfigPath::get($raw, 'hosts.' . $name);

        if (!$existing['found']) {
            $io->error(sprintf('No host called "%s".', $name));

            return self::FAILURE;
        }

        $hosts = $raw['hosts'] ?? [];

        if (!is_array($hosts)) {
            $io->error('"hosts" is not a mapping, so there is nothing to remove from it.');

            return self::INVALID;
        }

        if (count($hosts) === 1) {
            // The loader insists on at least one, so this would produce a file
            // the server refuses to start with.
            $io->error(sprintf(
                '"%s" is the only configured host, and at least one is required. '
                . 'Add another first, or edit %s directly.',
                $name,
                $this->configPath(),
            ));

            return self::INVALID;
        }

        if (!$this->confirmRemoval($input, $io, $name)) {
            return self::INVALID;
        }

        unset($hosts[$name]);
        $raw['hosts'] = $hosts;

        $problem = $this->validationProblem($raw);

        if ($problem !== null) {
            $io->error($problem);
            $io->note('Nothing was written.');

            return self::INVALID;
        }

        if (!$this->confirmCommentLoss($io, $input, $this->configPath())) {
            return self::INVALID;
        }

        $this->saveConfig($raw);

        $orphan = $this->orphanedVariable($existing['value'], $raw);

        if ($this->wantsJson($input)) {
            $this->json($output)->document([
                'host' => $name,
                'removed' => true,
                'unused_variable' => $orphan,
            ]);

            return self::SUCCESS;
        }

        $io->success(sprintf('Removed "%s". The DNS record itself was not touched.', $name));

        if ($orphan !== null) {
            $io->text(sprintf('%s in %s is now unused, if you want to delete it.', $orphan, $this->envPath()));
        }

        return self::SUCCESS;
    }

    private function confirmRemoval(InputInterface $input, \Symfony\Component\Console\Style\SymfonyStyle $io, string $name): bool
    {
        if ($input->getOption('force') === true || !$input->isInteractive()) {
            return true;
        }

        return $io->confirm(sprintf('Stop keeping "%s" up to date?', $name), false);
    }

    /**
     * The variable this host's token came from, when nothing else refers to it.
     *
     * Reported rather than deleted: `.env` is hand-edited, and a variable two
     * hosts share is not this command's to remove.
     *
     * @param array<array-key, mixed> $remaining
     */
    private function orphanedVariable(mixed $removed, array $remaining): ?string
    {
        if (!is_array($removed)) {
            return null;
        }

        $token = $removed['token'] ?? null;

        if (!is_string($token) || preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/', trim($token), $matches) !== 1) {
            return null;
        }

        $variable = $matches[1];

        if (!array_key_exists($variable, EnvFileWriter::read($this->envPath()))) {
            return null;
        }

        return str_contains(json_encode($remaining, JSON_THROW_ON_ERROR), '${' . $variable . '}')
            ? null
            : $variable;
    }
}
