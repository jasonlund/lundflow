#!/usr/bin/env bash
#
# UserPromptSubmit hook — route feedback on completed work through tdd-feedback.
#
# Fires when a prompt looks like feedback / a change request against work that is
# already done (review comments, bug reports, "remove X") — the trigger for the
# `lundflow:tdd-feedback` skill. Injects a reminder so the agent classifies + routes
# instead of jumping straight to grep/edit.
#
# It only REMINDS (stdout -> added context); it never blocks. Exit 0 always.

set -euo pipefail

INPUT="$(cat)"

# --- pull the user's prompt text out of the hook payload -----------------------
PROMPT="$(printf '%s' "$INPUT" | jq -r '.prompt // ""' 2>/dev/null || true)"

# A finished background task reaches this hook as a prompt. Its summary is the
# agent's wording, not the user's, so it is never feedback however it reads.
case "$PROMPT" in *'<task-notification>'*) exit 0 ;; esac

LOWER="$(printf '%s' "$PROMPT" | tr '[:upper:]' '[:lower:]')"

# --- feedback-shaped language --------------------------------------------------
# Conservative: targets feedback on EXISTING work, not new feature asks
# (those are the plain `lundflow:tdd` skill's job).
printf '%s' "$LOWER" | grep -Eq \
  'review(er)?|pr comment|code review|diff comment|this comment was left|after merge|post-merge|already merged|\bbug\b|regression|reproduce|doesn'\''t work|is broken|should (be|have been)|why (is|does|did) (this|it)|\bremove\b|\brename\b|\bchange\b' \
  || exit 0

cat <<'EOF'
[feedback-router] This prompt looks like FEEDBACK / a change request on work that
already exists (review comment, bug report, "remove/rename/change X").

Before reading or editing ANY file, walk this check in order:

  1. Did the user invoke a DIFFERENT skill in THIS message?
       YES -> follow that skill; stop here.
       NO  -> continue.

  2. Did the user explicitly say this is NOT TDD work / not to use the skill in
     THIS message?
       YES -> proceed normally; stop here.
       NO  -> continue.

  3. Neither applies -> invoke the `lundflow:tdd-feedback` skill NOW, FIRST.

Let the skill classify each item (BUG / SLICE / REFACTOR / DIRECT) and route. Do
NOT jump straight to grepping or editing, and do NOT decide for yourself that the
work is "not TDD" or "just config" — that judgment is the skill's job, not yours.
EOF
exit 0
