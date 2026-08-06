<?php

declare(strict_types=1);

namespace Ddns\Console;

use Ddns\Bootstrap;
use Ddns\Console\Command\ConfigGetCommand;
use Ddns\Console\Command\ConfigInitCommand;
use Ddns\Console\Command\ConfigPathCommand;
use Ddns\Console\Command\ConfigSetCommand;
use Ddns\Console\Command\ConfigShowCommand;
use Ddns\Console\Command\ConfigValidateCommand;
use Ddns\Console\Command\HostsListCommand;
use Ddns\Console\Command\ProvidersListCommand;
use Ddns\Console\Command\UpdateCommand;
use Ddns\Console\Command\WatchCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;

/**
 * Assembles the console application.
 */
final class ConsoleApplicationFactory
{
    public const VERSION = Bootstrap::VERSION;

    public static function create(ContainerInterface $container): Application
    {
        $application = new Application('ddns', self::VERSION);

        $application->addCommands([
            new UpdateCommand($container),
            new WatchCommand($container),
            new HostsListCommand($container),
            new ConfigInitCommand($container),
            new ConfigValidateCommand($container),
            new ConfigShowCommand($container),
            new ConfigGetCommand($container),
            new ConfigSetCommand($container),
            new ConfigPathCommand($container),
            new ProvidersListCommand($container),
        ]);

        return $application;
    }
}
