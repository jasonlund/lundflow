<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ToolkitFiles;

/**
 * Drift guard for the per-project settings the plugins read.
 *
 * Prose cites a value as "the *Key* setting", and each project answers it in the
 * `## lundflow settings` table the scaffold ships. Nothing else connects the two:
 * a citation of a key the template lacks sends an agent looking for a value no
 * project was ever asked to write down, and it finds nothing rather than failing.
 */

/** The template a project copies once and fills in. */
$templatePath = 'scaffold/.ai/guidelines/lundflow-settings.md';

/**
 * The first-column keys of the template's table, header and separator rows aside.
 *
 * @return list<string>
 */
$templateKeys = fn (): array => collect(ToolkitFiles::splitLines(ToolkitFiles::read($templatePath)))
    ->map(fn (string $line): ?string => preg_match('/^\|\s*([^|]+?)\s*\|/', $line, $cell) === 1 ? $cell[1] : null)
    ->filter()
    ->reject(fn (string $key): bool => $key === 'Key' || preg_match('/^-+$/', $key) === 1)
    ->values()
    ->all();

/**
 * Every key the shipped prose cites, paired with the file that cites it.
 *
 * A citation runs one or more italic spans into the word "setting" — "the *A*
 * setting", "the *A* or *B* setting" — and may wrap mid-span, so each file is read
 * whole and a key's inner whitespace collapsed. The template is left out: its
 * intro describes the citation form with a placeholder key.
 *
 * @return list<array{file: string, key: string}>
 */
$citedKeys = function () use ($templatePath): array {
    $finder = (new Finder)->files()->name('*.md')
        ->in([ToolkitFiles::path('plugins'), ToolkitFiles::path('scaffold/.ai/guidelines')]);

    $cited = [];

    foreach ($finder as $file) {
        $relative = ToolkitFiles::relative($file->getPathname());

        if ($relative === $templatePath) {
            continue;
        }

        preg_match_all('/((?:\*[^*\n]+(?:\n[^*\n]+)?\*\s*(?:\/|,|or|and)?\s*)+)settings?\b/', (string) file_get_contents($file->getPathname()), $runs);

        foreach ($runs[1] as $run) {
            preg_match_all('/\*([^*]+)\*/', $run, $spans);

            foreach ($spans[1] as $key) {
                $cited[] = ['file' => $relative, 'key' => (string) preg_replace('/\s+/', ' ', trim($key))];
            }
        }
    }

    return $cited;
};

describe('settings template', function () use ($templateKeys): void {
    it('declares exactly the keys the plugins are written against, in order', function () use ($templateKeys): void {
        // Arrange
        $expected = [
            'Ticket prefix',
            'Guideline source',
            'Regenerate guidelines',
            'Backend test (filtered)',
            'Backend test (full)',
            'Frontend test (filtered)',
            'Frontend test (full)',
            'Finalize gates (backend)',
            'Finalize gates (frontend)',
            'Conventions skill: backend',
            'Conventions skill: frontend',
            'Seam reference skill',
            'Primary checkout',
            'Solo workspace',
        ];

        // Act
        $keys = $templateKeys();

        // Assert
        expect($keys)->toBe($expected);
    });
});

describe('settings citations', function () use ($templateKeys, $citedKeys): void {
    it('cites only keys the template declares', function () use ($templateKeys, $citedKeys): void {
        // Arrange
        $declared = $templateKeys();

        // Act
        $unknown = collect($citedKeys())
            ->reject(fn (array $c): bool => in_array($c['key'], $declared, true))
            ->map(fn (array $c): string => sprintf('%s  →  *%s* is not a settings key', $c['file'], $c['key']))
            ->unique()
            ->values()
            ->all();

        // Assert
        expect($unknown)->toBe([]);
    });

    it('actually finds citations rather than silently scanning nothing', function () use ($citedKeys): void {
        // Arrange
        // the shipped prose is the whole input; scanning it is the act

        // Act
        $citations = $citedKeys();

        // Assert
        expect(count($citations))->toBeGreaterThan(10);
    });
});
