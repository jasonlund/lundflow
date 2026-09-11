<?php

declare(strict_types=1);

namespace Lundflow\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use Lundflow\Console\Concerns\EmitsHeartbeat;
use stdClass;
use Symfony\Component\Finder\SplFileInfo;

#[Description('Install the lundflow scaffold into a project')]
#[Signature('lundflow:install {--path=} {--linear-api-key}')]
final class InstallScaffold extends Command
{
    use EmitsHeartbeat;

    /**
     * The guideline layers the kit owns outright: a project that hand-edits one has
     * drifted from the kit, and a re-run is how it comes back in line. Every other
     * scaffold file is project-owned — seeded once for the project to fill in, and
     * never written over again.
     *
     * @var list<string>
     */
    private const array KIT_OWNED = [
        '.ai/guidelines/lundflow-workflow.md',
        '.ai/guidelines/lundflow-linear.md',
        '.ai/guidelines/lundflow-worktree.md',
        '.ai/guidelines/lundflow-laravel.md',
    ];

    /**
     * The kit's review commands and agents write their run output under `.context/`,
     * so a project that does not ignore it can commit that output by accident.
     */
    private const string GITIGNORE_ENTRY = '/.context/';

    private const string LINEAR_SERVER = 'linear-server';

    /**
     * @var array{type: string, url: string, headersHelper: string}
     */
    private const array LINEAR_SERVER_ENTRY = [
        'type' => 'http',
        'url' => 'https://mcp.linear.app/mcp',
        'headersHelper' => 'php vendor/bin/lundflow-linear-auth',
    ];

    public function handle(): int
    {
        $root = $this->root();

        $this->installGitignoreEntry($root);
        $this->installScaffoldFiles($root);

        if ($this->option('linear-api-key') && ! $this->installLinearServer($root)) {
            return self::FAILURE;
        }

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    private function installGitignoreEntry(string $root): void
    {
        $path = $root.'/.gitignore';
        $contents = File::exists($path) ? File::get($path) : '';

        $lines = collect(explode("\n", $contents))->map(fn (string $line): string => Str::trim($line));

        if ($lines->contains(self::GITIGNORE_ENTRY)) {
            $this->output->writeln('  [install gitignore kept '.self::GITIGNORE_ENTRY.']');

            return;
        }

        // A bare append onto a last line with no trailing newline fuses the two rules
        // into one (`/vendor/.context/`), silently breaking both.
        $separator = $contents === '' || Str::endsWith($contents, "\n") ? '' : "\n";

        File::put($path, $contents.$separator.self::GITIGNORE_ENTRY."\n");

        $this->output->writeln('  [install gitignore added '.self::GITIGNORE_ENTRY.']');
    }

    private function installScaffoldFiles(string $root): void
    {
        $files = $this->scaffoldFiles();

        foreach ($files as $file) {
            $status = $this->installFile($file, $root);

            $this->output->writeln("  [install {$status} {$file}]");
        }

        $this->mark('install files', count($files));
    }

    private function installFile(string $file, string $root): string
    {
        $source = $this->scaffoldPath($file);
        $target = $root.'/'.$file;

        return in_array($file, self::KIT_OWNED, true)
            ? $this->installKitOwned($source, $target)
            : $this->installProjectOwned($source, $target);
    }

    /**
     * The kit's copy always wins, but identical bytes are left alone so the
     * target's mtime only moves when its contents actually change.
     */
    private function installKitOwned(string $source, string $target): string
    {
        $contents = File::get($source);
        $exists = File::exists($target);

        if ($exists && File::get($target) === $contents) {
            return 'unchanged';
        }

        File::ensureDirectoryExists(dirname($target));
        File::put($target, $contents);

        return $exists ? 'updated' : 'created';
    }

    /**
     * The project owns the file once it exists, so an existing copy is never touched.
     */
    private function installProjectOwned(string $source, string $target): string
    {
        if (File::exists($target)) {
            return 'kept';
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy($source, $target);

        return 'created';
    }

    /**
     * Enumerated from disk, dotfiles included, so a file added to the scaffold is
     * installed without a code change.
     *
     * @return list<string>
     */
    private function scaffoldFiles(): array
    {
        return collect(File::allFiles($this->scaffoldPath(''), true))
            ->map(fn (SplFileInfo $file): string => Str::replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
            ->values()
            ->all();
    }

    private function scaffoldPath(string $file): string
    {
        return dirname(__DIR__, 3).'/scaffold/'.$file;
    }

    /**
     * Runs after the scaffold copy so a fresh project's seeded `.mcp.json` receives
     * the entry.
     */
    private function installLinearServer(string $root): bool
    {
        $path = $root.'/.mcp.json';

        try {
            // Decoded as objects: an assoc round-trip turns another server's `{}` into `[]`.
            $config = json_decode(File::get($path), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->output->writeln('  [install mcp refused .mcp.json: not valid JSON]');

            return false;
        }

        $config->mcpServers ??= new stdClass;

        if (isset($config->mcpServers->{self::LINEAR_SERVER})) {
            $this->output->writeln('  [install mcp kept '.self::LINEAR_SERVER.']');

            return true;
        }

        $config->mcpServers->{self::LINEAR_SERVER} = self::LINEAR_SERVER_ENTRY;

        File::put($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->output->writeln('  [install mcp added '.self::LINEAR_SERVER.']');

        return true;
    }

    private function root(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== '' ? $path : base_path();
    }
}
