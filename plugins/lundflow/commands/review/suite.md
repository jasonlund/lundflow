---
description: Two-engine PR review — runs /lundflow:review:claude (multi-agent) plus the CodeRabbit CLI in parallel, then posts each engine's findings to the GitHub PR as its own source-attributed review via /lundflow:review:add.
---

# Two-Engine PR Review

You orchestrate **two independent reviewers** over one PR and land each one's
findings on GitHub as a **separate, source-attributed review**:

1. **`/lundflow:review:claude`** — the in-house adversarial multi-agent review (runs top-level
   here, because it spawns its own subagent fleet and can't be nested).
2. **CodeRabbit CLI** — dispatched to the `lundflow:coderabbit-reviewer` subagent.

The CLI subagent does the dump-heavy normalization in **isolated context** and
returns a canonical report file. **You (the orchestrator) do all the posting** —
the subagent never touches GitHub. Both always run; a failing engine is skipped,
never fatal.

Loop position: `/lundflow:review:create-pr` → `/lundflow:review:debrief` (author-facing summary +
ticket-scope check) → `/lundflow:review:human` (the human read of the diff) →
**`/lundflow:review:suite`** → `/lundflow:review:process`. Run `/lundflow:review:debrief` first for a
plain-language read of the branch before these engines dig for defects.

## Input
- **PR number** — positional arg, or auto-detected from the current branch.
- **Ticket ID** — `{PREFIX}-XXX`, where `{PREFIX}` is the *Ticket prefix* setting;
  optional; passed straight through to `/lundflow:review:claude`.

## Example Invocation
```
/lundflow:review:suite                     # auto-detect PR + ticket
/lundflow:review:suite 142                 # explicit PR
/lundflow:review:suite 142 {PREFIX}-154    # explicit PR + ticket
```

---

## Phase 0: Resolve PR + Run Dir

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `${CLAUDE_PLUGIN_ROOT}/skills/review-pipeline/SKILL.md`. If no PR is found, HALT and tell the
   user to open one with `/lundflow:review:create-pr` (lints, commits, pushes, opens the PR), or
   pass the number. CodeRabbit can review locally, but `/lundflow:review:add` needs the PR —
   so a PR is required.
2. **Base** = `main`.
3. **Run dir** — compute a unique scratch dir and create it:
   ```bash
   RUN_DIR=".context/review-all/pr${PR}-$(date +%s)"; mkdir -p "$RUN_DIR"; echo "$RUN_DIR"
   ```
   Use the absolute path when handing it to subagents.

---

## Phase 1: Dispatch the CodeRabbit CLI subagent (background)

Spawn it with `run_in_background: true` so it churns while `/lundflow:review:claude` runs:

- `subagent_type: lundflow:coderabbit-reviewer` — prompt with `PR_NUMBER`, `RUN_DIR`
  (absolute), `BASE=main`.

It returns a `=== … REPORT ===` block with `STATUS: OK|FAILED`, `REPORT_FILE`,
and `COUNTS`. Do not block on it yet.

---

## Phase 2: Run /lundflow:review:claude inline (top-level)

Invoke `/lundflow:review:claude`, passing through the same PR number and ticket
id. Let it run its full pipeline and emit its markdown report.

Then **persist that report** so it can be posted uniformly:
1. Take the report `/lundflow:review:claude` just produced (the `# PR Review: PR #<n> …`
   markdown).
2. Insert a `Source: /lundflow:review:claude` line immediately under the `# PR Review:` header.
3. Write it to `"$RUN_DIR/reviewpr.report.md"`.

---

## Phase 3: Join the CLI subagent

Collect the CodeRabbit subagent's final report block (it has completed by now):
- `STATUS: OK` → record its `REPORT_FILE` and counts.
- `STATUS: FAILED` → record the reason; it will be skipped in Phase 4.

---

## Phase 4: Post both (you do this — one /lundflow:review:add per engine)

For each engine that produced a report file (`reviewpr.report.md`,
`coderabbit.report.md`), invoke `/lundflow:review:add` with the **report file
path as the argument**, once per file:


```
/lundflow:review:add <RUN_DIR>/reviewpr.report.md
/lundflow:review:add <RUN_DIR>/coderabbit.report.md
```

This produces **two separate `COMMENT` reviews** on the PR, each attributed via
its `Source:` header (`via /lundflow:review:claude` / `via CodeRabbit`). Skip any engine whose
subagent returned `FAILED`. Post sequentially (not in parallel) so the two reviews
land cleanly.

---

## Phase 5: Summary

```
✅ /lundflow:review:suite on PR #{number}

| Engine     | Status | Spec | Blocking | Should Fix | Consider | Review |
|------------|--------|------|----------|------------|----------|--------|
| review:claude | ✅  | …    | …        | …          | …        | <url>  |
| CodeRabbit | ✅     | n/a  | …        | …          | …        | <url>  |

Reports: {RUN_DIR}/
```

**Spec** counts the findings on the requirements axis — "does it do what the ticket
asked". It sits beside the severity counts as its own column and stays out of them,
because the two axes are not commensurable: a PR can be clean on every convention
and still miss an acceptance criterion. A row is clean only when **all four** count
columns read 0. CodeRabbit reviews standards only, so its Spec cell reads `n/a`.

List any failed engine with its one-line reason. Do not commit or push — this
command only reviews and posts.

$ARGUMENTS
