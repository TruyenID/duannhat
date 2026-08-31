# Debug 001 — Fix

> Fix record for [Category click opens edit drawer instead of detail view](README.md).

## Root cause

`page.tsx:147` wired the row name's `onClick` to `openEdit(category)`, which opened the right-side `Sheet` drawer in edit mode. There was no detail page route, so users had no way to drill into a category and see its children — the chevron expand was the only mechanism, and it was not perceived as the same action as "click name to view this". The user requested a real detail page and a Modal (Dialog) replacement for the drawer on both New and Edit.

## Changes made

### Modified

| File | Lines | What changed | Why |
|------|-------|--------------|-----|
| `frontend/src/app/hq/[brandSlug]/categories/page.tsx` | name column + state | Row click no longer opens a drawer; the name is now a real `<Link>` to `/hq/{brandSlug}/categories/{id}`. Removed `editingCategory` state and `openEdit`. The Dialog imported here is now used for **New only** — Edit lives on the detail page. | Drives the user to the detail page where children are visible. |
| `frontend/src/app/hq/[brandSlug]/categories/components/category-tree-row.tsx` | full file | Replaced `<Link href="#">` + `onClick` callback with a real `<Link href={detailHref}>`. New `detailHref` prop replaces the old `onClick` prop. | Native browser navigation, real URL, accessible link semantics, ⌘-click works. Also fixes plan-002 review issue #18. |

### Created

| File | Purpose |
|------|---------|
| `frontend/src/app/hq/[brandSlug]/categories/[id]/page.tsx` | Category detail page — shows metadata sidebar + list of direct child categories with status badges and click-through to drill deeper. Edit and Delete buttons live in the page header; Edit opens the same `CategoryFormDialog` in edit mode. |
| `frontend/src/app/hq/[brandSlug]/categories/components/category-form-dialog.tsx` | Replacement for the deleted `category-form-sheet.tsx`. Same form fields, same field-error wiring, same parent-picker descendant exclusion. Only difference: `Sheet*` → `Dialog*` from `@/components/ui/dialog`. |
| `frontend/src/__tests__/category-tree-row.test.tsx` | Regression test (vitest + @testing-library/react). Asserts the row name is a real `<a href="...">` to the detail page (not `href="#"`), the chevron only appears when `hasChildren=true`, and indentation is `depth × 16px`. |

### Deleted

| File | Reason |
|------|--------|
| `frontend/src/app/hq/[brandSlug]/categories/components/category-form-sheet.tsx` | Replaced by `category-form-dialog.tsx`. Drawer pattern was the wrong UI primitive for this entity. |

## Regression test

A vitest test that would fail if this bug returned.

- **File:** `frontend/src/__tests__/category-tree-row.test.tsx`
- **Test name:** `it renders the name as a real <a> link to the detail page`
- **What it asserts:** the row name renders an `<a>` whose `href` matches the `detailHref` prop and is **not** `#`. The pre-fix code rendered `href="#"` and intercepted the click — this test would have failed before the fix.
- **Run with:** `npx vitest run src/__tests__/category-tree-row.test.tsx`
- **Result:** **3/3 passing** (1 main regression assertion + 2 supporting structural assertions for indentation and chevron visibility)

## Verification

- [x] Existing tests pass: `npx vitest run` (full vitest suite + new test) and backend Pest contract tests still 21/21.
- [x] Regression test added and passes (3/3 in `category-tree-row.test.tsx`).
- [x] `tsc --noEmit` is clean for all touched files (the only remaining errors are pre-existing duplicate identifiers in `src/types/models/index.ts` — Omnify drift, unrelated to this debug).
- [ ] **Manual repro** — DB has 0 categories on this Herd instance, so the visual repro requires seeded data. Tracked as a manual smoke step in the PR description.
- [ ] **Browser verified** — no Pest 4 browser infra in the project; manual browser pass before merge.
- [x] No new pint warnings (no PHP files were touched).

## Side effects

- The "New Category" button on the list page still opens a Dialog (not navigates) because creating a new category from an empty state doesn't have a URL to land on. Edit explicitly moved to the detail page; New stays on the list page for speed.
- The `parent` link on the detail page metadata sidebar links back up the tree, enabling true breadcrumb-style navigation.
- The `useCategory(id)` hook in `use-categories.ts` was previously dead code (review issue #11) — it is now wired up by the detail page, so that issue auto-resolves.
- Row click on a soft-deleted category still navigates to its detail page; the detail page header automatically shows a Restore button instead of Edit/Delete when `deleted_at` is set.
- Tree expand/collapse via the chevron remains unchanged.

## Follow-ups

_Things this fix exposes that should be addressed separately (don't scope-creep into this debug)._

- The detail page reads children by filtering the page-wide `useCategories` list (≤100 rows). For brands beyond the truncation cap, children of a deeply nested category may be missing. Same trade-off as the list page banner — out of scope here.
- The detail page does not yet show a "+ New child" inline action. Users must go back to the list page to add a child. Worth a follow-up if it shows up in UAT.
- The detail page does not load the `products` relation (the `CategoryResource` has it, but it would add a query). Worth adding once we know how the catalog UX wants to surface it.
- Plan-002 review issue #4 ("initial expanded state UX call") is still open — the tree starts collapsed, which is now slightly less of a problem because the list is no longer the only way to drill in (users can click the name and use the detail page's children list).
