---
name: map
description: Which skill fits the situation, and what to do at a phase boundary. A router over the lundflow kit.
disable-model-invocation: true
---

# Map

You don't remember 36 toolkit files — 13 skills, 11 commands, 12 subagents, this
skill among them — so ask. This page names all of them and when to reach for each.
It carries no description into the agent's context and fires nothing on its own.

Keep it current: a new skill, command, or subagent that never lands here is
invisible.

## The main flow: rough idea → merged PR

```
rough ticket ─/lundflow:plan:run──▶ plan-draft ─▶ plan-breakdown ─▶ plan-slices
                                                              │
                                          slice backlog in the ticket body
                                                              ▼
                                         tdd  (RED → GREEN → REFACTOR, per slice)
                                                              │
                                        every ticket done and green
                                                              ▼
                              review-tdd-cross-slice (multi-slice PRs only)
                                                              │
                                                              ▼
      /lundflow:review:run ─▶ create-pr ─▶ debrief ─▶ human ─▶ suite ─▶ process ─▶ delta
```

**Planning — `/lundflow:plan:run`** orchestrates all three with gates, so reach for the
individual skills only when you're re-entering partway:

- **`lundflow:plan-draft`** — a ticket names WHAT but pins no HOW. Interviews you to lock
  the decisions, then replaces the ticket body. Say so explicitly and it runs in
  **synthesis mode** instead: no interview, assembled from a conversation that
  already settled things.
- **`lundflow:plan-breakdown`** — the plan covers more than one ticket. Cuts vertical
  tracer-bullet tickets, builds the wave/concurrency graph, writes Linear.
- **`lundflow:plan-slices`** — one ticket's plan becomes a **seam contract** plus an ordered
  TDD slice backlog. Also runs standalone.

**Execution — `lundflow:tdd`.** One behavior slice per cycle, each phase in its own
subagent so tests can't be retrofitted. `laravel:tdd-laravel-testing` and
`laravel:tdd-react-testing` or `laravel:tdd-vue-testing` (by frontend) — the laravel plugin's picks for the *Conventions skill: backend* and *Conventions skill: frontend* settings — carry the stack conventions; the
subagents load whichever skills those settings name.

**Review — `/lundflow:review:run`** chains the six stages with approval gates:
`/lundflow:review:create-pr` (lint, commit, push, open) → `/lundflow:review:debrief` (what the
branch did, checked against the ticket) → `/lundflow:review:human` (a person reads the
diff before any engine does) → `/lundflow:review:suite` → `/lundflow:review:process` (fix the
approved items) → delta.
Standalone when you want one piece: `/lundflow:review:claude` (multi-agent analysis),
`/lundflow:review:suite` (that plus CodeRabbit), and **`/lundflow:review:add`** — posts a
`/lundflow:review:claude` report to the PR as one review, inline where the file/line is in
the diff. `/lundflow:review:suite` calls `add` for you; run it yourself after a bare
`/lundflow:review:claude`.
**`/lundflow:review:human`** is the human read of the branch — it prints the Linear diff
link, waits for a person to review the diff and submit, then hands the submitted
comments to `/lundflow:review:process`. It runs as the loop's third stage, and standalone
whenever you want the read on its own.

## On-ramps

Situations that generate work and then merge onto the flow.

- **Feedback on work already done** — a review comment, a bug report, "change X" →
  **`lundflow:tdd-feedback`**. It classifies each item (BUG / SLICE / REFACTOR HAT / DIRECT)
  and routes into the existing `lundflow:tdd` machinery. A `UserPromptSubmit` hook nudges it
  automatically on feedback-shaped prompts.
- **A bug that resists the first look** → `lundflow:tdd-feedback`'s BUG branch hands off to
  **`mattpocock-skills:diagnosing-bugs`**, which refuses to theorise until it has a
  **tight** loop that goes **red** on this bug.
- **A ticket needs a workspace to work in** → **`/worktree:up {PREFIX}-NNN`** (where
  `{PREFIX}` is the *Ticket prefix* setting): derives the branch, cuts the LaborForest
  worktree, registers it in Solo, runs `up`, and reads the verdict off the run log.
  **`/worktree:down`** reverses it once the PR is merged, refusing on a dirty or
  unmerged branch. User-invoked — they create and drop a database.
- **Every slice in a multi-slice PR is done** → **`lundflow:review-tdd-cross-slice`**.
  Per-slice refactors never see the combined diff; this points the REFACTOR HAT at
  the whole PR. Single-slice PR → skip it; one ticket of many slices still qualifies.

## Upkeep

- **`lundflow:agent-writing`** — authoring or tightening any document an agent consumes, and
  the cure when a skill fires unreliably (its description is a context pointer, and
  the wording is the bug).
- **`lundflow:review-pipeline`** — the shared contract every reviewer cites: finding format,
  severity taxonomy, the Comment Bar, the model tiers, the endorsed-convention
  false-positive list, and how findings are worded.
- **`laravel:codebase-design`** — the laravel plugin's pick for the *Seam reference
  skill* setting: the vocabulary layer beneath planning, tdd, and review — module,
  interface, depth, **seam**, adapter, leverage, locality.
- **Asking the user a question** — not a skill but a section of
  `.ai/guidelines/lundflow-workflow.md`: the one contract every skill and command asks
  under, the two renderings that carry it (the decision round, and the disposition
  list `/lundflow:review:process` uses), and the silence contract that makes an
  unanswered question lock at its recommendation.

## Subagents

Twelve, and you never invoke one directly — the commands and skills above dispatch
them, each into its own context window.

- **Triage** (`/lundflow:review:claude`, before the reviewers): `lundflow:review-skip-check` answers
  SKIP or REVIEW, so a closed, draft, or trivial PR spends nothing below it;
  `lundflow:review-summarizer` returns what the PR does and the guideline paths its files
  fall under.
- **Phase 3 reviewers** (`/lundflow:review:claude`, four in parallel, two of each):
  `lundflow:review-compliance` checks the diff against those guideline paths;
  `lundflow:review-bug-hunter` works diff-local for logic errors.
- **Phase 4 validators** (`/lundflow:review:claude`, one per finding):
  `lundflow:review-compliance-validator` and `lundflow:review-bug-validator` each answer CONFIRMED or
  DROPPED over the one finding they are handed. A finding no validator confirms is
  dropped, never downgraded — that fail-closed drop is what keeps the report short.
- **`lundflow:coderabbit-reviewer`** — `/lundflow:review:suite`'s second engine; runs the CodeRabbit
  CLI and normalizes its output into the pipeline's finding format.
- **`lundflow:review-feedback-collector`** — `/lundflow:review:process` Phase 0; fetches every
  un-resolved PR item, keys it, and scope-checks it against the diff. Mechanical only.
- **`lundflow:review-fixer`** — `/lundflow:review:process` runs these in parallel, one per approved
  item or file-cluster, test-first. Each owns its files and never commits.
- **TDD trio** (`lundflow:tdd`, one phase each so tests can't be retrofitted):
  `lundflow:tdd-test-writer` → `lundflow:tdd-implementer` → `lundflow:tdd-refactorer`.

## Borrowed practice

Several skills here adapt practice from the **AI Hero** plugin
(`~/.claude/skills/mattpocock-skills/skills/`), each borrowed section closing with a
`**Source:**` line. The convention for those lines — offer to explain the origin
when you apply one — is in `CLAUDE.md` under *Borrowed practice carries a Source
line*, already in your context; it isn't repeated here.

What is local: **20 of the 35 upstream skills are user-invoked only**
(`disable-model-invocation: true`), among them `ask-matt`, `wait-what`, `to-spec`,
`to-tickets`, `grill-with-docs`, `triage`, and `wayfinder`. No skill, command, or
hook here can reach those — only you can type them. The rest are callable, and the
kit hands off to several by name: `diagnosing-bugs`, `prototype`, `research`,
`codebase-design`, `wizard`. (`code-review`, `tdd`, and `writing-for-agents` are
callable too, yet their practice is still inlined — see the `CLAUDE.md` section
above for why.)

## Phase boundaries

A **phase** is a chunk of work inside a session: the grilling, the implementation,
the QA. The **boundary** is the gap between two, and it is the only place the
continue / `clear` / hand-off / subagent / `compact` decision belongs — mid-phase,
either continue or split what's left into subagents.

The decision tree — five questions, top to bottom, **first yes wins** — is in
`${CLAUDE_PLUGIN_ROOT}/skills/map/PHASE-BOUNDARIES.md`, beside this file. Open it at a boundary.

**Source:** the flow-map shape is adapted from `mattpocock-skills:ask-matt`.
