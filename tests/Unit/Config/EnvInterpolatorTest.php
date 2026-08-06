<?php

declare(strict_types=1);

namespace Ddns\Tests\Unit\Config;

use Ddns\Config\EnvInterpolator;
use Ddns\Config\Environment;
use Ddns\Config\Exception\ConfigurationError;
use PHPUnit\Framework\TestCase;

final class EnvInterpolatorTest extends TestCase
{
    public function testSubstitutesAVariable(): void
    {
        self::assertSame('s3cr3t', $this->expand('${SECRET}', ['SECRET' => 's3cr3t']));
    }

    public function testSubstitutesNestedValues(): void
    {
        $result = $this->interpolate(
            ['providers' => ['do' => ['token' => '${DO_TOKEN}']]],
            ['DO_TOKEN' => 'abc'],
        );

        self::assertSame(['providers' => ['do' => ['token' => 'abc']]], $result);
    }

    public function testSubstitutesInsideALargerString(): void
    {
        self::assertSame(
            'https://api.example.com/v2',
            $this->expand('https://${HOST}/v2', ['HOST' => 'api.example.com']),
        );
    }

    public function testSubstitutesSeveralVariablesInOneValue(): void
    {
        self::assertSame('one-two', $this->expand('${A}-${B}', ['A' => 'one', 'B' => 'two']));
    }

    public function testUsesTheColonDashDefaultWhenUnset(): void
    {
        self::assertSame('fallback', $this->expand('${MISSING:-fallback}'));
    }

    public function testUsesTheDashDefaultWhenUnset(): void
    {
        self::assertSame('fallback', $this->expand('${MISSING-fallback}'));
    }

    public function testPrefersTheRealValueOverTheDefault(): void
    {
        self::assertSame('real', $this->expand('${SET:-fallback}', ['SET' => 'real']));
    }

    public function testTreatsAnEmptyVariableAsUnsetForDefaulting(): void
    {
        self::assertSame('fallback', $this->expand('${EMPTY:-fallback}', ['EMPTY' => '']));
    }

    public function testAllowsAnEmptyDefault(): void
    {
        self::assertSame('', $this->expand('${MISSING:-}'));
    }

    /**
     * A silently empty credential would fail much later with a confusing 401,
     * so an unset variable with no default is an error at load time.
     */
    public function testThrowsWhenAVariableIsUnsetAndHasNoDefault(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('environment variable "MISSING"');

        $this->interpolate(['providers' => ['do' => ['token' => '${MISSING}']]]);
    }

    public function testTheErrorNamesTheOffendingKeyPath(): void
    {
        try {
            $this->interpolate(['providers' => ['do' => ['token' => '${MISSING}']]]);
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $e) {
            self::assertStringContainsString('providers.do.token', $e->getMessage());
        }
    }

    public function testLeavesNonStringValuesAlone(): void
    {
        self::assertSame(
            ['ttl' => 300, 'on' => true, 'nothing' => null],
            $this->interpolate(['ttl' => 300, 'on' => true, 'nothing' => null]),
        );
    }

    public function testLeavesPlainStringsUntouched(): void
    {
        self::assertSame('example.com', $this->expand('example.com'));
    }

    public function testIgnoresAMalformedPlaceholder(): void
    {
        self::assertSame('${ }', $this->expand('${ }'));
    }

    /**
     * Expand a single scalar value.
     *
     * @param array<string, string> $env
     */
    private function expand(string $value, array $env = []): mixed
    {
        return $this->interpolate(['v' => $value], $env)['v'] ?? null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string>   $env
     *
     * @return array<array-key, mixed>
     */
    private function interpolate(array $data, array $env = []): array
    {
        return (new EnvInterpolator(new Environment($env)))->interpolate($data);
    }
}
