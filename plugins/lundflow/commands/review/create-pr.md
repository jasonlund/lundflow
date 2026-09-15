---
description: First stage of the review loop — lint the dirty files, commit and push the branch, then open a GitHub PR whose title/body are grounded in the Linear ticket, the diff, and the commit history. Leaves a clean pushed branch with an open PR that /lundflow:review:claude auto-detects.
---

# Create PR

You are running the **first** stage of the review loop:
**`/lundflow:review:create-pr`** (lint → commit → push → open PR) → `/lundflow:review:debrief`
(plain-language account of the branch + ticket-scope check) → `/lundflow:review:suite` or
`/lundflow:review:claude` (generate findings) → `/lundflow:review:add` (post them) →
`/lundflow:review:process` (act on them).

Your job is to leave a clean, pushed branch with one open PR against `main`,
titled and described so a reviewer (human or `/lundflow:review:claude`) can pick it up with no
extra context. You **stop** after opening the PR — you do not chain `/lundflow:review:claude`.

## Input
- **Ticket ID** — `{PREFIX}-XXX`, where `{PREFIX}` is the *Ticket prefix* setting;
  positional arg, or auto-extracted from the branch.
- Everything else is inferred from the working tree and branch.

## Example Invocation
```
/lundflow:review:create-pr                 # auto-detect ticket from branch, open PR against main
/lundflow:review:create-pr {PREFIX}-154    # explicit ticket
```

---

## Phase 0: Preconditions

1. **Not on `main`.** Run `git branch --show-current`. If it is `main` (or the
   branch has no commits ahead of `origin/main`), HALT and tell the user there is
   nothing to open a PR for.
2. **No PR already open.** Run `gh pr view --json number,url -q '.number' 2>/dev/null`.
   If one exists, HALT and print its URL — this branch already has a PR; direct the
   user to `/lundflow:review:claude`.
3. **Ticket ID** — if not passed, follow **Ticket ID Auto-Extraction** in
   `${CLAUDE_PLUGIN_ROOT}/skills/review-pipeline/SKILL.md` (branch name → PR title → null). If
   null, **prompt the user to create a Linear ticket** before proceeding (per the
   CLAUDE.md Linear rule — every branch maps to ≥1 ticket). Do not open a PR
   without a ticket unless the user explicitly waives it.

---

## Phase 1: Lint the Dirty Files

Run every linter/formatter for the files you touched, **scoped to the changed
work — never a repo-wide sweep** (a repo-wide run rewrites generated and unrelated
files). "Dirty" = uncommitted changes **plus** files this branch changed vs
`origin/main`:

```bash
git diff --name-only origin/main...HEAD; git diff --name-only; git diff --name-only --cached
```
Deduplicate, keep only existing files, then split by type.

### 1a. Backend touched (PHP) — in the order the setting lists
Run the *Finalize gates (backend)* setting over the changed backend files, in the
order it lists its commands. The formatter runs **last**, to normalize what a
refactor tool reformatted (in a Laravel project: Rector, then Pint).

### 1b. Frontend touched (`.vue`/`.ts`/`.tsx`/`.js`/`.css`)
Run the *Finalize gates (frontend)* setting.

### 1c. Re-run affected tests
Linters reorder and retype code, so re-verify green **after** 1a/1b. Filter to the
touched domain(s), not the whole suite:
- the *Backend test (filtered)* setting, filtered to `<Domain>`, if backend code
  changed;
- the *Frontend test (full)* setting, if frontend code changed.

**Gate:** if any linter errors out (not just reformats) or any test is red, **HALT
here** — report exactly what failed and do **not** commit, push, or open a PR. A PR
only opens on a clean, green tree.

---

## Phase 2: Commit & Push

The skill owns the commit.

1. Stage everything: `git add -A`.
2. If nothing is staged (branch already fully committed and lint changed nothing),
   skip to push.
3. Commit with a ticket-prefixed subject matching the repo convention:
   ```
   {PREFIX}-XXX: <concise summary of the change>
   ```
   Append the co-author trailer the harness supplies for this session — never
   hardcode one, it stamps a model version that rots. Squash lint fixups into this
   commit rather than adding a separate "lint" commit.
4. Push and set upstream: `git push -u origin HEAD`.

---

## Phase 3: Generate PR Content

**Ground the content in three sources — do not free-hallucinate a summary.** Each
source supplies one thing:

| Source | Supplies | How |
|---|---|---|
| Linear ticket | the *why* — intent + acceptance criteria | fetch via `mcp__linear-server__get_issue` when a ticket ID is set |
| Diff | the *what* — actual changes | `git diff origin/main...HEAD` |
| Commits | the narrative / ordering | `git log --oneline origin/main..HEAD` |

### Title
`{PREFIX}-XXX: <concise summary>` — every PR follows this. Drop the
`{PREFIX}-XXX:` prefix only if there is genuinely no ticket.

### Body
Write the way the repo already writes PR bodies: **one tight prose paragraph**
describing what the PR does and why, in the house style (present tense, names the
key classes/files). Not a wall of bullets. If the diff spans several distinct
concerns, a short bullet list may follow the paragraph — but summary-first, always.

When the diff changes anything a user sees and this session holds a
`⚠️ Not browser-verified` flag or no record that the render check ran
(*Browser verification* in `.ai/guidelines/lundflow-workflow.md`), add
`Browser verification: not done — {reason}` before the trailer.

End the body with the standard trailer:
```
🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

There is no `.github/PULL_REQUEST_TEMPLATE.md`, so this format is authoritative. If
one gets added later, fill it instead and keep the grounding sources above.

---

## Phase 4: Open the PR

Write the body to a temp file (avoids shell-escaping issues), then open a **ready**
(non-draft) PR against `main`:
```bash
gh pr create --base main --title "<title>" --body-file /tmp/create-pr-body.md
```
Delete the temp file after. Do not pass `--draft`.

---

## Phase 4b: Move ticket(s) to In Review

Once the PR is confirmed open, advance **every ticket the PR covers** to **In
Review** via the `linear-server` MCP `save_issue`, per the *Automatic ticket
status transitions* contract in `.ai/guidelines/lundflow-linear.md` (forward-only;
skip Canceled/Duplicate; no-op if already In Review or later).

**Then read it back — PR-open is contended.** Linear's GitHub integration reacts to
the same `pull_request.opened` event and its default mapping for *opened* is In
Progress, so it can revert our write within a few hundred milliseconds. Follow the
contract's **write → read back → correct once** clause: `get_issue` after the write,
re-apply once if it was reverted, and stop after a second revert rather than
looping. Report the correction when one was needed.

"Covered" = the union of
the ticket resolved in Phase 0 (explicit arg → branch name → PR title) **and**
every `{PREFIX}-XXX` id in the branch name — so a ticket passed explicitly or found in
the PR title but absent from the branch name still moves, and a multi-ticket branch
moves all of them together at PR open. No ticket resolves → skip silently.

---

## Phase 5: Report

```
✅ PR #{number} opened against main
   {title}

Branch pushed · {N} commit(s) · lint clean · tests green
{ticket(s)} → In Review{ (corrected after the GitHub integration reverted it)}

View: {PR URL}

Next: /lundflow:review:debrief
```

## Notes
- **Single-purpose.** Do not run `/lundflow:review:claude` or any review agents — hand off.
- **Never force-push** or rewrite existing commits beyond squashing your own lint
  fixups into the commit you just made.
- If `gh pr create` fails, show the error and the generated title/body so the work
  isn't lost.

$ARGUMENTS
