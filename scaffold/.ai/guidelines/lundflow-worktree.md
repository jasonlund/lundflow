## Local worktree tooling: LaborForest + Solo

Two tools, version-controlled. **LaborForest** (`lf`) owns worktree lifecycle
through `.laborforest/workflows/{up,down,refresh}.yaml`; **Solo** owns the
long-running dev processes through a committed `solo.yml`.

- **The lifecycle is two commands, not a remembered procedure** — **`/worktree:up
  {PREFIX}-NNN`** (where `{PREFIX}` is the *Ticket prefix* setting) and
  **`/worktree:down`**. Each runs the whole thing: the LaborForest workflow *and* the
  Solo registration, verified from the run log. Reach for them rather than
  re-deriving the steps; the rules below are why they do what they do, not a
  procedure to follow by hand. They are **commands, not skills**, deliberately: a
  procedure that drops a database must be user-invoked and can never fire on its own.
- **Never put computation in a workflow's bash string.** A `shell` step's `run:` is
  a string inside YAML — nothing can test it, so any logic there is unverifiable by
  construction. Route it through an artisan command and test that at `artisan()`:
  `lf:workspace-env` derives a workspace's site/database/URL, and
  `lf:workspace-sync` clears LaborForest's seeded `.laborforest/` files before
  fast-forwarding onto `origin/main`. A step should be one line.
- **A step that must run before `composer install` calls the *Primary checkout*'s
  artisan.** A fresh worktree has no `vendor/`, so its own `php artisan` cannot boot
  until Composer has run — and Composer cannot move ahead of `up`'s fast-forward
  without resolving the stale `composer.lock`. Such a step spells the binary
  `php "{{ PROJECT_PRIMARY_DIR }}/artisan"` and passes `{{ WORKSPACE_DIR }}` as an
  argument, because `base_path()` in that process is the *primary's* tree, never the
  workspace's. `lf:workspace-sync` is the only one, and `LaborForestWorkflowTest`
  pins both halves — the workspace's own artisan still may not precede Composer.
- **The workflows never touch Solo — but the agent driving them may.** `up` creates no
  Solo state, so `down` has none to reverse, and the boundary stays where the two tools
  already draw it: LaborForest orchestrates worktrees, Solo runs processes inside one.
  A `shell` step reaching across it means either Solo's CLI (gated behind a per-machine
  "local CLI access" setting nothing in the repo can enforce — it *silently no-ops*
  when off, which is worse than failing) or a JSON-RPC socket client. Neither belongs
  in a workflow. **The constraint is on the workflow, not on Solo.** `mcp__solo__create_project`
  registers a worktree with no UI and none of the CLI's fragility, so an agent
  provisioning a workspace should register it there too, under the *Solo workspace*
  setting — the committed `solo.yml` processes sync in on their own.
- **Trusting those processes stays human-only, by design.** Every Solo start/restart
  tool is scoped to *trusted* commands and the API exposes no trust/approve tool, so a
  freshly registered project starts with every process stopped. That gate is what stops
  a committed `solo.yml` from auto-running arbitrary commands in any checkout that
  clones it — so every committed process carries `auto_start: false` to match, rather
  than declaring an intent the gate would silently refuse to honor. Never document or
  script around it; leave the one click to the operator.
- **`solo.yml` is repo-controlled, with limits worth knowing.** Solo syncs it into
  local state, but **only `command` processes are YAML-backed** — terminals and
  agents are not stored there at all, so they stay per-machine. New or changed YAML
  commands start **untrusted** and will not run or auto-start until trusted in
  Solo's UI.
- **Only servers that resolve on every checkout belong in the committed `.mcp.json`.**
  `php artisan boost:mcp` is repo-relative and qualifies. Solo's MCP server lives in
  a macOS app bundle — machine-local, so it goes in a user-scoped Claude config, not
  a project-scoped file every checkout inherits.
- **Every destructive step carries the primary guard** —
  `if: test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"` — and so does
  `up`'s nested `refresh` call. The *Primary checkout*'s database holds far more
  than a workspace's seed can restore. `LaborForestWorkflowTest` is what enforces
  this; add a destructive step and you must add its matcher there.
- **Teardown is best-effort and always exits 0.** `down` is the only path
  `ready → suspended`, and LaborForest hides its Remove action unless a workspace is
  suspended — so a step that fails makes the worktree permanently undeletable. An
  orphaned resource is the cheaper failure; say so in a heartbeat rather than
  returning non-zero.
- **Per-workspace names are computed, never templated.** `{{ WORKSPACE_SLUG_SNAKE }}`
  verbatim overflows MySQL's 64-char database-name limit on real branch names.
  `lf:workspace-env` strips the project prefix, trims the branch to 40 chars, and
  prefixes `lf-` (Herd site) / `lf_` (database), which also keeps LaborForest's
  databases visibly distinct from the primary's.
- **Drive LaborForest through its MCP, not the `lf` CLI.** When
  `mcp__laborforest__*` tools are present, use `add-workspace` to cut the worktree,
  `run-workflow` to run `up`/`down`/`refresh`, and `override-workspace-status` to
  clear the `error` a failed run leaves behind. `lf` exposes only `add-project`,
  `run`, `validate` — it cannot create a workspace or clear a status at all. There
  is **no `remove-workspace` tool**; final removal is a GUI action (`remove-project`
  deletes a whole project, not one workspace).
- **`run-workflow` only dispatches.** It returns a run id and the workflow executes
  asynchronously inside the app, so its return says nothing about success. Read
  `.laborforest/ignored/logs/` — the newest file records every step's exit code,
  output and `skip_reason`. Judging a run by the dispatch call is how a failure gets
  reported as a success. **`php artisan lf:run-log <workflow>` is that check**, so the
  rule lives in a tested helper rather than in prose an agent re-derives: it selects the
  newest log for the workflow, exits non-zero naming the failing step, and surfaces the
  `[orphaned …]` lines that best-effort teardown leaves in step *output*. A skipped step
  carries no `exitCode` at all — reading that as a failure calls every successful `up` a
  failure, which is exactly why the judgment is not hand-written each time.
- **Validate through the MCP; the CLI's `lf validate` is inert.** `lf validate` exits
  0 for a missing file *and* for a schema-invalid one. The MCP's `validate-workflow`
  is a real check — it returns `isError` with the reason ("The selected require
  status is invalid. The sort order field is required.") on the same file the CLI
  passes. So a schema check exists, but only through the MCP; the Pest guards remain
  the only check that runs in CI, and a real `up` → `down` round trip is still the
  only end-to-end proof.
- **Fall back to the GUI when the MCP is absent.** No `laborforest` tools usually
  means the session started before the server was registered — a new session fixes
  it. "Nothing is listening" usually means the Settings toggles were never saved;
  `~/.laborforest/settings.yaml` is the source of truth, and the server starts from
  the saved file.
- Machine-local settings that cannot be version-controlled — `~/.laborforest/settings.yaml`
  (`command_launch_terminal`) and trusting a worktree's `solo.yml` commands in Solo's
  UI — are README operator steps. Keep that list short: **an operator step nothing
  enforces is a liability**, so prefer a design that needs none over one that
  documents another. Never install a tool by symlinking out of an app bundle; use
  the app's own supported path, or don't depend on it.
