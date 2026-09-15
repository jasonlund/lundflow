## Context routing

Put a convention where it's cheapest to load. Universal rules (apply across
domains) → the *Guideline source*. Rules specific to one bounded context →
`app/Domains/{Domain}/GUIDELINES.md`, read on demand (never `@import` — imports
load at launch and save nothing). A multi-step workflow → a skill in
`.claude/skills/`. Keep the *Guideline source* to the shared kernel; a rule that
only matters inside one domain belongs in that domain's file.

## Comments

- **Comment the *why*, let tests pin the *what*.** A comment earns its place by
  capturing a non-obvious reason, contract, or gotcha a reader can't derive from
  the code. If a passing test or the code itself already says it, cut it.
- **Docblocks: keep type info PHP can't express** (`@param array<int, array{...}>`,
  `@return list<string>`, generics, `@throws`) and genuine "why" prose. Cut
  summary lines that restate the method name, `@param`/`@var` that add nothing
  past the native type hint, and framework stubs (a `@var string` that only
  restates a typed property).

## Documentation

**Default to the *Guideline source* (agent context, every session); write to
README only when a human operator needs it; write nothing when code or git
already says it.**

- **The *Guideline source* — default.** Any convention, architecture/domain
  boundary, naming/structure rule, always/never, or non-obvious rationale a future
  agent would miss. Edit the *Guideline source* only (never the generated
  `CLAUDE.md`/`AGENTS.md`), then run the *Regenerate guidelines* command.
- **`README.md` — human-operator surface only.** Install, run, test, required
  credentials, what the app is. Never edit silently — prompt and name the stale
  section. New required env var → the README's credentials table (var, purpose,
  where to get it). Grow its overview as user-facing features ship.
- **Nothing** when derivable from code/tests/git, or true only of this one change.

Both a rule and an operator step? Rule → the *Guideline source*, step → README,
cross-reference — don't duplicate.

## Browser verification

When an agent changes anything a user sees — a frontend page or component, or
anything else that renders — it renders the result and checks it in a real browser.

- **Tools, in order:** Claude in Chrome first, Playwright second — both driving the
  live page, not a written test.
- **The check:** the page renders, the changed UI is visible and behaves as
  intended, and the console shows no JavaScript errors.
- **Reaching the app:** open the workspace URL `lf:workspace-env` derives. With no
  dev server up, the agent may run `npm run build`. Starting a Solo process stays
  human-only (*Local worktree tooling* in `.ai/guidelines/lundflow-worktree.md`).
- **A failed check** — a console error, the changed UI missing or broken, or
  `npm run build` failing on the code — is fixed as part of the work, then re-run.
- **Not a gate.** When neither tool can run the check — extension not connected, no
  server — or the check still fails after the fix, continue the work and write
  `⚠️ Not browser-verified — {reason}` in the next summary, the reason naming the
  blocker or the failure. For a blocker, ask the user to fix it so the check can
  re-verify in the browser.
- **Who runs it:** the session's main agent — in a TDD or review flow, the
  orchestrator. Phase subagents and fixers carry no browser tools.

## Asking the user a question

Every question an agent puts to the user in this repo is **plain markdown in the
chat**. Never `AskUserQuestion`, never a menu, picker, or dialog tool — no
exceptions, and a `PreToolUse` hook denies the tool outright.

The picker truncates the reasoning behind a recommendation, which is the part that
makes it judgeable, and forces one shot at a fixed option set — where the user needs
to answer per question, amend an earlier lock, or reject the framing itself.

**Not a question round:** `EnterPlanMode` / `ExitPlanMode` plan approval (the `tdd`
skill's RED gate). That is a plan-approval gate the harness renders, not a question
put to the user — it stays.

### The contract

Binds every asking site, whichever rendering below it uses:

1. **Numbered, and the number is durable.** A number names one thing for the whole
   session — a later round *continues* the sequence rather than restarting it, so
   item 6 is still item 6 two rounds on.
2. **Every entry carries a recommendation and its reasoning.** No recommendation →
   the entry is not ready to put to the user.
3. **Silence locks every recommendation, and nothing is re-asked** — except where
   clause 7 names.
4. **A partial reply locks what it names; every entry it does not name stands.**
5. **Any earlier lock is amendable by number, at any point.**
6. **Close with one line saying silence accepts.**
7. **One exception, and only this one: a person's own review of a diff.** In
   `/review:process --human-round`, an item the pipeline judges wrong — or cannot
   classify — holds the gate instead of standing. Clause 3 earns its keep because the
   entries are usually cheap and numerous, and a machine finding dropped on silence
   costs one re-run. A human who read the diff and asked for something is neither, so
   dropping their request because nobody replied inverts the deference that round
   exists to provide. That command owns the carve-out; no other site has one.

Two renderings carry it. Pick by payload, not by preference: a decision you are
putting to the user takes the **decision round**; a batch of items you have already
triaged, each arriving with a proposed disposition, takes the **disposition list**.

### Rendering A — the decision round

```
❓ **Q1** — **<title>**: <the decision, with its concrete options>

➡️ <your recommendation and why>
```

Used by `plan-draft`, `plan-breakdown`, `plan-slices`, `tdd` and `/plan:run`. The
template is mandated verbatim, glyphs included, and is defined here and **nowhere
else** — a second copy is the drift this section exists to prevent.

- **`Q7` names one question forever**, so a later reply can amend it by number
  (contract 1).
- **Only ask what's answerable now.** A question whose answer depends on another
  question open in the same round belongs to a later round.

### Rendering B — the disposition list

A numbered list of already-triaged items, each carrying a severity tag, its
location, and the slots that hold its issue, its proposed change, and the reasoning
for the disposition it is filed under. Used by `/review:process`, which owns the
shape block itself — it is that command's only user, and a review item's severity,
`path:line`, source attribution and lean do not fit rendering A's two lines.

The contract binds it unchanged: items are numbered durably across rounds, each
carries its recommendation and reasoning, and silence accepts the whole list.

### Silence is an answer

**An unanswered question locks at its recommendation and is never re-asked.** The
user may reply `nt` ("no text") or send an empty message — Claude Code permits one —
and both mean every recommendation in the round stands. Treat either as a complete
answer, not an absent reply to chase.

A partial reply (`3. b`, `4a`) locks what it names and locks the rest at their
recommendations. The user may amend any earlier lock at any point.

## Agent skills

Configuration the installed engineering skills read before they act —
`mattpocock-skills:triage`, `:to-spec`, `:to-tickets`, `:wayfinder`,
`:code-review`. They are **user-scoped**, not a plugin and not in this repo —
they live under `~/.claude/skills/mattpocock-skills/`, so **every one is invoked
with that prefix** and none of them are available in a checkout on a machine that
has not installed them; the skill files' own cross-references to bare
`/to-spec`-style names are upstream text and are stale here. Written by
`mattpocock-skills:setup-matt-pocock-skills`; edit `docs/agents/*.md` directly to
change the config.

**`/map` is the router** — one user-invoked skill naming every skill, command,
subagent, and flow here, and pointing at the phase-boundary tree beside it. Open it
when you've forgotten what exists.

### Borrowed practice carries a Source line

Several native skills adapt practice from the AI Hero plugin rather than calling it,
each borrowed section closing with a `**Source:**` line naming the upstream skill.
Two reasons. **20 of the 35 upstream skills set `disable-model-invocation: true`**,
so nothing here *can* call them — including the one inlined most directly,
`ask-matt` (Source of `/map`). The rest are callable — `code-review`'s smell
baseline, `writing-for-agents` — and are inlined anyway, because the practice has to
be in context *before* the work starts: one Skill call per reviewer costs more than
the text and lands too late to shape the finding. (A different set from the five
config readers named above; these are skills whose *text* is adapted here.)

**When you apply a section that carries one, offer to explain its origin** — the
upstream skill, what it argues, and the file to read. One line, then continue:
*"This is the seam contract, adapted from `mattpocock-skills:tdd` — want the
original reasoning?"* Offer once, and paste upstream text only when asked.

### Human-only steps → offer the wizard

When a task needs steps only a human can take — provisioning a third-party
credential, clicking through a vendor dashboard, setting a CI secret, a one-off
cutover — offer `mattpocock-skills:wizard`. It generates an interactive bash script
that opens each URL, captures each value, and writes it where it belongs, so the
procedure stops being re-explained every time. Adding an API credential here is the
standard case: the value must reach `.env.example`, the README credentials table,
**and** the seed `.env` a fresh worktree copies — the `.env` in the *Primary
checkout* under LaborForest + Solo. Do the work directly whenever you can; the
wizard is for where a human is genuinely in the loop.

### Issue tracker

Linear, tickets `{PREFIX}-123` (where `{PREFIX}` is the *Ticket prefix* setting),
via `mcp__linear-server__*` only — GitHub Issues are unused. See
`docs/agents/issue-tracker.md`.

### Triage labels

The five canonical roles, each label string equal to its name. See
`docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at the repo root. See
`docs/agents/domain.md`.

## Linting & formatting (finalize gates)

Before finalizing **any** change, run every linter/formatter for the files you
touched — scoped to your changed work, never a repo-wide sweep (a repo-wide
rewrite reaches generated caches and unrelated files).

- **Backend touched:** the *Finalize gates (backend)* setting, in the order it
  lists them (a formatter runs after a rewriter, to normalize what the rewriter
  reformatted).
- **Frontend touched:** the *Finalize gates (frontend)* setting.
- Then re-run the affected tests (the *Backend test (filtered)* / *Frontend test
  (filtered)* settings) — linters reorder and retype code, so re-verify green
  before finalizing.
