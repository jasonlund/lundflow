<?php

declare(strict_types=1);

namespace Lundflow;

use Illuminate\Support\ServiceProvider;
use Lundflow\Console\Commands\DeriveBranchName;
use Lundflow\Console\Commands\InstallScaffold;
use Lundflow\Console\Commands\ReadRunLog;
use Lundflow\Console\Commands\SyncWorkspace;
use Lundflow\Console\Commands\WriteWorkspaceEnv;

final class LundflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DeriveBranchName::class,
                InstallScaffold::class,
                ReadRunLog::class,
                SyncWorkspace::class,
                WriteWorkspaceEnv::class,
            ]);
        }
    }
}
