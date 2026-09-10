# lundflow

A Claude Code workflow kit, packaged as a plugin marketplace: a test-first TDD loop,
a gated planning pipeline, multi-agent PR review, and a LaborForest + Solo worktree
lifecycle.

> **Status: early.** Extracted from [lundflix-v2](https://github.com/jasonlund/lundflix-v2)
> and being generalized for any Laravel project. The worktree plugin's `lf:*` artisan
> commands still live in lundflix; they move into a composer package next. Expect
> breaking changes.

## Plugins

| Plugin | What it ships |
|---|---|
| `lundflow` | The stack-agnostic core. Skills `lundflow:tdd`, `lundflow:tdd-feedback`, `lundflow:plan-draft`, `lundflow:plan-breakdown`, `lundflow:plan-slices`, `lundflow:review-pipeline`, `lundflow:review-tdd-cross-slice`, `lundflow:agent-writing`, and the `/lundflow:map` router; commands `/lundflow:plan:run` and `/lundflow:review:*`; the TDD and review subagents; six guard hooks |
| `laravel` | Laravel + Inertia + React test conventions (`laravel:tdd-laravel-testing`, `laravel:tdd-react-testing`) and the seam vocabulary (`laravel:codebase-design`) |
| `worktree` | `/worktree:up` and `/worktree:down` for LaborForest + Solo |

Everything a plugin ships is namespaced by the plugin: skill `lundflow:tdd`, subagent
`lundflow:tdd-test-writer`, command `/lundflow:review:claude`.

## Install

```
/plugin marketplace add jasonlund/lundflow
/plugin install lundflow@lundflow
/plugin install laravel@lundflow
/plugin install worktree@lundflow
```

## Per-project setup

Plugins cannot ship project files, so `scaffold/` holds what a project copies in:

| Path | Owner | Purpose |
|---|---|---|
| `.ai/guidelines/lundflow-workflow.md`, `-linear.md`, `-worktree.md`, `-laravel.md` | kit — re-copy on update | The guideline layers every agent reads. Laravel Boost concatenates `.ai/guidelines/` into `CLAUDE.md`. |
| `.ai/guidelines/lundflow-settings.md` | project — fill in once | The per-project values the plugins read: ticket prefix, test and finalize commands, which conventions skill serves each language, primary checkout, Solo workspace. Prose cites each as "the *Key* setting". |
| `docs/agents/*.md` | project | Issue-tracker, triage-label and domain-doc config for the engineering skills; replace `<team>` with your Linear team. |
| `solo.yml`, `.laborforest/workflows/`, `.mcp.json` | project | The worktree lifecycle's process list and LaborForest workflows. |

Decisions behind the kit live beside the plugin they bind, in `plugins/*/docs/adr/`.

## Development

The guards are Pest tests that read the plugins and scaffold off disk and run the
hook scripts.

```
composer install
composer test
```

Hook tests need `bash`, `jq`, and `node` on the `PATH`.
