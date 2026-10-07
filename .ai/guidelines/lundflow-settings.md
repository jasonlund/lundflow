## lundflow settings

The per-project values the lundflow plugins read, each cited in the kit's prose as
"the *Key* setting". Every key is required — write `none` where a project has no
such thing, except *Kit root*, which is `.` when the kit sits at the repo root.

| Key | Value |
|---|---|
| Ticket prefix | `LUN` |
| Kit root | `.` |
| Guideline source | `CLAUDE.md` |
| Regenerate guidelines | `none` |
| Backend test (filtered) | `vendor/bin/pest --compact --filter='{filter}'` |
| Backend test (full) | `composer test` |
| Frontend test (filtered) | `none` |
| Frontend test (full) | `none` |
| Finalize gates (backend) | `vendor/bin/pint --dirty --format agent` |
| Finalize gates (frontend) | `none` |
| Conventions skill: backend | `laravel:tdd-laravel-testing` |
| Conventions skill: frontend | `none` |
| Seam reference skill | `laravel:codebase-design` |
| Primary checkout | `~/Sites/lundflow` |
| Solo workspace | `lundflow` |
