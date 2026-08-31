---
name: convert-to-new-design
description: "Use when the user asks to convert, refactor, or migrate an existing HQ page from the old design system (modal-based forms, native <select>, plain textarea, flat single-column layout) to the new design system (route-based /new pages, Card grid layout, shadcn Select with SelectGroup, RichTextEditor, PageHeader actions, Vietnamese labels). Trigger phrases: 'convert sang design moi', 'chuyen sang he thong moi', 'refactor theo format moi', 'doi sang layout moi', 'update design', 'migrate to new format'. NOT for creating brand-new screens from scratch (use port-hq-screen instead)."
---

# Convert to New Design System

A repeatable recipe for converting existing HQ pages from the old design patterns to the new standardized design system used across this Next.js frontend.

## When to use this skill

- User asks to convert/refactor an existing page to match the new design
- User references a page that still uses modals for create, native `<select>`, plain `<textarea>`, or single-column flat layout
- User says "convert", "chuyen doi", "doi sang format moi", "update design theo X"

## Reference implementations (the "new" standard)

Always read these before starting a conversion:

| Reference | Path |
|---|---|
| Product create page (gold standard) | `src/app/hq/[brandSlug]/products/new/page.tsx` |
| Material create page | `src/app/hq/[brandSlug]/materials/new/page.tsx` |
| Recipe create page | `src/app/hq/[brandSlug]/recipes/new/page.tsx` |
| PageHeader (with backHref) | `src/components/layout/page-header.tsx` |

## Old vs New — What changes

### 1. Create flow: Modal -> Route page

**Old pattern (remove):**
```tsx
// State in list page
const [formOpen, setFormOpen] = useState(false);
const [editTarget, setEditTarget] = useState<Entity | null>(null);

function openCreate() {
  setEditTarget(null);
  setFormOpen(true);
}

// Button
<Button onClick={openCreate}>New</Button>

// Dialog at bottom of page
<EntityFormDialog open={formOpen} onOpenChange={setFormOpen} entity={editTarget} />
```

**New pattern (apply):**
```tsx
// List page: Link to /new route
import Link from "next/link";

<Link
  href={`/hq/${brandSlug}/<resource>/new`}
  className="inline-flex h-7 items-center gap-1 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground shadow-sm hover:bg-primary/90"
>
  <Plus className="size-3.5" />
  New
</Link>

// Keep FormDialog ONLY for edit (rename state: formOpen -> editOpen)
const [editOpen, setEditOpen] = useState(false);
const [editTarget, setEditTarget] = useState<Entity | null>(null);
```

Then create `src/app/hq/[brandSlug]/<resource>/new/page.tsx` as a full page.

### 2. Page layout: Flat form -> Card grid 12-col

**Old pattern:**
```tsx
<form className="mx-auto flex max-w-3xl flex-col gap-4 text-sm">
  <Field label="Name">...</Field>
  <Section title="...">...</Section>
</form>
```

**New pattern:**
```tsx
<PageHeader title="Them <resource> moi" description="Quan ly trung tam he thong">
  <Button variant="outline" size="sm" className="h-8" onClick={cancel}>Huy bo</Button>
  <Button size="sm" className="h-8 gap-1.5" onClick={handleSubmit}>
    {isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}
    Luu <resource>
  </Button>
</PageHeader>

<PageContent>
  <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
    {/* LEFT: main content (8 cols) */}
    <div className="flex flex-col gap-4 lg:col-span-8">
      <Card className="p-4">
        <div className="mb-4 text-sm font-semibold">Thong tin co ban</div>
        <div className="flex flex-col gap-4">
          {/* fields here */}
        </div>
      </Card>
      {/* More cards for sections */}
    </div>

    {/* RIGHT: sidebar (4 cols) */}
    <div className="flex flex-col gap-4 lg:col-span-4">
      <Card className="p-4">
        <div className="mb-3 text-sm font-semibold">Trang thai</div>
        {/* status, cost, etc */}
      </Card>
    </div>
  </div>
</PageContent>
```

### 3. Form actions: Bottom buttons -> PageHeader buttons

**Old:** Cancel/Submit buttons at form bottom inside `<div className="border-t pt-4">`

**New:** Cancel + Save buttons in `<PageHeader>` children. No bottom buttons.

### 4. Description: `<textarea>` -> RichTextEditor

**Old:**
```tsx
<textarea value={form.description} onChange={...} rows={2}
  className="w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm" />
```

**New:**
```tsx
import { RichTextEditor } from "@/components/shared/editor/rich-text-editor";

<RichTextEditor value={form.description} onChange={(v) => update("description", v)} />
```

### 5. Dropdowns: Native `<select>` -> shadcn Select with groups

**Old:**
```tsx
<select value={row.variant_id} onChange={(e) => update(i, { variant_id: e.target.value })}
  className="h-9 w-full rounded-md border border-input bg-background px-2 text-xs">
  <option value="">— Select variant —</option>
  {allVariants.map((v) => <option key={v.id} value={v.id}>{label}</option>)}
</select>
```

**New (with product grouping for variants):**
```tsx
import {
  Select, SelectContent, SelectGroup, SelectItem,
  SelectLabel, SelectTrigger, SelectValue,
} from "@/components/ui/select";

// Group variants by product
const variantGroups = useMemo(() => {
  const groups: Record<string, typeof allVariants> = {};
  for (const v of allVariants) {
    const groupName = v.product?.name ?? "Other";
    if (!groups[groupName]) groups[groupName] = [];
    groups[groupName].push(v);
  }
  return Object.entries(groups);
}, [allVariants]);

// In JSX:
<Select
  value={row.variant_id || undefined}
  onValueChange={(v) => updateComponent(i, { variant_id: v ?? "", unit_id: "" })}
  disabled={variantLookup.isLoading}
>
  <SelectTrigger className="h-9 w-full text-xs">
    <SelectValue placeholder="— Chon variant —" />
  </SelectTrigger>
  <SelectContent>
    {variantGroups.map(([productName, variants]) => (
      <SelectGroup key={productName}>
        <SelectLabel>{productName}</SelectLabel>
        {variants.map((v) => (
          <SelectItem key={v.id} value={v.id}>
            {v.name || v.sku || v.id}
          </SelectItem>
        ))}
      </SelectGroup>
    ))}
  </SelectContent>
</Select>
```

**Important:** This project uses `@base-ui/react/select`, where `onValueChange` passes `string | null`. Always coerce with `v ?? ""` when assigning to string fields.

**For simple selects (type, unit):**
```tsx
<Select value={row.type} onValueChange={(v) => update(i, { type: v as SomeType })}>
  <SelectTrigger className="h-9 w-28 text-xs">
    <SelectValue />
  </SelectTrigger>
  <SelectContent>
    <SelectItem value="variant">Variant</SelectItem>
    <SelectItem value="material">Material</SelectItem>
  </SelectContent>
</Select>
```

### 6. Field labels: Mixed styles -> Consistent Label component

**Old:**
```tsx
<label className="text-[11px] font-medium text-muted-foreground">Name</label>
<label className="text-xs font-medium text-muted-foreground">Name</label>
```

**New:**
```tsx
import { Label } from "@/components/ui/label";

<Label htmlFor="name" className="text-xs font-medium text-muted-foreground">Ten nguyen lieu*</Label>
```

### 7. Input heights: Mixed -> Consistent h-9

All `Input`, `SelectTrigger`, native controls should use `className="h-9"`.

### 8. Section headers: Border-top dividers -> Card titles

**Old:**
```tsx
<div className="flex flex-col gap-2 border-t pt-3">
  <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Components</div>
  ...
</div>
```

**New:**
```tsx
<Card className="p-4">
  <div className="mb-4 text-sm font-semibold">Thanh phan</div>
  ...
</Card>
```

### 9. Language: English -> Vietnamese

| English | Vietnamese |
|---|---|
| New material | Them nguyen lieu moi |
| Cancel | Huy bo |
| Save / Create | Luu <resource> |
| Name | Ten <resource> |
| Description | Mo ta |
| Components / Ingredients | Thanh phan |
| Yield | San luong |
| Cost | Chi phi |
| Status | Trang thai |
| Active | Dang hoat dong |
| Type | Loai |
| Item | Muc |
| Quantity | So luong |
| Unit | Don vi |
| Add component | Them thanh phan |

### 10. Toast on success

```tsx
import { toast } from "sonner";

// After successful create
toast.success("<Resource> da duoc tao thanh cong!");
```

## Conversion workflow

### Step 1 — Read the current page

Read the target page and its form dialog. Identify which old patterns are present.

### Step 2 — Read the reference

Read `src/app/hq/[brandSlug]/products/new/page.tsx` as the gold standard.

### Step 3 — Create the /new route page

1. Create `src/app/hq/[brandSlug]/<resource>/new/page.tsx`
2. Extract form logic from the dialog into the new page
3. Apply ALL new patterns listed above:
   - Card-based grid layout (8+4 cols)
   - PageHeader with Cancel + Save buttons
   - RichTextEditor for description
   - shadcn Select with SelectGroup for variant/material dropdowns
   - Label component with consistent styling
   - Vietnamese labels
   - Toast on success
   - `h-9` input heights

### Step 4 — Update the list page

1. Replace "New" button with `<Link>` to the /new route
2. Rename `formOpen` -> `editOpen` (keep dialog only for edit)
3. Remove `openCreate()` function
4. Add `import Link from "next/link"`

### Step 5 — Verify

Run TypeScript check:
```bash
npx tsc --noEmit --pretty 2>&1 | grep "<resource>"
```

Check for:
- `string | null` type errors from base-ui Select (fix with `v ?? ""`)
- Unused imports from removed modal create flow
- Missing imports for new components (Card, Label, Select*, RichTextEditor, toast, Save icon)

## Hard rules

- **Never remove the edit dialog** — only the create flow moves to a route page. Edit stays as a dialog.
- **Always use shadcn Select** — never native `<select>` in new code
- **Always group variants by product** using `SelectGroup` + `SelectLabel`
- **Always coerce `onValueChange`** with `v ?? ""` for base-ui Select
- **PageHeader has the action buttons** — no bottom button bar
- **Card wraps each section** — no bare `border-t` section dividers
- **RichTextEditor for description** — never plain `<textarea>`
- **Vietnamese labels** — match the vocabulary table above
- **Toast on success** — use `sonner`
- **Do not change the existing API hooks or services** — this is a UI-only conversion

## Required imports for the new page

```tsx
"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Loader2, Plus, Save, Trash2 } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import {
  Select, SelectContent, SelectGroup, SelectItem,
  SelectLabel, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { RichTextEditor } from "@/components/shared/editor/rich-text-editor";
import { ApiError, apiFetch } from "@/lib/api";
import { toast } from "sonner";
```
