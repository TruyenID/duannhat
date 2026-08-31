---
name: detail-page-quick-actions
description: "Use when creating or reviewing the header quick-action buttons on any HQ/Shop detail page (resource/[id]/page.tsx). Enforces: button order (Cancel → workflow → Delete → Save), disabledAll mutual-exclusion pattern, workflow status flags, Spinner on pending, unsaved-changes guard on Cancel, loading/error state header. Canonical reference: products/[id]/page.tsx. Trigger phrases: 'quick action', 'header button', 'detail page header', 'nut header', 'nut detail', 'workflow button', 'save button detail', 'thiet ke header detail', 'tao trang detail'."
---

# Detail Page Header Quick Actions

Every resource detail page under `/hq/[brandSlug]/<resource>/[id]/page.tsx` MUST follow this header quick-action standard. The canonical reference is **`products/[id]/page.tsx`**.

## Required Button Order

Buttons inside `<PageHeader>` MUST appear in this fixed order (left → right):

```
Cancel | (workflow buttons) | Delete | Save
```

| Position | Button | Always shown? |
|---|---|---|
| 1 | **Cancel** | Yes |
| 2–N | **Workflow buttons** (status-gated) | Conditional |
| N+1 | **Delete** | Hidden when `isTrashed` |
| Last | **Save** | Yes (disabled when no changes) |

---

## Sizing Standard

All header buttons use **identical sizing tokens**:

```tsx
size="sm" className="h-8 gap-1.5"
```

Exception: **Cancel** has no icon, so `gap-1.5` may be omitted:

```tsx
size="sm" className="h-8"
```

---

## The `disabledAll` Flag

Every button MUST be disabled during any pending mutation. Compute this flag once at the top of the return block:

```ts
const disabledAll = update.isPending || deleteOne.isPending || workflowPending;
```

Where `workflowPending` combines all workflow mutations:

```ts
const workflowPending =
  submitForApproval.isPending ||
  approveMut.isPending ||
  rejectMut.isPending ||
  activateMut.isPending ||
  deactivateMut.isPending;
```

Pass `disabled={disabledAll}` to every button. The Save button additionally guards on `!canSubmit`:

```tsx
disabled={!canSubmit || disabledAll}
```

---

## Workflow Status Flags

Derive visibility flags from `product.status` and `isTrashed` above the return:

```ts
const isTrashed = !!product?.deleted_at;

const showSubmit      = !isTrashed && (product.status === "draft" || product.status === "rejected");
const showApproveReject = !isTrashed && product.status === "pending";
const showActivate    = !isTrashed && (product.status === "approved" || product.status === "inactive");
const showDeactivate  = !isTrashed && product.status === "active";
```

Adapt the status strings to match the resource's backend enum (e.g., menus use `"Draft"` / `"Active"`).

---

## Complete Button Set (canonical)

```tsx
<PageHeader
  title={headerTitle}
  description={t("<resource>.subtitle")}
  backHref={`/hq/${brandSlug}/<resource>`}
>
  {/* 1. Cancel — always first, guards unsaved changes */}
  <Button
    type="button"
    variant="outline"
    size="sm"
    className="h-8"
    onClick={handleRequestExit}
    disabled={disabledAll}
  >
    {t("common.cancel")}
  </Button>

  {/* 2. Submit / Resubmit (draft or rejected) */}
  {showSubmit && (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 gap-1.5"
      onClick={handleSubmitForApproval}
      disabled={disabledAll}
    >
      {submitForApproval.isPending ? (
        <Spinner className="size-3.5" />
      ) : (
        <Send className="size-3.5" />
      )}
      {product.status === "rejected"
        ? t("<resource>.workflow.resubmit")
        : t("<resource>.workflow.submit")}
    </Button>
  )}

  {/* 3. Reject + Approve (pending) */}
  {showApproveReject && (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 gap-1.5 border-red-300 text-red-700 hover:bg-red-50"
        onClick={() => setRejectDialogOpen(true)}
        disabled={disabledAll}
      >
        <XCircle className="size-3.5" />
        {t("<resource>.workflow.reject")}
      </Button>
      <Button
        type="button"
        size="sm"
        className="h-8 gap-1.5"
        onClick={handleApprove}
        disabled={disabledAll}
      >
        {approveMut.isPending ? (
          <Spinner className="size-3.5" />
        ) : (
          <CheckCircle2 className="size-3.5" />
        )}
        {t("<resource>.workflow.approve")}
      </Button>
    </>
  )}

  {/* 4. Activate (approved or inactive) */}
  {showActivate && (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 gap-1.5 border-green-400 text-green-700 hover:bg-green-50"
      onClick={handleActivate}
      disabled={disabledAll}
    >
      {activateMut.isPending ? (
        <Spinner className="size-3.5" />
      ) : (
        <PlayCircle className="size-3.5" />
      )}
      {t("<resource>.workflow.activate")}
    </Button>
  )}

  {/* 5. Deactivate (active) */}
  {showDeactivate && (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 gap-1.5"
      onClick={handleDeactivate}
      disabled={disabledAll}
    >
      {deactivateMut.isPending ? (
        <Spinner className="size-3.5" />
      ) : (
        <PauseCircle className="size-3.5" />
      )}
      {t("<resource>.workflow.deactivate")}
    </Button>
  )}

  {/* 6. Delete — hidden for trashed resources */}
  {!isTrashed && (
    <Button
      type="button"
      variant="destructive"
      size="sm"
      className="h-8 gap-1.5"
      onClick={() => setDeleteDialogOpen(true)}
      disabled={disabledAll}
    >
      <Trash2 className="size-3.5" />
      {t("<resource>.delete_action")}
    </Button>
  )}

  {/* 7. Save — always last, primary action */}
  <Button
    type="button"
    size="sm"
    className="h-8 gap-1.5"
    onClick={handleSubmit}
    disabled={!canSubmit || disabledAll}
  >
    {update.isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
    {t("<resource>.save")}
  </Button>
</PageHeader>
```

---

## Button Styling Reference

| Button | `variant` | Extra `className` | Icon |
|---|---|---|---|
| Cancel | `outline` | — | none |
| Submit / Resubmit | `outline` | — | `<Send>` / `<Spinner>` |
| Reject | `outline` | `border-red-300 text-red-700 hover:bg-red-50` | `<XCircle>` (no Spinner — opens dialog) |
| Approve | default | — | `<CheckCircle2>` / `<Spinner>` |
| Activate | `outline` | `border-green-400 text-green-700 hover:bg-green-50` | `<PlayCircle>` / `<Spinner>` |
| Deactivate | `outline` | — | `<PauseCircle>` / `<Spinner>` |
| Delete | `destructive` | — | `<Trash2>` (no Spinner — opens dialog) |
| Save | default | — | `<Save>` / `<Spinner>` |

**Icon-only Spinner rule:** Replace the icon with `<Spinner className="size-3.5" />` when `mutation.isPending`. Keep the text label visible alongside. Do NOT disable the label — only swap the icon.

**Reject and Delete** open a confirmation dialog, so they never show a Spinner inline — the dialog handles the pending state.

---

## `handleRequestExit` — Unsaved Changes Guard

Cancel MUST prompt for confirmation when there are unsaved changes:

```ts
const handleRequestExit = useCallback(() => {
  if (hasChanges) {
    setConfirmExitOpen(true);
    return;
  }
  router.push(`/hq/${brandSlug}/<resource>`);
}, [hasChanges, router, brandSlug]);
```

`hasChanges` is a `useMemo` that compares current form state against the hydrated server snapshot field-by-field. It gates both the Cancel prompt and the `canSubmit` flag.

---

## `canSubmit` — Save Button Guard

```ts
const canSubmit =
  hydrated &&
  !isTrashed &&
  effectiveName.length > 0 &&
  productTypeId !== "" &&     // ← adjust required fields per resource
  hasChanges &&
  !update.isPending;
```

Rules:
- `hydrated` — never enable Save before the form is populated from the API.
- `!isTrashed` — trashed resources are read-only; Save is permanently disabled.
- Required field guards (e.g., `productTypeId !== ""`) — if a required field is missing, Save stays disabled. **Show a visible validation hint** rather than silently disabling if the user has attempted to interact.
- `hasChanges` — Save is disabled until the user actually modifies a field.

---

## Loading and Error State Headers

When the query is pending or errored, render a **minimal header** — Cancel only, no action buttons. This prevents firing mutations against a resource that hasn't loaded.

```tsx
// Loading state
if (productQuery.isLoading || !product || !hydrated) {
  return (
    <>
      <PageHeader
        title={t("<resource>.edit_title")}
        description={t("<resource>.subtitle")}
        backHref={`/hq/${brandSlug}/<resource>`}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleRequestExit}
        >
          {t("common.cancel")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          {t("common.loading")}
        </div>
      </PageContent>
    </>
  );
}

// Error state
if (productQuery.isError) {
  return (
    <>
      <PageHeader
        title={t("<resource>.edit_title")}
        description={t("<resource>.subtitle")}
        backHref={`/hq/${brandSlug}/<resource>`}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleRequestExit}
        >
          {t("common.cancel")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="rounded-md border border-destructive/50 bg-destructive/5 p-4 text-sm text-destructive">
          {t("common.load_error")}
        </div>
      </PageContent>
    </>
  );
}
```

---

## Resources Without Workflow

For resources with `is_active` boolean (no multi-step approval), omit all workflow buttons. Only Cancel, Delete, and Save are needed:

```tsx
<PageHeader ...>
  <Button variant="outline" size="sm" className="h-8" onClick={handleRequestExit} disabled={disabledAll}>
    {t("common.cancel")}
  </Button>

  {!isTrashed && (
    <Button variant="destructive" size="sm" className="h-8 gap-1.5" onClick={() => setDeleteDialogOpen(true)} disabled={disabledAll}>
      <Trash2 className="size-3.5" />
      {t("common.delete")}
    </Button>
  )}

  <Button size="sm" className="h-8 gap-1.5" onClick={handleSubmit} disabled={!canSubmit || disabledAll}>
    {update.isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
    {t("<resource>.save")}
  </Button>
</PageHeader>
```

---

## Mandatory Imports

```tsx
import {
  CheckCircle2, PauseCircle, PlayCircle, Save, Send, Trash2, XCircle
} from "lucide-react";
import { Button } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
```

---

## Review Checklist

When reviewing or creating a detail page header, verify:

- [ ] Button order is: Cancel → workflow buttons → Delete → Save
- [ ] All buttons use `size="sm" className="h-8 gap-1.5"` (Cancel may omit `gap-1.5` if no icon)
- [ ] `disabledAll` is computed from ALL mutation pending states and passed to every button
- [ ] `workflowPending` aggregates all workflow mutation `.isPending` flags
- [ ] Workflow buttons are shown/hidden via status flags derived before the return
- [ ] `isTrashed` hides Delete (and all workflow buttons)
- [ ] Spinner replaces the icon when mutation is pending — **label text stays visible**
- [ ] Reject and Delete open dialogs, not inline mutations — no Spinner on those buttons
- [ ] Reject button uses `border-red-300 text-red-700 hover:bg-red-50` styling
- [ ] Activate button uses `border-green-400 text-green-700 hover:bg-green-50` styling
- [ ] Cancel calls `handleRequestExit` which guards on `hasChanges` before navigating
- [ ] `handleRequestExit` uses `useCallback` with `[hasChanges, router, brandSlug]` deps
- [ ] `canSubmit` includes `hydrated`, `!isTrashed`, required-field checks, `hasChanges`
- [ ] Loading/error states render a **minimal header** with Cancel only — no action buttons
- [ ] Tiptap/rich-text fields wait for `hydrated` before rendering (avoids empty editor on mount)
- [ ] Submit payload includes **all editable fields** tracked by `hasChanges` — no silent omissions
