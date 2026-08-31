---
name: port-hq-screen
description: "Use when the user asks to implement / port / convert / build a HQ management screen for a Laravel resource (e.g. 'làm màn hình product types', 'triển khai /hq/[brandSlug]/recipes', 'convert màn hình categories từ inertia', 'port the materials screen'). Covers brand-scoped HQ pages under /hq/[brandSlug]/<resource>. Encodes the full workflow: read the running Swagger spec, mirror the existing dxs-product Inertia reference, scaffold service + React Query hooks + page + form dialog following the categories/product-types pattern, and fix the recurring backend brand_id pitfall when present. NOT for shop-scoped pages (different middleware, different layout) or for non-CRUD pages."
---

# Port HQ Screen

A repeatable recipe for adding a new HQ resource page at `/hq/[brandSlug]/<resource>` in this Next.js frontend, powered by the Laravel backend at `~/Herd/dxs-product`.

The reference implementations to mirror are **`categories`** and **`product-types`** — read both before starting if you have not seen them recently.

## When to use this skill

- "làm màn hình X" / "triển khai /hq/{brand}/X" / "port màn hình X từ inertia" / "convert X screen"
- The resource has a HQ controller at `~/Herd/dxs-product/app/Http/Controllers/Api/V1/HQ/<X>Controller.php`
- The resource is brand-scoped (route prefix `/api/v1/hq/{brandSlug}`)

If the request is for a **shop-scoped** screen (`/shop/[shopSlug]/...`), the same shape applies but with `shopSlug` + `ResolveShopFromSlug` middleware — adapt accordingly.

## Inputs you need from the user (or infer)

1. **Resource name** in kebab-case (`product-types`, `recipes`, `materials`)
2. **Singular model name** in PascalCase (`ProductType`, `Recipe`, `Material`)
3. **Inertia reference** path (usually `~/dev/dxs-product/resources/js/pages/admin/<area>/<resource>.tsx`) — for UX cues only

If unclear, ask once. Do not start scaffolding without the Pascal/kebab pair.

## Workflow

### Step 1 — Read the authoritative API contract

The running backend's HQ Swagger spec is the source of truth, **not** the controller annotations and **not** the inertia frontend (both can drift).

```bash
curl -s "http://localhost:5400/docs/hq?hq-api-docs.json" -o /tmp/hq-spec.json
python3 -c "
import json
spec = json.load(open('/tmp/hq-spec.json'))
for path, methods in sorted(spec['paths'].items()):
    if '<resource>' in path:
        for m, op in methods.items():
            print(m.upper(), path, op.get('tags'))
            for p in op.get('parameters', []):
                if p.get('in') == 'query':
                    print('  query:', p['name'], p['schema'].get('type'))
            rb = op.get('requestBody')
            if rb:
                for mt, body in rb.get('content', {}).items():
                    s = body.get('schema', {})
                    print(f'  body[{mt}] required={s.get(\"required\", [])} props={list(s.get(\"properties\", {}).keys())}')
"
```

If the backend is on Herd (`https://dxs-product.test`) instead of Docker (`http://localhost:5400`), swap the host. The `.env.local` of this repo tells you which is in use.

Capture:
- Full list of endpoints (CRUD + lookup + bulk-delete + import/export + restore + toggle-status if applicable)
- Required and optional fields on POST and PUT
- Query params on GET

### Step 2 — Read the references

Open in this order, **read fully**, do not skim:

1. **Inertia reference** (UX cues only — DO NOT copy code, the framework is different):
   - `~/dev/dxs-product/resources/js/pages/admin/<area>/<resource>.tsx`
   - `~/dev/dxs-product/resources/js/services/<resource>Service.ts`
2. **This repo's reference patterns** (copy these patterns as the template):
   - `src/services/category-service.ts` *(or `product-type-service.ts`)*
   - `src/hooks/api/use-categories.ts`
   - `src/app/hq/[brandSlug]/categories/page.tsx`
   - `src/app/hq/[brandSlug]/categories/components/category-form-dialog.tsx`
3. **Auto-generated Omnify base type** (read but **NEVER edit**):
   - `src/types/models/base/<Entity>.ts` — auto-gen, has fields + zod schemas + i18n
   - `src/types/models/<Entity>.ts` — extends base, safe to edit
   - `src/types/models/enum/<Entity><EnumField>.ts` — for any enum fields

### Step 3 — Scaffold the frontend (5 files)

Create / edit in this exact order so each step typechecks before the next:

1. **`src/services/<resource>-service.ts`** — pure TS, no React. Mirror `src/services/category-service.ts`. Sections:
   - `interface <Entity> { ... }` based on the Omnify base type, not invented from the swagger
   - `interface <Entity>Filters` matching swagger query params
   - `interface Create<Entity>Input` and `type Update<Entity>Input = Partial<Create<Entity>Input>`
   - `function brandUrl(brandSlug, path = "")` returning `/api/v1/hq/${brandSlug}/<resource>${path}`
   - `function toParams(filters)` building `URLSearchParams`
   - `export const <resource>Service = { list, getById, lookup, create, update, delete, restore, toggleStatus, bulkDelete }`
   - **Do NOT include `brand_id` in `Create<Entity>Input`** — it's resolved server-side from `{brandSlug}`
   - Skip `import` / `exportCsv` in the first cut unless the user asks (they need multipart/blob handling — copy from `category-service.ts` later)

2. **`src/hooks/api/query-keys.ts`** — add or extend a `<resource>Keys` block following the existing convention:
   ```ts
   export const <resource>Keys = {
     all: (brandSlug: string) => ["<resource>", brandSlug] as const,
     list: (brandSlug: string, filters?: object) =>
       ["<resource>", brandSlug, "list", filters] as const,
     detail: (brandSlug: string, id: string) =>
       ["<resource>", brandSlug, "detail", id] as const,
     lookup: (brandSlug: string) => ["<resource>", brandSlug, "lookup"] as const,
   };
   ```

3. **`src/hooks/api/use-<resources>.ts`** — React Query wrappers. Mirror `use-categories.ts`. Every mutation invalidates `<resource>Keys.all(brandSlug)` and toasts via `sonner`. Hooks needed: `useList`, `useDetail`, `useLookup`, `useCreate`, `useUpdate`, `useDelete`, `useRestore`, `useBulkDelete`, plus `useToggleStatus` if the controller has it.

4. **`src/app/hq/[brandSlug]/<resource>/components/<resource>-form-dialog.tsx`** — create-or-edit dialog. Mirror `categories/components/category-form-dialog.tsx`. Key points:
   - `useEffect` to reset form when dialog opens or `entity` prop changes
   - Catch `ApiError` with `status === 422`, surface `body.errors` via `setFieldErrors`
   - Reuse the local `<Field label required error>` helper component pattern
   - Use existing `Input`, `Checkbox`, `Dialog*` from `@/components/ui/*`
   - For enums, use a row of styled buttons (see product-type form's `product_form` toggle) — NOT a native `<select>` unless trivial

5. **`src/app/hq/[brandSlug]/<resource>/page.tsx`** — list page. Mirror `categories/page.tsx` (flat) or `product-types/page.tsx` (closest analog for non-tree resources). Required pieces:
   - `useDebounce` hook (300ms) for search
   - Filter UI: search input, any enum filter, status filter, "Show trashed" checkbox
   - `useEffect` to clear `selected` when filters change (avoid bulk-deleting hidden rows)
   - Bulk-delete confirm `Dialog`
   - `DataTable` with columns: select / primary identity / domain badges / status / updated / actions
   - Inline row actions: Edit (set `editing` state), Toggle status, Delete (`confirm()`), Restore (only if `deleted_at`)
   - Render the create dialog and a separate edit dialog (both controlled by local state)

**Sidebar entry is already declared** in `src/app/hq/[brandSlug]/layout.tsx`. Do not duplicate it. If the entry is missing for the new resource, add a single line under the appropriate `navGroups` group — do not restructure the layout.

### Step 4 — Verify backend alignment (and fix the brand_id pitfall)

This step is **mandatory**. The Omnify-generated `<Entity>StoreRequestBase::schemaRules()` lists `brand_id` as `required|uuid|exists:brands,id`. Concrete subclasses **must** unset it, and the controller must set `brand_id` from middleware. Several resources in the repo have shipped without this fix (it was the bug we found on `product-types`). Always check.

Files to inspect at `~/Herd/dxs-product/`:

| File | Look for | Fix if missing |
|---|---|---|
| `app/Http/Requests/<Entity>StoreRequest.php` | `unset($rules['organization_id'], $rules['brand_id'])` | Add the unset |
| `app/Http/Requests/<Entity>UpdateRequest.php` | Same | Add the unset |
| `app/Http/Controllers/Api/V1/HQ/<Entity>Controller.php` | `store()` must set `$data['brand_id'] = $request->attributes->get('brand_id')` after `organization_id`. `index()` must pass `'brand_id' => $request->attributes->get('brand_id')` to the service. `restore()` must `where('brand_id', ...)`. `lookup()` must pass brand_id down. | Apply all four |
| `app/Services/<Area>/<Entity>Service.php` | `list()` must accept and apply `'brand_id'` filter. `lookup()` must accept `?string $brandId = null`. | Add the filter / param |

`ResolveBrandFromSlug` middleware (already wired at `routes/api.php` for the `hq/{brandSlug}` group) sets `$request->attributes->set('brand_id', $brand->id)` on every request — controllers only need to read it.

After the backend fix, run the resource's CRUD test suite from `~/Herd/dxs-product/`:

```bash
cd ~/Herd/dxs-product && php artisan test --filter=<Entity>CrudTest
```

It should be green. If pre-existing tests pass payloads with `brand_id`, that's still fine — Laravel silently drops unknown keys from `validated()`, and the controller sets `brand_id` itself.

### Step 5 — Diagnostics

Run `mcp__ide__getDiagnostics` on every file you created in step 3 (use the file URI form, encode `[brandSlug]` as `%5BbrandSlug%5D`). Fix until clean.

### Step 6 — Commit (TWO repos)

Frontend and backend live in separate git repos. Stage **only the files you touched** — both repos have unrelated unstaged changes that pre-date your session.

```bash
# Frontend
cd ~/Herd/godx-tempo-frontend
git add src/services/<resource>-service.ts \
        src/hooks/api/use-<resources>.ts \
        src/hooks/api/query-keys.ts \
        "src/app/hq/[brandSlug]/<resource>"
git commit -m "feat(hq): add <resource> management screen"

# Backend (only if you applied the brand_id fix)
cd ~/Herd/dxs-product
git add app/Http/Controllers/Api/V1/HQ/<Entity>Controller.php \
        app/Http/Requests/<Entity>StoreRequest.php \
        app/Http/Requests/<Entity>UpdateRequest.php \
        app/Services/<Area>/<Entity>Service.php
git commit -m "fix(hq): scope <resource> by brand and stop requiring brand_id from clients"
```

Push both with `git push origin main` after confirming with the user — both repos go to `godx-jp/godx-tempo-frontend` and `godx-jp/godx-tempo` respectively.

## Hard rules — do not break

- **Frontend NEVER sends `brand_id` in any payload.** It's in the URL. If you find yourself wanting to thread a `brandId` prop into a form, stop and re-read step 4.
- **Never edit `src/types/models/base/*.ts`** — they are auto-generated by `omnify-ts` and overwritten on next gen. Edit the sibling `src/types/models/<Entity>.ts` instead, or the plain interface in your new `<resource>-service.ts`.
- **Never write a custom `apiFetch` wrapper.** Always use `apiFetch` from `@/lib/api` — it handles 401 → `handleUnauthorized()` idempotently.
- **Never duplicate the sidebar entry.** Layout already declares them. Add at most one line if missing.
- **Reuse existing UI primitives.** Don't add new shadcn components without checking `src/components/ui/` first. Don't introduce Antd, MUI, or any other component lib (the inertia reference uses Antd — that is reference UX only).
- **Skip import/export on the first cut** unless explicitly asked. They need separate dialogs and multipart/blob plumbing — copy from `category-service.ts` only when needed.
- **Keep the form dialog stateless across opens** — `useEffect` resets when `open` flips.

## Key file paths cheat sheet

| What | Where |
|---|---|
| Frontend repo root | `~/Herd/godx-tempo-frontend` |
| Backend repo root | `~/Herd/dxs-product` |
| Inertia reference | `~/dev/dxs-product/resources/js/pages/admin/` |
| HQ Swagger UI | http://localhost:5400/docs/hq *(or https://dxs-product.test/docs/hq)* |
| HQ Swagger JSON | http://localhost:5400/docs/hq?hq-api-docs.json |
| HQ layout (sidebar) | `src/app/hq/[brandSlug]/layout.tsx` |
| Brand resolve middleware | `~/Herd/dxs-product/app/Http/Middleware/ResolveBrandFromSlug.php` |
| Reference: flat list page | `src/app/hq/[brandSlug]/product-types/page.tsx` |
| Reference: tree list page | `src/app/hq/[brandSlug]/categories/page.tsx` |
| Reference: form dialog | `src/app/hq/[brandSlug]/categories/components/category-form-dialog.tsx` |
| Reference: service | `src/services/category-service.ts` |
| Reference: hooks | `src/hooks/api/use-categories.ts` |
| Query keys file | `src/hooks/api/query-keys.ts` |
