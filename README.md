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
| `laravel` | Laravel + Inertia + React test conventions (`laravel:tdd-laravel-testing`, `laravel:tdd-react-testing`) and the seam vocabulary (`laravel:codebase-design`) |
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
| `docs/agents/*.md`, `solo.yml`, `.laborforest/workflows/*`, `.mcp.json` | project | Never touched once they exist. |

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
