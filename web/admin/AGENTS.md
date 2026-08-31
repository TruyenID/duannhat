<!-- BEGIN:nextjs-agent-rules -->
# This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` before writing any code. Heed deprecation notices.
<!-- END:nextjs-agent-rules -->

# Components — where new files go

When creating a component, decide its location with this rule:

| If the component is… | It belongs in… |
|---|---|
| An **atomic** design-system primitive — single DOM element + thin variant logic (Button, Input, Tabs, Badge, Spinner…) | `src/components/ui/` (shadcn convention) |
| A **high-leverage layout primitive** — page wrapper, sidebar, top-bar shell that EVERY downstream Omnify project will use (PageContainer, Sidebar, Sheet…) | `src/components/ui/` (canonical framework lib) |
| App shell / chrome — project-specific app navigation that other projects won't reuse (TopBar, app-specific PageHeader, LocaleSwitcher tied to project copy…) | `src/components/layout/` |
| A **composite** widget — multiple DOM nodes, internal state, wraps several primitives (DataTable, RichTextEditor, SlugInput, TagInput, TranslatableRichText…) reused across **≥2 unrelated routes** | `src/components/shared/` |
| Used by **exactly 1 route** | `src/app/{route}/components/` (colocated) |

**The ui/ vs layout/ split clarified:** `components/ui/` is the **canonical framework UI library** that downstream Omnify projects consume. PageContainer lives there even though it's a layout primitive — every project uses the same page-shell wrapper, so it's part of the standard library. `components/layout/` is for chrome that's truly app-specific (top bar with this app's branding, locale switcher tied to this app's copy, etc) and won't ship to other Omnify projects.

**Default to colocation.** A component only earns a spot in `components/shared/` once it has a second caller. Premature promotion to `shared/` creates a domain dump that nobody can navigate — see git history for the `components/shop/` cleanup that proved the point.

**`components/ui/` is for atoms only.** If a component has multiple DOM nodes or wraps several other UI primitives with state, it's a composite — it goes in `shared/` (cross-route) or stays colocated (single-route). Layout primitives that wrap page-level structure go in `layout/`. The whole reason `components/ui/` exists separately is so downstream Omnify projects can consume it as the canonical atomic library without dragging in app-specific composites or chrome.

**Imports inside a colocated `components/` folder use a relative path** (`./components/foo`), not the `@/` alias. This makes it obvious the component is route-private and surfaces the move when the route is renamed/deleted.

# Multi-language (i18n)

Two distinct i18n layers — don't confuse them:

- **UI strings** — hard-coded developer labels (button text, empty states, validation copy). Lives in `src/i18n/{ja,en,vi}.json` + `useTranslation()`. Rules covered below.
- **User content** — per-row translations end-users enter (product name, category label, menu section title, material description). Lives in backend Astrotomic `*_translations` tables. Full rules and reference implementation in **[docs/contributing/translatable-forms.md](../../docs/contributing/translatable-forms.md)** — read that doc before adding any translatable field, editing an edit page, or building a form that touches `<Input translatable />` / `<Textarea translatable />` / `<TranslatableRichText />`.

UI-string rules (layer 1):

- Every key must exist in `src/i18n/ja.json`, `en.json`, `vi.json`. Missing key → `t("key")` returns the raw key at runtime (visible as-is to users, looks like a bug).
- Key namespacing: `<domain>.<verb/noun>` — `product.save`, `common.loading`, `toast.product.saved`.
- Param interpolation: `t("common.showing", { from, to, total })` → `"Showing {from}–{to} of {total}"`.
- `defaultLocale: ja`, `fallbackLocale: en` — set in `src/i18n/index.ts`. Missing a key in `vi.json` falls back to `en.json`, then to the raw key.
- The locale switcher (top bar) calls `useTranslation().setLocale("vi")` which handles cookie + localStorage + `document.documentElement.lang` + fire-and-forget POST to `/api/v1/locale` via `silent401`. Don't reinvent any of this.

When touching user-content translations → open `docs/contributing/translatable-forms.md` before writing any code. It covers state shape, `buildI18nPayload` + top-level mirror, `hydrateLocaleMap` hydration, zero-ceremony display via `Accept-Language`, the locale-switch cache gap, and the full review checklist.

# API calls — only `apiFetch`, never raw `fetch`

Every HTTP call to the backend MUST go through `apiFetch` from `@/lib/api`. Calling `fetch()` directly anywhere under `src/` is a review-block.

`apiFetch` centralises four things that have bitten us when forked:
1. **`Accept-Language`** — stamped from the `app_locale` cookie so the backend Astrotomic translatable fallback returns the right locale. Forking means the caller forgets it, the backend returns default-locale strings, and the UI silently shows Japanese to Vietnamese users (we've hit this twice).
2. **`Authorization: Bearer <token>`** — read from the same cookie the Next.js middleware uses, with a localStorage fallback for legacy paths. Forked call sites drift on which storage they read.
3. **401 → redirect to `/login`** — unless the caller opts out with `silent401: true` for fire-and-forget flows (preference persistence, SSO callback before the token exists).
4. **Error shape** — `apiFetch` throws `ApiError` with a structured `body`. Callers that use raw `fetch` invent their own error shapes, which then fail the `instanceof ApiError` checks further up.

## The options you'd previously fork for

If you're about to write a raw `fetch` because `apiFetch` "doesn't support X", check this table first — it probably already does:

| You need… | Pass this option |
|---|---|
| Multipart upload (`FormData` body) | `body: formData` — `apiFetch` detects `FormData` and lets the browser set the boundary automatically, no Content-Type forking needed |
| Binary download (CSV, PDF, image) | `responseType: "blob"` — return type narrows to `Blob` via overload |
| Plain text response | `responseType: "text"` — return type narrows to `string` |
| Raw `Response` object (streaming, custom parsing) | `responseType: "raw"` |
| Skip the 401 redirect (preference save, auth bootstrap, heartbeat) | `silent401: true` — `apiFetch` throws `ApiError` with status 401 instead of bouncing the browser |
| Custom `Accept` / `Content-Type` header | Pass `headers: { Accept: "text/csv" }` — caller headers win over the defaults |

## Examples

```tsx
// ✅ JSON (default) — no options needed
const product = await apiFetch<Product>(`/api/v1/hq/${brandSlug}/products/${id}`);

// ✅ Multipart upload
const body = new FormData();
body.append("file", file);
body.append("brand_id", brandId);
const result = await apiFetch<ImportResult>(
  `/api/v1/hq/${brandSlug}/products/import`,
  { method: "POST", body }
);

// ✅ Binary download
const blob = await apiFetch(
  `/api/v1/hq/${brandSlug}/products/export`,
  { headers: { Accept: "text/csv" }, responseType: "blob" }
);

// ✅ Fire-and-forget preference persistence
apiFetch<void>("/api/v1/me/preferences", {
  method: "POST",
  body: JSON.stringify({ locale: "vi" }),
  silent401: true,
}).catch((err) => {
  // swallow — preference save is best-effort
});

// ❌ Raw fetch — strips Accept-Language, forks auth, breaks error contract
await fetch(`${API_BASE}${path}`, {
  method: "POST",
  headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
  body: JSON.stringify(data),
});
```

## If you genuinely cannot use apiFetch

There has not yet been a real case. If you think you've found one, **extend `apiFetch` with a new option** and use it — don't fork. Forks drift. The `responseType` / `silent401` / FormData-detection options were all added because call sites had forked for them; the fork got deleted once `apiFetch` absorbed the capability.

# Loading indicators — only `<Spinner>`, never raw `Loader2`

Use `import { Spinner } from "@godxjp/ui"` for every loading affordance. The Spinner ships with `role="status"` + `aria-label="Loading"` + `animate-spin` baked in via `cn`+`twMerge`.

> **`@/components/ui/spinner` does not resolve in this repo.** The whole
> `src/components/ui/` tree was deleted on 2026-04-11 in `76289f4`
> ("refactor: migrate to @godxjp/ui package") — 60+ components, spinner
> included, moved into the `@godxjp/ui` package and 222 imports were rewritten
> to `@godxjp/ui`. It was **moved, not dropped**: `Spinner` is still the same
> `Loader2Icon` wrapper, now shipped from the package. Real call site:
> `src/app/hq/[brandSlug]/settings/components/reverb-config-panel.tsx:12`.

```tsx
// ✅ correct
import { Spinner } from "@godxjp/ui";
<Spinner className="size-3.5" />
<Button disabled><Spinner className="mr-2" />Saving…</Button>

// ❌ wrong — missing a11y, can't be globally restyled
import { Loader2 } from "lucide-react";
<Loader2 className="size-3.5 animate-spin" />
```

Pass only sizing / color via `className`. Never re-add `animate-spin` — `twMerge` will dedupe but it's noise. Importing `Loader2` or `Loader2Icon` from `lucide-react` is a review-block **anywhere in this repo** — the only legitimate importer is the Spinner component itself, and that now lives in the `@godxjp/ui` package, not here. Enforced by the `no-restricted-imports` rule in `@tempo/eslint-config` — for admin-web that resolves to this repo's own `packages/eslint-config/index.mjs`, whose spinner carve-out (`src/components/ui/spinner.tsx`) matches nothing here since `76289f4` and is kept only as an inert safety net. Other apps keep their own copy of that config; pos-web's carve-out is live because it really does have `src/components/ui/spinner.tsx`.

# Component API conventions for `components/ui/` and `components/shared/`

Every component in `ui/`, `shared/`, and `layout/` MUST follow the canonical shadcn pattern. New files that violate this fail review:

| Convention | Why |
|---|---|
| Export the `XxxProps` (or `XxxOptions`) type | Downstream Omnify projects extend our types — un-exported props block reuse |
| Set `data-slot="<kebab-case-component-name>"` on the visible-DOM root | CSS scoping, debugging, and our internal `data-slot` query selectors depend on it |
| Composite widgets (multiple DOM nodes, internal state) accept `error?: string` where validation makes sense | Single-string error → red `<p>` below + `aria-invalid` on the inner input. Compound primitives like `<Select>` (Radix Root + Trigger + Content) put `error` on `<SelectTrigger>` rather than `<Select>` |
| Prefer `cva` for variants on form-input family | Hand-rolled className branching drifts; `cva` keeps variant matrices type-safe |
| Don't wrap composites in `forwardRef` | `forwardRef` is for atoms with a single DOM root. Composites have multiple DOM nodes and `forwardRef` becomes ambiguous |

# Translatable form fields

When a backend schema field is `translatable: true` (omnify YAML), the form field on the frontend MUST use the design system's translatable mode so the user can enter all locales:

- Plain text → `<Input translatable value={state} onChange={setState} />`
- Multi-line plain text → `<Textarea translatable value={state} onChange={setState} />`
- Rich text (Tiptap) → `<TranslatableRichText value={state} onChange={setState} />` from `@/components/shared/translatable-rich-text` (until Tiptap editor itself adds a built-in `translatable` prop)

State shape: `TranslatableValue` from `@godxjp/ui` (equivalent to `Record<Locale, string>`; init with `emptyLocaleMap()` from `@/types/models/payload-helpers`).

**The submit payload rules live in one place and are NOT repeated here** — `fillLocalesFallback` → `buildI18nPayload` → Rule 3 strip → top-level mirror (`effectiveName`, never `name[DEFAULT_LOCALE] ?? ""`), which required field each model checks, hydration on edit pages, and the review checklist: **[docs/contributing/translatable-forms.md § Rule 3](../../docs/contributing/translatable-forms.md)**. A copy of that code here drifted once already and taught the exact anti-pattern that doc forbids. Reference it; do not paste it.

# useQueries with derived state — always use `combine`

When you call `useQueries` and derive any value from the results (a `Map`, a flat array, a boolean flag), you MUST compute that derivation inside the `combine` option — never in a downstream `useMemo`.

**Why:** TanStack Query v5 guarantees a stable `combine` output reference when the underlying data has not changed. A `useMemo` that takes `results` as a dependency sees a new array reference every render even when the data is identical, causing the memoized value to recompute on every render and triggering unnecessary child re-renders.

```tsx
// CORRECT — derivation inside combine; stable reference unless data changes
const { skusByProduct, isLoading } = useQueries({
  queries: productIds.map((id) => ({
    queryKey: productQueryKeys.detail(id),
    queryFn: () => fetchProductDetail(id),
    staleTime: 5 * 60 * 1000,
  })),
  combine: (results) => {
    const map = new Map<string, ProductSku[]>();
    results.forEach((q, i) => {
      if (q.data) map.set(productIds[i], q.data.skus ?? []);
    });
    return {
      skusByProduct: map,
      isLoading: results.some((r) => r.isLoading),
    };
  },
});

// WRONG — useMemo on results re-runs every render
const results = useQueries({ queries: [...] });
const skusByProduct = useMemo(() => {
  const map = new Map<string, ProductSku[]>();
  results.forEach((q, i) => { ... }); // results is a new array ref every render
  return map;
}, [results]);
```

Never use `useMemo` to derive state from `useQueries` results. Move the logic into `combine` instead.

---

# Layout error recovery — handle 5xx inline, not via `throw`

Client Component layouts that load data (brand context, shop context) MUST handle non-404/403 errors with an inline recovery UI containing a Retry button. They must NOT call `throw error` for server errors — that would propagate to the nearest React Error Boundary and show a full-page crash screen, preventing the user from retrying without a hard reload.

Rules:
- **404 / 403** → call `notFound()` (Next.js shows the appropriate fallback page).
- **5xx / network errors** → show inline recovery UI; call `refetch()` on the Retry button.
- Never call `throw error` for transient/recoverable errors.

```tsx
// CORRECT
if (isError) {
  const status = (error as ApiError)?.status;
  if (status === 404 || status === 403) notFound();

  return (
    <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
      <p>{t("common.error_loading")}</p>
      <Button variant="outline" size="sm" onClick={() => refetch()}>
        {t("common.retry")}
      </Button>
    </div>
  );
}

// WRONG — throws for all errors, including transient 500s
if (isError) throw error;
```

i18n keys required: `common.error_loading` and `common.retry` must exist in all three locale files (`ja.json`, `en.json`, `vi.json`).

---

# Mandatory review checks

Whenever you review (or generate) frontend code, **you must verify all of the following before approving / committing**. Treat each as a hard fail — fix it in the same change, do not defer.

### 1. Component placement + API conventions

For every new `.tsx` file under `src/components/` or `src/app/`:

- [ ] It lives in the location matching the table in **Components — where new files go** above.
- [ ] If the component has only **one caller**, it is colocated under `src/app/{route}/components/`, **not** `components/shared/` or `components/{domain}/`.
- [ ] No new top-level domain dump folder under `components/` (e.g. `components/shop/`, `components/inventory/`). Domain components colocate.
- [ ] Imports of colocated components use a **relative path** (`./components/foo`), not `@/components/...`.
- [ ] **`components/ui/` contains atoms only.** Composites with multiple DOM nodes go in `shared/`. Layout primitives go in `layout/`.
- [ ] The component's `Props` type is **exported** (so downstream Omnify projects can extend).
- [ ] The visible-DOM root has `data-slot="<kebab-case-component-name>"`.
- [ ] No raw `Loader2` / `Loader2Icon` import from `lucide-react` anywhere — use `<Spinner />` from `@godxjp/ui` (admin-web has no local spinner file; `src/components/ui/` was migrated to the package in `76289f4`).
- [ ] Composites that participate in form validation accept `error?: string` and render `aria-invalid` + a red `<p>` below.

### 2. Translatable fields wired correctly

Whenever a form touches a backend field that is `translatable: true` in `schemas/*/*.yaml`:

- [ ] The form field uses the design system's translatable mode (`<Input translatable />`, `<Textarea translatable />`, or `<TranslatableRichText />`) — **not** a plain `<Input>` / `<Textarea>` / `<RichTextEditor>`.
- [ ] Form state for that field is `TranslatableValue` (from `@godxjp/ui`), initialized with `emptyLocaleMap()` (from `@/types/models/payload-helpers`).
- [ ] Submit payload: `buildI18nPayload({...})` → Rule 3 strip (delete locales where required field is empty) → spread. Top-level field mirrors `value[DEFAULT_LOCALE]`.
- [ ] Auto-derivations from the field (e.g. slugify) read from the default-locale entry, not the whole TranslatableValue object.
- [ ] Service-layer Create/Update interface for that resource extends a `*TranslationsInput` shape covering the translatable fields.

If you encounter a pre-existing form that violates these rules, fix it as part of the same change — silent multi-locale data loss is a correctness bug, not a style nit.

### 3. Generated files left alone

- [ ] No edits to `src/types/models/base/**` or other auto-generated paths. Extensions go in the editable sibling file (e.g. `src/types/models/Product.ts` next to `src/types/models/base/Product.ts`).
- [ ] If the schema needs to change, edit `schemas/*/*.yaml` at the umbrella root and regenerate via `npm run omnify:gen` from the umbrella, then commit the schema and the regen together in one commit.

### 4. TypeScript + ESLint clean

- [ ] `pnpm typecheck` (or `pnpm -r run typecheck` from umbrella root) returns 0 errors.
- [ ] `pnpm lint` introduces **0 new warnings or errors**. Pre-existing warnings are acceptable; new ones are not.
- [ ] Cross-cutting rules live in `packages/eslint-config/index.mjs` (not in `eslint.config.mjs`, which is for this app's plugin registration + ignores). **That file is this app's OWN copy** — `web/admin/pnpm-workspace.yaml` lists `packages/*`, so `@tempo/eslint-config` resolves through `workspace:*` to `web/admin/packages/eslint-config/`, and editing it changes `web/admin` only. Every other web app carries its own copy; a rule that should apply everywhere has to be applied in each of them.

A code review that doesn't enforce these checks is incomplete. Block the change until they pass.
