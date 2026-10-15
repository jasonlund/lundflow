<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\ToolkitFiles;

/**
 * The logo ships as hand-checked SVG under `art/` and leads the README. These
 * guards read the working tree: the files must render anywhere (no text, fonts or
 * remote references), survive untouched by an optimiser, and be the ones the
 * README's light/dark `<picture>` actually points at.
 */

/**
 * @return list<string>
 */
function brandArtVariants(): array
{
    return [
        'art/lundflow-icon.svg',
        'art/lundflow-logo-dark.svg',
        'art/lundflow-logo-light.svg',
        'art/lundflow-mark-dark.svg',
        'art/lundflow-mark-light.svg',
        'art/lundflow-wordmark-dark.svg',
        'art/lundflow-wordmark-light.svg',
    ];
}

/**
 * @return list<string>
 */
function brandArtFiles(): array
{
    return array_values((array) glob(ToolkitFiles::path('art/*.svg')));
}

/**
 * @param  list<string>  $paths  repo-relative
 * @return list<string>
 */
function missingBrandArt(array $paths): array
{
    return array_values(array_filter($paths, fn (string $path): bool => ! is_file(ToolkitFiles::path($path))));
}

describe('art/ files', function (): void {
    it('ships every logo variant', function (): void {
        // Arrange
        $expected = brandArtVariants();

        // Act
        $missing = missingBrandArt($expected);

        // Assert
        expect($missing)->toBe([]);
    });

    it('carries no text, font reference or external URL', function (): void {
        // Arrange
        $files = brandArtFiles();

        // Act
        $offenders = collect($files)
            ->flatMap(function (string $file): array {
                $svg = (string) file_get_contents($file);
                $name = ToolkitFiles::relative($file);
                preg_match_all('#https?://[^\s"\'<>)]+#i', $svg, $matches);
                $urls = collect($matches[0])
                    ->reject(fn (string $url): bool => $url === 'http://www.w3.org/2000/svg');

                return collect([
                    Str::contains($svg, '<text') ? $name.' → <text>' : null,
                    Str::contains($svg, 'font', ignoreCase: true) ? $name.' → font' : null,
                ])->merge($urls->map(fn (string $url): string => $name.' → '.$url))
                    ->filter()
                    ->all();
            })
            ->values()
            ->all();

        // Assert
        expect($files)->toHaveCount(count(brandArtVariants()))
            ->and($offenders)->toBe([]);
    });

    it('keeps the wave clip in user space wherever it is declared', function (): void {
        // Arrange
        $files = brandArtFiles();

        // Act
        $offenders = collect($files)
            ->mapWithKeys(fn (string $file): array => [ToolkitFiles::relative($file) => (string) file_get_contents($file)])
            ->filter(fn (string $svg): bool => Str::contains($svg, 'id="wave-foot"'))
            // Lookaheads hold both attributes to the same tag in either order.
            ->reject(fn (string $svg): bool => preg_match('/<clipPath\b(?=[^>]*\bid="wave-foot")(?=[^>]*\bclipPathUnits="userSpaceOnUse")/', $svg) === 1)
            ->keys()
            ->all();

        // Assert
        expect($files)->toHaveCount(count(brandArtVariants()))
            ->and($offenders)->toBe([]);
    });
});

describe('README logo', function (): void {
    it('opens with the light/dark lockup', function (): void {
        // Arrange
        $readme = ToolkitFiles::read('README.md');

        // Act
        $firstLine = Str::of($readme)->explode("\n")->first(fn (string $line): bool => trim($line) !== '');

        // Assert
        expect(rtrim((string) $firstLine))->toBe('<p align="left">')
            ->and($readme)->toContain('<picture>')
            ->and($readme)->toContain('<source media="(prefers-color-scheme: dark)" srcset="art/lundflow-logo-dark.svg">')
            ->and($readme)->toContain('<img src="art/lundflow-logo-light.svg" alt="lundflow" width="320">');
    });

    it('drops the text heading and points only at art that exists', function (): void {
        // Arrange
        $readme = ToolkitFiles::read('README.md');

        // Act
        preg_match_all('#art/[A-Za-z0-9._\-/]+\.svg#', $readme, $matches);
        $referenced = array_values(array_unique($matches[0]));
        $missing = missingBrandArt($referenced);

        // Assert
        expect(Str::startsWith(ltrim($readme), '# lundflow'))->toBeFalse()
            ->and($referenced)->not->toBe([])
            ->and($missing)->toBe([]);
    });
});
