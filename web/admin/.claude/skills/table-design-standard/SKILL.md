---
name: table-design-standard
description: "Use when creating or reviewing any HQ/Shop list page table. Enforces the standard DataTable column design: select-all checkbox, STT/No. column, green clickable name link, StatusBadge, locale-aware date formatting via formatDate/formatDateTime from @/lib/date, EllipsisVertical action dropdown. Trigger phrases: 'table design', 'list page', 'data table', 'cot bang', 'thiet ke bang', 'apply table standard', 'tao trang danh sach'. Also invoke proactively when port-hq-screen or convert-to-new-design creates a new list page."
---

# Standard DataTable Design

Every list page under `/hq/[brandSlug]/<resource>` and `/shop/[shopSlug]/<resource>` MUST follow this column design standard. The canonical reference is **`products/page.tsx`**.

## Required Column Order

Every table MUST include these columns in this order. Domain-specific columns slot between Name and Status.

```
[ ] | No. | Name | ...domain columns... | Status | Updated | Action
```

### 1. Select Column (checkbox)

```tsx
{
  id: "select",
  size: 36,
  header: () => (
    <Checkbox
      checked={items.length > 0 && selected.size === items.length}
      onCheckedChange={toggleSelectAll}
      aria-label="Select all"
    />
  ),
  cell: ({ row }) => (
    <Checkbox
      checked={selected.has(row.original.id)}
      onCheckedChange={() => toggleSelect(row.original.id)}
      aria-label="Select row"
    />
  ),
},
```

**Rules:**
- Header MUST be a select-all `<Checkbox>`, NOT a text label like `t("common.action")`.
- The page MUST implement `toggleSelectAll()` and `toggleSelect(id)` functions.
- If some rows are not selectable (e.g., active menus can't be deleted), filter them in `toggleSelectAll`.
- If the resource has no bulk operations, this column may be omitted (e.g., customers, orders).

### 2. STT / No. Column

```tsx
{
  id: "stt",
  header: t("hq.products.col.stt"),
  size: 50,
  cell: ({ row }) => (
    <span className="text-xs text-muted-foreground">{row.index + 1}</span>
  ),
},
```

**Rules:**
- Always present on every list page.
- Uses `row.index + 1` (1-based) for client-paginated tables.
- Server-paginated tables use `(meta.from ?? 1) + row.index` (see Pagination section).
- Styled: `text-xs text-muted-foreground`.

### 3. Name Column (green clickable link)

```tsx
{
  id: "name",
  header: t("common.name"),
  size: 260,
  cell: ({ row }) => (
    <Link
      href={`/hq/${brandSlug}/<resource>/${row.original.id}`}
      className="font-medium text-primary hover:underline"
    >
      {row.original.name}
    </Link>
  ),
},
```

**Rules:**
- MUST use `className="font-medium text-primary hover:underline"` (renders as green clickable text).
- If the resource has a detail page (`/[id]`), use `<Link>` from `next/link`.
- If the resource only has dialog-based editing (no detail route), use a `<button>` that opens the edit dialog:
  ```tsx
  <button
    type="button"
    className="text-left font-medium text-primary hover:underline"
    onClick={() => setEditing(row.original)}
  >
    {row.original.name}
  </button>
  ```
- If the name has a description subtitle, wrap in a flex column:
  ```tsx
  <div className="flex flex-col">
    <Link ...>{name}</Link>
    {description && (
      <span className="line-clamp-1 text-xs text-muted-foreground">
        {description}
      </span>
    )}
  </div>
  ```

### 4. Domain-Specific Columns

Slot between Name and Status. Examples:

| Resource | Domain Columns |
|---|---|
| Products | Type, Variants, Hidden |
| Materials | SKU, Components, Yield, Cost, Allergens |
| Recipes | SKU, Ingredients, Output, Linked Variants, Approval Status, Allergens |
| Menus | Priority, Valid Period, Products, Base |
| Categories | SKU, Children |
| Product Types | Code, Form, Recipe, Inventory |
| Allergens | Code, Jurisdiction, Severity, Is Active |
| Devices | Branch, Type, Pairing, Last Seen |
| Orders | Order Code, Type, Customer, Total |

**Styling rules for domain columns:**
- Numeric/count values: `text-xs text-muted-foreground`
- Code/SKU values: `<code className="rounded bg-muted px-1.5 py-0.5 text-xs">`
- Badge/enum values: `<Badge>` or `<StatusBadge>`
- Currency: `tabular-nums text-sm`
- Empty values: show `"—"` (em dash), not empty string or `"-"`

### 5. Status Column

```tsx
{
  id: "status",
  header: t("common.status"),
  size: 90,
  cell: ({ row }) => (
    <StatusBadge
      status={row.original.deleted_at ? "deleted" : row.original.status}
    />
  ),
},
```

**Rules:**
- Always use `<StatusBadge>` from `@godxjp/ui`, never raw `<Badge>` for active/inactive/deleted states.
- If the resource uses `is_active` boolean instead of a `status` string:
  ```tsx
  <StatusBadge
    status={
      row.original.deleted_at
        ? "deleted"
        : row.original.is_active
        ? "active"
        : "inactive"
    }
  />
  ```

### 6. Updated Column

```tsx
import { formatDate } from "@/lib/date";

// inside component:
const { t, locale } = useTranslation();

{
  accessorKey: "updated_at",
  header: t("common.updated"),
  size: 130,
  cell: ({ row }) => (
    <span className="text-xs text-muted-foreground">
      {formatDate(row.original.updated_at, locale)}
    </span>
  ),
},
```

**Rules:**
- MUST use `formatDate(iso, locale)` from `@/lib/date` — never `toLocaleDateString("ja-JP")` or any hardcoded locale.
- `locale` comes from `const { t, locale } = useTranslation()` (same hook call that provides `t`).
- Output by locale: `vi` → `28/05/2026` · `en` → `05/28/2026` · `ja` → `2026/05/28`
- For datetime fields (e.g. `created_at`, `last_seen_at`, `paid_at`) use `formatDateTime(iso, locale)` — same import, adds `HH:mm`.
- For time-only display (e.g. order time on dashboard) use `formatTime(iso, locale)`.
- Styled: `text-xs text-muted-foreground`

### 7. Action Column (dropdown)

The canonical reference is the **`menus/page.tsx`** action column.

#### 7a. Trigger (always identical)

```tsx
<DropdownMenuTrigger asChild>
  <Button variant="ghost" size="icon" className="size-7">
    <EllipsisVertical className="size-4" />
  </Button>
</DropdownMenuTrigger>
```

#### 7b. Content width

- `<DropdownMenuContent align="end">` — minimal items (≤3), no width class needed.
- `<DropdownMenuContent align="end" className="w-52">` — 4+ items or workflow actions.

#### 7c. Deleted-row variant (Restore only)

When `item.deleted_at` is set, show **only** Restore — no other actions are valid on a trashed row:

```tsx
if (item.deleted_at) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-7">
          <EllipsisVertical className="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => restore.mutate(item.id)}>
          <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
```

#### 7d. Two activation patterns — choose one per resource

**Pattern A — `is_active` boolean toggle** (product-types, materials, categories, allergens)

A single item whose label flips between Activate / Deactivate. Uses `<Power>` icon. The `toggleStatus` mutation handles both directions. Always visible on non-trashed rows. Delete is always allowed regardless of active state.

```tsx
{/* Edit */}
<DropdownMenuItem onClick={() => setEditing(pt)}>
  <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
</DropdownMenuItem>

{/* Toggle active/inactive — single item, label flips */}
<DropdownMenuItem onClick={() => toggleStatus.mutate(pt.id)}>
  <Power className="mr-2 size-3.5" />{" "}
  {pt.is_active ? t("common.deactivate") : t("common.activate")}
</DropdownMenuItem>

<DropdownMenuSeparator />

{/* Delete — always allowed */}
<DropdownMenuItem
  className="text-destructive"
  onClick={() => setConfirmSingle(pt)}
>
  <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
</DropdownMenuItem>
```

**Pattern B — status-machine workflow** (menus, products, recipes)

Separate Activate and Deactivate items shown conditionally based on `status` value. Uses `<Play>` / `<Pause>` icons. Delete is gated by `canDelete(status)` — some statuses (e.g., Active) block deletion at the backend.

```tsx
{/* Activate: shown when Approved or Inactive */}
{(m.status === "Approved" || m.status === "Inactive") && (
  <DropdownMenuItem onClick={() => activateMenu.mutate(m.id)}>
    <Play className="mr-2 size-3.5" /> {t("hq.menus.actions.activate")}
  </DropdownMenuItem>
)}

{/* Deactivate: shown when Active */}
{m.status === "Active" && (
  <DropdownMenuItem onClick={() => deactivateMenu.mutate(m.id)}>
    <Pause className="mr-2 size-3.5" /> {t("hq.menus.actions.deactivate")}
  </DropdownMenuItem>
)}

{/* Delete — guarded by canDelete */}
{canDelete(m.status) && (
  <>
    <DropdownMenuSeparator />
    <DropdownMenuItem
      className="text-destructive"
      onClick={() => setConfirmSingle(m)}
    >
      <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
    </DropdownMenuItem>
  </>
)}
```

**How to decide which pattern to use:**

| Signal | Pattern |
|---|---|
| Resource has `is_active: boolean` field | A — `<Power>` toggle |
| Resource has a `status` enum with multi-step workflow (Draft → Pending → Approved → Active) | B — separate Activate/Deactivate + `canDelete` guard |
| Active status blocks deletion at the backend | B — `canDelete` guard required |

#### 7e. Normal-row item ordering (both patterns)

Items MUST appear in this order:

```
1. Navigate/View  (router.push to detail page — if resource has a dedicated sub-page)
2. Edit           (open form dialog or navigate to /edit)
3. Clone / Sync   (resource-specific copy/sync actions — conditional on flags)
4. ── separator ── (only if workflow group follows)
5. Workflow actions (submit → approve / reject → activate / deactivate — status-gated, Pattern B only)
6. Toggle Active  (Pattern A only — goes right after Edit, no separator needed before it)
7. ── separator ── (before destructive zone)
8. Delete         (className="text-destructive" — always visible in Pattern A, canDelete-guarded in Pattern B)
```

Full example from menus page (Pattern B):

```tsx
<DropdownMenuContent align="end" className="w-52">
  {/* 1. Navigate */}
  <DropdownMenuItem
    onClick={() => router.push(`/hq/${brandSlug}/menus/${m.id}/items`)}
  >
    <UtensilsCrossed className="mr-2 size-3.5" /> {t("hq.menus.actions.manage_items")}
  </DropdownMenuItem>

  {/* 2. Edit */}
  <DropdownMenuItem onClick={() => openEdit(m)}>
    <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
  </DropdownMenuItem>

  {/* 3. Clone — conditional flag */}
  {m.is_master && (
    <DropdownMenuItem onClick={() => openCloneDialog(m)}>
      <Copy className="mr-2 size-3.5" /> {t("hq.menus.actions.clone")}
    </DropdownMenuItem>
  )}

  {/* 3. Sync — conditional flag */}
  {m.master_menu_id && (
    <DropdownMenuItem onClick={() => syncFromMaster.mutate(m.id)}>
      <RotateCcw className="mr-2 size-3.5" /> {t("hq.menus.actions.sync")}
    </DropdownMenuItem>
  )}

  {/* 5. Workflow: Submit (disabled with tooltip when precondition fails) */}
  {(m.status === "Draft" || m.status === "Rejected") && (
    <DropdownMenuItem
      onClick={() => canSubmit && submitMenu.mutate(m.id)}
      disabled={!canSubmit}
      title={submitBlockedReason}
    >
      <Send className="mr-2 size-3.5" /> {t("hq.menus.actions.submit")}
    </DropdownMenuItem>
  )}

  {/* 5. Workflow: Approve / Reject — grouped under separator */}
  {m.status === "Pending" && (
    <>
      <DropdownMenuSeparator />
      <DropdownMenuItem onClick={() => approveMenu.mutate(m.id)}>
        <Check className="mr-2 size-3.5" /> {t("hq.menus.actions.approve")}
      </DropdownMenuItem>
      <DropdownMenuItem
        onClick={() => setRejectDialog({ open: true, menuId: m.id })}
        className="text-destructive"
      >
        <X className="mr-2 size-3.5" /> {t("hq.menus.actions.reject")}
      </DropdownMenuItem>
    </>
  )}

  {/* 5. Workflow: Activate / Deactivate */}
  {(m.status === "Approved" || m.status === "Inactive") && (
    <DropdownMenuItem onClick={() => activateMenu.mutate(m.id)}>
      <Play className="mr-2 size-3.5" /> {t("hq.menus.actions.activate")}
    </DropdownMenuItem>
  )}
  {m.status === "Active" && (
    <DropdownMenuItem onClick={() => deactivateMenu.mutate(m.id)}>
      <Pause className="mr-2 size-3.5" /> {t("hq.menus.actions.deactivate")}
    </DropdownMenuItem>
  )}

  {/* 7. Delete */}
  {canDelete(m.status) && (
    <>
      <DropdownMenuSeparator />
      <DropdownMenuItem
        onClick={() => setConfirmSingle(m)}
        className="text-destructive"
      >
        <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
      </DropdownMenuItem>
    </>
  )}
</DropdownMenuContent>
```

#### 7f. Item-level rules

| Rule | Detail |
|---|---|
| Icon size | Always `className="mr-2 size-3.5"` on every icon inside a `<DropdownMenuItem>` |
| Destructive items | `className="text-destructive"` — Delete and Reject only |
| Disabled items | Use `disabled={!condition}` + `title="reason"` for tooltip — **do NOT hide** the item |
| Navigate action | Use `router.push(...)` inside `onClick`, not `<DropdownMenuItem asChild><Link>` — avoids focus-trap conflicts |
| No inline buttons | Never put `<Button>` or `<a>` as siblings to the dropdown — all row actions go inside the menu |

#### 7g. canDelete helper (Pattern B only)

Extract deletion eligibility into a named guard above the component:

```tsx
function canDelete(status: ResourceStatus): boolean {
  return status !== "Active"; // adjust per resource — Active blocks delete at the backend
}
```

Pattern A resources (`is_active` boolean) do NOT need this guard — Delete is always allowed.

#### 7h. Mutation dialogs triggered from dropdown

Actions that need confirmation or extra input (Delete confirm, Reject reason, Clone branch selector) MUST:
1. Store their target in component state (`const [rejectDialog, setRejectDialog] = useState(...)`)
2. Open the dialog from `onClick` — do NOT inline a `<Dialog>` inside the column cell
3. Render the dialog as a sibling below `<DataTable>` in the JSX return

#### 7i-ext. Single delete while bulk selection is active

When the user triggers a single-row delete from the dropdown **while other rows are also selected**, the selection state must stay consistent after the deletion completes. The correct behavior is:

- **Do NOT clear the whole selection** when opening the confirm dialog — that causes a jarring UI flash as the bulk toolbar disappears mid-interaction.
- **After single delete succeeds**, remove only that item's ID from `selected`:

```tsx
// in the useSingleDelete (or equivalent) hook — onSuccess callback:
onSuccess: () => {
  // remove the deleted id from any active bulk selection
  setSelected((prev) => {
    const next = new Set(prev);
    next.delete(deletedItem.id);
    return next;
  });
  // ... invalidate queries, toast, etc.
},
```

Because `setSelected` is defined in the page component and the hook lives in a separate file, pass `setSelected` (or a `onDeleted: (id: string) => void` callback) as an option to the hook, or call it directly in the `onSuccess` of the `useMutation` call-site on the page.

**Anti-patterns:**
- ❌ `setSelected(new Set())` on dropdown "Delete" click — wipes all other selected rows before user confirms.
- ❌ Not updating `selected` at all — stale IDs remain in the set; the bulk toolbar still shows a count that includes the now-deleted row, leading to a failed bulk request.
- ❌ Relying on query invalidation to implicitly fix selection — the `selected` Set is local state, not derived from server data; it must be updated explicitly.

#### 7i. Complete Pattern A template (is_active boolean)

Use for: product-types, materials, categories, recipes, allergens, devices, and any resource with `is_active` field and no multi-step approval workflow.

```tsx
{
  id: "actions",
  size: 50,
  header: t("common.action"),
  cell: ({ row }) => {
    const item = row.original;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-7">
            <EllipsisVertical className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {item.deleted_at ? (
            // Trashed → Restore only
            <DropdownMenuItem onClick={() => restore.mutate(item.id)}>
              <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
            </DropdownMenuItem>
          ) : (
            <>
              {/* Edit — use Link variant if resource has a detail route */}
              <DropdownMenuItem onClick={() => setEditing(item)}>
                <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
              </DropdownMenuItem>

              {/* Active toggle — single item, label flips */}
              <DropdownMenuItem onClick={() => toggleStatus.mutate(item.id)}>
                <Power className="mr-2 size-3.5" />{" "}
                {item.is_active ? t("common.deactivate") : t("common.activate")}
              </DropdownMenuItem>

              <DropdownMenuSeparator />

              {/* Delete — always allowed for is_active resources */}
              <DropdownMenuItem
                className="text-destructive"
                onClick={() => setConfirmSingle(item)}
              >
                <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  },
},
```

#### 7j. Complete Pattern B template (status-machine workflow)

Use for: menus, products, and any resource with a `status` enum that gates deletion.

```tsx
{
  id: "actions",
  size: 50,
  header: t("common.action"),
  cell: ({ row }) => {
    const item = row.original;

    // Trashed → early return with Restore only
    if (item.deleted_at) {
      return (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-7">
              <EllipsisVertical className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => restore.mutate(item.id)}>
              <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      );
    }

    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-7">
            <EllipsisVertical className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-52">
          {/* 1. Navigate to sub-page (if applicable) */}
          <DropdownMenuItem
            onClick={() => router.push(`/hq/${brandSlug}/<resource>/${item.id}/items`)}
          >
            <SomeIcon className="mr-2 size-3.5" /> {t("<resource>.actions.view")}
          </DropdownMenuItem>

          {/* 2. Edit */}
          <DropdownMenuItem onClick={() => openEdit(item)}>
            <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
          </DropdownMenuItem>

          {/* 3. Workflow: Submit (Draft / Rejected → Pending) */}
          {(item.status === "Draft" || item.status === "Rejected") && (
            <DropdownMenuItem
              onClick={() => canSubmit && submitMutation.mutate(item.id)}
              disabled={!canSubmit}
              title={submitBlockedReason}
            >
              <Send className="mr-2 size-3.5" /> {t("<resource>.actions.submit")}
            </DropdownMenuItem>
          )}

          {/* 3. Workflow: Approve / Reject (Pending) */}
          {item.status === "Pending" && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => approveMutation.mutate(item.id)}>
                <Check className="mr-2 size-3.5" /> {t("<resource>.actions.approve")}
              </DropdownMenuItem>
              <DropdownMenuItem
                className="text-destructive"
                onClick={() => setRejectDialog({ open: true, itemId: item.id })}
              >
                <X className="mr-2 size-3.5" /> {t("<resource>.actions.reject")}
              </DropdownMenuItem>
            </>
          )}

          {/* 3. Workflow: Activate (Approved / Inactive) */}
          {(item.status === "Approved" || item.status === "Inactive") && (
            <DropdownMenuItem onClick={() => activateMutation.mutate(item.id)}>
              <Play className="mr-2 size-3.5" /> {t("<resource>.actions.activate")}
            </DropdownMenuItem>
          )}

          {/* 3. Workflow: Deactivate (Active) */}
          {item.status === "Active" && (
            <DropdownMenuItem onClick={() => deactivateMutation.mutate(item.id)}>
              <Pause className="mr-2 size-3.5" /> {t("<resource>.actions.deactivate")}
            </DropdownMenuItem>
          )}

          {/* Delete — guarded by canDelete */}
          {canDelete(item.status) && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="text-destructive"
                onClick={() => setConfirmSingle(item)}
              >
                <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  },
},
```

## Required Page Structure

### Toolbar — `<ListPageToolbar>` (canonical, do NOT hand-roll)

**Do not hand-roll the filter bar.** Every list page MUST use `<ListPageToolbar>` from `@/components/shared/list-page-toolbar`. The component owns: search input, debounce wiring, skeleton state, trashed toggle, clear-filters button, AND the bulk-action swap.

```tsx
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";

<ListPageToolbar
  search={search}
  onSearchChange={setSearch}
  searchPlaceholder={t("<resource>.search_placeholder")}
  showTrashed={showTrashed}
  onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
  hasActiveFilters={hasActiveFilters}
  onClearFilters={() => {
    resetFilters();
    setSearch(""); // MUST call both — resetFilters clears URL, setSearch clears local input
  }}
  isLoading={isLoading && items.length === 0}
  selectedCount={selected.size}
  bulkActions={
    <Button
      variant="destructive"
      size="sm"
      className="h-7 gap-1 text-xs"
      onClick={() => setConfirmBulk(true)}
      disabled={bulkDeleteMutation.isPending}
    >
      <Trash2 className="size-3.5" />
      {t("common.delete_selected", { n: selected.size })}
    </Button>
  }
>
  {/* Filter dropdowns go here as children — each calls setFilter() directly */}
  <Select value={statusFilter} onValueChange={(v) => setFilter("status", v)}>
    ...
  </Select>
</ListPageToolbar>
```

#### Bulk-action toolbar swap — how it works

`<ListPageToolbar>` has a built-in swap: when `selectedCount > 0` AND `bulkActions` is provided, the entire toolbar body swaps in-place — search input + filter dropdowns disappear, bulk action button(s) + "Đã chọn N" counter render instead. **Row height is pinned to `h-9` (36px) so the table below never jumps.**

```
[🔍 Tìm theo tên...]  [Tất cả trạng thái ▾]      ← selectedCount === 0
──────────────────────────────────────────────────
[🗑 Xoá 3 đã chọn]  Đã chọn 3                    ← selectedCount > 0 (same row, same h-9 height)
```

**Anti-patterns — stop if you see these:**

- ❌ `{selected.size > 0 && <Button>}` inside `children` — bulk button mixed with filter dropdowns, does NOT swap, both visible at once.
- ❌ `{selected.size > 0 && <div className="...">` below `<ListPageToolbar>` — separate sibling row causes layout jump.
- ❌ Hand-rolled `<div className="mb-3 flex flex-wrap items-center gap-2">` — loses the height-pinning and swap logic.
- ❌ `flex-wrap` on the toolbar row — allows height to grow when filters overflow; use `overflow-x-auto` instead (already handled inside the component).

#### i18n keys required

| Key | ja | en | vi |
|---|---|---|---|
| `common.delete_selected` | `{n}件削除` | `Delete {n} selected` | `Xóa {n} đã chọn` |
| `common.selected_count` | `{n} 件選択中` | `{n} selected` | `Đã chọn {n}` |

Both keys MUST exist in `ja.json`, `en.json`, `vi.json`.

#### Toolbar for pages without `<ListPageToolbar>` (hand-rolled toolbars)

A small number of pages (e.g. `menus/page.tsx`) hand-roll their toolbar as a `<div>`. When adding bulk-action swap to these, apply the same pattern:

```tsx
<div className="mb-3 flex h-9 items-center gap-2 overflow-x-auto">
  {selected.size > 0 ? (
    <>
      <Button variant="destructive" size="sm" className="h-7 gap-1 text-xs" onClick={...}>
        <Trash2 className="size-3.5" />
        {t("common.delete_selected", { n: selected.size })}
      </Button>
      <span className="text-xs text-muted-foreground">
        {t("common.selected_count", { n: selected.size })}
      </span>
    </>
  ) : (
    <>
      {/* search + filter dropdowns */}
    </>
  )}
</div>
```

**Critical:** `h-9 overflow-x-auto` (NOT `flex-wrap`) — same height-pinning rule applies.

### PageHeader Actions

```tsx
<PageHeader
  title={t("<resource>.title")}
  description={`${meta.total} total`}
  onRefresh={refetch}
  isRefreshing={isFetching}
>
  {/* Action buttons — all use: size="sm" className="h-7 gap-1 text-xs" */}
  <Button variant="outline" size="sm" className="h-7 gap-1 text-xs">
    <Download className="size-3.5" /> {t("common.export")}
  </Button>
  <Button size="sm" className="h-7 gap-1 text-xs">
    <Plus className="size-3.5" /> {t("common.new")}
  </Button>
</PageHeader>
```

### Pagination

Server-paginated tables MUST render `<Pagination>` as the last child of `<PageContent>`:

```tsx
import { Pagination, type PaginationMeta } from "@/components/shared/pagination";

const meta: PaginationMeta = listQuery.data?.meta ?? {
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: perPage,
  from: null,
  to: null,
};

// In JSX — last child of <PageContent>:
<Pagination
  meta={meta}
  page={page}
  onPageChange={setPage}
  perPage={perPage}
  onPerPageChange={(v) => setFilter("per_page", String(v))}
/>
```

**STT column for server-paginated tables** — use `meta.from`, not `row.index + 1`:

```tsx
cell: ({ row }) => (
  <span className="text-xs text-muted-foreground">
    {(meta.from ?? 1) + row.index}
  </span>
),
```

## Bulk Delete Hook — Toast Standard

Every `useBulkDelete<Resource>` hook MUST follow this pattern. The backend returns HTTP 200 with `{ deleted, errors }` even when some items are skipped — so `onSuccess` alone is not enough; you must inspect `errors` to choose the correct toast level.

### Backend contract

The `HasBulkOperations` trait (and any custom `bulkDelete` controller method) returns:

```json
{
  "deleted": 2,
  "errors": [
    { "id": "uuid", "name": "Milk", "message": "Cannot delete allergen: still in use by 1 material(s)." }
  ]
}
```

- `deleted` — count of successfully deleted items
- `errors[].name` — display name of the item that could not be deleted (use this in toast)
- `errors[].message` — reason (for logging / debugging)

### Hook template

```ts
export function useBulkDelete<Resource>(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => <resource>Service.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.<resource>.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(t("toast.<resource>.bulk_skipped", { deleted: data.deleted, skipped, names }));
        }
      } else {
        toast.success(t("toast.<resource>.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: <resource>Keys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
```

### Toast levels

| Situation | Toast level | Key used |
|---|---|---|
| All deleted successfully | ✅ `toast.success` | `toast.<resource>.bulk_deleted` |
| Some deleted, some skipped | ⚠️ `toast.warning` | `toast.<resource>.bulk_skipped` |
| None deleted (all skipped) | ❌ `toast.error` | `toast.<resource>.bulk_skipped` |

### Required i18n keys (all 3 locales)

```jsonc
// en.json
"toast.<resource>.bulk_deleted": "{n} <resource>(s) deleted.",
"toast.<resource>.bulk_skipped": "{deleted} deleted, {skipped} skipped: {names}.",

// ja.json
"toast.<resource>.bulk_deleted": "{n}件の<resource>を削除しました。",
"toast.<resource>.bulk_skipped": "{deleted}件削除、{skipped}件スキップ：{names}。",

// vi.json
"toast.<resource>.bulk_deleted": "Đã xóa {n} <resource>.",
"toast.<resource>.bulk_skipped": "Đã xóa {deleted}, bỏ qua {skipped}: {names}.",
```

### Service type

```ts
bulkDelete: (brandSlug: string, ids: string[]) =>
  apiFetch<{
    deleted: number;
    errors: { id: string; name?: string | null; message: string }[];
  }>(brandUrl(brandSlug, "/bulk-delete"), {
    method: "POST",
    body: JSON.stringify({ ids }),
  }),
```

### Anti-patterns

- ❌ `toast.success(...)` unconditionally in `onSuccess` — hides silent failures when `deleted === 0`
- ❌ Reading `data.deleted` only — always check `data.errors.length` too
- ❌ Using `errors[].id` as the display name — use `errors[].name` (falls back to `id` only if name is missing)
- ❌ Hardcoding the error message from BE — use the i18n key with `{names}` interpolation

## Mandatory Imports

Every list page needs these imports (subset based on features used):

```tsx
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { EllipsisVertical, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination, type PaginationMeta } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";

import {
  Button, Checkbox, StatusBadge, Spinner,
  DropdownMenu, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from "@godxjp/ui";
```

## Review Checklist

When reviewing or creating a list page table, verify:

### Toolbar / bulk actions

- [ ] `<ListPageToolbar>` is used — no hand-rolled `<div className="mb-3 flex flex-wrap ...">` replacing it
- [ ] Bulk action is passed via `bulkActions={...}` prop — NOT `{selected.size > 0 && <Button>}` inside `children`
- [ ] `selectedCount={selected.size}` prop is present alongside `bulkActions`
- [ ] No separate sibling `<div>` below `<ListPageToolbar>` for bulk actions
- [ ] No "Clear selection" button inside `bulkActions` — header checkbox handles deselect
- [ ] `onClearFilters` calls BOTH `resetFilters()` AND `setSearch("")`
- [ ] Hand-rolled toolbars (pages not using `<ListPageToolbar>`) use `h-9 overflow-x-auto` (not `flex-wrap`) and a ternary swap `{selected.size > 0 ? <bulk> : <filters>}`

### Table columns

- [ ] Select column header is a select-all `<Checkbox>`, not text
- [ ] STT column is present — `row.index + 1` (client-paginated) or `(meta.from ?? 1) + row.index` (server-paginated)
- [ ] Name column uses `text-primary hover:underline` (green clickable)
- [ ] Name links to detail page (or opens edit dialog if no detail route)
- [ ] Status uses `<StatusBadge>`, not raw `<Badge>`
- [ ] Dates use `formatDate(iso, locale)` from `@/lib/date` — **never** `toLocaleDateString("ja-JP")` or any hardcoded locale string. Datetime fields use `formatDateTime`, time-only fields use `formatTime`.
- [ ] Action column uses `<EllipsisVertical>` dropdown, not inline buttons
- [ ] Delete action uses `className="text-destructive"`
- [ ] Empty values render as `"—"` (em dash)
- [ ] `toggleSelectAll` function exists when select column is present
- [ ] Trashed rows show **only** Restore — no other actions
- [ ] Dropdown content uses `className="w-52"` when 4+ items are present
- [ ] All icons inside `<DropdownMenuItem>` use `className="mr-2 size-3.5"`
- [ ] Disabled items use `disabled={!condition}` + `title="reason"`, not hidden
- [ ] `<DropdownMenuSeparator />` placed before workflow groups and before Delete
- [ ] Navigate actions use `router.push(...)`, not `<DropdownMenuItem asChild><Link>`
- [ ] Confirmation dialogs (Delete, Reject, Clone) stored in component state and rendered outside the column cell
- [ ] Single delete `onSuccess` removes only that item's ID from `selected` — does NOT clear the whole set, and does NOT skip the update entirely (stale IDs in selection → broken bulk request)
- [ ] **Pattern A** (`is_active`): uses `<Power>` icon + single toggle item; Delete always visible; no `canDelete` guard needed
- [ ] **Pattern B** (status-machine): uses `<Play>`/`<Pause>` conditional items; Delete guarded by `canDelete(status)` helper defined above component
- [ ] Reject action uses `className="text-destructive"` (same as Delete)

### Bulk delete hook

- [ ] `onSuccess` inspects `data.errors.length` — does NOT call `toast.success` unconditionally
- [ ] `deleted === 0 && errors > 0` → `toast.error` with skipped names
- [ ] `deleted > 0 && errors > 0` → `toast.warning` with skipped names
- [ ] `errors === 0` → `toast.success`
- [ ] Skipped names built from `errors.map(e => e.name ?? e.id).join(", ")`
- [ ] Service type has `errors: { id: string; name?: string | null; message: string }[]`
- [ ] i18n keys `toast.<resource>.bulk_deleted` and `toast.<resource>.bulk_skipped` exist in all 3 locales

---

## Textarea in Forms and Dialogs

Any page or dialog that contains a `<Textarea>` (from `@godxjp/ui`) for user-facing text input MUST follow these rules.

### Root cause

`@godxjp/ui` Textarea bakes in `field-sizing-content` — a CSS `field-sizing` value that auto-expands the textarea to fit its content. When the user types a long string with no whitespace (e.g. spamming a key), the textarea grows horizontally instead of wrapping, causing it to overflow its container.

### Required fixes — apply both together

#### 1. `field-sizing-fixed` class

Override `field-sizing-content` by adding `className="field-sizing-fixed"` to every user-facing `<Textarea>`. This forces the textarea to respect its container width and wrap text normally.

```tsx
// ✅ correct
<Textarea
  value={reason}
  onChange={(e) => setReason(e.target.value)}
  rows={4}
  maxLength={1000}
  className="field-sizing-fixed"
/>

// ❌ wrong — textarea grows horizontally on long unbroken strings
<Textarea
  value={reason}
  onChange={(e) => setReason(e.target.value)}
  rows={4}
/>
```

If the `<Textarea>` already has other classes, append: `className="field-sizing-fixed other-class"`.

#### 2. `maxLength` + character counter

Every user-facing `<Textarea>` MUST have a `maxLength` matching the backend validation limit, plus a visible character counter below.

```tsx
<Textarea
  value={reason}
  onChange={(e) => setReason(e.target.value)}
  rows={4}
  maxLength={1000}
  className="field-sizing-fixed"
/>
<p className="text-right text-xs text-muted-foreground">{reason.length}/1000</p>
```

**Backend limits reference:**

| Field type | Limit |
|---|---|
| Rejection / cancellation reason | 2000 |
| Description (menu, category, etc.) | 1000 |
| Notes / internal notes | 1000 |
| Order note | 500 |

If the backend has no explicit `max:` rule, default to **1000**.

### Native `<textarea>` (non-component)

A handful of dialogs use a raw `<textarea>` instead of the `@godxjp/ui` `<Textarea>`. These do **not** have `field-sizing-content` and do not need `field-sizing-fixed`. They still need `maxLength` and a counter:

```tsx
<textarea
  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
  rows={2}
  maxLength={1000}
  value={form.description}
  onChange={(e) => update("description", e.target.value)}
/>
<p className="text-right text-xs text-muted-foreground">{(form.description ?? "").length}/1000</p>
```

### Exceptions — do NOT add `field-sizing-fixed` to

JSON / code editor Textareas (used in admin-only notification rules / templates / audience pages) intentionally auto-resize and must never be constrained:

- `conditions_json`, `action_json` in `notifications/rules`
- `contentJson` in `notifications/templates`
- `ruleJson` in `notifications/audiences`

### Review checklist

- [ ] Every user-facing `<Textarea>` from `@godxjp/ui` has `className="field-sizing-fixed"` (or it is appended to existing classes)
- [ ] `maxLength={N}` is set, matching the backend validation rule
- [ ] A `<p className="text-right text-xs text-muted-foreground">{value.length}/{N}</p>` counter is rendered directly below the textarea
- [ ] JSON/code editor textareas are exempt from `field-sizing-fixed`
- [ ] Native `<textarea>` elements only need `maxLength` + counter, not `field-sizing-fixed`
