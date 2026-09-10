#!/usr/bin/env bash
set -euo pipefail

kit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
claude_dir="$HOME/.claude"
settings="$claude_dir/settings.json"
readonly rtk_include='@RTK.md'

# Reads the existing settings on stdin and the kit fragment as $fragment.
#
# `*` deep-merges objects with its right-hand side winning, so laying the
# existing settings over the fragment keeps every value already on the machine
# and only fills in the keys it lacks.
#
# Arrays do not deep-merge: `*` would let an existing hook event replace the
# fragment event wholesale, so a machine with any PreToolUse hook of its own
# would never receive the kit hooks. Hooks therefore merge per event instead:
# the existing groups stay as they are, and each fragment group is appended
# carrying only the commands no existing group under that event already runs,
# dropped entirely once none remain. That is also what makes a re-run a no-op.
readonly merge_settings_program='
    . as $current
    | $fragment[0] as $machine
    | ($machine * $current)
    | .hooks = reduce (($machine.hooks // {}) | to_entries[]) as $event ($current.hooks // {};
        (.[$event.key] // []) as $existing
        | [$existing[].hooks[]?.command] as $known
        | .[$event.key] = $existing + [
            $event.value[]
            | .hooks |= map(select(.command as $command | any($known[]; . == $command) | not))
            | select(.hooks | length > 0)
        ])
'

heartbeat() {
    echo "  [machine $1]"
}

require_jq() {
    command -v jq >/dev/null 2>&1 || {
        echo "machine/install.sh needs jq to merge settings.json; install it and re-run." >&2
        exit 1
    }
}

current_settings() {
    if [[ -f "$settings" ]]; then
        cat "$settings"
    else
        printf '{}'
    fi
}

merge_settings() {
    echo 'Merging machine settings…'

    # Written beside the target so the move is a same-filesystem rename: a jq
    # failure leaves the live settings untouched instead of truncated.
    settings_tmp="$(mktemp "$settings.XXXXXX")"
    trap 'rm -f "$settings_tmp"' EXIT

    current_settings \
        | jq --slurpfile fragment "$kit_dir/settings.json" "$merge_settings_program" \
        > "$settings_tmp"

    mv "$settings_tmp" "$settings"
    trap - EXIT

    heartbeat 'settings merged'
}

# Never overwrites: once installed, the file is the user's to edit, and a re-run
# must not revert their changes to the kit's copy.
install_machine_file() {
    local relative="$1"
    local target="$claude_dir/$relative"

    if [[ -e "$target" ]]; then
        heartbeat "kept $relative"
        return
    fi

    mkdir -p "$(dirname "$target")"
    cp "$kit_dir/$relative" "$target"
    heartbeat "created $relative"
}

install_machine_files() {
    echo 'Installing machine files…'

    while IFS= read -r relative; do
        install_machine_file "$relative"
    done < <(cd "$kit_dir" && find rules hooks -type f | LC_ALL=C sort && echo RTK.md)
}

# Claude Code reads RTK.md only through an `@` include in the user CLAUDE.md, so
# installing the file alone would leave it inert.
ensure_claude_include() {
    local claude_md="$claude_dir/CLAUDE.md"

    [[ -f "$claude_md" ]] && grep -qxF "$rtk_include" "$claude_md" && return

    # A last line with no trailing newline would otherwise swallow the include.
    if [[ -s "$claude_md" && -n "$(tail -c 1 "$claude_md")" ]]; then
        printf '\n' >> "$claude_md"
    fi

    printf '%s\n' "$rtk_include" >> "$claude_md"
}

print_manual_steps() {
    cat <<'EOF'
Manual steps this script cannot take:
  - In each project: composer require jasonlund/lundflow, then php artisan lundflow:install; trust the folder so Claude Code installs the plugins its .claude/settings.json declares.
  - Install the mattpocock-skills engineering skills under ~/.claude/skills/.
  - Register the Linear, LaborForest and Solo MCP servers, each with its own token/app; LaborForest's claude mcp add line is on its Settings → MCP page after Save.
  - Trust each project's solo.yml commands in Solo's UI.
EOF
}

main() {
    require_jq
    mkdir -p "$claude_dir"

    merge_settings
    install_machine_files
    ensure_claude_include

    print_manual_steps
    echo 'Done.'
}

main "$@"
