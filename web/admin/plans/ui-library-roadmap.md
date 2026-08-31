# Frontend UI Library Roadmap

> Purpose: turn `frontend/src/components/ui/` into the **canonical UI library for every future Omnify project**. This file is a context-handoff document for any session (Claude or human) that picks up the work — assume the reader has zero history.

**Last updated**: 2026-04-10
**Frontend submodule HEAD at start of work**: `6a1db39` (`docs(agents): add component placement + translatable + review checks`)

**Upstream issues that influence this work** (track before consuming):

| Issue | Title | Why this matters here |
|---|---|---|
| `omnify-jp/omnify-go#53` | TypeScript codegen target doesn't emit locale fields for `translatable: true` schema fields | Until merged, every translatable form keeps using the hand-rolled `buildI18nPayload` workaround. When merged, delete the workaround helper and the hand-rolled `*TranslationsInput` interfaces in `services/`. |
| `omnify-jp/omnify-go#54` | Generate form field metadata constant per model | Phase 2 stories and tests should consume `<model>Metadata` instead of re-deriving field info from base interfaces. Phase 1 Storybook setup can stub a placeholder metadata shape if #54 hasn't landed. |
| `omnify-jp/omnify-go#55` | Generate type-safe payload builders for Create/Update | When merged, replace hand-rolled `buildProductCreatePayload`-style code in pages with the generated `build<Model>CreatePayload`. Also delete `buildI18nPayload` from `src/i18n/translatable.ts` since #55 emits it as a shared utility. |

**Status of these issues**: all 3 filed; none merged yet. Re-check before each phase: `gh issue view 53 --repo omnify-jp/omnify-go` etc.

---

## Why this exists

The frontend was hand-rolled per-feature with inconsistent patterns:

- `<Loader2>` from `lucide-react` scattered 35x with inconsistent a11y
- Custom input variants (`SlugInput`, `TagInput`, `Combobox`) missing `data-slot` and exported `Props` types
- Layout primitives (`PageContainer`, `LocaleSwitcher`) sitting in `components/ui/` instead of `components/layout/`
- Domain components (`components/shop/`) at the wrong abstraction level
- Zero unit tests for `components/ui/` despite `vitest` being installed
- No Storybook → no visual catalog, no easy way for designers/other-Omnify-projects to browse the library
- Translatable fields (`translatable: true` schema) silently dropped non-default-locale input

The user is repurposing this folder as the **shared design system** for every Omnify-based project, so the bar is "framework-grade quality", not "good enough for one app".

---

## Current state (read this first when resuming)

### Phase 0 — Reorg progress

| Wave | Item | Status | Files affected |
|---|---|---|---|
| 1 | Move `page-container.tsx` `ui/` → `layout/` | ✅ done | `src/components/layout/page-container.tsx` |
| 1 | Move `locale-switcher.tsx` `ui/` → `layout/` | ✅ done | `src/components/layout/locale-switcher.tsx` |
| 1 | Move `slug-input.tsx` `ui/` → `shared/` | ✅ done | `src/components/shared/slug-input.tsx` |
| 1 | Move `tag-input.tsx` `ui/` → `shared/` | ✅ done | `src/components/shared/tag-input.tsx` |
| 1 | `<Loader2>` → `<Spinner>` mass migration | ✅ done | 34 files in `src/app/`, 1 in `src/components/layout/loading-shell.tsx` |
| 1 | Strip redundant `animate-spin` from `<Spinner className=…>` | ✅ done | 34 files |
| 1 | Fix relative imports in moved files (`./button` → `@/components/ui/button`) | ✅ done | the 4 moved files |
| 2 | Export `ComboboxProps` + `MultiComboboxProps` types | ✅ done | `src/components/ui/combobox.tsx` |
| 2 | Export `TagInputProps` type | ✅ done | `src/components/shared/tag-input.tsx` |
| 2 | Add `data-slot` to combobox/multi-combobox/tag-input/slug-input root | ✅ done | the 3 files above + `slug-input.tsx` |
| 2 | Doc comments for overlay family (dialog, alert-dialog, drawer, sheet) | ✅ done | each file has a "When to use:" contrastive guidance block |
| 2 | Doc comments for menu family (dropdown-menu, context-menu, menubar, navigation-menu) | ✅ done | same — contrastive selection guidance against siblings |
| 3 | `error?: string` on PasswordInput, SlugInput, TagInput | ✅ done | atoms + composites with single inner input |
| 3 | `error?: string` on Select, Combobox | ⏸️ deferred | compound primitives (Radix Root + Trigger + Content) — error belongs on `<SelectTrigger>` / Combobox `<Button>` not the Root, API design needs discussion. Track as Phase 2 follow-up |
| 4 | Update `frontend/AGENTS.md` with the new conventions discovered during reorg | ✅ done | added Spinner enforcement, ui/=atoms-only rule, API conventions table, expanded review checklist |

### Where the working tree was when this plan was written

The session that owns this plan committed `6a1db39` and then started Wave 1. After Wave 1 + the partial Wave 2 above, **NOTHING IS COMMITTED YET**. The working tree has:

- Renames (Wave 1 moves) — see `git status` for the `R` lines
- Modifications across 35 app files (Loader2 → Spinner)
- Modifications across the 4 moved files (relative → absolute imports)
- Modifications to combobox/tag-input/slug-input (Props export + data-slot)

**Action when resuming**: run `git status` first. If the working tree is clean, the previous session already committed/pushed. Skip to whichever phase is next.

### Verification status

- `npx tsc --noEmit` → **clean** (last verified mid-Wave 2)
- `npm run lint` → 0 errors, 56 warnings (all pre-existing — DO NOT introduce new ones)

---

## Phase 0 — Finish reorg (highest priority — must complete before Phase 1)

### Wave 2 remaining: doc comments on overlay + menu families

The 4 overlay primitives and 4 menu primitives have overlapping use cases that confuse downstream Omnify projects. Add a JSDoc role description to the top of each file. Each comment must include:

1. One-sentence "what is this"
2. **When to use this** — explicit, contrastive (what makes it different from siblings)
3. Tiny `@example` block

#### Overlay family — `src/components/ui/`

| File | Role to document |
|---|---|
| `dialog.tsx` | Modal interruption centered on screen, dismissible by clicking outside or pressing Esc. Use for forms, confirmations that aren't destructive, content viewers. |
| `alert-dialog.tsx` | Blocking confirmation that requires explicit action — **no overlay-click dismiss**. Use ONLY for destructive/irreversible actions (delete, archive, force-logout). |
| `drawer.tsx` | Mobile-first vaul drawer that slides up from the bottom and supports swipe-to-dismiss. Use when the UX is touch-driven (sheet pickers, mobile filters). |
| `sheet.tsx` | Side panel that slides in from any edge (top/bottom/left/right) with no swipe affordance. Use for desktop-first side-rail content (filters, settings, navigation). |

#### Menu family — `src/components/ui/`

| File | Role to document |
|---|---|
| `dropdown-menu.tsx` | Button-triggered popover menu for actions on a single subject (e.g. row actions, user menu). Anchored to the trigger button. |
| `context-menu.tsx` | Right-click triggered popover menu. Use ONLY when the user model expects right-click affordance (file managers, canvas editors). |
| `menubar.tsx` | macOS-style application menu bar (File / Edit / View). Use for app chrome where multiple top-level menus need to coexist horizontally. NOT for site nav. |
| `navigation-menu.tsx` | Site/app-wide navigation menu with submenu support. Use for the primary header navigation of a marketing site or web app. NOT for action menus. |

#### How to add the doc comment

Place above the main exported function. Pattern:

```tsx
/**
 * <One-sentence "what is this">
 *
 * **When to use:** <contrastive guidance — what makes this distinct from siblings>
 *
 * @example
 * ```tsx
 * <Foo>
 *   <FooTrigger>Click me</FooTrigger>
 *   <FooContent>Content here</FooContent>
 * </Foo>
 * ```
 */
```

Verify after each file: `npx tsc --noEmit` (should stay clean).

### Wave 3: standardize `error` prop across input family

Currently inconsistent:

- `<Input>` and `<Textarea>` translatable mode have `errors?: Partial<Record<string, string>>` (per-locale)
- `<Select>`, `<Combobox>`, `<TagInput>`, `<SlugInput>`, `<PasswordInput>` have **no error prop at all**

Goal: every input-family component supports a single error string at minimum:

```ts
error?: string;
```

When the prop is set:
- The component sets `aria-invalid` on the underlying input
- A red error message is rendered below the input via `<p className="text-[11px] text-red-500">{error}</p>` (matches the convention used in app pages today)

#### Files to touch

| File | Add prop |
|---|---|
| `src/components/ui/select.tsx` | `error?: string` |
| `src/components/ui/combobox.tsx` | `error?: string` on both `Combobox` and `MultiCombobox` |
| `src/components/shared/tag-input.tsx` | `error?: string` |
| `src/components/shared/slug-input.tsx` | `error?: string` |
| `src/components/ui/password-input.tsx` | `error?: string` |

**Do NOT** add `error` to `Input`/`Textarea` — they already have `errors` (per-locale) for translatable mode. Adding a second prop `error` is OK if you want to support both single-locale and translatable error display, but the simpler path is to leave them alone since callers set their own `<p>` below.

Verify after each: render in the existing pages (e.g. categories form, products new) and confirm the error UI matches.

### Wave 4: update `frontend/AGENTS.md`

After Wave 3 lands, update `AGENTS.md` with the conventions cemented during this reorg. Sections to add or expand:

1. **Spinner is the only loader** — `import { Spinner } from "@/components/ui/spinner"`. Never use raw `Loader2` from `lucide-react`. The Spinner has built-in `role="status"` + `aria-label="Loading"` + `animate-spin`. Pass only `className` for sizing (`size-3.5`, `size-5`, etc).
2. **`components/ui/` is for atomic primitives only.** Composites with multiple DOM nodes (like SlugInput, TagInput) belong in `components/shared/`. Layout primitives (PageContainer, LocaleSwitcher) belong in `components/layout/`.
3. **Every input-family component supports `error?: string`** — when setting field validation errors, prefer the prop over rendering a sibling `<p>`.
4. **Every shared component must export its `Props` type** — so downstream Omnify projects can extend it.
5. **Every visible-DOM-root component sets `data-slot="<kebab-case-name>"`** for CSS scoping and debugging.
6. **Mandatory review checklist** (extend the existing one) — add: "no raw Loader2 imports", "ui/ contains only atoms", "every new component in ui/ has a Storybook story (after Phase 1)", "every new component has a `.test.tsx` smoke test (after Phase 1)".

### How to commit Phase 0

Three logical commits in this order:

```sh
# Wave 1 + Wave 2 partial (moves + Loader2 + data-slot/Props)
git add -A
git commit -m "refactor(ui): canonical layout — atoms in ui/, composites in shared/

- Move page-container, locale-switcher to layout/ (were misfiled in ui/)
- Move slug-input, tag-input to shared/ (composites, not atoms)
- Spinner enforcement: 35 files migrated from raw Loader2
- Export Props types and add data-slot to combobox/tag-input/slug-input

See plans/ui-library-roadmap.md for the full reorg plan and rationale."

# Wave 2 doc comments
git add -A
git commit -m "docs(ui): add when-to-use guidance for overlay + menu families

Each of the 4 overlay primitives (dialog/alert-dialog/drawer/sheet) and
4 menu primitives (dropdown/context/menubar/navigation-menu) now has a
contrastive JSDoc explaining its distinct use case. Resolves the
'which one do I pick' confusion downstream Omnify projects had."

# Wave 3 + Wave 4
git add -A
git commit -m "feat(ui): unify error prop across input family + AGENTS rules

- Add error?: string to Select, Combobox, TagInput, SlugInput, PasswordInput
- Update AGENTS.md with reorg conventions and review checklist additions"
```

After commits: `git push origin main`. Then bump the umbrella submodule pointer:

```sh
cd ..   # umbrella root
git add frontend
git commit -m "chore(frontend): bump submodule (ui library reorg)"
git push origin main
```

---

## Phase 1 — Storybook + test infrastructure (✅ DONE in this session)

### Storybook 10 (not 8 — peer dep collision)

The original plan said "Storybook 8 stable, not 9 beta". After attempting `npx storybook@^8 init`, Storybook 8's `@storybook/nextjs` peer is `next@^13|^14|^15` — incompatible with Next.js 16 in this project. Switched to **Storybook 10.3.5** which has `next@^14.1.0 || ^15 || ^16` peer support and uses the new `@storybook/nextjs-vite` framework adapter (Vite-based, faster than the webpack adapter).

Setup completed:

- ✅ `npx storybook@10 init --type nextjs --no-dev --yes` ran clean
- ✅ Sample `src/stories/` scaffold removed (clashed with our actual Button)
- ✅ `.storybook/main.ts` left as default (`stories: ["../src/**/*.stories.@(...)"]` already catches our component stories)
- ✅ `.storybook/preview.tsx` (replaced the generated `.ts`) wraps every story with `<AppProvider defaultLocale="ja">` so translatable mode + theme + locale context resolve identically to runtime. Imports `globals.css`.
- ✅ Default addons installed: `@storybook/addon-vitest`, `@storybook/addon-a11y`, `@storybook/addon-docs`, `@storybook/addon-onboarding`, `@chromatic-com/storybook`
- ✅ Vitest browser-mode integration via `@storybook/addon-vitest` (stories double as tests via the vitest browser runner — see `vitest.workspace.ts` if it exists, otherwise the addon adds it on first run)
- ✅ `package.json` scripts: `storybook` (dev server, port 6006), `build-storybook` (static build to `storybook-static/`)
- ✅ ESLint flat config: `eslint-plugin-storybook` recommended rules added via `...storybook.configs["flat/recommended"]`

Verified:
- `npm run build-storybook` completes successfully (~1.5s after first run)
- 0 TS errors, 0 new lint warnings

### Template stories shipped

Two reference stories establishing the pattern. **All future stories must follow this shape.**

#### `src/components/ui/button.stories.tsx`

Covers: atomic states (Default, Destructive, Disabled), variant matrix (7 variants × 5 colors), size matrix (xs → xl + icon), with-icon variants, loading state demonstrating `<Spinner>` usage. Uses `args.onClick: fn()` from `storybook/test` for action logging. `argTypes` provides controls for `variant`, `color`, `size`, `disabled`.

#### `src/components/ui/input.stories.tsx`

Covers: standard mode (Default, Sizes, Disabled, Invalid) AND **translatable mode** (locale tab bar via `<Input translatable />`). Per-story state with `useState<TranslatableValue>`. Includes a `TranslatableWithErrors` story demonstrating per-locale error indicators.

### ESLint enforcement landed

`eslint.config.mjs` now has a `no-restricted-imports` rule blocking raw `Loader2` / `Loader2Icon` imports from `lucide-react` everywhere except `src/components/ui/spinner.tsx` (the Spinner file itself wraps the icon and needs the import). Any future PR that re-introduces raw Loader2 fails lint.

### Phase 1 commit

`feat: scaffold Storybook 10 + template stories + Loader2 lint rule` — see `git log` for the actual SHA.

### Setup steps

```sh
cd frontend

# Initialize Storybook with the Next.js framework adapter
npx storybook@latest init --type nextjs --no-dev

# Verify it scaffolded:
ls .storybook/
# expected: main.ts, preview.ts (or .tsx if Next.js adapter chose tsx)
```

Edit `.storybook/main.ts` so stories live next to the components, not in a top-level `stories/` folder:

```ts
import type { StorybookConfig } from "@storybook/nextjs";

const config: StorybookConfig = {
  stories: ["../src/components/**/*.stories.@(ts|tsx)"],
  addons: [
    "@storybook/addon-essentials",
    "@storybook/addon-interactions",
    "@storybook/addon-a11y",
  ],
  framework: { name: "@storybook/nextjs", options: {} },
  docs: { autodocs: "tag" },
};
export default config;
```

`.storybook/preview.tsx` must wrap all stories with `AppProvider` so the design system's `UIProvider`, locale config, theme, and translatable mode work inside Storybook:

```tsx
import type { Preview } from "@storybook/react";
import "../src/app/globals.css";
import { AppProvider } from "../src/providers/app-provider";

const preview: Preview = {
  decorators: [
    (Story) => (
      <AppProvider defaultLocale="ja">
        <Story />
      </AppProvider>
    ),
  ],
  parameters: {
    backgrounds: {
      default: "light",
      values: [
        { name: "light", value: "#ffffff" },
        { name: "dark", value: "#0a0a0a" },
      ],
    },
  },
};
export default preview;
```

Add scripts to `package.json`:

```json
"scripts": {
  "storybook": "storybook dev -p 6006",
  "build-storybook": "storybook build",
  "test-storybook": "test-storybook"
}
```

### Template stories — write these in this Phase 1 session

Two reference stories that establish the pattern. **Do not write more in Phase 1**. Phase 2 backfills the rest.

#### `src/components/ui/button.stories.tsx`

```tsx
import type { Meta, StoryObj } from "@storybook/react";
import { Button } from "./button";

const meta: Meta<typeof Button> = {
  title: "UI/Button",
  component: Button,
  tags: ["autodocs"],
  argTypes: {
    variant: { control: "select", options: ["default", "destructive", "outline", "secondary", "ghost", "link"] },
    size: { control: "select", options: ["default", "sm", "lg", "icon"] },
  },
};
export default meta;
type Story = StoryObj<typeof Button>;

export const Default: Story = { args: { children: "Button" } };
export const Destructive: Story = { args: { variant: "destructive", children: "Delete" } };
export const Sizes: Story = {
  render: () => (
    <div className="flex items-center gap-2">
      <Button size="sm">Small</Button>
      <Button>Default</Button>
      <Button size="lg">Large</Button>
    </div>
  ),
};
export const Loading: Story = {
  render: () => {
    const { Spinner } = require("./spinner");
    return <Button disabled><Spinner className="mr-2" />Saving…</Button>;
  },
};
```

#### `src/components/ui/input.stories.tsx`

```tsx
import type { Meta, StoryObj } from "@storybook/react";
import { useState } from "react";
import { Input } from "./input";

const meta: Meta<typeof Input> = {
  title: "UI/Input",
  component: Input,
  tags: ["autodocs"],
};
export default meta;
type Story = StoryObj<typeof Input>;

export const Default: Story = { args: { placeholder: "Enter text" } };
export const Sizes: Story = {
  render: () => (
    <div className="flex flex-col gap-2 w-72">
      <Input size="xs" placeholder="xs" />
      <Input size="sm" placeholder="sm" />
      <Input placeholder="default" />
      <Input size="lg" placeholder="lg" />
      <Input size="xl" placeholder="xl" />
    </div>
  ),
};

export const Translatable: Story = {
  render: () => {
    const [val, setVal] = useState({ ja: "", en: "", vi: "" });
    return <Input translatable value={val} onChange={setVal} placeholder="Enter text" />;
  },
};
```

### Test runner setup

Storybook 8 ships `@storybook/test` which lets stories double as tests. Add the test runner:

```sh
npm i -D @storybook/test-runner playwright
npx playwright install --with-deps chromium
```

Run with `npm run test-storybook` — this hits every story and asserts it renders without errors. That's the bar for Phase 1: zero stories crash. Phase 2 adds interactive `play` functions for behavioral coverage.

### Phase 1 commit

```sh
git add -A
git commit -m "feat: scaffold Storybook 8 + test runner for the ui library

- .storybook config wrapping stories with AppProvider so translatable
  mode and theme context are available
- Template stories for Button + Input as reference patterns
- npm scripts: storybook, build-storybook, test-storybook
- Test runner via @storybook/test-runner + playwright"
```

---

## Phase 1.5 — Folder-per-component restructure + barrel (✅ done in this session)

After Phase 1 shipped 2 template stories at `src/components/ui/<name>.stories.tsx` (flat layout), the user pushed back: "ui/ scaling to 60 .tsx + 60 .stories.tsx = 120 files at one level is unmanageable". Restructured before backfilling further:

- **Each component → its own folder** with `<name>/index.tsx` (Node module resolution finds it, so `import { Button } from "@/components/ui/button"` keeps working with zero callsite changes)
- **Stories live in the same folder** as `<name>/<name>.stories.tsx` (Storybook glob `**/*.stories.@(...)` picks them up regardless of nesting)
- **Sibling imports rewritten**: `from "./button"` inside `combobox/index.tsx` becomes `from "../button"` etc — 29 files updated by the migration script
- **`internal/` left as-is** — already a folder, kept untouched
- **Barrel `src/components/ui/index.ts`** generated alphabetically with `export * from './<name>'` for all 55 components. Lets consumers do `import { Button, Input, Card } from "@/components/ui"` without giving up the per-component import style.

ESLint adjustments:
- Added `storybook-static/**` to `globalIgnores` (the build output was getting linted, ~13k bogus errors on minified vendor JS)
- Updated the `no-restricted-imports` carve-out for Spinner: `src/components/ui/spinner.tsx` → `src/components/ui/spinner/**` to match the new folder layout

Card additions during the same restructure (per user request — "card cần thêm những loại có hình ảnh"):
- New `<CardMedia>` sub-component: edge-to-edge media slot with optional `aspectRatio` (e.g. `"16/9"`, `"4/3"`, `"square"`). First-child rounds top corners; last-child rounds bottom corners; middle stays flat for image strip layouts. Negative `mb-card` removes parent flex gap when media is followed by another sub-component (image flush against next slot).
- Card stories expanded with 5 image variants: `WithImageTop`, `WithImageSquare` (gallery grid), `ImageOnly` (pure tile), `HorizontalImageLeft` (flex-row override), `ImageMiddle` (sandwich layout).

## Phase 2 — Backfill stories + tests (multi-session)

### Tier progress (live tally)

**Total**: **204 stories across 66 test files** (was 183 → +21 in the final batch). All passing in vitest browser mode (chromium).

**Component coverage: 62/62 (100%)** — every component in `components/{ui,shared,layout}/` has at least one story file. Phase E sweep complete.

**Coverage by tier**:

- Tier 1 atoms (10): **9/10 done** (Select deferred)
- Tier 2 composites (10): **10/10 done**
- Tier 3 specialized inputs (10): **10/10 done** (incl. command, tag-input, slug-input, password-input, combobox, calendar, date-picker, time-picker, file-upload, color-picker)
- Tier 4 chrome/nav (10): **10/10 done** (sidebar, sheet, drawer, menubar, navigation-menu, context-menu, breadcrumb, pagination, accordion, collapsible)
- Tier 5 display/niche (22): **22/22 done** — final batch added rating, carousel, resizable, sonner, form, chart, page-container
- Select (Tier 1) shipped — has its own story; error API on `<SelectTrigger>` still pending design discussion
- Foundations (Phase D): 5 files, 14 stories

**Total component coverage**: 62/62 (100%). Phase E sweep complete.

#### Tier 1 atoms — 9/10 done (Select deferred)



- ✅ button — 10 stories (variants/colors/sizes/loading)
- ✅ input — 5 stories (standard + translatable + errors)
- ✅ label — 5 stories (default, with-input, with-checkbox, required, disabled)
- ✅ card — 10 stories (default, with-action, with-footer, with-badge, plain, **5 image variants**)
- ✅ badge — 4 stories (default, variants, colors, with-icon, status list)
- ✅ tabs — 4 stories (default, with-card-layout, many-tabs, keyboard-nav with `play` test)
- ✅ checkbox — 6 stories (default, checked, indeterminate, disabled, with-label, controlled list, click-toggles `play` test)
- ✅ switch — 5 stories (default, on, disabled, with-label, settings list, click-toggles `play` test)
- ✅ textarea — 6 stories (standard + translatable + errors)
- ❌ select — TODO (Wave 3 error API discussion needed)

#### Tier 2 composites — 10/10 done

- ✅ spinner — 4 stories (Default, Sizes, Colors, InsideButton)
- ✅ skeleton — 5 stories (TextLine, Circle, Paragraph, CardSkeleton, ListSkeleton)
- ✅ separator — 3 stories (Horizontal, Vertical, InMetaList)
- ✅ avatar — 4 stories (WithImage, Fallback, Sizes, Stack)
- ✅ tooltip — 3 stories (Default, OnIconButton, Sides)
- ✅ dialog — 3 stories (Default, Controlled, InfoDialog)
- ✅ alert-dialog — 2 stories (Destructive, ForceLogout)
- ✅ popover — 2 stories (Default, InlineEditor)
- ✅ dropdown-menu — 4 stories (RowActions, UserMenu, Checkboxes, Radio)
- ✅ table — 4 stories (Default, WithCaption, WithFooter, Empty)

#### Foundations (Phase D) — 5 files / 14 stories

- ✅ spacing.stories.tsx — Scale + YohakuRatio
- ✅ typography.stories.tsx — Scale + Weights + LineHeightComparison
- ✅ colors.stories.tsx — SemanticRoles + StatusColors + TraditionalPalette + CulturalNotes
- ✅ touch-targets.stories.tsx — SizeMatrix + HitAreaVisualization
- ✅ aspect-ratios.stories.tsx — Catalog + SilverVsGolden + ScaleRejection



### Priority order

Cover by usage frequency, not alphabetical. Most-used components first because they have the most blast radius.

**Tier 1 — atoms used everywhere (do these first):**

1. button (already done as template)
2. input (already done as template)
3. label
4. card
5. select
6. badge
7. tabs
8. checkbox
9. switch
10. textarea

**Tier 2 — common composites:**

11. dialog
12. alert-dialog
13. dropdown-menu
14. popover
15. tooltip
16. spinner
17. skeleton
18. avatar
19. separator
20. table

**Tier 3 — specialized:**

21. combobox + multi-combobox
22. command (cmdk)
23. calendar
24. date-picker
25. time-picker
26. file-upload
27. color-picker
28. tag-input (in shared/)
29. slug-input (in shared/)
30. password-input

**Tier 4 — chrome / nav (lower priority):**

31. sidebar
32. sheet
33. drawer
34. menubar
35. navigation-menu
36. context-menu
37. breadcrumb
38. pagination
39. accordion
40. collapsible

**Tier 5 — display-only / niche:**

41. alert
42. progress
43. slider
44. rating
45. resizable
46. scroll-area
47. carousel
48. chart
49. aspect-ratio
50. hover-card
51. toggle / toggle-group
52. radio-group
53. input-otp
54. form (RHF wrapper — needs special story setup)
55. translatable-field (the primitive — internal-ish)
56. locale-switcher (in layout/)
57. page-container (in layout/)
58. rich-text-editor (in shared/)
59. translatable-rich-text (in shared/)
60. data-table (in shared/)
61. status-badge (in shared/)
62. sonner (toast)

### Story quality bar (every component)

Every `.stories.tsx` must include:

1. **`title`** following the pattern `"<Category>/<ComponentName>"` (e.g. `"UI/Button"`, `"Layout/Sidebar"`, `"Shared/DataTable"`)
2. **`tags: ["autodocs"]`** so Storybook generates the docs page
3. **At least 3 named stories**: `Default`, plus 2 covering key variants/states
4. **Controls** for every prop the user might tweak (`argTypes` with `control: "select"|"text"|"boolean"|...`)
5. **No console errors/warnings** when the story renders (Storybook test-runner enforces)
6. **A11y check passes** (`@storybook/addon-a11y` runs axe-core on every story)

### Test quality bar (every component)

Each component additionally gets a sibling `.test.tsx` with vitest + RTL covering:

1. **Renders without crashing** with default props
2. **Each variant prop** produces the expected DOM (snapshot OR class assertion)
3. **Interactive behavior** (click → handler called, keyboard → handler called)
4. **Accessibility attributes** present (role, aria-label, data-slot)

Pattern (use this as the template, do not deviate without reason):

```tsx
// src/components/ui/button.test.tsx
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { Button } from "./button";

describe("Button", () => {
  it("renders children", () => {
    render(<Button>Click me</Button>);
    expect(screen.getByRole("button", { name: "Click me" })).toBeInTheDocument();
  });

  it("applies the destructive variant class", () => {
    render(<Button variant="destructive">Delete</Button>);
    expect(screen.getByRole("button")).toHaveClass(/destructive/);
  });

  it("calls onClick when clicked", async () => {
    const handler = vi.fn();
    render(<Button onClick={handler}>Go</Button>);
    await userEvent.click(screen.getByRole("button"));
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it("respects disabled prop", async () => {
    const handler = vi.fn();
    render(<Button disabled onClick={handler}>Go</Button>);
    await userEvent.click(screen.getByRole("button"));
    expect(handler).not.toHaveBeenCalled();
  });
});
```

### Cadence

Aim for **5–10 components per session**. Don't try to do all 60 in one go — context window will blow up and quality degrades. Each session:

1. Read this plan + check `git log` to see what's already done
2. Pick the next tier-N components from the list
3. Write story + test for each
4. `npm run test-storybook && npx vitest run` to verify
5. Commit per tier or per component group:
   ```
   test(ui): add stories + smoke tests for tier-1 atoms (button, input, label, card)
   ```

### Consume omnify metadata when issues #54/#55 land

The plan above assumes hand-written stories/tests reference the existing generated types. **Once `omnify-jp/omnify-go#54` (form metadata) merges**, refactor as follows:

1. Replace hand-derived field info in stories (e.g. "render every variant of the status enum") with iteration over `productMetadata.fields.status.enumValues`. The story automatically picks up new enum values when the schema changes — no manual maintenance.
2. Delete hand-rolled `<Model>TRANSLATABLE_FIELDS` arrays in any story/test/page — read `productMetadata.translatableFields` instead.
3. In `frontend/AGENTS.md`, add a rule: "when reading field metadata in stories/tests, always source from `<model>Metadata`, never from base interfaces or YAML".

**Once `omnify-jp/omnify-go#55` (payload builders) merges**:

1. Delete `src/i18n/translatable.ts` — `buildI18nPayload` now ships from omnify as a shared utility (the issue spec says "emit once at the codegen output root, not per-model").
2. Delete the hand-rolled `<Model>TranslationsInput` interfaces in service files (e.g. `ProductTranslationsInput` in `src/services/product-service.ts`) — the generated payload builder + zod schema cover this.
3. Refactor every form's submit handler to call the generated `build<Model>CreatePayload(form)` instead of hand-spreading `...buildI18nPayload(...)`.
4. Refactor every form's `useState` initializer to call `empty<Model>CreateForm()` instead of `emptyTranslatable()` ad-hoc per field.
5. Stories for forms can use the generated `empty<Model>CreateForm()` factory as their default args.

This eliminates the entire `buildI18nPayload` + `ProductTranslationsInput` + `emptyTranslatable` boilerplate stack across the codebase. After the cleanup, every form is **5–10 lines of layout JSX over a generated form-state + builder**.

### Translatable coverage gap follow-up

Phase 2 is also when to retrofit translatable mode onto the input family that lacks it:

| Component | Add `translatable` prop? |
|---|---|
| `select.tsx` | YES — many enum-like fields are translated (status labels) |
| `combobox.tsx` | YES — same reason |
| `textarea.tsx` | already has it ✅ |
| `password-input.tsx` | NO — passwords aren't translated |
| `tag-input.tsx` | YES — tags can be locale-specific |
| `slug-input.tsx` | NO — slugs are URL-friendly, single locale by definition |
| `rich-text-editor.tsx` | INDIRECT — already wrapped by `TranslatableRichText` in `shared/` |

Each retrofit must:
- Match the existing pattern in `input.tsx` (translatable mode branch + render via `TranslatableField`)
- Get updated stories showing the translatable variant
- Not break the non-translatable callers

---

## Phase 3 — CI enforcement (last)

Once Phase 2 is "complete enough" (≥80% of `components/ui/` has stories + tests), wire up CI:

1. **Pull request check**: any new file in `src/components/{ui,shared,layout}/` MUST have a sibling `.stories.tsx` AND `.test.tsx`. Enforce via a small script in `.github/workflows/ui-coverage.yml`.
2. **Test runner in CI**: `npm run test-storybook` and `npx vitest run` block merge.
3. **Visual regression**: pick one:
   - **Chromatic** (Storybook's first-party, paid, easiest) — `npx chromatic --project-token=...` in CI
   - **Playwright snapshots** (free, more setup) — `playwright test --update-snapshots`
   Recommend Chromatic for framework lib because of cross-project diff aggregation.

---

## Quality bars (apply to every phase)

| Bar | Enforcement |
|---|---|
| `npx tsc --noEmit` returns 0 errors | every commit |
| `npm run lint` introduces 0 NEW warnings (56 pre-existing OK) | every commit |
| Every commit message follows the existing convention (`type(scope): message` + body) | manual review |
| No edits to `src/types/models/base/**` (auto-generated) | review |
| Every shared component exports its `Props` type | review checklist |
| Every visible-DOM-root component has `data-slot="<kebab>"` | review checklist |
| No raw `Loader2` imports — use `<Spinner />` | review checklist + lint rule (TODO) |

Add a custom ESLint rule in Phase 1 setup to block raw `Loader2` imports. Pattern:

```js
// .eslintrc.cjs additions
"no-restricted-imports": ["error", {
  paths: [{
    name: "lucide-react",
    importNames: ["Loader2", "Loader2Icon"],
    message: "Use <Spinner> from @/components/ui/spinner instead.",
  }],
}],
```

---

## How a fresh session should resume this plan

1. Read this file end-to-end.
2. `cd frontend && git status` — see what's uncommitted.
3. `git log --oneline -10` — see what's been committed.
4. Check the **Current state** table near the top of this file. The session that updated the plan last marked items ✅ vs ❌.
5. If the table is out of date (commits exist that aren't reflected), update the table FIRST before doing new work.
6. Pick the next ❌ item.
7. Do the work.
8. **Update this file** before committing — keep the table current so the next session doesn't redo work.
9. `git push origin main` (frontend submodule) then bump umbrella pointer if appropriate.

### Anti-patterns to avoid

- ❌ "I'll just start writing tests for everything" — context blows up, quality degrades. Stay within phase scope.
- ❌ Editing `src/types/models/base/**` files. Those are omnify-generated. Edit the sibling editable file or the YAML schema.
- ❌ Creating a new top-level `components/{domain}/` folder. Domain components colocate under `app/{route}/components/`.
- ❌ Adding new `Loader2` imports. Use `<Spinner />`.
- ❌ Skipping the typecheck/lint verification step. Every commit must be clean.
- ❌ Bumping the umbrella submodule pointer without first pushing the submodule's commits to its own remote.

---

## Cross-reference

- **Upstream issue blocking translatable codegen**: `omnify-jp/omnify-go#53`
- **Frontend AGENTS.md**: contains the mandatory review checklist that this plan extends
- **Umbrella CLAUDE.md**: monorepo orientation, codegen workflow
- **frontend/CLAUDE.md**: imports AGENTS.md
