<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ToolkitFiles;

/**
 * lundflow runs its own worktree lifecycle, which calls `php artisan lf:*` from the
 * primary checkout exactly as a consuming project does. The repo is a package, so
 * its `artisan` boots through Testbench — exercised here as a process, the way
 * `/worktree:up` and `up.yaml` invoke it.
 */
describe('artisan shim', function (): void {
    afterEach(function (): void {
        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }
    });

    it('runs the lf commands from the repo root', function (): void {
        // Arrange
        // the committed repo-root artisan, no state to set up

        // Act
        $result = Process::path(ToolkitFiles::path())
            ->run(['php', 'artisan', 'lf:branch-name', 'LUN-15', 'Use lundflow inside lundflow']);

        // Assert
        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and($result->output())->toBe("lun-15-use-lundflow-inside\n");
    });

    it('registers the lf commands when run by absolute path from another directory', function (): void {
        // Arrange
        // `up` runs the primary's artisan with the workspace as its cwd; a temp dir stands in for it
        $this->workspace = sys_get_temp_dir().'/lundflow-repo-artisan-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);

        // Act
        $result = Process::path($this->workspace)
            ->run(['php', ToolkitFiles::path('artisan'), 'list', '--raw']);

        // Assert
        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and($result->output())->toContain('lf:run-log');
    });
});
