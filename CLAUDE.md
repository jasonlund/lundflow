# lundflow

This repo is the lundflow kit, installed into itself: the plugins in `plugins/`, the
`scaffold/` that `lundflow:install` copies into a project, and the artisan commands in
`src/`. Linear team: **Lundflow** (`LUN`).

- **Edit the kit's prose where it ships.** The kit-owned guidelines below are
  symlinks into `plugins/lundflow/guidelines/`; `docs/agents/issue-tracker.md` /
  `linear-pr-open-contention.md` are symlinks into `scaffold/`. One copy of each.
- **The plugins this session runs come from the marketplace, not this checkout.** A
  change under `plugins/` reaches a session only after it merges and the plugin
  updates; try one sooner with `claude --plugin-dir plugins/<name>`.
- **`php artisan` boots through Testbench** (`artisan` is a shim), so the `lf:*`
  commands run here as they do in a consuming project.
- Tests: `composer test`. Formatting: `vendor/bin/pint --dirty --format agent`.

@.ai/guidelines/lundflow-settings.md
@.ai/guidelines/lundflow-workflow.md
@.ai/guidelines/lundflow-linear.md
@.ai/guidelines/lundflow-worktree.md
