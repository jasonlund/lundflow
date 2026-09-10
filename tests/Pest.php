<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    // Teardown, not the test body, so a failed expectation still leaves no litter
    // behind — pathWithoutJq() writes outside the project and nothing else reaps it.
    ->afterEach(function (): void {
        $registry = jqFreePathRegistry();

        foreach ($registry as $dir) {
            foreach ((array) glob($dir.'/*') as $link) {
                unlink((string) $link);
            }

            rmdir((string) $dir);
        }

        $registry->exchangeArray([]);
    })
    ->in('Feature');

/**
 * The temp directories pathWithoutJq() has created but not yet removed. A
 * registry rather than a glob over the temp dir: sibling workspaces run their
 * own copy of this suite against the same /tmp, and a glob would delete a
 * concurrent run's directory out from under it mid-test.
 */
function jqFreePathRegistry(): ArrayObject
{
    static $paths = null;

    return $paths ??= new ArrayObject;
}

/**
 * A finished background task as it reaches a `UserPromptSubmit` hook: Claude Code
 * delivers the notification as the prompt, so the hook sees text the user never
 * wrote.
 */
function backgroundTaskNotification(string $summary): string
{
    return "<task-notification>\n<task-id>b1a2c3d4</task-id>\n<status>completed</status>\n<summary>Agent \"{$summary}\" finished</summary>\n</task-notification>";
}

/**
 * A PATH holding only the externals a hook needs (`cat`, `grep`) and NOT jq,
 * so "jq is missing from this machine" can be reproduced deterministically on
 * macOS and CI alike — both ship jq, just from different directories, so
 * trimming PATH to a known prefix would not remove it.
 */
function pathWithoutJq(): string
{
    $dir = sys_get_temp_dir().'/jq-free-path-'.uniqid('', true);
    mkdir($dir, 0777, true);

    foreach (['cat', 'grep'] as $binary) {
        symlink(Str::trim(Process::run('command -v '.$binary)->output()), $dir.'/'.$binary);
    }

    jqFreePathRegistry()->append($dir);

    return $dir;
}
