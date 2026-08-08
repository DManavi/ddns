<?php

/**
 * Turn a Clover report into a coverage percentage and a shields.io badge.
 *
 * Dependency-free and outside the application, for the same reason as
 * next-version.php: it runs in a workflow, nothing in `src/` should know about
 * it, and adding `.github` to the PHPStan paths would break `composer check`
 * inside the dev container, which runs against a tree with no `.github` in it.
 * `--self-test` stands in for the test suite, and CI runs it.
 *
 * Usage:
 *   coverage-badge.php --clover=build/coverage.xml
 *   coverage-badge.php --clover=build/coverage.xml --badge=.github/badges/coverage.json
 *   coverage-badge.php --clover=build/coverage.xml --min=70
 *   coverage-badge.php --self-test
 *
 * Writes `key=value` lines on stdout for $GITHUB_OUTPUT:
 *
 *   covered=1234
 *   total=1400
 *   percent=88.1
 *
 * Exits 1 when --min is given and not met.
 */

declare(strict_types=1);

/**
 * Bands for the badge colour. Read as "at least this percent is this colour",
 * highest first.
 */
const COLOURS = [
    90 => 'brightgreen',
    80 => 'green',
    70 => 'yellowgreen',
    60 => 'yellow',
    50 => 'orange',
    0 => 'red',
];

/**
 * Line coverage from a Clover report.
 *
 * Read from the `<metrics>` element of each `<file>` rather than the one on
 * `<project>`, because PHPUnit emits a project total that also counts methods
 * and classes as "elements". Summing the files gives statement coverage, which
 * is what "line coverage" is normally taken to mean.
 *
 * @return array{covered: int, total: int}
 *
 * @throws RuntimeException
 */
function lineCoverage(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('No Clover report at "%s".', $path));
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        throw new RuntimeException(sprintf('"%s" is not readable XML.', $path));
    }

    $covered = 0;
    $total = 0;

    /** @var \SimpleXMLElement $metrics */
    foreach ($xml->xpath('//file/metrics') ?: [] as $metrics) {
        $attributes = $metrics->attributes();

        if ($attributes === null) {
            continue;
        }

        $total += (int) ($attributes['statements'] ?? 0);
        $covered += (int) ($attributes['coveredstatements'] ?? 0);
    }

    return ['covered' => $covered, 'total' => $total];
}

/**
 * One decimal place, rounded down.
 *
 * Down rather than to nearest, so a badge never rounds 89.96 up to 90.0 and
 * claims a threshold the code has not reached.
 */
function percentage(int $covered, int $total): float
{
    if ($total === 0) {
        return 0.0;
    }

    return floor($covered / $total * 1000) / 10;
}

function colourFor(float $percent): string
{
    foreach (COLOURS as $floor => $colour) {
        if ($percent >= $floor) {
            return $colour;
        }
    }

    return 'lightgrey';
}

/**
 * The shields.io endpoint document.
 *
 * @return array<string, mixed>
 */
function badge(float $percent): array
{
    return [
        'schemaVersion' => 1,
        'label' => 'coverage',
        'message' => sprintf('%.1f%%', $percent),
        'color' => colourFor($percent),
    ];
}

function selfTest(): int
{
    $failures = 0;

    $percentages = [
        [0, 0, 0.0],
        [0, 100, 0.0],
        [100, 100, 100.0],
        [1, 3, 33.3],
        [2, 3, 66.6],
        // Rounded down, so a badge cannot claim a band it has not reached.
        [8996, 10000, 89.9],
        [899, 1000, 89.9],
    ];

    foreach ($percentages as [$covered, $total, $expected]) {
        $actual = percentage($covered, $total);

        if (abs($actual - $expected) > 0.001) {
            ++$failures;
            fwrite(STDERR, sprintf("FAIL percentage(%d, %d): expected %s, got %s\n", $covered, $total, $expected, $actual));
        }
    }

    $colours = [
        [100.0, 'brightgreen'],
        [90.0, 'brightgreen'],
        [89.9, 'green'],
        [80.0, 'green'],
        [79.9, 'yellowgreen'],
        [70.0, 'yellowgreen'],
        [69.9, 'yellow'],
        [60.0, 'yellow'],
        [59.9, 'orange'],
        [50.0, 'orange'],
        [49.9, 'red'],
        [0.0, 'red'],
    ];

    foreach ($colours as [$percent, $expected]) {
        $actual = colourFor($percent);

        if ($actual !== $expected) {
            ++$failures;
            fwrite(STDERR, sprintf("FAIL colourFor(%s): expected %s, got %s\n", $percent, $expected, $actual));
        }
    }

    // A real Clover shape, summed across files rather than read off the
    // project total - which counts methods and classes as elements too.
    $clover = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1">
          <project timestamp="1">
            <file name="/app/src/A.php">
              <metrics statements="10" coveredstatements="8" methods="2" coveredmethods="2"/>
            </file>
            <file name="/app/src/B.php">
              <metrics statements="30" coveredstatements="21" methods="4" coveredmethods="3"/>
            </file>
            <metrics files="2" statements="40" coveredstatements="29" elements="46" coveredelements="34"/>
          </project>
        </coverage>
        XML;

    $path = tempnam(sys_get_temp_dir(), 'clover-') ?: throw new RuntimeException('tempnam failed');
    file_put_contents($path, $clover);

    try {
        $result = lineCoverage($path);

        if ($result !== ['covered' => 29, 'total' => 40]) {
            ++$failures;
            fwrite(STDERR, sprintf("FAIL lineCoverage: got %s\n", json_encode($result)));
        }

        if (abs(percentage($result['covered'], $result['total']) - 72.5) > 0.001) {
            ++$failures;
            fwrite(STDERR, "FAIL lineCoverage percentage\n");
        }

        $document = badge(72.5);

        if ($document['message'] !== '72.5%' || $document['color'] !== 'yellowgreen') {
            ++$failures;
            fwrite(STDERR, sprintf("FAIL badge: got %s\n", json_encode($document)));
        }
    } finally {
        unlink($path);
    }

    try {
        lineCoverage('/tmp/definitely-no-such-clover.xml');
        ++$failures;
        fwrite(STDERR, "FAIL a missing report was accepted\n");
    } catch (RuntimeException) {
        // Expected.
    }

    $total = count($percentages) + count($colours) + 4;

    if ($failures > 0) {
        fwrite(STDERR, sprintf("\n%d of %d cases failed.\n", $failures, $total));

        return 1;
    }

    fwrite(STDOUT, sprintf("All %d cases passed.\n", $total));

    return 0;
}

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
function options(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m) === 1) {
            $options[$m[1]] = $m[2] ?? '';
        }
    }

    return $options;
}

/** @var list<string> $argv */
$options = options($argv);

if (array_key_exists('self-test', $options)) {
    exit(selfTest());
}

try {
    $clover = $options['clover'] ?? '';

    if ($clover === '') {
        throw new RuntimeException('--clover is required.');
    }

    ['covered' => $covered, 'total' => $total] = lineCoverage($clover);
    $percent = percentage($covered, $total);

    $badgePath = $options['badge'] ?? '';

    if ($badgePath !== '') {
        $directory = dirname($badgePath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $directory));
        }

        $encoded = json_encode(badge($percent), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($badgePath, $encoded . "\n") === false) {
            throw new RuntimeException(sprintf('Could not write "%s".', $badgePath));
        }
    }

    printf("covered=%d\n", $covered);
    printf("total=%d\n", $total);
    printf("percent=%.1f\n", $percent);

    $min = $options['min'] ?? '';

    if ($min !== '' && $percent < (float) $min) {
        fwrite(STDERR, sprintf("Coverage is %.1f%%, below the required %s%%.\n", $percent, $min));

        exit(1);
    }

    exit(0);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}
