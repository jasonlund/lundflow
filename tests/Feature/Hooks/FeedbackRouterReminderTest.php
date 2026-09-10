<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\ToolkitFiles;

/**
 * Run the feedback-router-reminder UserPromptSubmit hook with a JSON payload on
 * stdin and return its stdout. Exercises the real bash script + jq (no mocking).
 */
function runFeedbackRouterHook(string $prompt, string $cwd): string
{
    $json = json_encode(['prompt' => $prompt, 'cwd' => $cwd]);

    $result = Process::input($json)
        ->run('bash '.ToolkitFiles::path('plugins/lundflow/hooks/feedback-router-reminder.sh'));

    expect($result->successful())->toBeTrue('hook should exit 0; stderr: '.$result->errorOutput());

    return $result->output();
}

/** A fresh empty temp dir with no comments attachment present. */
function freshHookCwd(): string
{
    $dir = sys_get_temp_dir().'/feedback-router-'.uniqid('', true);
    mkdir($dir, 0777, true);

    return $dir;
}

describe('feedback-router hook firing', function (): void {
    it('fires on a reviewer reporting broken work', function (): void {
        // Arrange
        $cwd = freshHookCwd();

        // Act
        $output = runFeedbackRouterHook('the reviewer said the importer is broken', $cwd);

        // Assert
        expect($output)->toContain('[feedback-router]');
    });

    it('fires on a bug-fix request against existing work', function (): void {
        // Arrange
        $cwd = freshHookCwd();

        // Act
        $output = runFeedbackRouterHook('fix this bug in the dataset importer', $cwd);

        // Assert
        expect($output)->toContain('[feedback-router]');
    });

    it('fires on a request to remove an unused helper', function (): void {
        // Arrange
        $cwd = freshHookCwd();

        // Act
        $output = runFeedbackRouterHook('remove the now-unused helper method', $cwd);

        // Assert
        expect($output)->toContain('[feedback-router]');
    });
});

describe('feedback-router hook silence', function (): void {
    it('stays silent on a new feature request', function (): void {
        // Arrange
        $cwd = freshHookCwd();

        // Act
        $output = runFeedbackRouterHook('add an export button to the dashboard', $cwd);

        // Assert
        expect(Str::trim($output))->toBe('');
    });

    it('stays silent on an unrelated question', function (): void {
        // Arrange
        $cwd = freshHookCwd();

        // Act
        $output = runFeedbackRouterHook('what time is it', $cwd);

        // Assert
        expect(Str::trim($output))->toBe('');
    });

    it('stays silent on a trivial prompt even when a comment attachment exists', function (): void {
        // A recent diff-comment attachment was a Conductor-only signal. The kit
        // supports LaborForest + Solo, which have no equivalent, so the file is
        // noise rather than evidence of feedback.
        // Arrange
        $cwd = freshHookCwd();
        mkdir($cwd.'/.context/attachments/comments', 0777, true);
        file_put_contents($cwd.'/.context/attachments/comments/x.md', "diff comment\n");

        // Act
        $output = runFeedbackRouterHook('ok', $cwd);

        // Assert
        expect(Str::trim($output))->toBe('');
    });

    it('stays silent on a background-task notification that reads like feedback', function (): void {
        // A finished background task arrives as a prompt, and its summary routinely
        // carries "review" or "change" — words the user never wrote.
        // Arrange
        $cwd = freshHookCwd();

        // Act
        $output = runFeedbackRouterHook(backgroundTaskNotification('Review the changed files'), $cwd);

        // Assert
        expect(Str::trim($output))->toBe('');
    });
});
