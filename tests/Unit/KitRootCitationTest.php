<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ToolkitFiles;

/**
 * Drift guard for where the plugins say the kit's files live.
 *
 * Plugin prose cites a kit guideline or agent doc by the path it has in a
 * project — `.ai/guidelines/lundflow-workflow.md`, `docs/agents/domain.md`. That
 * path is relative to wherever the project installed the kit, which the *Kit root*
 * setting answers. A citation that leaves the setting out reads as repo-root
 * relative, and an agent in a project that installed elsewhere looks in the wrong
 * place and finds nothing rather than failing.
 *
 * The rule has no runtime effect, so this scan is its only observable — hence the
 * floor beside it: a finder or pattern that stopped matching would otherwise pass
 * forever.
 */

/**
 * Every `.ai/guidelines/….md` or `docs/agents/….md` path cited in the plugins'
 * markdown, paired with the paragraph it sits in.
 *
 * A paragraph is a blank-line-bounded block: prose wraps, so the setting and the
 * path it qualifies are rarely on one line, but they are always in one block.
 *
 * The pattern keeps `AgentToolkitReferencesTest`'s lookbehind — `~/…` and absolute
 * paths are not project paths — but, unlike that guard, admits `*` and `{}`: a
 * glob such as `docs/agents/*.md` resolves to no single file yet still tells the
 * reader where to look, which is the thing the setting qualifies.
 *
 * @return list<array{file: string, line: int, path: string, paragraph: string}>
 */
$scanCitations = function (): array {
    $finder = (new Finder)->files()->in(ToolkitFiles::path('plugins'))->name('*.md')->sortByName();

    $citations = [];

    foreach ($finder as $file) {
        $relative = ToolkitFiles::relative($file->getPathname());
        $lines = ToolkitFiles::splitLines(ToolkitFiles::read($relative));

        $paragraph = [];

        // The trailing '' closes a paragraph that runs to the end of the file.
        foreach ([...$lines, ''] as $index => $text) {
            if (trim($text) !== '') {
                $paragraph[$index + 1] = $text;

                continue;
            }

            $block = implode("\n", $paragraph);

            foreach ($paragraph as $line => $paragraphLine) {
                preg_match_all('#(?<![~/\w])(?:\.ai/guidelines|docs/agents)/[A-Za-z0-9._\-/*{}]+\.md\b#', $paragraphLine, $matches);

                foreach ($matches[0] as $path) {
                    $citations[] = ['file' => $relative, 'line' => $line, 'path' => $path, 'paragraph' => $block];
                }
            }

            $paragraph = [];
        }
    }

    return $citations;
};

describe('kit root citations', function () use ($scanCitations): void {
    it('names the Kit root setting in every paragraph that cites a kit guideline or agent doc', function () use ($scanCitations): void {
        // Arrange
        $citations = $scanCitations();

        // Act
        // `\s+` because a citation may wrap between the two words of the key.
        $unrooted = collect($citations)
            ->reject(fn (array $c): bool => preg_match('/\*Kit\s+root\*/', $c['paragraph']) === 1)
            ->map(fn (array $c): string => sprintf('%s:%d', $c['file'], $c['line']))
            ->unique()
            ->values()
            ->all();

        // Assert
        expect($unrooted)->toBe([]);
    });

    it('actually finds citations rather than silently scanning nothing', function () use ($scanCitations): void {
        // Arrange
        $citations = $scanCitations();

        // Act
        $files = collect($citations)->pluck('file')->unique();

        // Assert
        expect(count($citations))->toBeGreaterThan(20)
            ->and($files->count())->toBeGreaterThan(5);
    });
});
