<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Drift guard for the local-development tooling config the scaffold ships:
 * `solo.yml`, `.mcp.json`, and the `.laborforest/workflows/*.yaml` lifecycle files.
 *
 * Every one of these is read by an external tool (Solo, the MCP client, the
 * LaborForest app) and by nothing inside this repo, so drift is silent: a process
 * added from Solo's UI, an MCP server registered on one machine only, or a file
 * left untracked all behave perfectly for the person who made the change and
 * reach nobody else. The tests below pin the decisions that have to survive a
 * fresh checkout.
 *
 * Scope limit, deliberate: the tracking test asks git whether a path is tracked
 * and nothing more, so a tracked file whose working-tree edits are never
 * committed still passes. Its path list names only the files this branch newly
 * commits — the ones previously reachable through a machine-local
 * `.git/info/exclude` line. `scaffold/.mcp.json` is absent because it was never
 * at risk of that; its contents are pinned by the server assertion below instead.
 */

/** Repo-root paths whose contents must reach every checkout. */
$committedToolingPaths = [
    'scaffold/solo.yml',
    'scaffold/.laborforest/workflows/up.yaml',
    'scaffold/.laborforest/workflows/down.yaml',
    'scaffold/.laborforest/workflows/refresh.yaml',
];

/**
 * The repo root.
 *
 * The Unit suite doesn't boot the app container, so resolve it from this file's
 * location rather than base_path(). One level deeper than the guards in
 * tests/Unit, hence 3.
 */
$repoRoot = fn (): string => dirname(__DIR__, 3);

/**
 * `solo.yml`'s process map, keyed by process name.
 *
 * @return array<string, array<string, mixed>>
 */
$soloProcesses = function () use ($repoRoot): array {
    $config = (array) Yaml::parseFile($repoRoot().'/scaffold/solo.yml');

    return collect((array) ($config['processes'] ?? []))
        ->filter(fn (mixed $process): bool => is_array($process))
        ->all();
};

/**
 * A repo-root JSON file, decoded to an array.
 *
 * @return array<string, mixed>
 */
$rootJson = function (string $path) use ($repoRoot): array {
    return (array) json_decode((string) file_get_contents($repoRoot().'/'.$path), true);
};

/**
 * A `.mcp.json`'s server map, keyed by server name.
 *
 * @return array<string, mixed>
 */
$mcpServers = fn (string $path = 'scaffold/.mcp.json'): array => (array) ($rootJson($path)['mcpServers'] ?? []);

/**
 * The subset of repo-root paths git does not track. Asks git rather than the
 * disk, so a file kept alive only by a machine-local ignore rule still counts.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
$untrackedOf = function (array $paths) use ($repoRoot): array {
    $process = new Process(['git', 'ls-files', '-z', '--', ...$paths], $repoRoot());
    $process->run();
    $tracked = explode("\0", $process->getOutput());

    return collect($paths)->reject(fn (string $path): bool => in_array($path, $tracked, true))->values()->all();
};

/**
 * A repo-root file's lines, each trimmed.
 *
 * @return list<string>
 */
$rootFileLines = function (string $path) use ($repoRoot): array {
    return collect(file($repoRoot().'/'.$path, FILE_IGNORE_NEW_LINES) ?: [])
        ->map(fn (string $line): string => trim($line))
        ->values()
        ->all();
};

describe('solo.yml processes', function () use ($soloProcesses): void {
    it('declares exactly the four development processes', function () use ($soloProcesses): void {
        // Solo rewrites this file whenever a process is added or removed in its
        // UI, so the set is pinned exactly — a stray local process reaches every
        // checkout the moment the rewritten file is committed.
        // Arrange
        $expected = ['Horizon', 'npm:dev', 'Queue', 'Pint'];

        // Act
        $declared = collect($soloProcesses())->keys()->sort()->values()->all();

        // Assert
        expect($declared)->toBe(collect($expected)->sort()->values()->all());
    });

    it('commits no process that declares an auto start', function () use ($soloProcesses): void {
        // A fresh checkout's processes are all untrusted, Solo's API exposes no way
        // to trust one, and an untrusted command never starts — so `auto_start:
        // true` is an intent the trust gate silently refuses to honor. That gate is
        // what keeps a cloned `solo.yml` from auto-running arbitrary commands, so
        // the committed file matches it rather than declaring the opposite. An
        // absent key is an offender too: the value has to be stated, not inferred.
        // Arrange
        $processes = $soloProcesses();

        // Act
        $autoStarting = collect($processes)
            ->filter(fn (array $process): bool => ($process['auto_start'] ?? null) !== false)
            ->keys()
            ->all();

        // Assert
        expect($autoStarting)->toBe([]);
    });

    it('commits no process that disables permission prompts', function () use ($soloProcesses): void {
        // Solo's own local database carries agent processes launched with
        // permission prompts disabled. They stay local by decision: a committed
        // one is inherited silently by every future checkout, where nobody chose
        // it and nothing announces it.
        // Arrange
        $processes = $soloProcesses();

        // Act
        $unprompted = collect($processes)
            ->filter(fn (array $process): bool => Str::contains(
                (string) ($process['command'] ?? ''),
                '--dangerously-skip-permissions'
            ))
            ->keys()
            ->all();

        // Assert
        expect($unprompted)->toBe([]);
    });
});

describe('.mcp.json servers', function () use ($mcpServers): void {
    // Only servers whose command is true on every checkout belong in a committed,
    // project-scoped file. `php artisan boost:mcp` is repo-relative and qualifies.
    // Solo's server is a macOS app bundle path — machine-local, so it belongs in a
    // user-scoped Claude config, not here.
    it('registers only servers that resolve on any checkout', function () use ($mcpServers): void {
        // Arrange
        // the committed file is the whole input; reading it is the act

        // Act
        $registered = collect($mcpServers())->keys()->sort()->values()->all();

        // Assert
        expect($registered)->toBe(['laravel-boost']);
    });
});

describe('version control', function () use ($untrackedOf, $committedToolingPaths): void {
    it('tracks every committed tooling file in git', function () use ($untrackedOf, $committedToolingPaths): void {
        // A machine-local ignore rule (.gitignore, or .git/info/exclude, which no
        // checkout can see) keeps these files working locally while they reach
        // nobody. Ask git what it actually tracks rather than whether the file
        // exists on this disk.
        // Arrange
        // the file-scoped path list is the whole input

        // Act
        $untracked = $untrackedOf($committedToolingPaths);

        // Assert
        expect($untracked)->toBe([]);
    });
});

describe('repo self-install', function () use ($untrackedOf, $repoRoot, $rootFileLines, $rootJson, $mcpServers): void {
    // The kit installed into itself works only for a checkout that holds these
    // files; the tracking tests ask git, not the disk, because only that proves a
    // fresh clone gets them.
    it('tracks the self-install entry points and tooling config', function () use ($untrackedOf): void {
        // Arrange
        $paths = [
            'artisan',
            'CLAUDE.md',
            '.mcp.json',
            'solo.yml',
            '.claude/settings.json',
            '.laborforest/workflows/up.yaml',
            '.laborforest/workflows/down.yaml',
        ];

        // Act
        $untracked = $untrackedOf($paths);

        // Assert
        expect($untracked)->toBe([]);
    });

    it('tracks the self-install guidelines and agent docs', function () use ($untrackedOf): void {
        // Arrange
        $paths = [
            '.ai/guidelines/lundflow-linear.md',
            '.ai/guidelines/lundflow-settings.md',
            '.ai/guidelines/lundflow-workflow.md',
            '.ai/guidelines/lundflow-worktree.md',
            'docs/agents/domain.md',
            'docs/agents/issue-tracker.md',
            'docs/agents/linear-pr-open-contention.md',
            'docs/agents/triage-labels.md',
        ];

        // Act
        $untracked = $untrackedOf($paths);

        // Assert
        expect($untracked)->toBe([]);
    });

    // The committed file is read rather than asking `git check-ignore`, which also
    // honors the machine-local `.git/info/exclude` and so passes on one machine
    // while every other checkout commits the file.
    it('ignores the local env file holding the Linear API key', function () use ($rootFileLines): void {
        // Arrange
        // the committed file is the whole input; reading it is the act

        // Act
        $lines = $rootFileLines('.gitignore');

        // Assert
        expect($lines)->toContain('/.env');
    });

    it('ignores the LaborForest run logs', function () use ($rootFileLines): void {
        // Arrange
        // the committed file is the whole input; reading it is the act

        // Act
        $lines = $rootFileLines('.gitignore');

        // Assert
        expect($lines)->toContain('/.laborforest/ignored/');
    });

    // One copy of the kit's prose: the self-install reads scaffold/ through a
    // symlink, so an edit made in either place is the same edit.
    it('links each kit-owned guideline and doc into scaffold', function (string $path) use ($repoRoot): void {
        // Arrange
        $installed = $repoRoot().'/'.$path;

        // Act
        $resolved = is_link($installed) ? realpath($installed) : false;

        // Assert
        expect($resolved)->toBe(realpath($repoRoot()).'/scaffold/'.$path);
    })->with([
        '.ai/guidelines/lundflow-linear.md',
        '.ai/guidelines/lundflow-workflow.md',
        '.ai/guidelines/lundflow-worktree.md',
        'docs/agents/issue-tracker.md',
        'docs/agents/linear-pr-open-contention.md',
    ]);

    it('imports every lundflow guideline from CLAUDE.md', function () use ($rootFileLines): void {
        // Arrange
        $expected = [
            '@.ai/guidelines/lundflow-settings.md',
            '@.ai/guidelines/lundflow-workflow.md',
            '@.ai/guidelines/lundflow-linear.md',
            '@.ai/guidelines/lundflow-worktree.md',
        ];

        // Act
        $lines = $rootFileLines('CLAUDE.md');

        // Assert
        expect($lines)->toContain(...$expected);
    });

    it('registers the Linear MCP server authenticated by the kit helper', function () use ($mcpServers): void {
        // Arrange
        // the committed file is the whole input; reading it is the act

        // Act
        $servers = $mcpServers('.mcp.json');

        // Assert
        expect($servers)->toHaveKey('linear-server')
            ->and($servers['linear-server']['headersHelper'] ?? null)->toBe('php bin/lundflow-linear-auth');
    });

    it('enables the worktree plugin', function () use ($rootJson): void {
        // Arrange
        // the committed file is the whole input; reading it is the act

        // Act
        $plugins = (array) ($rootJson('.claude/settings.json')['enabledPlugins'] ?? []);

        // Assert
        expect($plugins['worktree@lundflow'] ?? null)->toBeTrue();
    });
});
