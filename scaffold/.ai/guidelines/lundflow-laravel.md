## Architecture: Domain-Driven Design

Business logic organized by **domain**, not technical type. All domain code
shares one namespace root `App\Domains\*` under `app/` — so Rector, Shift, IDE
tooling, and Laravel auto-discovery work with no `composer.json` autoload
changes.

### Backend layout

```
app/Domains/
├── Common/              # shared kernel — used by many domains
│   ├── Contracts/       # interfaces other domains depend on
│   ├── ValueObjects/
│   ├── Enums/
│   └── Data/            # DTOs
└── {Domain}/            # e.g. Catalog, Billing — one bounded context
    ├── Models/
    ├── Actions/         # see Action classes below
    ├── Contracts/       # the ONLY cross-domain entry point
    └── ...              # add default Laravel folders as needed
```

- Plural `Domains`, one folder per bounded context.
- Folders use default Laravel names (`Models`, `Actions`, `Services`, `Events`,
  `Jobs`, `Policies`, `Enums`, `Exceptions`, `Data`, …). Create a subfolder only
  when you have something to put in it — no empty scaffolding.
- **A domain owns business logic, not the HTTP layer.** Never create
  `app/Domains/{Domain}/Http`. Infra/UI stays at `app/` root and *calls into*
  domains: `app/Http`, `app/Filament`, `app/Providers`.
- **Controllers live in `app/Http/Controllers/{Domain}`** — PascalCase folder per
  bounded context, namespace `App\Http\Controllers\{Domain}`, and `routes/web.php`
  points at them. Keep them thin — guard, call an Action or Service, respond.

### Class modifiers

Two mechanical rules, no per-file judgment, enforced by an arch test over
everything the repo owns (`app/`, the PSR-4 halves of `database/`, the helper
classes under `tests/`):

- **Every non-abstract class is `final`.** Inheritance is opt-in, and opting in is
  spelled `abstract` — a shared base (a command family's common engine) declares
  itself abstract and is named in the arch test's `->ignoring()` list, which a
  staleness guard pins as genuinely abstract.
- **A class with no parent is additionally `readonly`** — `final readonly class`.
  This holds for stateless and static-only helpers too; the point is that the
  shape is predictable, not that each class earns it individually.

The second rule stops at the parent because **PHP forbids a `readonly class` from
extending a non-readonly one** (a fatal, not a warning). So everything extending a
framework base — Models, Commands, Factories, Exceptions, spatie `Data`,
Providers, Middleware, Filament pages — can only ever take `final`. Enums, traits
and interfaces are outside both rules; enums are implicitly final and can never be
readonly.

Anonymous migration classes are the one structural exclusion — they can't be named,
and the arch targets (the PSR-4 halves of `database/`) don't reach them.

### Import every class, including global ones

**A class name is written bare and imported with a `use` statement — never inlined
as a leading-backslash FQCN.** This holds for global classes and PHP's own
attributes too: `use Override;` then `#[Override]`, not `#[\Override]`; `use
Throwable;` then `catch (Throwable $e)`, not `catch (\Throwable $e)`.

One import block at the top of the file is the only place a reader has to look to
learn what a class depends on, and a `\`-prefixed name in the body hides that
dependency from it.

A leading backslash you meet in existing code is debt, not precedent. Where no
Rector rule or arch test enforces this, it holds by review.

### Action classes

Single-purpose actions live in `App\Domains\{Domain}\Actions`.

- Name `VerbNoun` in PascalCase, **no `Action` suffix**; the noun includes the
  entity so it reads clearly when imported — `CreateUser`, `UpdateUserProfile`
  (not `Create`, `UpdateProfile`).
- Standalone actions expose one `handle()`. Actions bound to a framework contract
  (e.g. Fortify `CreatesNewUsers`) keep the interface's method name. Fortify
  auth/profile actions live in the owning domain's `Actions` (e.g.
  `App\Domains\Identity\Actions`), wired in `App\Providers\FortifyServiceProvider`.

### DTOs — domain boundaries speak in types, not array shapes

A public method on a domain's `Actions`/`Services` **never takes or returns a bare
`array`** for an app-shaped struct. `array{id: int, …}` in a docblock is a type the
language won't check; make it a class. Enforced by a DTO-boundary fence test.

- **Location: `App\Domains\{Domain}\Data`** — every data-carrying shape. `Support/`
  holds **behavior helpers only**. A class whose job is to carry values belongs in
  `Data/` even when it exposes accessors over them.
- **Base class by boundary:** plain `final readonly class` by default; extend spatie
  `Data` **only** when the object crosses a serialization boundary — Inertia props,
  `#[TypeScript]`, `::from()` hydration. A reflection-heavy base buys nothing on an
  internal service→action struct.
- **Plain carriers.** No `toArray()`, named constructors, or behavior. Marshalling
  belongs to the seam that needs it, not the DTO.
- **Nullability states trust.** A DTO of verified data types its fields tightly; one
  carrying unvalidated request data is nullable so a missing field reaches the
  validator instead of the constructor. Omitting a field entirely is a real guard —
  an input DTO with no `email` gives a spoofed one nowhere to land.

**Three exemptions, and only these** (the fence documents each entry with its reason):

1. **Raw upstream payloads** — the wire shape is the source's, not ours. Two forms,
   and which one you have decides how much of the signature is exempt:
   - **Ingest sinks — the exempt `array` is a *parameter*.** `array $payloads`/`$rows`/
     `$page` feeding columns that store the upstream values raw. A DTO there is a
     transform at ingest and breaks a list-driven column mapping. The **return**
     still converts — these hand back a count or a DTO.
   - **Wire-shape reads — the exempt `array` is the *return*.** A method whose
     `array`/`?array` return *is* the decoded upstream response body (an API
     client's fetch methods). No column mapping to break and no DTO planned —
     modelling a third party's response shape buys a class that changes whenever
     they change. The return stays `array` indefinitely.
2. **Framework-fixed signatures** — Fortify's `CreatesNewUsers::create(array $input)`,
   Inertia's `share(): array`. Not ours to retype.
3. **Scalar lists** — `list<int>`/`list<string>` returns. A list of ints is not a
   struct.

The fence **throws on an exemption entry that no longer resolves** to a real
`Class::method`, so the list can't rot into silently exempting nothing. Adding an
entry is a deliberate act — a new source integration must classify its ingest methods
consciously.

**Session gotcha:** `config('session.serialization')` is `json`, so a PHP object put
in the session decodes back as an array. Stash a JSON-safe payload and hydrate on
read. The Feature suite **cannot catch this** — the test client sends no session
cookie between requests, so each gets a fresh id and an in-memory object survives.
Round-trip the value through the serializer in the test.

### Exceptions

**One explicitly named class per distinct failure**, named for the condition,
PascalCase, in `App\Domains\{Domain}\Exceptions` — one domain often has several
(e.g. `CorruptImportArchive` and `CannotOpenImportArchive`).
Never funnel unrelated failures through a catch-all (factory methods or a
type/code discriminator) — split them so callers `catch` each by type. A static
named constructor (`::at($path)`) for the message is fine.

### Enums

Logic over an enum's **own cases** (validating, parsing, normalizing raw values
against the case set) lives as **static methods on the enum**, not a trait,
helper, or action — e.g. `Status::fromRawValues(array $raw): list<Status>`. Don't
reach for a shared `Concerns/` trait when a static enum method shares just as well
and keeps the knowledge on the type.

### Cross-domain rules

- A domain never imports another domain's `Models` or internals — only its
  `Contracts/` (interfaces) or published `Services`.
- `Common` is the shared kernel: only *incredibly stable* shared concepts (value
  objects, enums, contracts, DTOs). Keep it small — bloat couples every domain.
  `Common` depends on nothing domain-specific.

### Frontend layout (Inertia + React)

Mirrors backend domains (Inertia owns `pages/`, so it can't live under a PHP
namespace). Rule: *"Does it relate to a business domain/feature?"*

```
resources/js/
├── common/            # generic, reusable, no domain knowledge (mirrors Domains\Common)
├── modules/{domain}/  # reusable domain UI/logic across pages (mirrors Domains\{Domain})
└── pages/{domain}/    # Inertia entry points by domain; page-local components only
```

- Pages group by **domain**, not by URL — `pages/identity/Login.tsx`, lowercase
  folder matching `modules/{domain}`. The render key is the path, so the
  controller calls `Inertia::render('identity/Login')`. App-wide pages that
  belong to no domain (e.g. `Welcome`) sit at the `pages/` root, mirroring
  app-wide infra staying at `app/` root.
- `pages/{x}/components/` = that page only. Shared domain UI → `modules/`.
- PascalCase components, camelCase other files, kebab-case dirs, `Page`/`Layout`
  suffixes.

### Testing (DDD + TDD)

**Test-first by default** via the `lundflow:tdd` skill: RED → GREEN → REFACTOR, one
behavior **slice** (~2–6 tests) per cycle, each phase in an isolated subagent so
tests can't be retrofitted. RED slice approved first (plain terminal approval under
LaborForest + Solo — the gate is the approval, not the UI that renders it).

- **AAA, always.** Three blocks in order — arrange, **one** act, assert —
  separated by blank lines. One Act per test; need a second action → second test.
  Keep Arrange minimal (factories/props). Label form is a **strict, enforced
  standard** (mandatory label-only lines, ` & ` collapse only, protected
  banners) — see the testing skills.
- **Test behavior through public interfaces**, not implementation — tests survive
  refactoring. A slice = one behavior + its obvious variants.
- **Tests mirror the domain tree:** `tests/Feature/{Domain}/`,
  `tests/Unit/{Domain}/`, and `tests/Browser/{Domain}/` mirror
  `app/Domains/{Domain}/`.
- **`tests/Support/` holds helper *classes*** (PSR-4 `Tests\Support\…`; `composer.json`
  maps `Tests\` → `tests/`) backing the self-policing guards. `tests/Pest.php` stays
  the home for global helper *functions* (`fixtureBytes`, …); a cohesive rule engine
  with its own constants belongs in a class, which also sidesteps the suite-wide
  uniqueness rule on global helper names.
- **External-HTTP tests use real-data fixtures: byte-exact, in the API's native
  wire format**, committed under `tests/Fixtures/{Domain}/{source}/` in the exact
  extension the API returns (`.json`, `.tsv.gz`). Load via
  `fixtureBytes('{Domain}/{source}/<file>.json')`. Never fabricate bodies by
  hand. `Http::preventStrayRequests()` is global in Feature tests — fake every
  call. DB *state* uses factories, never fixtures. Synthetic bodies only for
  inputs real data can't produce (corrupt payloads, blank lines, HTTP errors).
- **Backend:** Pest 4, run through the *Backend test (filtered)* setting per slice
  and the *Backend test (full)* setting for the suite. Feature is default; Unit only
  for isolated logic. Factories + `RefreshDatabase`; assert Inertia with
  `AssertableInertia`. Create via `php artisan make:test --pest`.
- **Frontend:** Vitest + RTL, run through the *Frontend test (filtered)* / *Frontend
  test (full)* settings. Colocate `*.test.tsx`; query by role/text; mock
  `@inertiajs/react`; jsdom, setup `resources/js/test/setup.ts`.
- **Full-stack Inertia** → two cycles, backend first (assert component + props),
  then frontend (RTL renders with those props).
- Detailed conventions: the skills the *Conventions skill: PHP* and *Conventions
  skill: TSX/JSX* settings name (`laravel:tdd-laravel-testing` +
  `laravel:tdd-react-testing` in a Laravel + React project).

#### Browser tests (Pest 4 + Playwright) — the seam the other two suites can't reach

`tests/Browser/{Domain}/` drives real Chromium via `visit()`. It exists for one
reason: a Feature test posts raw HTTP so React never runs, and a Vitest test
stubs `@inertiajs/react` so the submit never leaves the component. **Neither
proves the form a user actually fills reaches the controller.** Write a browser
test only for that seam — a full-stack flow whose submit/redirect round trip is
otherwise unproven. Everything a Feature or RTL test can assert stays there;
they are far faster and browser coverage that duplicates them is pure drag.

- **Same process as the test.** Pest serves the app on an in-process Amp socket
  through `HttpKernel`, and merges `test()->prepareCookiesForRequest()` into
  every browser request. So the whole Laravel test API reaches the browser:
  `RefreshDatabase` on sqlite `:memory:`, `Http::fake()`,
  `$this->withSession([...])`, `actingAs`, `assertAuthenticated`. Arrange
  session/DB state in PHP exactly as in a Feature test — no UI setup walk.
- **Never let a browser test reach a third party.** A leg that redirects
  off-site (an OAuth hand-off to the provider's host) is out of scope by rule:
  following it hits a real host on every CI run. Cover that redirect with a
  Feature-test header assertion and start the browser test at the first page we
  serve.
- **Assert `assertNoJavaScriptErrors()`** on every page driven — it's the one
  check no other suite can make. Avoid `assertNoSmoke()`/`assertNoConsoleLogs()`
  unless the page is genuinely log-free.
- **Locate by `#id`, not text**, for form fields — ids stay stable, and text
  lookups break on the next copy or design pass.
- **Requires built assets.** `npm run build` must have run, or Inertia 500s on
  the Vite manifest. CI builds before Pest and installs Chromium with
  `npx playwright install --with-deps chromium`.
- Registered as its own `Browser` testsuite in `phpunit.xml` and bound in
  `tests/Pest.php` (`->in('Feature', 'Browser')`). Screenshots are gitignored.

Domain boundaries are enforced by **Pest architecture tests** (a domain's
`Models` used only within it; `Common` depends on no concrete domain).

### File creation

Create files with `php artisan make:*` whenever a generator exists (models,
migrations, policies, tests, `make:class`) — don't hand-write boilerplate. Land
them in the DDD structure by passing the domain path, e.g.
`php artisan make:model Domains/Catalog/Models/Product`. If a generator can't
target the domain path, generate then move the file and fix its namespace. Never
break the DDD layout to satisfy a generator's default location.

### Filament pages

**Never hand-write a page's Blade view for a standard form/table page.** Every
Filament page renders through its `content(Schema $schema)` method, not a bespoke
template — override `content()` and drop the `$view` property entirely. The base
`Page` already renders `{{ $this->content }}` via `filament-panels::pages.page`,
so a custom `.blade.php` under `resources/views/filament/` is templated
boilerplate that duplicates what the schema gives for free.

- Embed the page's form in `content()` the way Filament's own auth pages do:
  `Form::make([EmbeddedSchema::make('form')])->livewireSubmitHandler('save')`
  with the submit button as an `Actions`/`Action` in the form `->footer([...])`.
  (`EditProfile`/`Login` in `filament/filament` are the reference.)
- A custom Blade view is justified **only** for genuinely non-schema markup a
  `content()` schema can't express — and even then prefer `getHeader()`/
  `getFooter()` view slots over replacing the whole page view.

## Console commands: simple line-by-line heartbeat output

**Every artisan command emits simple, line-by-line progress output — never silent,
never fancy.** A command that runs a pipeline must let the operator see it working:
one plain `writeln` line per phase, plus an indented bracketed heartbeat as work
flows. The bar is a command someone runs at a prompt and gets a bare prompt back,
unsure anything happened — that is a defect.

- **Write through `$this->output->writeln(...)`** — the established convention.
  Not `$this->info()`, not `$this->components`, not a logger.
- **Two line shapes, that's it:**
  - a plain phase line — `writeln('Syncing orders…')`, `writeln('Done.')`;
  - an indented bracketed heartbeat — `  [stripe charges 1000]`,
    `  [github issues 288]` (two-space indent, `[tag value]`).
- **Simple, not fancy.** No progress bars, spinners, tables, colors, or ASCII art.
  Line by line. A heartbeat per phase boundary and per item in a long fan-out —
  enough to prove liveness, not a dashboard.
- Put the output in the shared base when a family of commands share an engine,
  so every subcommand inherits it.

### Heartbeat tags are source-prefixed

- **Every tag names its upstream source first** — `[stripe charges 1000]`,
  `[github issues 288]`. An orchestrator runs its children into one interleaved
  stream, so an unprefixed `[issues N]` is ambiguous — the same noun can mean one
  thing from one source and a different thing from the next.
- **Local tooling has no third-party source, so the tag names the work instead** —
  `[dump orders 240]`, `[import orders]`. Don't invent a prefix for it.
- **`[elapsed …]` is the one unprefixed exception** — `[elapsed {leg} 12.4s]`,
  `[elapsed 12.4s]`. It measures the leg rather than naming what the leg read, so
  there is no source to put first. Don't prefix it, and don't copy the shape for a
  tag that does count a source's work.
- **The value need not be a count.** `[github issues p10]` is a walk position and
  `[elapsed issues 12.4s]` a leg duration; a reader tells them apart by the value.
  Only *running totals* go through the emitter below — a position or a one-shot
  fact stays a plain `writeln`.

### `EmitsHeartbeat` is the shared emitter

`App\Domains\Common\Console\Concerns\EmitsHeartbeat`. New commands `use` it rather
than hand-rolling a counter: it tracks the last mark **per tag**, so one command
can beat several tags independently and the closing total never repeats a line the
beat already printed.

- **`mark($tag, $total, $suffix = null)` — the caller owns the cadence.** For a
  one-shot count (`[github repos 2]`) or a per-batch flush where the batch size is
  already the operator's cadence knob. Reaching for `beat()` at those callsites
  silences nearly every line, because the totals sit far below any sensible
  interval.
- **`beat($tag, $total, $interval, $suffix = null)` — a running total on interval
  boundaries.** Reach for it when a total climbs steadily and the interval, not the
  batch, is the operator's cadence knob.
- **`flushTotal($tag, $total)` — the closing exact total.** Every run calls it, so a
  run shorter than one interval still reports what it did.
- **`failureSummary($count, $noun, $consequence)` — the closing failure line**,
  a no-op at zero.

### Every run closes: final total, failures, `Done.`

- **A final total, then `Done.`, on every command.** `flushTotal()` exists so a run
  shorter than one beat interval still reports what it did — otherwise a short run
  prints no count at all — and a run that processed nothing still prints its `0`.
  The one exception is a marker-gated early return (`… unchanged since the last
  sync; skipping.`): that line is already terminal, and `Done.` after it would
  claim work that never happened.
- **A caught-and-reported failure emits a plain closing line naming the count *and*
  the consequence** — `3 records failed; marker not advanced.` Unindented on
  purpose: the two-space indent means "running count", and a run-level consequence
  is not one. The consequence is the half that matters, and it names whatever
  *this* leg held back — `marker not advanced`, `watermark not advanced`. Either
  way it says the window gets re-covered next run.
- **…and the command returns `FAILURE`.** An invisible failure that silently skips
  a marker advance is worse than a cron alert on a transient miss. A 404 is still
  **not** a failure — it stays present-as-null, or every deleted upstream record
  would alert on every run.
- **An orchestrator names what it lost rather than counting it** — it closes with
  `Failed commands: sync:orders`, because a re-runnable command name is more use to
  an operator than a number. That is why it does **not** go through
  `failureSummary()`: a count would say "1 command failed" of a run whose whole
  point is that it kept going, leaving the operator to find which one in the
  interleaved wall of child output. `Done.` still follows it — losing a leg does not
  exempt a run from closing.

## Laravel helpers over PHP functions

Prefer `Illuminate\Support\Str` / `Arr` helpers over the PHP-native equivalents.
Where the project enforces it mechanically — a custom `FuncCallToStaticCall` map in
`rector.php` rewrites the native call and a `rector --dry-run` CI gate fails on any
that slip in — you rarely hand-write the native form.

- **Rector-rewritten (don't write the native form):** `str_starts_with` /
  `str_ends_with` / `str_contains` → `Str::startsWith`/`endsWith`/`contains`;
  `str_replace` → `Str::replace`; `strtolower`/`strtoupper`/`ucfirst` →
  `Str::lower`/`upper`/`ucfirst`; `trim`/`ltrim`/`rtrim` → `Str::trim`/…;
  `substr` → `Str::substr`; `strlen` → `Str::length`; `str_repeat` →
  `Str::repeat`; `ucwords` → `Str::ucwords`.
- **Stay native — do NOT "fix" these** (no clean 1:1, don't add them to the map):
  `array_key_exists` (`Arr::exists($array, $key)` swaps the argument order — the
  positional Rector map can't express it); signature-mismatch `str_pad` /
  `preg_replace` / `explode`; no-equivalent `preg_match` /
  `preg_split` / `implode` / `sprintf` / `count` / `in_array` and
  the `array_map`/`filter`/`merge`/`keys`/`values`/`column`/`unique`/`flip`/
  `combine` family; `json_encode` / `json_decode` (`Js::` is HTML-embedding only);
  `last()` / `head()` (already helpers).
- **Multibyte caveat:** `Str::lower`/`upper`/`length`/`substr` are multibyte and
  `Str::trim` strips unicode whitespace (nbsp/BOM) — correct for ASCII inputs. If
  a call measures **bytes** (payload size), keep native `strlen`/`substr`.
- **Null-haystack caveat:** `Str::startsWith`/`endsWith`/`contains` coerce a
  `null` haystack to `false`, where the native `str_starts_with`/`str_ends_with`/
  `str_contains` (under `strict_types`) would throw a `TypeError` — guard the
  haystack rather than relying on the silent `false`.
- **Preferred, outside the map:** `number_format` → `Number::format`
  (`currency`/`fileSize`/`percentage`); `date()`/`time()`/`strtotime()` →
  `now()`/`today()`/Carbon.
- **Collections over arrays** — in **new** code prefer a Collection pipeline
  (`collect($x)->map(...)->filter(...)->pluck(...)`) over chained `array_*` calls.
  Convention only (not Rector-enforced); existing arrays are left as-is.

## Persistence: iterating rows you write to → `chunkById`

When a loop **writes to the DB rows it is iterating** (stamping a column,
flipping a flag, reconciling a crosswalk), walk the query with **`chunkById()`**,
not `chunk()`, `get()`, or `lazy()`. `chunkById` paginates by primary key, so
mutating a **non-key** column mid-iteration cannot skip or double-process a
row — the failure mode `chunk()` (offset-paginated) and a materialized `get()`
both invite. This is the default for any iterate-and-write path; deviate only for
a **distinct, stated reason** (e.g. a read-only walk, or a set small and bounded
enough that materializing is provably fine — say why in a comment).

- Read-only iteration with no writes → `lazy()`/`cursor()` is fine (streams
  without the PK-pagination overhead).

## Cache: store scalars, never objects

`config/cache.php` sets `'serializable_classes' => false` (Laravel's gadget-chain
hardening default), so every store reads through
`unserialize($value, ['allowed_classes' => false])` and **no object survives the
round trip** — it returns as `__PHP_Incomplete_Class`. A `Cache::put`/`forever` of
an object writes fine and can never be read back: the value is write-only.

- **Cache strings, ints, bools, and arrays of those.** A timestamp goes in as
  `->toIso8601String()` and is parsed on read; a header goes in verbatim.
- **Type-check the read** whenever a stale key may predate the rule
  (`is_string($marker)`) and degrade to the no-value path. An entry poisoned by an
  older build then self-heals on the next write instead of throwing — no manual
  `cache:forget` in the deploy.
- **Never widen `serializable_classes` to rescue a call site** — it weakens a
  security default app-wide for one value that should have been a scalar.
- **The test `array` store is `'serialize' => true` on purpose**, against the
  framework default, so the suite serializes exactly like production. Leaving it
  false lets a cached `CarbonImmutable` pass the whole suite and fail every
  production run. Never flip it back.
