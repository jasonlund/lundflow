<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\ToolkitFiles;

/**
 * Records a project directory installProjectDir() just created; called with no
 * argument, hands back everything recorded since the last such call and forgets it.
 *
 * afterEach deletes exactly these paths rather than globbing the shared system
 * temp directory, which would delete a parallel worker's project mid-test.
 *
 * @return list<string>
 */
function trackedInstallProjectDirs(?string $created = null): array
{
    static $dirs = [];

    if ($created !== null) {
        $dirs[] = $created;

        return $dirs;
    }

    $tracked = $dirs;
    $dirs = [];

    return $tracked;
}

/**
 * A throwaway consuming-project directory, seeded with $files (project-relative
 * path => contents).
 *
 * Every test passes it through --path so the command can never write into the
 * Testbench skeleton's base path; afterEach deletes it again.
 *
 * @param  array<string, string>  $files
 */
function installProjectDir(array $files = []): string
{
    $dir = sys_get_temp_dir().'/lundflow-install-'.uniqid('', true);

    trackedInstallProjectDirs($dir);

    File::ensureDirectoryExists($dir);

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $contents);
    }

    return $dir;
}

/**
 * The kit-owned guideline layers, by filename under `.ai/guidelines/`.
 *
 * A literal list, not a directory scan of `scaffold/`: the scaffold also carries
 * the project-owned `lundflow-settings.md`, which a scan would sweep into the set.
 *
 * @return list<string>
 */
function kitGuidelineLayers(): array
{
    return [
        'lundflow-workflow.md',
        'lundflow-linear.md',
        'lundflow-worktree.md',
        'lundflow-laravel.md',
    ];
}

/**
 * Every file the kit ships under `scaffold/`, by its project-relative path, in
 * path order.
 *
 * A directory scan, not a literal list, so a file added to the scaffold later is
 * covered without touching this test. Dotfiles are included on purpose —
 * `.mcp.json` and `.laborforest/` are scaffold files a default Finder would skip.
 *
 * @return list<string>
 */
function installScaffoldFiles(): array
{
    return collect((new Finder)
        ->files()
        ->ignoreDotFiles(false)
        ->in(ToolkitFiles::path('scaffold'))
        ->sortByName())
        ->map(fn (SplFileInfo $file): string => Str::replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
        ->values()
        ->all();
}

/**
 * The non-blank lines of a command's output, indentation kept — the two-space
 * indent is what marks a heartbeat, so trimming would erase the shape under test.
 *
 * @return list<string>
 */
function installOutputLines(string $output): array
{
    return collect(explode("\n", $output))
        ->map(fn (string $line): string => Str::replace("\r", '', $line))
        ->filter(fn (string $line): bool => Str::trim($line) !== '')
        ->values()
        ->all();
}

/**
 * The project's `.gitignore`, split into lines — empty when the file is absent,
 * so a missing file fails the line assertion instead of throwing on the read.
 *
 * @return list<string>
 */
function installGitignoreLines(string $dir): array
{
    if (! File::exists($dir.'/.gitignore')) {
        return [];
    }

    return collect(explode("\n", File::get($dir.'/.gitignore')))
        ->map(fn (string $line): string => Str::replace("\r", '', $line))
        ->values()
        ->all();
}

afterEach(function (): void {
    foreach (trackedInstallProjectDirs() as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('lundflow:install kit-owned layers', function (): void {
    it('creates every kit-owned layer byte-identical to its scaffold source in an empty project', function (): void {
        // Arrange
        $dir = installProjectDir();

        // Act
        $this->artisan('lundflow:install', ['--path' => $dir])->assertSuccessful();

        // Assert
        foreach (kitGuidelineLayers() as $layer) {
            expect($dir.'/.ai/guidelines/'.$layer)->toBeFile();
            expect(File::get($dir.'/.ai/guidelines/'.$layer))
                ->toBe(ToolkitFiles::read('scaffold/.ai/guidelines/'.$layer));
        }
    });

    it('reports each layer it creates, then the closing total and Done.', function (): void {
        // Arrange
        $dir = installProjectDir();
        $scaffoldTotal = count(installScaffoldFiles());

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        $lines = installOutputLines(Artisan::output());
        expect($exitCode)->toBe(Command::SUCCESS);
        expect($lines)
            ->toContain('  [install created .ai/guidelines/lundflow-workflow.md]')
            ->toContain('  [install created .ai/guidelines/lundflow-linear.md]')
            ->toContain('  [install created .ai/guidelines/lundflow-worktree.md]')
            ->toContain('  [install created .ai/guidelines/lundflow-laravel.md]');
        expect(array_slice($lines, -2))->toBe(["  [install files {$scaffoldTotal}]", 'Done.']);
    });

    // Kit-owned means the kit's copy always wins: a project that hand-edits a layer
    // has drifted from the kit, and a re-run is how it comes back in line.
    it('overwrites a project edit to a kit-owned layer with the kit copy and reports the update', function (): void {
        // Arrange
        $dir = installProjectDir([
            '.ai/guidelines/lundflow-workflow.md' => "# Workflow\n\nA project-local edit.\n",
        ]);

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(installOutputLines(Artisan::output()))
            ->toContain('  [install updated .ai/guidelines/lundflow-workflow.md]');
        expect(File::get($dir.'/.ai/guidelines/lundflow-workflow.md'))
            ->toBe(ToolkitFiles::read('scaffold/.ai/guidelines/lundflow-workflow.md'));
    });

    // The mtime is pinned an hour into the past so any rewrite — even one writing
    // the identical bytes back — moves it to now and is caught; a same-second
    // rewrite would otherwise be indistinguishable from leaving the file alone.
    it('leaves a layer already identical to the kit copy untouched and reports it unchanged', function (): void {
        // Arrange
        $dir = installProjectDir([
            '.ai/guidelines/lundflow-workflow.md' => ToolkitFiles::read('scaffold/.ai/guidelines/lundflow-workflow.md'),
        ]);
        $layer = $dir.'/.ai/guidelines/lundflow-workflow.md';
        touch($layer, 1_700_000_000);
        clearstatcache(true, $layer);

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        clearstatcache(true, $layer);
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(installOutputLines(Artisan::output()))
            ->toContain('  [install unchanged .ai/guidelines/lundflow-workflow.md]');
        expect(filemtime($layer))->toBe(1_700_000_000);
    });
});

describe('lundflow:install project-owned files', function (): void {
    it('installs every scaffold file byte-identical to its source in an empty project', function (): void {
        // Arrange
        $dir = installProjectDir();

        // Act
        $this->artisan('lundflow:install', ['--path' => $dir])->assertSuccessful();

        // Assert
        foreach (installScaffoldFiles() as $file) {
            expect($dir.'/'.$file)->toBeFile();
            expect(File::get($dir.'/'.$file))->toBe(ToolkitFiles::read('scaffold/'.$file));
        }
    });

    it('reports a project-owned file it creates because the project lacks it', function (): void {
        // Arrange
        $dir = installProjectDir();

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(installOutputLines(Artisan::output()))
            ->toContain('  [install created solo.yml]');
    });

    // Project-owned means the project fills the file in and owns it from then on,
    // so a re-run must never write the kit's placeholder back over it.
    it('keeps a project-owned file the project already has and reports it kept', function (): void {
        // Arrange
        $dir = installProjectDir([
            '.ai/guidelines/lundflow-settings.md' => "# Settings\n\nBackend test (filtered): composer test\n",
            'solo.yml' => "processes:\n  queue:\n    command: php artisan queue:work\n",
        ]);

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(installOutputLines(Artisan::output()))
            ->toContain('  [install kept .ai/guidelines/lundflow-settings.md]')
            ->toContain('  [install kept solo.yml]');
        expect(File::get($dir.'/.ai/guidelines/lundflow-settings.md'))
            ->toBe("# Settings\n\nBackend test (filtered): composer test\n");
        expect(File::get($dir.'/solo.yml'))
            ->toBe("processes:\n  queue:\n    command: php artisan queue:work\n");
    });
});

// The kit's review commands and agents write run output under `.context/`, so a
// project that does not ignore it can commit review output by accident.
describe('lundflow:install gitignore entry', function (): void {
    it('creates a .gitignore carrying /.context/ in a project that has none', function (): void {
        // Arrange
        $dir = installProjectDir();

        // Act
        $this->artisan('lundflow:install', ['--path' => $dir])->assertSuccessful();

        // Assert
        expect($dir.'/.gitignore')->toBeFile();
        expect(installGitignoreLines($dir))->toContain('/.context/');
    });

    it('reports the entry added on a first run', function (): void {
        // Arrange
        $dir = installProjectDir();

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(installOutputLines(Artisan::output()))
            ->toContain('  [install gitignore added /.context/]');
    });

    // A file with no trailing newline is where a bare append fuses the entry onto
    // the project's last line (`/vendor/.context/`), silently breaking both rules.
    it('appends /.context/ on its own line after a last line with no trailing newline', function (): void {
        // Arrange
        $dir = installProjectDir(['.gitignore' => '/vendor']);

        // Act
        $this->artisan('lundflow:install', ['--path' => $dir])->assertSuccessful();

        // Assert
        expect(installGitignoreLines($dir))
            ->toContain('/vendor')
            ->toContain('/.context/');
    });

    it('holds /.context/ exactly once after a re-run', function (): void {
        // Arrange
        $dir = installProjectDir();
        Artisan::call('lundflow:install', ['--path' => $dir]);

        // Act
        $this->artisan('lundflow:install', ['--path' => $dir])->assertSuccessful();

        // Assert
        expect(collect(installGitignoreLines($dir))->filter(fn (string $line): bool => $line === '/.context/')->count())
            ->toBe(1);
    });

    it('reports the entry kept on a re-run', function (): void {
        // Arrange
        $dir = installProjectDir();
        Artisan::call('lundflow:install', ['--path' => $dir]);

        // Act
        $exitCode = Artisan::call('lundflow:install', ['--path' => $dir]);

        // Assert
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(installOutputLines(Artisan::output()))
            ->toContain('  [install gitignore kept /.context/]');
    });
});
