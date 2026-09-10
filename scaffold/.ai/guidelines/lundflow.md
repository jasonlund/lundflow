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

