<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\ToolkitFiles;

/**
 * machine/install.sh is a bash script, so it is exercised end to end — real bash,
 * real jq — and judged only by what it leaves in `$HOME/.claude` and what it
 * prints. Every run points HOME at a fresh temp directory: the script writes into
 * whatever HOME names, and the real one holds the operator's live settings.
 *
 * Exit 0 is asserted inside the runner rather than as its own case, so every path
 * below carries the "the install succeeds" half of the contract.
 */
/**
 * Records a home runMachineHome() just created; called with no argument, hands
 * back everything recorded since the last such call and forgets it.
 *
 * A registry rather than a glob over the temp dir: sibling workspaces run their
 * own copy of this suite against the same /tmp, and a glob would delete a
 * concurrent run's home out from under it mid-test.
 *
 * @return list<string>
 */
function trackedMachineHomes(?string $created = null): array
{
    static $homes = [];

    if ($created !== null) {
        $homes[] = $created;

        return $homes;
    }

    $tracked = $homes;
    $homes = [];

    return $tracked;
}

/**
 * A throwaway HOME, optionally seeded with an existing `.claude/settings.json`.
 *
 * @param  array<string, mixed>|null  $settings
 */
function freshMachineHome(?array $settings = null): string
{
    $home = sys_get_temp_dir().'/lundflow-machine-home-'.uniqid('', true);

    trackedMachineHomes($home);

    File::ensureDirectoryExists($home);

    if ($settings !== null) {
        File::ensureDirectoryExists($home.'/.claude');
        File::put($home.'/.claude/settings.json', (string) json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $home;
}

function runMachineInstall(string $home): ProcessResult
{
    $result = Process::env(['HOME' => $home])
        ->run('bash '.ToolkitFiles::path('machine/install.sh'));

    expect($result->successful())->toBeTrue('install should exit 0; stderr: '.$result->errorOutput());

    return $result;
}

/**
 * @return array<string, mixed>
 */
function machineSettings(string $home): array
{
    return (array) json_decode((string) file_get_contents($home.'/.claude/settings.json'), true);
}

/**
 * Every command registered under one hook event, flattened out of its groups.
 *
 * @param  array<string, mixed>  $settings
 * @return Collection<int, string>
 */
function machineHookCommands(array $settings, string $event): Collection
{
    return collect($settings['hooks'][$event] ?? [])
        ->flatMap(fn (array $entry): array => (array) ($entry['hooks'] ?? []))
        ->map(fn (array $hook): string => (string) ($hook['command'] ?? ''))
        ->values();
}

/**
 * The kit files the install copies, as paths relative to `machine/` — which is
 * also where each lands relative to `$HOME/.claude/`.
 *
 * Read off disk rather than listed by hand, so a rule or hook added to the kit is
 * covered the moment it is committed.
 *
 * @return list<string>
 */
function machineKitFilePaths(): array
{
    $machine = ToolkitFiles::path('machine');

    return collect((new Finder)->files()->in([$machine.'/rules', $machine.'/hooks'])->sortByName())
        ->map(fn (SplFileInfo $file): string => Str::after($file->getPathname(), $machine.'/'))
        ->push('RTK.md')
        ->values()
        ->all();
}

/**
 * A file under `$HOME/.claude/`, or null when the install never wrote it — so an
 * absent file fails on the comparison rather than on a read warning.
 */
function machineInstalledBytes(string $home, string $relative): ?string
{
    $path = $home.'/.claude/'.$relative;

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * How many lines of `$HOME/.claude/CLAUDE.md` are exactly the RTK include; zero
 * when the file does not exist.
 */
function machineRtkIncludeCount(string $home): int
{
    return collect(ToolkitFiles::splitLines((string) machineInstalledBytes($home, 'CLAUDE.md')))
        ->filter(fn (string $line): bool => $line === '@RTK.md')
        ->count();
}

/**
 * The expected heartbeat lines the output does not carry verbatim.
 *
 * @param  list<string>  $expected
 * @return list<string>
 */
function missingMachineHeartbeats(ProcessResult $result, array $expected): array
{
    return collect($expected)
        ->diff(ToolkitFiles::splitLines($result->output()))
        ->values()
        ->all();
}

/**
 * The checklist entries no output line above the closing line carries, named by
 * the step each one tells the user to take.
 *
 * An entry is found only when a single line holds every one of its fragments, so
 * fragments scattered across unrelated lines do not count.
 *
 * @param  array<string, list<string>>  $entries  step => fragments one line must hold
 * @return list<string>
 */
function missingMachineChecklistEntries(ProcessResult $result, array $entries): array
{
    $aboveClosingLine = collect(ToolkitFiles::splitLines(Str::trim($result->output())))->slice(0, -1);

    return collect($entries)
        ->reject(fn (array $fragments): bool => $aboveClosingLine->contains(fn (string $line): bool => Str::containsAll($line, $fragments)))
        ->keys()
        ->all();
}

/**
 * A PATH holding every external command install.sh runs except jq, so the
 * missing-jq run fails on jq alone rather than on whichever other command a
 * trimmed PATH happened to drop. Recorded in pathWithoutJq()'s registry, so the
 * suite's teardown removes it.
 */
function machinePathWithoutJq(): string
{
    $dir = sys_get_temp_dir().'/lundflow-machine-jq-free-path-'.uniqid('', true);
    mkdir($dir, 0777, true);

    foreach (['cat', 'cp', 'dirname', 'find', 'grep', 'mkdir', 'mktemp', 'mv', 'rm', 'sort', 'tail'] as $binary) {
        $resolved = Str::trim(Process::run('command -v '.$binary)->output());

        if (Str::startsWith($resolved, '/')) {
            symlink($resolved, $dir.'/'.$binary);
        }
    }

    jqFreePathRegistry()->append($dir);

    return $dir;
}

afterEach(function (): void {
    foreach (trackedMachineHomes() as $home) {
        File::deleteDirectory($home);
    }
});

describe('machine/install.sh settings merge', function (): void {
    it('installs the fragment as the settings file on a fresh machine', function (): void {
        // Arrange
        $home = freshMachineHome();

        // Act
        runMachineInstall($home);

        // Decoded equality also pins the hook commands' literal `$HOME`: Claude
        // Code's shell expands it at hook time, so an install that expanded it
        // would bake this machine's path into the file.
        // Assert
        expect(machineSettings($home))->toBe(json_decode(ToolkitFiles::read('machine/settings.json'), true));
    });

    it('keeps existing keys and values while filling in the fragment\'s absent keys', function (): void {
        // Arrange
        $home = freshMachineHome(['theme' => 'dark', 'model' => 'sonnet']);

        // Act
        runMachineInstall($home);

        // Assert
        $settings = machineSettings($home);
        expect($settings['theme'] ?? null)->toBe('dark')
            ->and($settings['model'] ?? null)->toBe('sonnet')
            ->and($settings['statusLine'] ?? null)->toBe(['type' => 'command', 'command' => 'ccstatusline']);
    });

    it('does not duplicate a hook command the existing settings already register', function (): void {
        // The fragment's other PreToolUse command is asserted too: with the rtk
        // command already on disk, "exactly one" alone would pass against a script
        // that never merged the event at all.
        // Arrange
        $command = 'node "$HOME/.claude/hooks/rtk-redirect-guard.js"';
        $home = freshMachineHome(['hooks' => ['PreToolUse' => [
            ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => $command]]],
        ]]]);

        // Act
        runMachineInstall($home);

        // Assert
        $commands = machineHookCommands(machineSettings($home), 'PreToolUse');
        expect($commands->filter(fn (string $registered): bool => $registered === $command)->count())->toBe(1)
            ->and($commands->all())->toContain('ccstatusline --hook');
    });

    it('leaves the settings file byte-identical when run a second time', function (): void {
        // The first run is setup: the property under test is what a re-run does to
        // a home the script itself already installed. Bytes, not decoded JSON —
        // a re-run that only reformats or reorders is still a change on disk.
        // Arrange
        $home = freshMachineHome();
        runMachineInstall($home);
        $firstRun = (string) file_get_contents($home.'/.claude/settings.json');

        // Act
        runMachineInstall($home);

        // Assert
        expect((string) file_get_contents($home.'/.claude/settings.json'))->toBe($firstRun);
    });
});

describe('machine/install.sh output', function (): void {
    it('reports the settings merge as a heartbeat and closes with Done.', function (): void {
        // Arrange
        $home = freshMachineHome();

        // Act
        $result = runMachineInstall($home);

        // Assert
        expect($result->output())->toMatch('/^  \[machine settings merged\]$/m')
            ->and(Str::trim($result->output()))->toEndWith('Done.');
    });

    it('lists the manual steps it cannot take before closing with Done.', function (): void {
        // Arrange
        $home = freshMachineHome();

        // Act
        $result = runMachineInstall($home);

        // Only lines above the closing line are searched, so a checklist printed
        // after Done. still counts as missing.
        // Assert
        expect(Str::trim($result->output()))->toEndWith('Done.')
            ->and(missingMachineChecklistEntries($result, [
                'the checklist heading' => ['Manual steps'],
                'adopting the kit in a project' => ['composer require jasonlund/lundflow', 'lundflow:install'],
                'installing the engineering skills' => ['mattpocock-skills'],
                'registering the MCP servers' => ['Linear', 'LaborForest', 'Solo'],
                'trusting a project\'s Solo commands' => ['solo.yml'],
            ]))->toBe([]);
    });
});

describe('machine/install.sh without jq', function (): void {
    it('exits 1 naming jq and writes no settings file', function (): void {
        // The PATH keeps every other command the script runs, so the run can only
        // stop on jq itself; the machine's own jq never decides the outcome.
        // Arrange
        $home = freshMachineHome();
        $path = machinePathWithoutJq();

        // Act
        $result = Process::env(['HOME' => $home, 'PATH' => $path])
            ->run('/bin/bash '.ToolkitFiles::path('machine/install.sh'));

        // Assert
        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain('jq')
            ->and($home.'/.claude/settings.json')->not->toBeFile();
    });
});

describe('machine/install.sh machine files', function (): void {
    it('installs every rule, hook and RTK.md byte-identical on a fresh machine', function (): void {
        // Arrange
        $home = freshMachineHome();
        $kitFiles = machineKitFilePaths();

        // Act
        runMachineInstall($home);

        // Keyed by path so a failure names each file that is missing or differs.
        // The toContain floor keeps an empty enumeration from passing vacuously.
        // Assert
        $installed = collect($kitFiles)->mapWithKeys(fn (string $relative): array => [$relative => machineInstalledBytes($home, $relative)]);
        $source = collect($kitFiles)->mapWithKeys(fn (string $relative): array => [$relative => ToolkitFiles::read('machine/'.$relative)]);
        expect($kitFiles)->toContain('rules/terse-answers.md', 'hooks/rtk-redirect-guard.js', 'hooks/package.json', 'RTK.md')
            ->and($installed->all())->toBe($source->all());
    });

    it('reports each file it installs as a created heartbeat', function (): void {
        // Arrange
        $home = freshMachineHome();
        $expected = collect(machineKitFilePaths())
            ->map(fn (string $relative): string => '  [machine created '.$relative.']')
            ->all();

        // Act
        $result = runMachineInstall($home);

        // Assert
        expect(missingMachineHeartbeats($result, $expected))->toBe([]);
    });

    it('keeps a machine file the home already has and reports it kept', function (): void {
        // The content half alone would pass against a script that never copies
        // anything; the kept heartbeat is what proves the script found the file
        // and chose not to overwrite it.
        // Arrange
        $home = freshMachineHome();
        File::ensureDirectoryExists($home.'/.claude/rules');
        File::put($home.'/.claude/rules/terse-answers.md', "My own rules.\n");

        // Act
        $result = runMachineInstall($home);

        // Assert
        expect(machineInstalledBytes($home, 'rules/terse-answers.md'))->toBe("My own rules.\n")
            ->and(missingMachineHeartbeats($result, ['  [machine kept rules/terse-answers.md]']))->toBe([]);
    });
});

describe('machine/install.sh CLAUDE.md include', function (): void {
    it('creates CLAUDE.md holding the RTK include on a fresh machine', function (): void {
        // Arrange
        $home = freshMachineHome();

        // Act
        runMachineInstall($home);

        // Assert
        expect(machineRtkIncludeCount($home))->toBe(1);
    });

    it('appends the RTK include to an existing CLAUDE.md, keeping its lines', function (): void {
        // Arrange
        $home = freshMachineHome();
        File::ensureDirectoryExists($home.'/.claude');
        File::put($home.'/.claude/CLAUDE.md', "# Personal\n@OTHER.md\n");

        // Act
        runMachineInstall($home);

        // Assert
        expect(machineInstalledBytes($home, 'CLAUDE.md'))->toStartWith("# Personal\n@OTHER.md\n")
            ->and(machineRtkIncludeCount($home))->toBe(1);
    });

    it('holds the RTK include exactly once after a second run', function (): void {
        // The first run is setup: the property under test is what a re-run does
        // to a CLAUDE.md the script itself already wrote.
        // Arrange
        $home = freshMachineHome();
        runMachineInstall($home);

        // Act
        runMachineInstall($home);

        // Assert
        expect(machineRtkIncludeCount($home))->toBe(1);
    });
});
