---
description: Gate-first multi-agent PR review against the project's standards. A skip gate runs first, then the deterministic gates (the project's finalize gates and test suites — Pint/Rector/Pest/ESLint/Vitest in a Laravel + React project), then four parallel reviewers in isolated context, then one validator per finding that drops everything it cannot confirm.
---

# PR Review

You orchestrate the review and never review in your own context. Every judgement
call is made by a subagent in isolated context.

## Input

- **PR number** — positional arg, or auto-detected from the current branch.
- **Ticket ID** — `{PREFIX}-XXX`, where `{PREFIX}` is the *Ticket prefix* setting;
  positional arg, or extracted from the branch name / PR title. It names the PR in
  the report header; the spec axis itself belongs to
  `/lundflow:review:debrief`.

```
/lundflow:review:claude                   # auto-detect PR + ticket from branch
/lundflow:review:claude 142               # explicit PR, auto-detect ticket
/lundflow:review:claude {PREFIX}-154      # auto-detect PR, explicit ticket
/lundflow:review:claude 142 {PREFIX}-154  # explicit both
```

---

## Phase 0: Resolve PR + Ticket

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `${CLAUDE_PLUGIN_ROOT}/skills/review-pipeline/SKILL.md`. If no PR is found, HALT and tell the
   user to open one with `/lundflow:review:create-pr` (which lints, commits, pushes, and
   opens the PR), or to pass the number.
2. **Ticket ID** — if not passed, follow **Ticket ID Auto-Extraction** in the same
   contract (branch name → PR title → null).

---

## Phase 0.5: Skip Gate

```bash
gh pr view {PR_NUMBER} --json state,isDraft,title,files,reviews
```

Dispatch **review-skip-check** with that JSON. One call carries every signal the
gate judges on: state and draft flag, title, the per-file additions/deletions
that stand in for a diffstat, and `reviews` — each review body names the engine
that posted it, so a prior `/lundflow:review:claude` review is visible to the gate.

It answers SKIP or REVIEW.

- **SKIP** — print its one-line reason (closed, draft, trivial, already reviewed)
  and STOP. Every phase below stays unspent; that saving is the whole point of
  running this gate ahead of the reviewers.
- **REVIEW** — continue to Phase 1.

---

## Phase 1: Deterministic Gates

These produce facts. Save them as `DETERMINISTIC_FINDINGS`; they are auto-included
and skip validation entirely.

Each gate runs a command a project setting names. The examples are what a Laravel +
React project runs; `SOURCE` is the tool that reported the finding.

### 1a. Formatter (style)
The formatter step of the *Finalize gates (backend)* setting, in its check-only mode
so it reports rather than rewrites (Pint: `--dirty --test`).
Each violation → `SEVERITY: NIT`, `CATEGORY: convention`, `SOURCE: {tool}` (e.g.
`pint`).

### 1b. Refactor tool (modernization / safe refactors)
The refactor step of the *Finalize gates (backend)* setting, as a dry run (Rector:
`--dry-run`).
Each proposed change → `SEVERITY: CONSIDER`, `CATEGORY: convention`,
`SOURCE: {tool}` (e.g. `rector`).

### 1c. Backend tests
The *Backend test (filtered)* setting, filtered to the domains the diff touches when
it sits under one or more; otherwise the *Backend test (full)* setting (Pest, in a
Laravel project). Each failure → `SEVERITY: BLOCKING`, `CATEGORY: testing`,
`SOURCE: {tool}` (e.g. `pest`). Architecture-test failures (domain-boundary breaks)
count here too.

### 1d. Frontend lint — when the diff touches frontend source
The lint step of the *Finalize gates (frontend)* setting (ESLint, in a Laravel +
React project).
Errors → `SEVERITY: SHOULD_FIX`, warnings → `NIT`, `CATEGORY: convention`,
`SOURCE: {tool}` (e.g. `eslint`).

### 1e. Frontend tests — when the diff touches frontend source
The *Frontend test (full)* setting (Vitest, in a Laravel + React project).
Each failure → `SEVERITY: BLOCKING`, `CATEGORY: testing`, `SOURCE: {tool}` (e.g.
`vitest`).

---

## Phase 2: Context

```bash
gh pr diff {PR_NUMBER}
```
Assert exit 0 and non-empty output. Save as `PR_DIFF`.

Dispatch **review-summarizer** with `PR_DIFF`. It returns `PR_SUMMARY` (what the
PR does) and `GUIDELINE_PATHS` (the `CLAUDE.md` / `GUIDELINES.md` / `.ai/rules`
paths the changed files fall under), so each reviewer loads the rules in scope
instead of the whole guideline tree.

---

## Phase 3: Parallel Review

Dispatch four agents **in parallel**, each in isolated context:

- 2 × **review-compliance** — convention compliance against `GUIDELINE_PATHS`,
  with the Fowler smell baseline as the rubric for design friction.
- 2 × **review-bug-hunter** — bugs and logic errors.

Pass each one `PR_SUMMARY`, `GUIDELINE_PATHS`, `PR_DIFF`, and the finding format,
severity definitions, Simplified Technical English rules, Smell Baseline, and
Convention Override Rule in `${CLAUDE_PLUGIN_ROOT}/skills/review-pipeline/SKILL.md`.

### The bar every reviewer applies

Every finding is a **demonstration**: quote the changed line, then either name the
state where it fails (bug hunter) or quote the guideline rule it breaks
(compliance). Which categories clear that bar differs by role, and each agent file
is the single source for its own — `${CLAUDE_PLUGIN_ROOT}/agents/review-bug-hunter.md`,
`${CLAUDE_PLUGIN_ROOT}/agents/review-compliance.md`.

Stay silent on everything else — style and quality concerns, subjective
preference, speculation, anything a Phase 1 gate already owns, a pre-existing
issue in untouched code, and anything under a lint-ignore comment. Uncertain that
an issue is real → stay silent.

Each reviewer returns **at most 400 words**.

**Bug hunters work DIFF-LOCAL.** Flag what the diff alone proves.

The two agents of a pair share a brief and a diff, so the same defect arrives
twice. Merge duplicates on (file, line ±10, category), keeping the richest
evidence and the highest severity.

---

## Phase 4: Validation

One validator per surviving finding, dispatched in parallel:

- **review-bug-validator** — every review-bug-hunter finding.
- **review-compliance-validator** — every review-compliance finding.

Pass the single finding, `PR_DIFF`, and the file it cites. Each answers CONFIRMED
or DROPPED with a one-line reason.

---

## Phase 5: Fail-Closed

**This phase judges the Phase 3 reviewer findings, and only those.**
`DETERMINISTIC_FINDINGS` reach Phase 6 whole: a gate produced them, so they are
already fact, and Phase 4 dispatches no validator for them by design. Fail-closed
grades judgement, so a failing Pest run stays BLOCKING here.

A reviewer finding is validated on one condition: its own validator answered
CONFIRMED. Keep those. **Drop every reviewer finding no validator confirmed** —
including one whose validator errored, timed out, returned nothing, or answered
ambiguously. Fail-closed means dropped, never downgraded: a judgement nobody
could confirm never reaches the author. Count those drops as `DROPPED` for the
tally.

---

## Phase 6: Final Report

Write every line in Simplified Technical English (rules in
`${CLAUDE_PLUGIN_ROOT}/skills/review-pipeline/SKILL.md`). Lead with the tally, then the defects.

```markdown
# PR Review: PR #{number}{ against {ticket_id} if present}

**{X} blocking · {Y} should-fix · {Z} consider · {N} nits · {D} dropped in validation**

## Spec — does it do what the ticket asked?

`/lundflow:review:debrief` Phase 3 owns the spec axis. This review covers standards only.

## Blocking Issues (must fix before merge)

[One entry per confirmed BLOCKING finding. When there are none, write the single
line "No blocking issues."]
- **File:** `path/to/file.php` (lines N-M)
- **Issue:** [description]
- **Violates:** [the rule or convention, quoted verbatim]
- **Fix:** [specific recommendation]
- **Found by:** [agent/tool]

## Should Fix (not blocking but strongly recommended)

[Same fields. When there are none: "No should-fix defects."]

## Consider (valid concerns, author's judgment)

[Same fields. When there are none: "No further concerns."]

## Nits (trivial, take them or leave them)

[Same fields. Holds every NIT — the formatter violations from gate 1a, the lint
warnings from gate 1d, and any confirmed reviewer NIT. When there are none:
"No nits."]
```

To post the report to the PR as inline comments, run `/lundflow:review:add` afterward.

$ARGUMENTS
