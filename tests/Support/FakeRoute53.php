<?php

declare(strict_types=1);

namespace Ddns\Tests\Support;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Route53\Route53Client;
use GuzzleHttp\Psr7\Response;

/**
 * A Route53 client backed by the SDK's MockHandler.
 *
 * The AWS SDK carries its own HTTP stack rather than the PSR-18 client the
 * other drivers share, so it needs its own test double to keep the suite
 * offline.
 */
final class FakeRoute53
{
    private readonly MockHandler $handler;

    /** @var list<array{name: string, args: array<string, mixed>}> */
    private array $calls = [];

    public function __construct()
    {
        $this->handler = new MockHandler();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function queueResult(array $data): self
    {
        $this->handler->append(
            /** @param Command<array<string, mixed>> $command */
            function (Command $command) use ($data): Result {
                $this->record($command);

                return new Result($data);
            },
        );

        return $this;
    }

    /**
     * Queue an AWS-side failure, identified by its error code.
     */
    public function queueError(string $awsErrorCode, int $status = 400, string $message = 'failed'): self
    {
        $this->handler->append(
            /** @param Command<array<string, mixed>> $command */
            function (Command $command) use ($awsErrorCode, $status, $message): never {
                $this->record($command);

                throw new AwsException($message, $command, [
                    'code' => $awsErrorCode,
                    'message' => $message,
                    'response' => new Response($status),
                ]);
            },
        );

        return $this;
    }

    /**
     * Queue a hosted-zone lookup answering with the given zones.
     *
     * @param list<array{0: string, 1: string, 2?: bool}> $zones id, name, isPrivate
     */
    public function queueZoneLookup(array $zones): self
    {
        return $this->queueResult([
            'HostedZones' => array_map(
                static fn (array $zone): array => [
                    'Id' => '/hostedzone/' . $zone[0],
                    'Name' => rtrim($zone[1], '.') . '.',
                    'Config' => ['PrivateZone' => $zone[2] ?? false],
                ],
                $zones,
            ),
        ]);
    }

    /**
     * Queue a record-set listing.
     *
     * @param list<array<string, mixed>> $sets
     */
    public function queueRecordSets(array $sets): self
    {
        return $this->queueResult(['ResourceRecordSets' => $sets]);
    }

    /**
     * Queue the response Route53 gives to a successful change.
     */
    public function queueChangeAccepted(): self
    {
        return $this->queueResult([
            'ChangeInfo' => ['Id' => '/change/C123', 'Status' => 'PENDING'],
        ]);
    }

    public function client(): Route53Client
    {
        return new Route53Client([
            'region' => 'us-east-1',
            'version' => '2013-04-01',
            'credentials' => ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'test-secret'],
            'handler' => $this->handler,
            // Retries would silently replay queued responses and make call
            // counts meaningless.
            'retries' => 0,
        ]);
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function operationAt(int $index): string
    {
        return $this->callAt($index)['name'];
    }

    /**
     * @return array<string, mixed>
     */
    public function argumentsAt(int $index): array
    {
        return $this->callAt($index)['args'];
    }

    /**
     * A dotted path into a recorded call's arguments, e.g.
     * `ChangeBatch.Changes.0.Action`.
     */
    public function argAt(int $index, string $path): mixed
    {
        $current = $this->argumentsAt($index);

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new \OutOfBoundsException(sprintf(
                    'AWS call %d has no argument "%s".',
                    $index,
                    $path,
                ));
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return array_map(static fn (array $call): string => $call['name'], $this->calls);
    }

    /**
     * @return array{name: string, args: array<string, mixed>}
     */
    private function callAt(int $index): array
    {
        return $this->calls[$index]
            ?? throw new \OutOfBoundsException(sprintf('No AWS call was made at index %d.', $index));
    }

    /**
     * @param Command<array<string, mixed>> $command
     */
    private function record(Command $command): void
    {
        /** @var array<string, mixed> $args */
        $args = $command->toArray();

        $this->calls[] = ['name' => $command->getName(), 'args' => $args];
    }
}
