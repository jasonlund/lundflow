<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Shared scaffolding for the static guards over the kit's plugins —
 * `AgentModelPolicyTest`, `ReviewCommandStructureTest`, `ReviewContractTest`.
 *
 * Those guards read committed prose off disk and report each offender as a
 * `file:line → text` string, so they need the same few things: a repo root the
 * Unit suite can resolve, a line splitter that keeps a multi-byte file's line
 * numbers honest, a flat sweep of the agent roster, and a pattern check that
 * names the commitment it looked for. Each of those lives here once.
 *
 * The rules themselves stay in the guard that owns them — which agents are
 * retired, which contract sections survive, which shapes are forbidden. This
 * class knows how to read the toolkit and nothing about what it should say.
 */
final readonly class ToolkitFiles
{
    /**
     * An absolute path inside the repo, from a repo-relative one.
     *
     * The Unit suite doesn't boot the app container, so the root is resolved
     * from this file's location rather than from `base_path()`.
     */
    public static function path(string $relative = ''): string
    {
        $root = dirname(__DIR__, 2);

        return $relative === '' ? $root : $root.'/'.$relative;
    }

    /**
     * A committed file, read from disk by its repo-relative path.
     */
    public static function read(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    /**
     * A repo-relative path, from an absolute one inside the repo.
     */
    public static function relative(string $absolute): string
    {
        return Str::after($absolute, self::path().'/');
    }

    /**
     * A source split into its lines.
     *
     * Split on real newlines only, NOT `\R`: without the `u` modifier PCRE
     * treats the 0x85 byte as NEL, and 0x85 is a UTF-8 continuation byte of
     * characters the toolkit files carry (em dashes, ✅). `\R` breaks
     * mid-character and shifts every later line number by one, which makes
     * every reported `file:line` wrong.
     *
     * @return list<string>
     */
    public static function splitLines(string $source): array
    {
        return preg_split('/\r\n|\n|\r/', $source) ?: [];
    }

    /**
     * How many lines a source runs to, for a guard's non-vacuous floor.
     */
    public static function lineCount(string $source): int
    {
        return count(self::splitLines($source));
    }

    /**
     * Every line of every file the given finders resolve, paired with the
     * repo-relative path it came from.
     *
     * @return list<array{file: string, line: int, text: string}>
     */
    public static function scanLines(Finder ...$finders): array
    {
        $prefix = self::path().DIRECTORY_SEPARATOR;

        $lines = [];

        foreach ($finders as $finder) {
            foreach ($finder as $file) {
                $realPath = (string) $file->getRealPath();
                $relative = Str::replace($prefix, '', $realPath);

                foreach (self::splitLines((string) file_get_contents($realPath)) as $index => $text) {
                    $lines[] = [
                        'file' => $relative,
                        'line' => $index + 1,
                        'text' => $text,
                    ];
                }
            }
        }

        return $lines;
    }

    /**
     * The agent roster, by basename, in name order.
     *
     * Flat on purpose: the harness loads an agent by basename alone, so a
     * nested file is not one — recursing would report it under a path nothing
     * ever reads.
     *
     * @return Collection<int, string>
     */
    public static function agentNames(): Collection
    {
        return collect((new Finder)
            ->files()
            ->in(self::path('plugins/lundflow/agents'))
            ->name('*.md')
            ->depth(0)
            ->sortByName())
            ->map(fn (SplFileInfo $file): string => $file->getBasename('.md'))
            ->values();
    }

    /**
     * The required commitments whose pattern finds no match, named the way a
     * reader states them rather than as the regex that looks for them.
     *
     * A bare `expect($matched)->toBeTrue()` names nothing, so a failure would
     * say a check failed without saying which commitment left the file.
     *
     * @param  array<string, string>  $required  human description => pattern
     * @return list<string>
     */
    public static function missingPatterns(string $source, array $required): array
    {
        return collect($required)
            ->reject(fn (string $pattern): bool => preg_match($pattern, $source) === 1)
            ->keys()
            ->all();
    }

    /**
     * The forbidden shapes whose pattern still finds a match, named the same
     * way.
     *
     * @param  array<string, string>  $forbidden  human description => pattern
     * @return list<string>
     */
    public static function survivingPatterns(string $source, array $forbidden): array
    {
        return collect($forbidden)
            ->filter(fn (string $pattern): bool => preg_match($pattern, $source) === 1)
            ->keys()
            ->all();
    }

    /**
     * Every command file the plugins ship, keyed by repo-relative path and
     * valued by the slash command it is addressed as, in path order.
     *
     * @return Collection<string, string>
     */
    public static function commands(): Collection
    {
        return collect((new Finder)->files()->in(self::path('plugins/*/commands'))->name('*.md')->sortByName())
            ->mapWithKeys(function (SplFileInfo $file): array {
                $relative = Str::after($file->getPathname(), self::path().'/');
                preg_match('#^plugins/([^/]+)/commands/(.+)\.md$#', $relative, $parts);

                return [$relative => self::commandAddress($parts[1], $parts[2])];
            });
    }

    /**
     * The slash command a plugin's command file is addressed as.
     *
     * The harness names a plugin command `<plugin>:<path>` and ignores any
     * front-matter `name:` — `commands/review/claude.md` in plugin `lundflow`
     * runs as `/lundflow:review:claude`.
     */
    public static function commandAddress(string $plugin, string $path): string
    {
        return $plugin.':'.Str::replace('/', ':', $path);
    }

    /**
     * The kit file a path in plugin prose resolves to, or null when it resolves to
     * nothing the kit ships.
     *
     * `${CLAUDE_PLUGIN_ROOT}` expands to the citing file's own plugin — never a
     * sibling — so it resolves inside that plugin. `.ai/…` and `docs/…` name files
     * the consuming project owns, which the kit provides through `scaffold/`. A
     * `.claude/…` path resolves to nothing: an installed plugin lives in the plugin
     * cache, so no kit file is ever at the project's `.claude/`.
     */
    public static function resolveReference(string $citingFile, string $path): ?string
    {
        $candidate = match (true) {
            Str::startsWith($path, '${CLAUDE_PLUGIN_ROOT}/') => 'plugins/'.Str::betweenFirst($citingFile, 'plugins/', '/').'/'.Str::after($path, '${CLAUDE_PLUGIN_ROOT}/'),
            Str::startsWith($path, ['.ai/', 'docs/']) => 'scaffold/'.$path,
            default => null,
        };

        return $candidate !== null && file_exists(self::path($candidate)) ? $candidate : null;
    }
}
