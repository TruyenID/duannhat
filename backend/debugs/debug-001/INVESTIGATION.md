# Debug 001 — Investigation

> Investigation log for [Category click opens edit drawer instead of detail view](README.md).

## Method

This is a **design mismatch**, not a code defect. There is no hypothesis space — the code is doing exactly what it was written to do, and what it was written to do is wrong. The only investigation needed is to confirm where the broken interaction is wired.

## Files inspected

| File | Why | Finding |
|------|-----|---------|
| `frontend/src/app/hq/[brandSlug]/categories/page.tsx:147` | Where the row click handler is set | `onClick: openEdit` — clicking a row name calls `openEdit(category)`, which sets `editingCategory` and opens the Sheet |
| `frontend/src/app/hq/[brandSlug]/categories/page.tsx:159-162` | What `openEdit` does | Sets `editingCategory` + `setSheetOpen(true)` — opens the right-side drawer |
| `frontend/src/app/hq/[brandSlug]/categories/components/category-tree-row.tsx:55-65` | The clickable element | `<Link href="#">` with `e.preventDefault()` then calls `onClick(category)` (wired to `openEdit`) |
| `frontend/src/app/hq/[brandSlug]/categories/components/category-form-sheet.tsx` | The drawer the user complains about | `Sheet` from `@/components/ui/sheet` — slides from the right, contains the edit form. No children list. |
| `frontend/src/app/hq/[brandSlug]/categories/components/category-form-sheet.tsx:172` | Top-level component | `<Sheet open={open} ...><SheetContent>...</SheetContent></Sheet>` — must convert to `Dialog` |
| `plans/plan-002/DESIGN.md` "Key decisions" → "Decision 2" | Original design rationale | "Chose Sheet drawer ... Why: category is a lightweight entity, drawer keeps the user in the tree context, and the user explicitly chose this option." — the user's preference has now changed |

## Root cause

> **File:** `frontend/src/app/hq/[brandSlug]/categories/page.tsx:147`
> **Trigger:** any click on a category row name
> **Mechanism:** the row's `onClick` is hard-wired to `openEdit`, which opens the Sheet drawer in edit mode. There is no detail page route, no children list anywhere outside the inline chevron expand. The user expects "click name = drill into category", which the current code does not do.

Two separate but related fixes are required:

1. **Add a detail page** at `frontend/src/app/hq/[brandSlug]/categories/[id]/page.tsx` that shows the category's metadata and a list of its child categories. The list page links to it on row click.
2. **Replace `Sheet` with `Dialog`** in the create/edit form. Per the user's instruction, the Sheet drawer is the wrong UI primitive; New and Edit should both be Modal dialogs.

## Confidence

- [x] **High** — root cause directly observed in `page.tsx:147`. No hypothesis space. The fix is mechanical.

## Proposed fix

- **Approach:** create a new detail page route, convert the form component from `Sheet` to `Dialog`, change the row click to `router.push` the detail route. Edit happens from a button on the detail page (and the existing "New" button continues to open the create dialog from the list page).
- **Files to modify:**
  - `frontend/src/app/hq/[brandSlug]/categories/page.tsx` — wire row click to `router.push("/hq/${brandSlug}/categories/${id}")`; remove `editingCategory` state path; rename `CategoryFormSheet` import to `CategoryFormDialog`
  - `frontend/src/app/hq/[brandSlug]/categories/components/category-form-sheet.tsx` → rename to `category-form-dialog.tsx`, swap `Sheet*` for `Dialog*`
  - `frontend/src/app/hq/[brandSlug]/categories/components/category-tree-row.tsx` — change `<Link href="#">` to a real `<Link href={detailPath}>` (also fixes review issue #18)
- **Files to create:**
  - `frontend/src/app/hq/[brandSlug]/categories/[id]/page.tsx` — new detail page: metadata + children list + Edit button (opens dialog) + Back link
- **Side effects:**
  - Browser test scenarios in plan-002/TESTS.md that referenced "Sheet" need to be updated to "Dialog" (none currently implemented — browser tests deferred).
  - The `CategoryFormSheet` export name disappears. Search for any other importer first (none expected — only the page imports it).
- **Regression test:** add an arch test (Pest) asserting the categories area has a `[id]/page.tsx` route file, and a TypeScript-level smoke test confirming the dialog component compiles and exports the expected name. A real browser test for the click → navigate behavior would be ideal but the project has no browser test infrastructure.

## Alternatives considered

- **Show children inside the drawer.** Rejected — user explicitly said "không nên dùng drawer".
- **Click name = expand/collapse only.** Rejected by the user (they want a detail page, not just better expand UX).
- **Keep drawer for "New" only.** Considered — but the user's note says "chuyển sang modal cho new và edit only", so both New and Edit move to Dialog.

## Open questions

- [ ] Should the detail page also let users add a child category inline (e.g. "+ New child")? Out of scope for this debug — leave for follow-up.
- [ ] Should the detail page show products that belong to this category? `CategoryResource` has a `products` relation but loading it adds a query. Out of scope.
