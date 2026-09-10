# worktree

`/worktree:up` and `/worktree:down` drive a LaborForest worktree through its MCP and
register it in Solo. This file is the operator reference both commands point at.

## Driving it from an agent (LaborForest MCP)

LaborForest ships a local MCP server, so an agent can cut a worktree and provision
it without you touching the GUI. Enable it once in **LaborForest → Settings → MCP**
(Enable MCP on, Read only **off** — `add-workspace` is only exposed on a writable
server), press **Save changes**, then run the `claude mcp add …` line it shows you.

That command is `--scope user` on purpose: the server is a machine-local app on a
localhost port with a bearer token, so it belongs in your user config — never in a
project's committed `.mcp.json`, which only carries servers whose command resolves on
every checkout.

The tools arrive prefixed `mcp__laborforest__`, and the four that matter are:

| Tool | Arguments | Does |
| --- | --- | --- |
| `add-workspace` | `path` (project) or `uuid`, `branch`, `base_branch` | cuts the worktree |
| `run-workflow` | `path` (workspace), `workflow` | runs `up` / `down` / `refresh`; returns a run id |
| `override-workspace-status` | `path`, `status` (`ready`\|`suspended`) | clears the `error` a failed run leaves |
| `validate-workflow` | `path`, `workflow` | parses a workflow and reports schema errors |

`run-workflow` only dispatches: success or failure shows up in
`.laborforest/ignored/logs/`, which `php artisan lf:run-log` reads for you. Solo
registration is `mcp__solo__create_project`, which `/worktree:up` runs; a freshly
registered project's processes stay stopped until you trust them in Solo's UI.

**A branch cut before the project installed the worktree tooling cannot be brought
up as-is.** `up` calls `php artisan lf:workspace-env`, and it deliberately does *not*
fast-forward a branch carrying its own commits — so the run aborts with `There are no
commands defined in the "lf" namespace` and every later step reports `skip_reason:
aborted`. Merge the base branch into it first, then clear the `error` status and
re-run.

**`up` also depends on the primary checkout's *code*, not just its branches.** Its
fast-forward step runs the primary's `php artisan lf:workspace-sync`, so a workspace
whose `up.yaml` expects that command while the *Primary checkout* predates it aborts
with the same error — from the primary this time. A `git pull` there is the whole
fix; no `composer install` is needed.

**Two limits.** There is **no `remove-workspace` tool** — `remove-project` removes a
whole project, not one workspace — so final removal stays a GUI action after `down`.
And enabling this grants more than worktrees: with Read only off and shell execution
allowed, the bearer token authorises arbitrary shell commands and `update-settings`
can re-widen the settings themselves. Regenerate the token if it is ever exposed.

## Fallback: when the MCP doesn't answer

Everything below works with the MCP off, and is what to reach for when an agent
reports no `laborforest` tools or a connection error.

First, tell the two apart:

- **No `laborforest` tools at all** — Claude Code binds MCP servers at session start.
  If you registered the server after the session began, start a new session; nothing
  needs redoing.
- **"Nothing is listening" / connection refused** — check `~/.laborforest/settings.yaml`
  actually says `mcp_enabled: true`. The Settings toggles do nothing until **Save
  changes** is pressed, and the server starts from the saved file, so unsaved toggles
  look correct while nothing is bound to the port.
- **401** — the token was regenerated; re-run `claude mcp add` with the new one.

Then do it by hand:

| Instead of | Do |
| --- | --- |
| `add-workspace` | **Add workspace** in the LaborForest GUI |
| `run-workflow` | the row's **Workflows** button, or `lf run <name>` from inside the worktree |
| `override-workspace-status` | the row's **⋮** menu → status action → `suspended` |
| reading the run id | `.laborforest/ignored/logs/` directly, newest file |

`lf` itself only exposes `add-project`, `run` and `validate` — there is no CLI path
to creating a workspace or clearing a status, which is why those two rows say GUI.
