---
name: ui-component-rules
description: "ALWAYS invoke before writing any UI code, creating components, or importing from @godxjp/ui. Enforces: Japanese design system tokens (SmartHR, 和色, density modes), @godxjp/ui import rules, design foundation constraints (yohaku spacing, OKLCH colors, touch targets). Triggers: any component creation, styling, layout work, form building, or UI debugging. Also invoke when user mentions 'design', 'UI', 'component', 'spacing', 'color', 'theme', 'density'."
---

# UI Component Rules — @godxjp/ui + Japanese Design System

## CRITICAL — Read before writing ANY UI code

All UI components live in `@godxjp/ui` (GitHub: godx-jp/godx-ui). Frontend imports from this package — **NEVER** create components in `frontend/src/components/ui/`.

## Import rules

```tsx
// ✅ CORRECT — all components from @godxjp/ui
import { Button, Input, Card, CardContent, Badge, Dialog, DialogContent } from "@godxjp/ui";
import { UIProvider, useLocale, useTheme } from "@godxjp/ui";
import { SchemaField, SchemaTable } from "@godxjp/ui";

// ❌ WRONG — these paths no longer exist
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
```

### Where components CAN live in frontend

| Location | Purpose | Example |
|---|---|---|
| `@godxjp/ui` | ALL UI primitives (atoms) | Button, Input, Card, Dialog, Badge, SchemaField, SchemaTable |
| `src/components/layout/` | App-specific chrome | TopBar, AppSidebar, PageHeader, LocaleSwitcher |
| `src/components/shared/` | Composites used by ≥2 routes | DataTable, RichTextEditor, SlugInput, TagInput |
| `src/app/{route}/components/` | Single-route colocated | ProductFormDialog, CategoryTreeRow |

## Japanese Design System — Hard Rules

Source: `plans/design-foundations-japanese.md` + `src/app/globals.css`

### Colors — OKLCH, NOT hex

```css
/* ✅ These are the correct tokens */
--primary: oklch(56% 0.15 240);       /* SmartHR MAIN blue */
--destructive: oklch(52% 0.18 25);    /* 茜 akane — NOT pure red */
--success: oklch(72% 0.13 155);       /* 若竹 wakatake */
--warning: oklch(80% 0.17 85);        /* 山吹 yamabuki */
--background: oklch(99% 0.002 60);    /* warm off-white */
--foreground: oklch(20% 0.006 60);    /* warm off-black — 墨色 */
--border: oklch(86% 0.006 60);        /* warm gray */

/* ❌ NEVER use */
background: #ffffff;          /* use bg-background */
color: #000000;               /* use text-foreground */
border-color: rgba(0,0,0,.1); /* use border-border */
color: #ff0000;               /* destructive is 茜, not pure red */
```

**Cultural rule**: 新しめのデザインを採用しているサービスほど赤は避けるか、控えめに — modern Japanese services avoid pure red. Use 茜 (`--destructive`).

### Spacing — 4px base, yohaku 余白 ratio 1:1.5:3

```
Inside card padding:     16px (spacing-4)   ← yohaku tight
Between sibling cards:   24px (spacing-6)   ← yohaku loose
Between sections:        48px (spacing-12)  ← yohaku very loose
```

```tsx
// ✅ Use Tailwind spacing tokens
<div className="p-4">          {/* 16px — inside card */}
<div className="gap-6">        {/* 24px — between cards */}
<div className="mt-12">        {/* 48px — between sections */}

// ❌ Arbitrary values
<div className="p-[18px]">     {/* off-grid */}
<div className="gap-7">        {/* 28px — not on scale */}
```

### Typography — 14px body, 1.7 line-height for JP

```
Body:     14px / 1.7 line-height (text-base)
Caption:  12px / 1.5 (text-xs)
Heading:  weight 500 (font-medium), NOT bold for h3/h4
Weights:  ONLY 400 / 500 / 700 — per 簡素 (kanso) principle
Font:     Hiragino Sans → Yu Gothic → Noto Sans JP → system
```

### Density — 3 modes via `[data-density]`

| Mode | Control height | Card padding | Use case |
|------|---------------|--------------|----------|
| compact | 28px | 12px | kintone-style dense tables |
| **default** | **32px** | **16px** | Standard |
| comfortable | 44px | 24px | Digital Agency touch floor |

Use `h-element` (not `h-8`, `h-9`, `h-10`) for density-aware heights:
```tsx
// ✅ Density-aware
<Input size="default" />   {/* uses h-element → 32px default, 28px compact, 44px comfortable */}

// ❌ Hardcoded height
<div className="h-10">     {/* ignores density mode */}
```

### Border radius — ladder

```
2px  — dense table inputs
4px  — buttons, inputs, controls (rounded-md)
6px  — cards (rounded-lg)
8px  — modals (rounded-xl)
```

### Touch target — 44×44px minimum (Digital Agency WCAG 2.5.5)

All interactive elements must have 44×44 CSS px hit area minimum. Small buttons use padding/margin to achieve this even if visual size is 28px.

### 和色 (Traditional Japanese Colors) — decorative only

13 accent colors for charts, tags, badges. NEVER use for semantic status:
藍, 群青, 瑠璃, 紺, 若竹, 萌葱, 山吹, 朱, 茜, 臙脂, 桜, 墨, 鼠

## SchemaField — metadata-driven forms

```tsx
import { SchemaField } from "@godxjp/ui";
import { zoneMetadata } from "@/types/models/base/Zone";

// Reads metadata → auto-renders correct input with label, placeholder, validation
<SchemaField metadata={zoneMetadata} field="name" value={form.name} onChange={v => update("name", v)} />
<SchemaField metadata={zoneMetadata} field="is_active" value={form.is_active} onChange={v => update("is_active", v)} />
<SchemaField metadata={zoneMetadata} field="status" value={form.status} onChange={v => update("status", v)} />
```

## SchemaTable — metadata-driven list pages

```tsx
import { SchemaTable } from "@godxjp/ui";

<SchemaTable
  metadata={productMetadata}
  data={products}
  columns={["name", "status", "is_hidden"]}
  sortableFields={["name", "created_at"]}
  filterableFields={["status", "is_hidden"]}
  showColumnToggle
  actions={[{ label: "Edit", onClick: handleEdit }]}
  pagination={{ page, perPage, total }}
  filters={filters}
  onFiltersChange={setFilters}
/>
```

## Verification checklist

Before committing any UI code:

1. ✅ All imports from `@godxjp/ui`, NOT `@/components/ui/`
2. ✅ Colors use tokens (`bg-primary`, `text-destructive`), NOT hex
3. ✅ Spacing on 4px grid (p-2, p-4, gap-6, mt-12), NOT arbitrary
4. ✅ Density-aware heights (`h-element`, `size="default"`), NOT hardcoded
5. ✅ Font weights ONLY 400/500/700
6. ✅ Touch targets ≥ 44px
7. ✅ No `#ff0000` — destructive is 茜
8. ✅ `npx tsc --noEmit --skipLibCheck` clean
9. ✅ `npx next build` clean
