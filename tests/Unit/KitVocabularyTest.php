<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ToolkitFiles;

/**
 * The kit grew up inside one project and ships to others. Everything under
 * `plugins/` is loaded by agents in every project that installs the kit, and
 * everything under `scaffold/` is copied into those projects' trees — so a trace
 * of the original project there is an instruction about a codebase the reader is
 * not in.
 *
 * Per-project values have a home instead: the settings table
 * (`scaffold/.ai/guidelines/lundflow-settings.md`), cited as "the *Key* setting".
 */

/**
 * @return list<array{file: string, line: int, text: string}>
 */
$shippedLines = fn (): array => ToolkitFiles::scanLines(
    (new Finder)->files()->ignoreDotFiles(false)->in([ToolkitFiles::path('plugins'), ToolkitFiles::path('scaffold'), ToolkitFiles::path('src'), ToolkitFiles::path('machine')]),
);

describe('shipped vocabulary', function () use ($shippedLines): void {
    it('carries nothing specific to the project the kit was extracted from', function () use ($shippedLines): void {
        // Arrange
        $forbidden = [
            'a ticket id or prefix — use the *Ticket prefix* setting' => '/\bFLIX\b/',
            'the originating project by name' => '/lundflix/i',
            'Conductor, which the kit does not support' => '/\bConductor\b/',
            'download-domain vocabulary' => '/torrent|seeders?\b|leechers?\b|\bswarm\b/i',
            'a kit file addressed at the project\'s .claude/ — an installed plugin is never there' => '#(?<![~/\w])\.claude/(?:skills|agents|commands|hooks)/[A-Za-z0-9._\-/]+\.(?:md|sh|js)\b#',
        ];

        // Act
        $offenders = collect($shippedLines())
            ->flatMap(fn (array $l): array => collect($forbidden)
                ->filter(fn (string $pattern): bool => preg_match($pattern, $l['text']) === 1)
                ->keys()
                ->map(fn (string $why): string => sprintf('%s:%d  →  %s', $l['file'], $l['line'], $why))
                ->all())
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(count($shippedLines()))->toBeGreaterThan(1000);
    });
});
