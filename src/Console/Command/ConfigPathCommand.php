<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Bootstrap;
use Ddns\Config\Exception\ConfigurationError;
use Ddns\Console\AbstractDdnsCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports which configuration file is actually in use.
 *
 * `DDNS_CONFIG` can move it and `--config` can override that, so "which file
 * am I editing?" is a real question - particularly under a web server, where
 * the answer depends on the process rather than on the shell.
 */
#[AsCommand(
    name: 'config:path',
    description: 'Print the path of the configuration file in use.',
)]
final class ConfigPathCommand extends AbstractDdnsCommand
{
    protected function configure(): void
    {
        $this->addJsonOption('Emit JSON instead of the bare path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->pathInUse();

        if ($this->wantsJson($input)) {
            $this->json($output)->document([
                'path' => $path,
                'exists' => is_file($path),
                'readable' => is_readable($path),
                'writable' => is_writable($path),
            ]);

            return self::SUCCESS;
        }

        // The bare path on stdout, so it composes: $EDITOR $(ddns config:path)
        $output->writeln($path);

        return self::SUCCESS;
    }

    /**
     * Where the configuration is, or would be.
     *
     * Resolving the path does not read the file, so this answers even when the
     * contents are broken. It also answers when there is no file at all, which
     * every other command treats as fatal: here the missing file is the thing
     * being asked about, and `exists` below is only meaningful if it can be
     * false. Without this, the documented `$EDITOR "$(ddns config:path)"` had
     * nothing to open on the one occasion you would reach for it.
     */
    private function pathInUse(): string
    {
        try {
            return $this->configPath();
        } catch (ConfigurationError) {
            return Bootstrap::intendedConfigPath();
        }
    }
}
