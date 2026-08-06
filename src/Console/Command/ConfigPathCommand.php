<?php

declare(strict_types=1);

namespace Ddns\Console\Command;

use Ddns\Console\AbstractDdnsCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports which configuration file is actually in use.
 *
 * Four locations are searched and two environment variables can override the
 * result, so "which file am I editing?" is a real question - particularly
 * under a web server, where the answer depends on the process rather than the
 * shell.
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
        // Resolving the path is enough; the file's contents are not needed and
        // an invalid file should not stop this from answering.
        $path = $this->configPath();

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
}
