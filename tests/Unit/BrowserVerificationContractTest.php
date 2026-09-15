<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\ToolkitFiles;

/**
 * Drift guard for the one rule an agent follows when it checks a UI change in a
 * real browser.
 *
 * Every orchestrator that finishes frontend work — the TDD loop, the review gate,
 * the PR opener — needs the same answer to the same questions: which browser tool
 * to reach for first, what counts as a passing check, what to do when the check
 * cannot run, and whether it may start a dev server of its own. Restate that answer
 * at each site and the copies drift silently: one site still reaches for the second
 * tool first, another still blocks the run on an unverifiable check, and nothing
 * reports which version an agent read.
 *
 * So the rule is written ONCE, under *Browser verification* in
 * `.ai/guidelines/lundflow-workflow.md`, and every site cites it by that anchor.
 *
 * Scope, deliberate: every assertion checks that TEXT IS PRESENT, never that an
 * agent opened a browser. Whether a model actually verified the page is a runtime
 * property no static scan can reach; what a scan can guarantee is that the rule
 * exists in one place and every site sends the reader there.
 *
 * NB: the flag string's glyphs live in PHP string literals as escaped codepoints,
 * never as literal characters, so a later glyph guard over the kit can never read
 * this file's patterns as an offence.
 */

/**
 * The anchor every citing site must use — the guideline section's own heading
 * text, so a reader following the citation lands on the rule itself.
 */
$anchor = 'Browser verification';

/**
 * The guideline source the rule lives in, by repo-relative path.
 */
$guideline = 'scaffold/.ai/guidelines/lundflow-workflow.md';

/**
 * A citation: the italic anchor followed closely by the guideline path, allowing a
 * wrapped line between them.
 */
$citation = '~\*'.preg_quote($anchor, '~').'\*.{0,200}?\.ai/guidelines/lundflow-workflow\.md~s';

/**
 * Every orchestrator site that must cite the rule, by repo-relative path.
 *
 * @var list<string>
 */
$citingSites = [
    'plugins/lundflow/skills/tdd/SKILL.md',
    'plugins/lundflow/commands/review/process.md',
    'plugins/lundflow/commands/review/create-pr.md',
];

/**
 * The smallest line count a real pinned file can plausibly have. An emptied or
 * renamed file reads back as one blank line, so the floor sits above that.
 */
$minimumFileLines = 50;

/**
 * One `## ` section of a markdown file whose heading STARTS with the given text,
 * from that heading to the next one.
 *
 * The empty string when the heading is absent, so in-section patterns fail by name
 * instead of the extraction throwing.
 */
$sectionOf = function (string $file, string $headingPrefix): string {
    $pattern = sprintf('~^##\s+%s[^\n]*$.*?(?=^##\s|\z)~msu', preg_quote($headingPrefix, '~'));

    return preg_match($pattern, ToolkitFiles::read($file), $matches) === 1 ? $matches[0] : '';
};

describe('browser verification rule', function () use ($anchor, $guideline, $sectionOf): void {
    it('writes the whole rule once in the guideline source', function () use ($anchor, $guideline, $sectionOf): void {
        // The section is the single source of truth, so it carries the whole rule:
        // the tool order, what the check looks at, the not-a-gate flag an agent writes
        // when it cannot verify, and the dev-server boundary.
        // Arrange
        $section = $sectionOf($guideline, $anchor);
        $flag = "\u{26A0}\u{FE0F} Not browser-verified \u{2014} {reason}";
        $required = [
            'a top-level `## '.$anchor.'` heading' => '~^##\s+'.preg_quote($anchor, '~').'\s*$~m',
            'Claude in Chrome, the first tool' => '~Claude in Chrome~',
            'Playwright, the second tool' => '~Playwright~',
            'Claude in Chrome named before Playwright' => '~Claude in Chrome.*?Playwright~s',
            'the check for JavaScript console errors' => '~console[^\n]{0,40}error~i',
            'the verbatim not-verified flag' => '~'.preg_quote($flag, '~').'~u',
            'a prompt to fix the blocker and re-verify' => '~re-?verify~i',
            'the `npm run build` the agent may run' => '~npm run build~',
            'the URL `lf:workspace-env` derives' => '~lf:workspace-env~',
            'the Solo process the agent never starts' => '~\bSolo\b~',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($section, $required);

        // Assert
        expect($missing)->toBe([]);
    });
});

describe('citing sites', function () use ($citation, $sectionOf): void {
    it('adds a render check step to the tdd skill that points at the rule', function () use ($citation, $sectionOf): void {
        // The TDD loop is where frontend work finishes, so it owns the check — once
        // per ticket, after the last frontend slice, and never as a gate that halts
        // the loop.
        // Arrange
        $step = $sectionOf('plugins/lundflow/skills/tdd/SKILL.md', 'Step 5 — Render check');
        $required = [
            'a `## Step 5 — Render check` heading' => '~^##\s+Step 5 — Render check\s*$~mu',
            'a citation of the rule by anchor and guideline path' => $citation,
            'the statement that it is not a gate' => '~not a gate~i',
            'running once, after the last frontend slice' => '~last frontend slice~i',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($step, $required);

        // Assert
        expect($missing)->toBe([]);
    });

    it('runs the render check in the review gate and reports it in the summary', function () use ($citation, $sectionOf): void {
        // A fix round can change the UI after the TDD loop verified it, so the review
        // gate re-runs the check where it verifies fixes and says in its closing
        // summary whether the browser check happened.
        // Arrange
        $file = 'plugins/lundflow/commands/review/process.md';
        $source = ToolkitFiles::read($file);
        $verify = $sectionOf($file, 'Phase 4');
        $summary = $sectionOf($file, 'Phase 6');

        // Act
        $missing = [
            ...ToolkitFiles::missingPatterns($source, ['a citation of the rule by anchor and guideline path' => $citation]),
            ...ToolkitFiles::missingPatterns($verify, ['the render check, in the Phase 4 verify step' => '~render check~i']),
            ...ToolkitFiles::missingPatterns($summary, ['a browser verification line in the Phase 6 summary' => '~^[^\n]*Browser verification~m']),
        ];

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($verify))->toBeGreaterThan(200)
            ->and(Str::length($summary))->toBeGreaterThan(200);
    });

    it('carries the not-done line into the PR body', function () use ($citation): void {
        // An unverified change still ships, so the reviewer reading the PR has to be
        // told the browser check did not happen and why.
        // Arrange
        $source = ToolkitFiles::read('plugins/lundflow/commands/review/create-pr.md');
        $required = [
            'the verbatim PR-body line' => '~'.preg_quote("Browser verification: not done \u{2014} {reason}", '~').'~u',
            'a citation of the rule by anchor and guideline path' => $citation,
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([]);
    });
});

describe('single source', function () use ($guideline, $sectionOf, $anchor, $citingSites, $minimumFileLines): void {
    it('states the tool order in the guideline and nowhere in the plugins', function () use ($guideline, $sectionOf, $anchor): void {
        // A site that names both tools on one line has re-grown its own copy of the
        // tool order beside the pointer. The guideline lives under `scaffold/`, so
        // every hit under `plugins/` is an offender.
        // Arrange
        $lines = ToolkitFiles::scanLines((new Finder)->files()->in(ToolkitFiles::path('plugins'))->name('*.md'));
        $section = $sectionOf($guideline, $anchor);

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $line): bool => Str::contains($line['text'], 'Claude in Chrome') && Str::contains($line['text'], 'Playwright'))
            ->map(fn (array $line): string => sprintf('%s:%d  →  %s', $line['file'], $line['line'], Str::trim($line['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(count($lines))->toBeGreaterThan(1000)
            ->and(ToolkitFiles::missingPatterns($section, [
                'the tool-order sentence, both tools on one line in the guideline' => '~^[^\n]*Claude in Chrome[^\n]*Playwright~m',
            ]))->toBe([]);
    });

    it('actually reads every pinned file rather than silently finding nothing', function () use ($guideline, $citingSites, $minimumFileLines): void {
        // Every check above reports "missing" both when prose is absent and when the
        // file itself is gone; a moved file needs a different fix, so it is pinned
        // apart.
        // Arrange
        $files = collect([$guideline, ...$citingSites]);

        // Act
        $short = $files
            ->filter(fn (string $file): bool => ! file_exists(ToolkitFiles::path($file))
                || ToolkitFiles::lineCount(ToolkitFiles::read($file)) < $minimumFileLines)
            ->values()
            ->all();

        // Assert
        expect($short)->toBe([]);
    });
});
