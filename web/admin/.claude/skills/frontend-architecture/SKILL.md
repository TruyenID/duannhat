---
name: frontend-architecture
description: "Invoke for any architectural question about this Next.js frontend: folder structure, data flow, state management, API layer, i18n, testing, or coding standards. Also invoke when user asks 'where should I put X', 'how does Y work', 'what pattern for Z'. Enforces the established rules: TanStack Query for data fetching, @godxjp/ui for components, omnify-generated types, service layer pattern, toast on mutations, query key factories."
---

# Frontend Architecture Rules

## Stack

- **Framework**: Next.js 16 (App Router, Turbopack)
- **Language**: TypeScript (strict mode)
- **Components**: `@godxjp/ui` (private npm package — github:godx-jp/godx-ui)
- **Data fetching**: TanStack React Query v5
- **Forms**: `react-hook-form` + `zod` (from omnify-generated schemas)
- **Styling**: Tailwind CSS v4 + Japanese Design System tokens
- **i18n**: Custom (ja/en/vi), locale cookie + provider
- **Auth**: Cookie-based token + SSO middleware

## Folder structure

```
src/
├── app/                          # Next.js App Router (file-based routing)
│   ├── hq/[brandSlug]/          # HQ management screens (brand-scoped)
│   ├── shop/[shopSlug]/         # Shop management screens (shop-scoped)
│   └── globals.css              # Design system tokens (DO NOT duplicate)
├── components/
│   ├── layout/                  # App-specific: TopBar, Sidebar, PageHeader
│   └── shared/                  # Cross-route composites: DataTable, RichTextEditor
├── hooks/api/                   # React Query hooks (1 file per resource)
│   ├── query-keys.ts            # ALL query key factories
│   ├── use-products.ts          # useProducts, useCreateProduct, etc.
│   └── use-zones.ts
├── services/                    # Pure TS API calls (no React)
│   ├── product-service.ts       # productService.list(), .create(), etc.
│   └── zone-service.ts
├── types/models/                # Omnify-generated types
│   ├── base/                    # AUTO-GEN — never edit
│   └── *.ts                     # User-land extensions — safe to edit
├── providers/                   # React Context providers
│   └── app-provider.tsx         # Theme, locale, i18n
├── i18n/                        # Translation dictionaries
└── lib/                         # Utilities
    ├── api.ts                   # apiFetch() — auth, locale header, error handling
    └── utils.ts                 # cn() utility
```

## Data flow pattern

```
API endpoint
  ← src/services/*-service.ts        (pure TS, no React)
    ← src/hooks/api/use-*.ts          (React Query wrapper)
      ← src/app/**/page.tsx            (page component)
```

**NEVER** call `apiFetch()` directly from components. Always go through service → hook.

## Service layer rules

1. **Pure TypeScript** — no React imports, no hooks
2. **1 file per resource** — `product-service.ts`, `zone-service.ts`
3. **URL convention**: `/api/v1/hq/{brandSlug}/products` or `/api/v1/shops/{shopSlug}/zones`
4. **Methods**: `list`, `getById`, `lookup`, `create`, `update`, `delete`, `restore`, `bulkDelete`
5. **Frontend NEVER sends `brand_id`** — it's in the URL, resolved by backend middleware
6. **Use omnify-generated `createXxxBaseService`** when available, extend with spread

## React Query rules

1. **Query keys from factory** — `src/hooks/api/query-keys.ts`, NEVER hardcode
2. **Every mutation toasts** — `toast.success()` on success, `toast.error()` on error (sonner)
3. **Every mutation invalidates** — `queryClient.invalidateQueries({ queryKey: xxxKeys.all(slug) })`
4. **`enabled` guard** — `enabled: !!brandSlug` to prevent firing before params resolve
5. **Convention**: `[entity, scope, variant, filters]` — e.g. `["products", brandSlug, "list", { status: "active" }]`

## i18n rules

1. **Default locale**: `ja` (Japanese)
2. **All user-facing text through `t()`** — from `useLocale()` hook
3. **Accept-Language header** — `apiFetch()` sends locale on every request
4. **Translatable fields**: use `<Input translatable />` or `<SchemaField>` which auto-renders locale tabs

## Testing rules

1. **Unit tests**: vitest, `src/__tests__/`
2. **Component tests**: in `@godxjp/ui` repo (365 tests), NOT in frontend
3. **Type check before commit**: `npx tsc --noEmit --skipLibCheck`
4. **Build check**: `npx next build`

## Component import rules — MANDATORY

```tsx
// ✅ ALL UI primitives from @godxjp/ui
import { Button, Input, Card, Dialog, Badge, Select, Combobox } from "@godxjp/ui";
import { SchemaField, SchemaTable } from "@godxjp/ui";
import { UIProvider, useLocale } from "@godxjp/ui";

// ✅ Layout components from local
import { PageHeader } from "@/components/layout/page-header";
import { AppSidebar } from "@/components/layout/app-sidebar";

// ✅ Shared composites from local
import { DataTable } from "@/components/shared/data-table";

// ❌ NEVER — these no longer exist
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
```

## API error handling

```tsx
import { ApiError } from "@/lib/api";

try {
  await mutation.mutateAsync(data);
} catch (err) {
  if (err instanceof ApiError && err.status === 422) {
    // Laravel validation errors
    const body = err.body as { errors?: Record<string, string[]> };
    if (body.errors) setFieldErrors(body.errors);
  }
}
```

## Hard rules

1. **`@godxjp/ui` for ALL UI primitives** — never create in `src/components/ui/`
2. **Service → Hook → Component** — never call API directly from components
3. **Query keys in factory** — never hardcode query key strings
4. **Toast on every mutation** — success + error
5. **`npx tsc --noEmit --skipLibCheck`** before every commit
6. **Never edit `src/types/models/base/`** — auto-generated by omnify
7. **Never send `brand_id` from frontend** — URL param, backend resolves
8. **Japanese design system tokens** — see `ui-component-rules` skill
