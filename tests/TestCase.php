<?php

declare(strict_types=1);

namespace Tests;

use Lundflow\LundflowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The Feature suite boots the package inside Testbench's skeleton app — the hook
 * guards need its container for the `Process` facade, and the worktree commands
 * need the service provider. `base_path()` resolves inside that skeleton, never
 * this repo: read kit files through `ToolkitFiles::path()`.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LundflowServiceProvider::class];
    }
}
