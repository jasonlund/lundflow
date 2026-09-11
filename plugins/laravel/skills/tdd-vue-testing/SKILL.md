---
name: tdd-vue-testing
description: >-
  Conventions for writing Vue 3 + Inertia frontend tests (Vitest + Testing
  Library for Vue): rendering pages/components with props and slots, querying by
  role, user interaction, emitted events, and Inertia mocking. Loaded by the TDD
  subagents when the target is a `.vue` single-file component or frontend
  TypeScript/JavaScript in a Vue project. Use when writing or refactoring Vue tests.
---

# Vue + Inertia testing conventions

> Stack: Vue 3 `<script setup lang="ts">` SFCs + `@inertiajs/vue3` ^3, Vite, npm.
> Expected toolchain: Vitest + `@testing-library/vue` + jest-dom + user-event +
> jsdom, `@vitejs/plugin-vue` in `vite.config.ts` (Vitest compiles `.vue` files
> through it), a `test` block (`environment: 'jsdom'`, `globals: true`,
> `setupFiles: ['resources/js/test/setup.ts']`), a setup file importing
> `@testing-library/jest-dom/vitest`, and `vitest/globals` + jest-dom types
> registered in `tsconfig.json`. Keep `globals: true` — Testing Library registers
> its after-each unmount only when `afterEach` is global, so without it rendered
> components leak into the next test.

## Runner & commands

- **Vitest** + **Testing Library for Vue** + `@testing-library/jest-dom` +
  `@testing-library/user-event`.
- The project's own commands are the *Frontend test (filtered)* and *Frontend test
  (full)* settings — use them where they differ from the defaults below, and
  confirm both against the `scripts` in `package.json`.
- Run one test file: `npx vitest run resources/js/pages/orders/Index.test.ts`
- Watch a single file while iterating: `npx vitest resources/js/.../X.test.ts`
- Whole suite: `npm test`.
- Run the slice under work during a TDD cycle; run the broader suite before
  finishing GREEN.

## Where tests live

- Pages live in `resources/js/pages/` (**lowercase**; Inertia resolves
  `./pages/${name}.vue`), components in `resources/js/components/`.
- Colocate a `*.test.ts` sibling next to the SFC under test — `Index.vue` →
  `Index.test.ts`. No JSX in Vue tests, so `.ts`, never `.tsx`.

## Patterns

- Render the page/component **with props** (the same shape the Laravel Inertia
  response provides) and assert what the user sees. A page receives its Inertia
  props as ordinary component props (`defineProps<{ orders: Order[] }>()`), so pass
  them through `render(Component, { props })` rather than a real Inertia visit:

**Arrange–Act–Assert (mandatory):** three blank-line-separated blocks, one Act per
test. For pure-render tests the render is the Act:

```ts
import { render, screen } from '@testing-library/vue'
import Index from './Index.vue'

describe('order index page', () => {
  it('shows the orders returned by the server', () => {
    // Arrange
    const orders = [{ id: 1, reference: 'ORD-1001' }]

    // Act
    render(Index, { props: { orders } })

    // Assert
    expect(screen.getByRole('heading', { name: /ord-1001/i })).toBeInTheDocument()
  })
})
```

- **Query by role/text/label** through `screen` (`getByRole`, `getByLabelText`,
  `getByText`), not test IDs or class names. Use `findBy*` for async UI.
- **Never reach into the instance.** No `wrapper.vm`, no reading refs or reactive
  state, no `@vue/test-utils` `mount` alongside Testing Library. What the user sees
  and what the parent receives are the whole contract.
- **Name tests for WHAT, not HOW** — `test('shows an error when the reference is
  blank')`, not `test('sets errors.reference')`.
- **Never tautological.** The expected value must not be recomputed the way the
  component computes it. Deriving the expected text from the same props with the
  same `map`/`format` call makes the assertion pass by construction:

  ```ts
  // BAD — recomputes the component's own formatting
  expect(screen.getByText(`${order.reference} (${order.status})`)).toBeInTheDocument()

  // GOOD — an independent literal
  expect(screen.getByText('ORD-1001 (paid)')).toBeInTheDocument()
  ```
- Drive interaction with `userEvent`, not `fireEvent`: create the user in Arrange
  with `const user = userEvent.setup()`, then `await user.click(...)` /
  `await user.type(...)` as the Act. Await every call — Vue patches the DOM on the
  next tick, and an unawaited interaction asserts against the old render.
- **Emitted events and slots are observable behavior.** A parent sees what a
  component emits and what it does with slot content, so assert those — the
  `emitted()` record from the render result, and the slot's rendered output:

  ```ts
  it('emits the chosen order when its row is selected', async () => {
    // Arrange
    const user = userEvent.setup()
    const { emitted } = render(OrderRow, { props: { order: { id: 7, reference: 'ORD-1007' } } })

    // Act
    await user.click(screen.getByRole('button', { name: /select ord-1007/i }))

    // Assert
    expect(emitted().select).toEqual([[7]])
  })
  ```

  Pass slot content through `render(Card, { slots: { default: 'Paid in full' } })`
  and assert it with `screen.getByText('Paid in full')`, never by inspecting the
  slot function.
- **Render our own children for real.** `global.stubs` is for a third-party
  component that can't run in jsdom; stubbing one of the app's own child components
  couples the test to the component tree. `global.plugins` / `global.provide` carry
  what the app installs at boot.
- Mock Inertia where components call it: stub `@inertiajs/vue3`'s `router`, `Link`,
  `Head`, `useForm`, or `usePage` with `vi.mock` so you test the component's
  behavior, not Inertia internals. `vi.mock` is hoisted above the imports, so a mock
  the test later asserts on is declared with `vi.hoisted`:

  ```ts
  const { post } = vi.hoisted(() => ({ post: vi.fn() }))

  vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, reactive } = await import('vue')

    return {
      Head: defineComponent({ render: () => null }),
      Link: defineComponent({
        props: { href: { type: String, required: true } },
        setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()),
      }),
      router: { visit: vi.fn(), get: vi.fn(), post: vi.fn() },
      usePage: () => ({ props: { auth: { user: { name: 'Ada Lovelace' } } } }),
      useForm: <T extends object>(data: T) => reactive({ ...data, errors: {}, processing: false, post }),
    }
  })
  ```

  `useForm` returns a reactive object, so the stub must be `reactive` too, or
  `v-model` on `form.reference` never reaches the value the test asserts on.

**Source:** the never-tautological rule and WHAT-not-HOW test naming are adapted
from `mattpocock-skills:tdd`'s `tests.md`. Offer to explain the upstream reasoning
when one of them rejects a test.

## Full-stack Inertia features

Two cycles, **backend first**. The first is a Feature test under
`laravel:tdd-laravel-testing` asserting the Inertia component name and props
(`->component('orders/Index')` resolves `resources/js/pages/orders/Index.vue`). The
second is the Vue test rendering that page with the same prop shape the Feature
test pinned — so a renamed prop fails on the backend contract, not silently in the
browser.

## Test-comment standard (strict)

Test comments are **deliberate and mandatory** — one canonical form, the same one
the Pest suite holds:

1. **AAA labels mandatory, one per block, label-only line.** `// Arrange`,
   `// Act`, `// Assert` — each alone on its line, no prose appended.
2. **Collapse only when one statement serves two roles**, joined by ` & `
   (space-ampersand-space): `// Arrange & Act`, `// Act & Assert`. Only `&` —
   never `/` or a no-space variant.
3. **Missing / unneeded block → label + reason on the next line**, never silently
   absent:

   ```ts
   // Arrange
   // no props or state to set up — pure static render
   ```
4. **Why-prose on its own line(s), above the label it explains.** The AAA line
   stays label-only.

## Test-organization standard (strict)

The same standard the Pest suite follows. Its grouping and description-form rules
key off `describe(`/`it(`, so they read identically in both languages:

- **Every `it()`/`test()` lives inside a `describe()`.** Several top-level
  describes per file are fine; nesting allowed. Never a top-level test.
- **Descriptions** start lowercase, never start with "should", and are unique
  within their describe.
- **Describe labels are unique within a file** — no two `describe()` blocks in
  one file may share a label, at any nesting level.

Judgment rules, not machine-checkable: label a describe by **subject + facet**
(`describe('Login page', …)`, `describe('submit handler', …)`), put the happy path
first and failures last, and prefer a per-`describe` `beforeEach` over repeating
the same arrange in every test of that group. The skeleton-order and helper-name
rules are PHP-only and don't apply here.

## RED checklist (for `lundflow:tdd-test-writer`)

- A small cohesive set (2–6) of failing tests for one behavior slice; each describes
  one user-observable behavior (something rendered, an event emitted, or a reaction
  to interaction).
- Render with realistic props; assert via role/text or `emitted()`.
- Run it; it must fail on the **assertion** (element/behavior absent), not on a
  compile error from a missing SFC or a render crash from an unrelated missing mock.

## REFACTOR targets (for `lundflow:tdd-refactorer`)

- Extract repeated logic into **composables** (`useX`) and repeated markup into
  **components**; move derived values into `computed`.
- Simplify conditionals; clarify prop, emit, and variable names.
- Keep accessibility roles and emitted event names intact so behavior-level tests
  stay valid. Keep tests green; show the run.
