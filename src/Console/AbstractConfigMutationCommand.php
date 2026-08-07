<?php

declare(strict_types=1);

namespace Ddns\Console;

use Ddns\Config\ConfigFile;
use Ddns\Config\ConfigLoader;
use Ddns\Config\EnvInterpolator;
use Ddns\Config\Environment;
use Ddns\Config\Exception\ConfigurationError;
use Ddns\Provider\ProviderFactories;
use Ddns\Support\Services;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared behaviour for the commands that rewrite the configuration file.
 *
 * Every one of them owes the reader the same three guarantees, and they are
 * easy to get subtly different if each command implements them itself:
 *
 *  - what is written always loads, because it is validated first;
 *  - comments are not discarded without being asked about;
 *  - a secret goes to `.env` as a `${VAR}` placeholder, never into the file.
 */
abstract class AbstractConfigMutationCommand extends AbstractDdnsCommand
{
    /** 24 bytes hex-encoded, comfortably above the loader's 12-character floor. */
    private const TOKEN_BYTES = 24;

    protected function addForceOption(string $description = 'Do not ask before discarding comments.'): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, $description);
    }

    /**
     * The file as written, with no interpolation.
     *
     * Never the loaded configuration: saving that back would replace every
     * `${VAR}` with the secret it resolved to.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationError
     */
    protected function readRawConfig(): array
    {
        return ConfigFile::read($this->configPath());
    }

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, string>   $pendingSecrets values not yet written to `.env`
     *
     * @return string|null the reason it would not load, or null when it will
     */
    protected function validationProblem(array $config, array $pendingSecrets = []): ?string
    {
        $loader = new ConfigLoader(
            new EnvInterpolator(Environment::fromGlobals()->with($pendingSecrets)),
            $this->service(ProviderFactories::class)->catalog(),
        );

        try {
            $loader->fromArray($config);
        } catch (ConfigurationError $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Rewriting the file loses its comments, which is destructive enough to
     * confirm first. A file `config:init` wrote passes silently, because its
     * header is re-emitted.
     */
    protected function confirmCommentLoss(SymfonyStyle $io, InputInterface $input, string $path): bool
    {
        if ($input->getOption('force') === true) {
            return true;
        }

        if (!ConfigFile::hasComments((string) file_get_contents($path))) {
            return true;
        }

        if (!$input->isInteractive()) {
            $io->error(sprintf('%s contains comments, which rewriting would discard. Pass --force to proceed.', $path));

            return false;
        }

        $io->warning(sprintf('Rewriting %s will discard its comments.', $path));

        return $io->confirm('Continue?', false);
    }

    /**
     * A secret nobody has a reason to choose.
     */
    protected function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * `home` + `token` becomes `HOME_TOKEN`, suffixed when that name is taken.
     *
     * Two secrets behind one variable would silently give one of them the
     * other's value, so an existing name is never reused.
     *
     * @param array<string, string> $alsoTaken names claimed in this run but not yet written
     */
    protected function environmentVariableName(string $context, string $key, array $alsoTaken = []): string
    {
        $base = trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $context . '_' . $key) ?? ''), '_');
        $taken = array_merge(array_keys(EnvFileWriter::read($this->envPath())), array_keys($alsoTaken));

        if (!in_array($base, $taken, true)) {
            return $base;
        }

        for ($suffix = 2; ; ++$suffix) {
            $candidate = $base . '_' . $suffix;

            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * Only the project's own `.env` is loaded at runtime, so that is the only
     * place a `${VAR}` placeholder can be resolved from.
     */
    protected function envPath(): string
    {
        return Services::string($this->container, 'env.path');
    }

    /**
     * Write the configuration, and any secrets it now refers to.
     *
     * The secrets go first: a configuration naming a variable that does not
     * exist yet is one the server refuses to load.
     *
     * `$replace` names the variables that may overwrite an existing value.
     * Everything else is only ever added, because replacing a credential
     * somebody is using is not something to do as a side effect - but a
     * rotation that quietly kept the old secret would be worse, since it
     * reports success while changing nothing.
     *
     * @param array<array-key, mixed> $config
     * @param array<string, string>   $secrets
     * @param list<string>            $replace
     *
     * @throws ConfigurationError
     */
    protected function saveConfig(array $config, array $secrets = [], array $replace = []): void
    {
        if ($secrets !== []) {
            EnvFileWriter::apply($this->envPath(), $secrets, $replace);
        }

        ConfigFile::write($this->configPath(), $config);
    }
}
