<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ToolkitFiles;

/**
 * Claude Code runs `bin/lundflow-linear-auth` as an MCP `headersHelper` from the
 * project directory and reads the JSON object of headers it prints to stdout, so
 * the script is exercised as a process and judged on exit code, stderr and stdout.
 *
 * The project directory holds nothing but a `.env` — no Laravel app — which proves
 * the script reads the file directly instead of booting an app.
 *
 * Expected headers are hand-written literals, never rebuilt from the `.env` the
 * test wrote.
 */

/**
 * Records a project directory linearAuthProjectDir() just created; called with no
 * argument, hands back everything recorded since the last such call and forgets it.
 *
 * afterEach deletes exactly these paths rather than globbing the shared system
 * temp directory, which would delete a parallel worker's project mid-test.
 *
 * @return list<string>
 */
function trackedLinearAuthProjectDirs(?string $created = null): array
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

/** A throwaway project directory whose only file is a `.env` holding $env; a null $env leaves it empty. */
function linearAuthProjectDir(?string $env): string
{
    $dir = sys_get_temp_dir().'/lundflow-linear-auth-'.uniqid('', true);

    trackedLinearAuthProjectDirs($dir);

    File::ensureDirectoryExists($dir);

    if ($env !== null) {
        File::put($dir.'/.env', $env);
    }

    return $dir;
}

/** The headers helper, run with $dir as its working directory. */
function runLinearAuth(string $dir): ProcessResult
{
    return Process::path($dir)->run('php '.ToolkitFiles::path('bin/lundflow-linear-auth'));
}

afterEach(function (): void {
    foreach (trackedLinearAuthProjectDirs() as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('lundflow-linear-auth header output', function (): void {
    it('prints only the Bearer header built from LINEAR_API_KEY in the working directory .env', function (): void {
        // Arrange
        $dir = linearAuthProjectDir("APP_NAME=Demo\nLINEAR_API_KEY=lin_api_7Qm2Xv9LkT4pRz8N\nDB_DATABASE=demo\n");

        // Act
        $result = runLinearAuth($dir);

        // Assert
        expect($result->exitCode())->toBe(0)
            ->and($result->errorOutput())->toBe('')
            ->and(json_decode($result->output(), true))->toBe(['Authorization' => 'Bearer lin_api_7Qm2Xv9LkT4pRz8N']);
    });

    it('unwraps a quoted LINEAR_API_KEY value', function (string $env): void {
        // Arrange
        $dir = linearAuthProjectDir($env);

        // Act
        $result = runLinearAuth($dir);

        // Assert
        expect($result->exitCode())->toBe(0)
            ->and(json_decode($result->output(), true))->toBe(['Authorization' => 'Bearer lin_api_7Qm2Xv9LkT4pRz8N']);
    })->with([
        'double-quoted' => "APP_NAME=Demo\nLINEAR_API_KEY=\"lin_api_7Qm2Xv9LkT4pRz8N\"\n",
        'single-quoted' => "APP_NAME=Demo\nLINEAR_API_KEY='lin_api_7Qm2Xv9LkT4pRz8N'\n",
    ]);
});

/*
 * A non-zero exit is what marks the MCP server failed in `/mcp`, and stderr is the
 * only place the user learns which file to fix. The script reports getcwd(), which
 * resolves symlinks (macOS temp dirs sit behind /var -> /private/var), so the
 * expected path is realpath($dir), never $dir as written.
 */
describe('lundflow-linear-auth without a usable key', function (): void {
    it('fails naming LINEAR_API_KEY and the .env path when the working directory has no .env', function (): void {
        // Arrange
        $dir = linearAuthProjectDir(null);

        // Act
        $result = runLinearAuth($dir);

        // Assert
        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toBe('')
            ->and($result->errorOutput())->toContain('LINEAR_API_KEY')
            ->and($result->errorOutput())->toContain(realpath($dir).'/.env');
    });

    it('fails naming LINEAR_API_KEY and the .env path when the .env does not define LINEAR_API_KEY', function (): void {
        // Arrange
        $dir = linearAuthProjectDir("APP_NAME=Demo\nDB_DATABASE=demo\n");

        // Act
        $result = runLinearAuth($dir);

        // Assert
        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toBe('')
            ->and($result->errorOutput())->toContain('LINEAR_API_KEY')
            ->and($result->errorOutput())->toContain(realpath($dir).'/.env');
    });

    it('fails naming LINEAR_API_KEY and the .env path when LINEAR_API_KEY is blank', function (string $env): void {
        // Arrange
        $dir = linearAuthProjectDir($env);

        // Act
        $result = runLinearAuth($dir);

        // Assert
        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toBe('')
            ->and($result->errorOutput())->toContain('LINEAR_API_KEY')
            ->and($result->errorOutput())->toContain(realpath($dir).'/.env');
    })->with([
        'empty' => "APP_NAME=Demo\nLINEAR_API_KEY=\n",
        'empty quotes' => "APP_NAME=Demo\nLINEAR_API_KEY=\"\"\n",
        'spaces only' => "APP_NAME=Demo\nLINEAR_API_KEY=\"   \"\n",
    ]);

    it('fails without a stack trace, naming LINEAR_API_KEY and the .env path, when the .env cannot be parsed', function (string $env): void {
        // Arrange
        $dir = linearAuthProjectDir($env);

        // Act
        $result = runLinearAuth($dir);

        // Assert
        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toBe('')
            ->and($result->errorOutput())->toContain('LINEAR_API_KEY')
            ->and($result->errorOutput())->toContain(realpath($dir).'/.env')
            ->and($result->errorOutput())->not->toContain('Stack trace');
    })->with([
        // phpdotenv throws InvalidFileException on unquoted whitespace
        'unquoted whitespace' => "APP_NAME=Demo\nLINEAR_API_KEY=x y z\n",
        // phpdotenv returns no variables at all for an unterminated quote
        'unterminated quote' => "APP_NAME=Demo\nLINEAR_API_KEY=\"lin_api_7Qm2Xv9LkT4pRz8N\n",
    ]);
});
