---
name: tdd-vue-testing
description: >-
  Conventions for writing Vue 3 + Inertia frontend tests with Vitest and either
  Vue Test Utils (`@vue/test-utils`) or Testing Library for Vue
  (`@testing-library/vue`), whichever the project installs: rendering
  pages/components with props and slots, asserting rendered output and emitted
  events, user interaction, and Inertia mocking. Loaded by the TDD subagents when
  the target is a `.vue` single-file component or frontend TypeScript/JavaScript
  in a Vue project. Use when writing or refactoring Vue tests.
---

# Vue + Inertia testing conventions

> Stack: Vue 3 `<script setup lang="ts">` SFCs + `@inertiajs/vue3` ^3, Vite, npm.
> Expected toolchain: Vitest + jsdom + one component-testing library (see
> *Choosing the library*), `@vitejs/plugin-vue` in `vite.config.ts` (Vitest
> compiles `.vue` files through it), a `test` block (`environment: 'jsdom'`,
> `globals: true`, `setupFiles: ['resources/js/test/setup.ts']`), and
> `vitest/globals` types registered in `tsconfig.json`. Testing Library adds
> jest-dom + user-event, a setup file importing `@testing-library/jest-dom/vitest`,
> and jest-dom types. Keep `globals: true` — Testing Library registers its
> after-each unmount only when `afterEach` is global, so without it rendered
> components leak into the next test. Vue Test Utils unmounts nothing on its own;
> `enableAutoUnmount(afterEach)` in the setup file is its equivalent.

## Choosing the library

Both are first-class. The project's install decides, never a preference — read
`dependencies` and `devDependencies` in `package.json`:

| Installed | Write with |
| --- | --- |
| `@vue/test-utils` only | Vue Test Utils |
| `@testing-library/vue` only | Testing Library |
| both (common — Testing Library is built on Vue Test Utils) | whatever the existing tests nearest the target use — the same folder first, then the rest of `resources/js/`; none yet → Testing Library |
| neither | Testing Library, and the RED card says so |

One library per test file — never `mount` and `render` side by side.

## Runner & commands

- **Vitest** + the chosen library: **Vue Test Utils**, or **Testing Library for
  Vue** with `@testing-library/jest-dom` + `@testing-library/user-event`.
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

Every rule here holds under both libraries; the two sections after it translate
them into each library's calls.

- Render the page/component **with props** (the same shape the Laravel Inertia
  response provides) and assert what the user sees. A page receives its Inertia
  props as ordinary component props (`defineProps<{ orders: Order[] }>()`), so pass
  them through the `props` mounting option rather than a real Inertia visit. Slot
  content goes through `slots`, app-level installs through `global` — both
  libraries take the same mounting options.

**Arrange–Act–Assert (mandatory):** three blank-line-separated blocks, one Act per
test. For pure-render tests the render (`mount` / `render`) is the Act; for an
interaction test the render is Arrange and the interaction is the Act.

- **Assert rendered output and emitted events — never component internals.** What
  the user sees and what the parent receives are the whole contract: rendered
  text, elements, attributes and visibility, the events a component emits, and
  what it does with slot content. No component instance, refs, reactive state or
  methods — each library section names the calls this rules out.
- **Drive interaction the way a user would** — click the button, type into the
  field — and await every interaction. Vue patches the DOM on the next tick, and an
  unawaited interaction asserts against the old render.
- **Name tests for WHAT, not HOW** — `test('shows an error when the reference is
  blank')`, not `test('sets errors.reference')`.
- **Never tautological.** The expected value must not be recomputed the way the
  component computes it. Deriving the expected text from the same props with the
  same `map`/`format` call makes the assertion pass by construction:

  ```ts
  // BAD — recomputes the component's own formatting
  const expected = `${order.reference} (${order.status})`

  // GOOD — an independent literal
  const expected = 'ORD-1001 (paid)'
  ```
- **Render our own children for real.** `global.stubs` is for a third-party
  component that can't run in jsdom; stubbing or shallow-rendering one of the app's
  own child components couples the test to the component tree — the Vue form of
  never mocking our own classes. `global.plugins` / `global.provide` carry what the
  app installs at boot.
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

## Vue Test Utils

- **Mount** with `mount(Component, { props, slots, global })` from
  `@vue/test-utils`:

  ```ts
  import { mount } from '@vue/test-utils'
  import Index from './Index.vue'

  describe('order index page', () => {
    it('shows the orders returned by the server', () => {
      // Arrange
      const orders = [{ id: 1, reference: 'ORD-1001' }]

      // Act
      const wrapper = mount(Index, { props: { orders } })

      // Assert
      expect(wrapper.get('h2').text()).toBe('ORD-1001')
    })
  })
  ```
- **Locate by what a user sees:** element selectors (`get('button')`, `get('h1')`,
  `findAll('li')`) and attributes that carry accessibility or behavior
  (`find('[aria-label="Select ORD-1007"]')`, `get('input[name="reference"]')`,
  `find('[role="alert"]')`). Among several matches, pick by text —
  `findAll('button').find((b) => b.text() === 'Save')`. Prefer these over
  test-only classes or ids, which name markup the user never sees; add a
  `data-testid` only as a last resort, when nothing a user would recognize tells
  the element apart.
- **Assert on output:** `wrapper.text()` or `get(...).text()` for content (`toBe`
  for one element's exact text, `toContain` for a passage in a larger render),
  `attributes('href')` for an attribute, `isVisible()` for `v-show`. `get` throws
  naming the selector when nothing matches, so use it for what must be there;
  assert absence with `expect(wrapper.find('[role="alert"]').exists()).toBe(false)`.
- **Interact through the DOM:** `await wrapper.get('button').trigger('click')`,
  `await wrapper.get('input[name="reference"]').setValue('ORD-1001')` (fires
  `input`, so `v-model` updates). Both resolve on the next tick — await them.
  `await wrapper.setProps({ … })` is a parent passing new props: observable, allowed.
- **Emitted events:** `wrapper.emitted('select')` holds one argument array per
  emission, `undefined` when it never fired:

  ```ts
  it('emits the chosen order when its row is selected', async () => {
    // Arrange
    const wrapper = mount(OrderRow, { props: { order: { id: 7, reference: 'ORD-1007' } } })

    // Act
    await wrapper.get('[aria-label="Select ORD-1007"]').trigger('click')

    // Assert
    expect(wrapper.emitted('select')).toEqual([[7]])
  })
  ```

  Pass slot content through `mount(Card, { slots: { default: 'Paid in full' } })`
  and assert it with `expect(wrapper.text()).toContain('Paid in full')`.
- **Async:** `await flushPromises()` (from `@vue/test-utils`) settles pending
  promises — a mocked call resolving inside the component; `await nextTick()` (from
  `vue`) settles a reactive change no awaited interaction produced.
- **Forbidden:**
  - `wrapper.vm`, on the root or on a `findComponent(...)` result — reading refs,
    reactive state, computed values or methods, or calling them
    (`wrapper.vm.submit()`).
  - `setData` — it writes state the user never could; reach that state through
    the interaction that produces it.
  - `shallowMount`, and `global.stubs` naming one of the app's own child
    components — the Vue form of "never mock our own classes".

## Testing Library

- **Render** with `render(Component, { props, slots, global })` from
  `@testing-library/vue`:

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
- Drive interaction with `userEvent`, not `fireEvent`: create the user in Arrange
  with `const user = userEvent.setup()`, then `await user.click(...)` /
  `await user.type(...)` as the Act.
- **Emitted events:** the `emitted()` record from the render result:

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
- **Forbidden:** importing `@vue/test-utils` into a Testing Library file to get at
  the instance — the Vue Test Utils forbidden list holds here too.

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
- Render with realistic props; assert rendered output (Vue Test Utils: `get()` /
  `text()`; Testing Library: role/text queries) or emitted events.
- Name the library in the RED card whenever `package.json` didn't settle it — the
  both-installed tiebreak, or the neither-installed fallback to Testing Library,
  which adds `@testing-library/vue`, jest-dom and user-event to `devDependencies`
  so the approval covers the new dependencies.
- Run it; it must fail on the **assertion** (element/behavior absent), not on a
  compile error from a missing SFC or a render crash from an unrelated missing mock.

## REFACTOR targets (for `lundflow:tdd-refactorer`)

- Extract repeated logic into **composables** (`useX`) and repeated markup into
  **components**; move derived values into `computed`.
- Simplify conditionals; clarify prop, emit, and variable names.
- Keep accessibility roles, the selectors tests locate by, and emitted event names
  intact so behavior-level tests stay valid. Keep tests green; show the run.
