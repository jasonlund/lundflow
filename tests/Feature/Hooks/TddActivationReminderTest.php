<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\ToolkitFiles;

/**
 * Run the tdd-activation-reminder UserPromptSubmit hook with a JSON payload on
 * stdin and return its stdout. Exercises the real bash script + jq (no mocking).
 */
function runTddActivationHook(string $prompt): string
{
    $json = json_encode(['prompt' => $prompt, 'cwd' => sys_get_temp_dir()]);

    $result = Process::input($json)
        ->run('bash '.ToolkitFiles::path('plugins/lundflow/hooks/tdd-activation-reminder.sh'));

    expect($result->successful())->toBeTrue('hook should exit 0; stderr: '.$result->errorOutput());

    return $result->output();
}

describe('tdd-activation hook firing', function (): void {
    it('fires on a new feature request', function (): void {
        // Arrange
        $prompt = 'add an export button to the dashboard';

        // Act
        $output = runTddActivationHook($prompt);

        // Assert
        expect($output)->toContain('[tdd-router]');
    });
});

describe('tdd-activation hook silence', function (): void {
    it('stays silent on a background-task notification that reads like a feature request', function (): void {
        // A finished background task arrives as a prompt; "build" or "implement" in
        // its summary is the agent's wording, not a request from the user.
        // Arrange
        $prompt = backgroundTaskNotification('Build the export command');

        // Act
        $output = runTddActivationHook($prompt);

        // Assert
        expect(Str::trim($output))->toBe('');
    });
});
