<?php

declare(strict_types=1);

namespace Ddns\Console;

use Ddns\Console\Command\ConfigInitCommand;
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
    public const VERSION = '1.0.0';

    public static function create(ContainerInterface $container): Application
    {
        $application = new Application('ddns', self::VERSION);

        $application->addCommands([
            new UpdateCommand($container),
            new WatchCommand($container),
            new HostsListCommand($container),
            new ConfigInitCommand($container),
            new ConfigValidateCommand($container),
            new ProvidersListCommand($container),
        ]);

        return $application;
    }
}
