<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The hook guards drive their scripts through Laravel's `Process` facade, so the
 * Feature suite needs a booted container — Testbench supplies one without an app.
 * `base_path()` resolves inside Testbench's skeleton, never this repo: read kit
 * files through `ToolkitFiles::path()`.
 */
abstract class TestCase extends Orchestra {}
