# lundflow

A Claude Code workflow kit, packaged as a plugin marketplace: a test-first TDD loop,
a gated planning pipeline, multi-agent PR review, and a LaborForest + Solo worktree
lifecycle.

> **Status: early extraction.** The contents were moved verbatim from
> [lundflix-v2](https://github.com/jasonlund/lundflix-v2), where the suite grew up.
> The prose still names lundflix's project-local paths (`.claude/skills/…`,
> `.ai/guidelines/project.md`) and Laravel-specific gates; genericizing it is the
> next phase. Expect breaking changes.

## Plugins

| Plugin | What it ships |
|---|---|
| `lundflow` | Skills `tdd`, `tdd-feedback`, `plan-draft`, `plan-breakdown`, `plan-slices`, `review-pipeline`, `review-tdd-cross-slice`, `agent-writing`, `map`; commands `/lundflow:plan:run` and `/lundflow:review:*`; the TDD and review subagents; six guard hooks |
| `laravel` | Laravel + Inertia + React test conventions (`tdd-laravel-testing`, `tdd-react-testing`) and the seam vocabulary (`codebase-design`) |
| `worktree` | `/worktree:up` and `/worktree:down` for LaborForest + Solo |

Plugin skills and agents are namespaced by their plugin: `lundflow:tdd`,
`lundflow:tdd-test-writer`.

## Install

```
/plugin marketplace add jasonlund/lundflow
/plugin install lundflow@lundflow
/plugin install laravel@lundflow
/plugin install worktree@lundflow
```

Plugins cannot ship project files, so `scaffold/` holds what a consuming project
copies in by hand for now: `solo.yml`, `.laborforest/workflows/`, `.mcp.json`,
`docs/agents/`, and the guideline layer `.ai/guidelines/lundflow.md`.

## Development

The guards are Pest tests that read the plugins off disk and run the hook scripts.

```
composer install
composer test
```

Hook tests need `bash`, `jq`, and `node` on the `PATH`.
