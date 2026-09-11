## lundflow settings

The per-project values the lundflow plugins read, each cited in the kit's prose as
"the *Key* setting". Every key is required — write `none` where a project has no
such thing.

| Key | Value |
|---|---|
| Ticket prefix | `<ABC>` |
| Guideline source | `<.ai/guidelines/project.md>` |
| Regenerate guidelines | `<php artisan boost:install --guidelines>` |
| Backend test (filtered) | `<php artisan test --compact --filter='{filter}'>` |
| Backend test (full) | `<php artisan test --compact>` |
| Frontend test (filtered) | `<npm test -- {filter}>` |
| Frontend test (full) | `<npm test>` |
| Finalize gates (backend) | `<vendor/bin/rector process {files}, then vendor/bin/pint --dirty --format agent>` |
| Finalize gates (frontend) | `<npm run lint, npm run format, npm run types>` |
| Conventions skill: backend | `<laravel:tdd-laravel-testing>` |
| Conventions skill: frontend | `<laravel:tdd-vue-testing or laravel:tdd-react-testing>` |
| Seam reference skill | `<laravel:codebase-design>` |
| Primary checkout | `<~/Sites/my-app>` |
| Solo workspace | `<my-app>` |
