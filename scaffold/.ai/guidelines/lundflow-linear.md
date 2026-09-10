## Linear (issue tracking)

- **Always use the `mcp__linear-server__*` tools** for every lookup/create/update
  — never assume or hand-edit ticket state.
- **Write to the ticket body, never comment.** When recording progress, plans,
  results, or deviations, replace or append the ticket's **description**
  (`save_issue` with `description`) — keep it the single source of truth, not
  `save_comment`.
- **Every branch maps to ≥1 ticket**, and the branch name is **derived, not pasted**.
  Linear's own `gitBranchName` runs past 60 characters, which propagates into the
  worktree directory and every per-workspace name derived from it. `/worktree:up`
  derives it for you. The shape: every ticket id (`{PREFIX}-123`, where `{PREFIX}`
  is the *Ticket prefix* setting), lowercased and `-`-joined, then **at most 20
  characters** of title slug cut at a word boundary (e.g.
  `{prefix}-123-scaffold-new`). Deriving is what makes the budget unskippable — a
  rule an agent has to remember is one it forgets. The 40-character workspace-slug
  trim in *Local worktree tooling: LaborForest + Solo* is a **separate downstream
  guard**, unchanged by this and still covering a hand-cut branch.
- **No ticket yet → prompt to create one** before proceeding.
- **Work deviates → confirm first, then update the ticket** and mark it a
  deviation.
- **Planning artifacts live in Linear, not in the repo.** PRDs, plans, slice
  backlogs, and decomposition notes belong in the relevant Linear issue —
  never committed as repo files. Don't create a `docs/plans` or `.ai/plans`
  tree; a plan on disk drifts from the ticket and biases future agents who read
  it as a convention. Bars *version-controlled* planning files only — gitignored
  scratch space is fine; it never enters the repo.
- **Durable decision records are a different artifact class, and DO live in the
  repo.** The bar above is on **per-ticket** planning — a plan for one piece of
  work, which drifts from its ticket the moment either changes. A **glossary**
  (`CONTEXT.md`) and an **ADR** (`docs/adr/NNNN-slug.md`) are neither: they are
  cross-ticket, decision-level, and outlive the work that produced them. They also
  have to be checked in to do their job — skills read them from the working tree
  while exploring, which a Linear body can't support. Both are created **lazily**,
  only when a term is actually resolved or a decision actually made; see *Domain
  docs*.
  - An **ADR is 1–3 sentences** and earns its place only when all three hold:
    hard to reverse, surprising without context, and the result of a real
    trade-off. Miss one and skip it — an easily-reversed decision just gets
    reversed, and an unsurprising one leaves nobody wondering why.
  - **Don't duplicate what the guidelines already say.** A convention documented
    at length in a guideline layer or the *Guideline source* (e.g. *Architecture:
    Domain-Driven Design*) does not also get an ADR; two sources drift. ADRs are
    for decisions with no home there — especially deliberate deviations from an
    outside authority.

### Automatic ticket status transitions

The flow skills advance a ticket's status **automatically** as work crosses each
lifecycle boundary — no manual status changes. The map (each fires once, at the
boundary named):

| Boundary | Fired by | Target status |
| --- | --- | --- |
| Planning done (TDD backlog appended) | `lundflow:plan-slices` | **Todo** |
| Execution begins (first slice for the ticket) | `lundflow:tdd` | **In Progress** |
| PR opened | `/lundflow:review:create-pr`, then **verified** (see below) | **In Review** |
| PR merged | Linear's native GitHub integration | **Done** |

The lifecycle order is `Backlog < Todo < In Progress < In Review < Done`. Each
transition follows one shared contract — reference this section from the skills
rather than restating it:

- **Primitive.** `mcp__linear-server__save_issue(id: <{PREFIX}-XXX>, state: "<name>")`
  — pass the status **name**, never an id. MCP only; no bash/token path.
- **Resolve the ticket from the branch** (`{prefix}-XXX-…` → `{PREFIX}-XXX`, the
  `lundflow:review-pipeline` Ticket ID Auto-Extraction). **No ticket resolves →
  skip silently** (the "when applicable").
- **Forward-only.** Apply the target **only if** the ticket's current status is
  *strictly earlier* in the lifecycle. Never move backward; a re-run at or past
  the target is a silent no-op.
- **Never touch `Canceled` / `Duplicate`** tickets.
- **Active ticket only.** Each ticket transitions when *its own* work runs;
  sibling sub-tickets and a decomposed parent are left untouched. (Exception:
  at PR-open, every ticket the PR covers moves to In Review together.)
- **Report, don't ask.** State the transition in one line; the change is
  automatic — never prompt for permission.

### PR-open is contended — write, then verify

At PR-open **both** `/lundflow:review:create-pr` and Linear's GitHub integration
write the status, and the integration's default mapping for *opened* is In
Progress — so our In Review write can be reverted milliseconds later,
nondeterministically and silently. That one transition is therefore **write → read
back → correct once**: `save_issue(state: "In Review")`, re-read with `get_issue`
**after** the PR-created call returns, and re-apply once if it was reverted (say so
in the report). A **second** revert means the integration is fighting the contract
— stop, leave it, tell the user to fix the mapping, never loop. **The durable cure
is one writer, not a better retry:** set the integration's PR-opened mapping to In
Review in Linear's GitHub settings — a vendor-dashboard click, so offer
`mattpocock-skills:wizard`.

The incident behind the rule and the timing forensics:
`docs/agents/linear-pr-open-contention.md`.
