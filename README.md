# lundflow

A Claude Code workflow kit for Laravel projects: a test-first TDD loop, a gated
planning pipeline, multi-agent PR review, and a LaborForest + Solo worktree
lifecycle. It ships as a plugin marketplace, a composer package, and a machine
bootstrap.

> **Status: early.** Extracted from [lundflix-v2](https://github.com/jasonlund/lundflix-v2),
> where it is being dogfooded. Expect breaking changes.

## Plugins

| Plugin | What it ships |
|---|---|
| `lundflow` | The stack-agnostic core. Skills `lundflow:tdd`, `lundflow:tdd-feedback`, `lundflow:plan-draft`, `lundflow:plan-breakdown`, `lundflow:plan-slices`, `lundflow:review-pipeline`, `lundflow:review-tdd-cross-slice`, `lundflow:agent-writing`, and the `/lundflow:map` router; commands `/lundflow:plan:run` and `/lundflow:review:*`; the TDD and review subagents; six guard hooks |
| `laravel` | Laravel + Inertia + React or Vue test conventions (`laravel:tdd-laravel-testing`, `laravel:tdd-react-testing`, `laravel:tdd-vue-testing`) and the seam vocabulary (`laravel:codebase-design`) |
| `worktree` | `/worktree:up` and `/worktree:down` for LaborForest + Solo |

Everything a plugin ships is namespaced by the plugin: skill `lundflow:tdd`, subagent
`lundflow:tdd-test-writer`, command `/lundflow:review:claude`.

## Adopting it in a project

```
composer require --dev jasonlund/lundflow
php artisan lundflow:install
```

`lundflow:install` copies `scaffold/` into the project and adds `/.context/` (where the
review engines write run output) to `.gitignore`. Re-run it after every kit update.

| Path | Owner | On re-run |
|---|---|---|
| `.ai/guidelines/lundflow-workflow.md`, `-linear.md`, `-worktree.md`, `-laravel.md` | kit | Brought back in line with the kit — edit these in the kit, not the project. |
| `.ai/guidelines/lundflow-settings.md` | project | Never touched. Fill in the project's values: ticket prefix, test and finalize commands, which conventions skill serves each language, primary checkout, Solo workspace. |
| `docs/agents/*.md`, `solo.yml`, `.laborforest/workflows/*`, `.mcp.json` | project | Never touched once they exist, except that `--linear-api-key` adds a `linear-server` entry to `.mcp.json` when it has none. |

Two project settings keep an install from fighting the project's own tooling:

- **Keep the kit's files out of the project's formatter.** `lundflow:install` rewrites the
  kit-owned layers on every run, so a formatter that reflows them fails its check again
  after each update — and indent width is a per-project choice the kit can't match. Add
  `.ai/**`, `.laborforest/**`, `docs/agents/**` and `solo.yml` to the formatter's ignore
  list, next to the agent files it most likely skips already.
- **With Laravel Boost, exclude its `tests` guideline.** It says copy, styling and layout
  changes need no tests, which contradicts the kit's test-first rule. A `config/boost.php`
  holding only `'guidelines' => ['exclude' => ['tests']]` keeps it out of every
  regeneration; Boost merges the file one level deep, so its other options keep their
  defaults.

Laravel Boost concatenates `.ai/guidelines/` into `CLAUDE.md`, so regenerate after an
install. Then declare the plugins in the project's committed `.claude/settings.json`:

```json
{
    "extraKnownMarketplaces": {
        "lundflow": { "source": { "source": "github", "repo": "jasonlund/lundflow" } }
    },
    "enabledPlugins": {
        "lundflow@lundflow": true,
        "laravel@lundflow": true,
        "worktree@lundflow": true
    }
}
```

Claude Code offers to install them when you trust the folder; `claude plugin install
lundflow@lundflow --scope project` does it by hand.

The package also registers the artisan commands the worktree lifecycle runs:
`lf:branch-name`, `lf:workspace-env`, `lf:workspace-sync`, `lf:run-log`.

**Dogfooding kit changes:** use a separate clone of the project, not a git worktree —
Claude Code loads the main checkout's `.claude/` into every worktree of a repo, so a
worktree always sees the main branch's toolkit too.

### Linear across several workspaces

Linear's MCP login authorizes one workspace per machine, and every project shares it. A
project whose tickets live in another workspace can use its own personal API key instead:

1. Put `LINEAR_API_KEY=lin_api_…` in the project's `.env` — the primary checkout's;
   `/worktree:up` copies it into each worktree.
2. Run `php artisan lundflow:install --linear-api-key` and commit the `.mcp.json` change.
3. Check with `/mcp`.

The `.mcp.json` entry overrides the user-level `linear-server` for this project only: the same
`mcp__linear-server__*` tools, authenticated by `vendor/bin/lundflow-linear-auth`, which
reads the key from `.env` without booting the app.

- Accept Claude Code's trust prompt in the main checkout. Until the folder is trusted the
  helper doesn't run, and your user-level `linear-server` answers instead, quietly, in its
  own workspace. Trust covers the checkout's worktrees too.
- The key needs write access. The kit creates tickets, moves statuses and edits labels,
  so a restricted read-only key fails on the first write.
- A missing or blank key shows the server as failed in `/mcp`, with a reason naming
  `LINEAR_API_KEY` and the `.env` path.
- The entry is committed, so every collaborator needs their own key in their `.env`, and
  `php` must be on the `PATH` Claude Code runs with.
- To opt out, delete the `linear-server` entry from `.mcp.json`.
- If the MCP still answers for the other workspace, agents run the operation through
  Linear's GraphQL API with the same key rather than skipping it.

## Machine setup

`machine/` holds the per-machine Claude Code setup: a settings fragment, rules, the RTK
redirect hook. `bash machine/install.sh` installs it into `~/.claude`:

- merges `machine/settings.json` into `~/.claude/settings.json` — existing values win,
  and a hook is added only if its command is not already registered;
- copies `machine/rules/*`, `machine/hooks/*` and `RTK.md`, never overwriting a file you
  already have;
- makes sure `~/.claude/CLAUDE.md` includes `@RTK.md`;
- finishes with the steps it cannot do for you.

It needs `jq`, and it writes to your real `~/.claude` — read `machine/` first.

## Development

The guards are Pest tests that read the plugins, scaffold and machine files off disk,
run the hook and bootstrap scripts, and drive the artisan commands through Testbench.

```
composer install
composer test
```

Hook and bootstrap tests need `bash`, `jq`, and `node` on the `PATH`.
