<?php

/**
 * Work out the next version from what a pull request changed.
 *
 * Deliberately dependency-free and outside the application: it runs from a
 * workflow before `composer install`, and nothing in `src/` should have to know
 * how this project is released. That also keeps it out of the PHPStan and
 * PHP-CS-Fixer paths, which the containerised toolchain runs against a tree
 * with no `.github` in it - so `--self-test` below is what stands in for the
 * test suite. The release workflow runs it before trusting anything here.
 *
 * Usage:
 *   next-version.php --current=1.2.3 --event=workflow_dispatch
 *   next-version.php --current=1.2.3 --event=pull_request --pr-json=pr.json
 *   next-version.php --self-test
 *
 * `pr.json` is whatever `gh pr view <n> --json title,commits` produced.
 *
 * Writes `key=value` lines on stdout, which is the format
 * $GITHUB_OUTPUT expects:
 *
 *   previous=1.2.3
 *   bump=minor
 *   version=1.3.0
 *   release=true
 */

declare(strict_types=1);

/**
 * Conventional Commit types that reach a user of the released artefact, and so
 * justify a version on their own. `perf` and `revert` are here because both
 * change behaviour; `build` is, because it can change what the package
 * installs.
 */
const PATCH_TYPES = ['fix', 'perf', 'refactor', 'build', 'revert'];

/**
 * Types that change nothing anyone could install. A pull request made only of
 * these publishes nothing, rather than spending a version number on a README
 * typo.
 */
const SILENT_TYPES = ['docs', 'chore', 'ci', 'style', 'test'];

const RANK = ['none' => 0, 'patch' => 1, 'minor' => 2, 'major' => 3];

/**
 * The bump a single commit message asks for.
 *
 * An unrecognised type counts as a patch rather than as nothing: a message this
 * cannot classify still changed something, and shipping it unreleased is the
 * worse of the two mistakes.
 */
function bumpFor(string $message): string
{
    $message = trim(str_replace("\r\n", "\n", $message));

    if ($message === '') {
        return 'none';
    }

    $lines = explode("\n", $message);
    $header = $lines[0];

    if (preg_match('/^(?<type>[A-Za-z]+)(?:\((?<scope>[^()]*)\))?(?<bang>!)?:\s+\S/', $header, $m) !== 1) {
        // Not a conventional commit at all. Something changed and nothing says
        // what, so take the smallest bump that still ships it.
        return 'patch';
    }

    if (($m['bang'] ?? '') === '!') {
        return 'major';
    }

    // The footer form. Uppercase is mandatory in the specification, and
    // BREAKING-CHANGE is synonymous, so both spellings are accepted and a
    // lowercase one deliberately is not.
    foreach (array_slice($lines, 1) as $line) {
        if (preg_match('/^BREAKING[ -]CHANGE:\s/', $line) === 1) {
            return 'major';
        }
    }

    $type = strtolower($m['type']);

    if ($type === 'feat') {
        return 'minor';
    }

    if (in_array($type, PATCH_TYPES, true)) {
        return 'patch';
    }

    if (in_array($type, SILENT_TYPES, true)) {
        return 'none';
    }

    return 'patch';
}

/**
 * The highest bump any of these messages asks for.
 *
 * @param list<string> $messages
 */
function highestBump(array $messages): string
{
    $highest = 'none';

    foreach ($messages as $message) {
        $bump = bumpFor($message);

        if (RANK[$bump] > RANK[$highest]) {
            $highest = $bump;
        }
    }

    return $highest;
}

/**
 * @throws RuntimeException when the version is not three numbers
 */
function applyBump(string $current, string $bump): string
{
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', trim($current), $m) !== 1) {
        throw new RuntimeException(sprintf('"%s" is not a MAJOR.MINOR.PATCH version.', $current));
    }

    [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) $m[3]];

    return match ($bump) {
        'major' => sprintf('%d.0.0', $major + 1),
        'minor' => sprintf('%d.%d.0', $major, $minor + 1),
        'patch' => sprintf('%d.%d.%d', $major, $minor, $patch + 1),
        default => sprintf('%d.%d.%d', $major, $minor, $patch),
    };
}

/**
 * Every message a pull request offers as evidence: its title, because a
 * squash merge turns the title into the commit, and each commit, because a
 * merge commit keeps them instead. Whichever way the repository merges, the
 * types are in here somewhere.
 *
 * @return list<string>
 *
 * @throws RuntimeException
 */
function messagesFromPullRequest(string $path): array
{
    $raw = @file_get_contents($path);

    if ($raw === false) {
        throw new RuntimeException(sprintf('Could not read "%s".', $path));
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('"%s" is not a JSON object.', $path));
    }

    $messages = [];

    if (isset($decoded['title']) && is_string($decoded['title'])) {
        $messages[] = $decoded['title'];
    }

    $commits = $decoded['commits'] ?? [];

    if (is_array($commits)) {
        foreach ($commits as $commit) {
            if (!is_array($commit)) {
                continue;
            }

            $headline = is_string($commit['messageHeadline'] ?? null) ? $commit['messageHeadline'] : '';
            $body = is_string($commit['messageBody'] ?? null) ? $commit['messageBody'] : '';

            if ($headline === '') {
                continue;
            }

            $messages[] = $body === '' ? $headline : $headline . "\n\n" . $body;
        }
    }

    return $messages;
}

/**
 * @param array<string, string> $cases message => expected bump
 */
function selfTest(): int
{
    $bumps = [
        'feat: add a thing' => 'minor',
        'feat(cli): add a thing' => 'minor',
        'fix: correct a thing' => 'patch',
        'fix(config): correct a thing' => 'patch',
        'perf: make it quicker' => 'patch',
        'refactor: move it' => 'patch',
        'build: bump a pin' => 'patch',
        'revert: undo it' => 'patch',
        'docs: explain it' => 'none',
        'chore: tidy up' => 'none',
        'ci: adjust a workflow' => 'none',
        'style: reformat' => 'none',
        'test: cover it' => 'none',
        'feat!: replace the thing' => 'major',
        'fix!: replace the thing' => 'major',
        'feat(config)!: replace the thing' => 'major',
        // Even a silent type is a major when it says it breaks something.
        'chore!: drop PHP 8.1' => 'major',
        "feat: add a thing\n\nBody text.\n\nBREAKING CHANGE: it moved." => 'major',
        "fix: a thing\n\nBREAKING-CHANGE: it moved." => 'major',
        // Uppercase is mandatory, so this is not a breaking change.
        "fix: a thing\n\nBreaking change: it moved." => 'patch',
        // A footer mentioned in prose is not a footer.
        "fix: a thing\n\nThis is not a BREAKING CHANGE: honestly." => 'patch',
        'not a conventional commit' => 'patch',
        'feat:no space after the colon' => 'patch',
        'feat: ' => 'patch',
        '' => 'none',
        'FEAT: shouting' => 'minor',
        'Fix: capitalised' => 'patch',
    ];

    $failures = 0;

    foreach ($bumps as $message => $expected) {
        $actual = bumpFor((string) $message);

        if ($actual !== $expected) {
            ++$failures;
            fwrite(STDERR, sprintf(
                "FAIL bumpFor(%s)\n  expected %s\n  actual   %s\n",
                var_export($message, true),
                $expected,
                $actual,
            ));
        }
    }

    $highest = [
        [['docs: a', 'chore: b'], 'none'],
        [['docs: a', 'fix: b'], 'patch'],
        [['fix: a', 'feat: b'], 'minor'],
        [['feat: a', 'fix!: b'], 'major'],
        [[], 'none'],
    ];

    foreach ($highest as [$messages, $expected]) {
        $actual = highestBump($messages);

        if ($actual !== $expected) {
            ++$failures;
            fwrite(STDERR, sprintf("FAIL highestBump(%s): expected %s, got %s\n", json_encode($messages), $expected, $actual));
        }
    }

    $applied = [
        ['1.2.3', 'major', '2.0.0'],
        ['1.2.3', 'minor', '1.3.0'],
        ['1.2.3', 'patch', '1.2.4'],
        ['1.2.3', 'none', '1.2.3'],
        ['0.9.9', 'minor', '0.10.0'],
        ['1.9.0', 'major', '2.0.0'],
        ['10.0.1', 'patch', '10.0.2'],
    ];

    foreach ($applied as [$current, $bump, $expected]) {
        $actual = applyBump($current, $bump);

        if ($actual !== $expected) {
            ++$failures;
            fwrite(STDERR, sprintf("FAIL applyBump(%s, %s): expected %s, got %s\n", $current, $bump, $expected, $actual));
        }
    }

    foreach (['1.2', 'v1.2.3', '1.2.3-rc1', 'nonsense', ''] as $bad) {
        try {
            applyBump($bad, 'patch');
            ++$failures;
            fwrite(STDERR, sprintf("FAIL applyBump(%s) was accepted\n", var_export($bad, true)));
        } catch (RuntimeException) {
            // Expected.
        }
    }

    $total = count($bumps) + count($highest) + count($applied) + 5;

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
    $current = $options['current'] ?? '';
    $event = $options['event'] ?? '';

    if ($current === '') {
        throw new RuntimeException('--current is required.');
    }

    // A hand-run release is always a patch. Nothing was merged, so there is no
    // set of commit messages to read an intention from, and inventing a larger
    // bump from silence would be worse than the smallest one that ships.
    if ($event === 'workflow_dispatch') {
        $bump = 'patch';
    } elseif ($event === 'pull_request') {
        $path = $options['pr-json'] ?? '';

        if ($path === '') {
            throw new RuntimeException('--pr-json is required for a pull_request event.');
        }

        $bump = highestBump(messagesFromPullRequest($path));
    } else {
        throw new RuntimeException(sprintf('Unknown --event "%s".', $event));
    }

    $version = applyBump($current, $bump);

    printf("previous=%s\n", $current);
    printf("bump=%s\n", $bump);
    printf("version=%s\n", $version);
    printf("release=%s\n", $bump === 'none' ? 'false' : 'true');

    exit(0);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}
