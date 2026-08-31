---
title: Translatable Forms
category: contributing
tags: [i18n, forms, translations, astrotomic, godxjp-ui, admin-web, omnify, apifetch]
summary: Complete rules and reference implementation for admin forms that edit multi-locale user content in TempoFast. Covers the two i18n layers (UI strings vs user content), translatable input components from @godxjp/ui, form state shape, submit payload via buildI18nPayload, hydration on edit, zero-ceremony display, and the locale-switch cache gap.
related: [documentation.md, i18n-locale-fill.md]
---

# Translatable Forms

> How to build an admin form that edits a multi-locale field (product name, category description, material label, menu section title) in TempoFast, and how to display that content back to end-users.

This is the canonical how-to for **user-content i18n** in `web/admin/`. The supporting pattern is the `@godxjp/ui` translatable-input API (`TranslatableValue`, `TranslatableField`, `<Input translatable />`, `<Textarea translatable />`, `<TranslatableRichText />`) plus backend Astrotomic translation tables. Read [web/admin/AGENTS.md § Multi-language (i18n)](../../web/admin/AGENTS.md#multi-language-i18n) first for the at-a-glance summary and review-checklist item; this doc is the reference implementation.

## The two i18n layers

Do not confuse them.

| Layer | What | Edited by | Lives in | Example |
|---|---|---|---|---|
| **1. UI strings** | Hard-coded developer labels, button text, validation messages, empty-state copy | Developers at build time | `src/i18n/{ja,en,vi}.json` + `useTranslation()` | `t('product.save')` → `"保存"` / `"Save"` / `"Lưu"` |
| **2. User content** | Per-row translations end-users enter — product names, descriptions, category labels, menu section titles, material display names | End-users at runtime via admin forms | Backend Astrotomic translation tables (`product_translations`, `category_translations`, …) | `products.translations.ja.name = "フォー"`, `products.translations.vi.name = "Phở"` |

If the string is written by a developer → layer 1. If the string is written by a business user through a form → layer 2. Never hard-code user content into a JSON dict, never stuff UI labels into a `*_translations` table.

This doc covers **layer 2 only**. Layer 1 is handled by `src/providers/app-provider.tsx` `useTranslation()` — see `web/admin/AGENTS.md`.

## Core Principles

1. **TempoFast runs on `ja / en / vi`.** Both layers share the same locale set — `defaultLocale: ja`, `fallbackLocale: en`, third locale `vi`. Any new locale flows through `omnify.yaml`'s `locale.locales` + regen, then `SUPPORTED_LOCALES` in `src/types/models/payload-helpers.ts` updates automatically.
2. **Form state is a `Record<LocaleCode, string>` per field.** Every translatable field gets its own map keyed by locale, initialised with an empty string per locale so the input is always controlled. State type alias is `TranslatableValue` from `@godxjp/ui`.
3. **Submit payload uses `buildI18nPayload({ ... })` spread + a top-level mirror field.** The top-level field is NOT NULL in the parent table; the nested per-locale map populates `*_translations`. Empty locales are stripped before the request.
4. **Display is zero-ceremony.** Read-only cells and detail headers use the resource field directly (`product.name`) — `apiFetch` sends `Accept-Language`, backend middleware resolves the locale, Astrotomic returns the localized scalar. No client-side locale resolution.
5. **Locale switches do not invalidate React Query cache.** Changing `app_locale` updates `Accept-Language` for the next request but existing cached queries keep serving the previous locale until the user refetches manually or the stale time expires — see [Rule 6: The locale-switch cache gap](#6-the-locale-switch-cache-gap).

## Reference implementation

The canonical examples are:

- `web/admin/src/app/hq/[brandSlug]/products/new/page.tsx` — create form with translatable `name` + `description`.
- `web/admin/src/app/hq/[brandSlug]/products/[id]/page.tsx` — edit form with hydration from existing `product.translations`.
- `web/admin/src/app/hq/[brandSlug]/products/components/basic-info-card.tsx` — shared translatable section reused by both create and edit.
- `web/admin/src/app/hq/[brandSlug]/materials/new/page.tsx` — translatable material form.

The minimal shape:

```tsx
// web/admin/src/app/hq/[brandSlug]/products/new/page.tsx (simplified)
"use client";

import { useState } from "react";
import { Input, TranslatableRichText, type TranslatableValue } from "@godxjp/ui";
import { toast } from "sonner";
import {
  buildI18nPayload,
  DEFAULT_LOCALE,
  emptyLocaleMap,
} from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { useCreateProduct } from "@/hooks/api/use-products";
import type { CreateProductInput } from "@/services/product-service";

export default function NewProductPage() {
  const [name, setName] = useState<TranslatableValue>(emptyLocaleMap);
  const [description, setDescription] = useState<TranslatableValue>(emptyLocaleMap);
  const [productTypeId, setProductTypeId] = useState("");
  const createProduct = useCreateProduct(brandSlug);

  async function handleSubmit() {
    // 1. Compute effective values — first non-empty in ja → en → vi priority.
    //    Used for canSubmit gate AND as the top-level mirror field in the payload.
    const effectiveName =
      name[DEFAULT_LOCALE]?.trim() || name["en"]?.trim() || name["vi"]?.trim() || "";
    const effectiveDescription =
      description[DEFAULT_LOCALE]?.trim() ||
      Object.values(description).find((v) => v?.trim()) ||
      "";

    // 2. Fill empty locales with effectiveName before building the payload so
    //    switching locale never shows null — see docs/contributing/i18n-locale-fill.md.
    const i18n = buildI18nPayload({
      name: fillLocalesFallback(name),
      description: effectiveDescription ? fillLocalesFallback(description) : description,
    });

    // 3. Rule 3 — strip locales whose required field is still empty.
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    // 4. Assemble the payload.
    const payload: CreateProductInput = {
      name: effectiveName,           // top-level mirror = effectiveName (NOT DEFAULT_LOCALE)
      description: effectiveDescription || null,
      product_type_id: productTypeId,
      // ... other non-translatable fields ...
      ...i18n, // ← adds ja/en/vi keys at the top level of the payload
    };

    await createProduct.mutateAsync(payload);
    // useCreateProduct hook handles the toast — don't duplicate.
  }

  return (
    <form onSubmit={handleSubmit}>
      <Input
        translatable
        value={name}
        onChange={setName}
        placeholder="e.g. Phở bò tái"
      />
      <TranslatableRichText
        value={description}
        onChange={setDescription}
      />
      <Button type="submit">Save product</Button>
    </form>
  );
}
```

## Rules

### 1. State shape is `Record<LocaleCode, string>` per field

Every translatable field gets its own state slot keyed by locale code. Initialise every supported locale to the empty string — a controlled input's `value` cannot be `undefined` without flipping to uncontrolled mode.

```tsx
// ✅ Correct — one slot per locale, every locale initialised
import type { TranslatableValue } from "@godxjp/ui";
import { emptyLocaleMap } from "@/types/models/payload-helpers";

const [name, setName] = useState<TranslatableValue>(emptyLocaleMap);
const [description, setDescription] = useState<TranslatableValue>(emptyLocaleMap);
```

```tsx
// ❌ Wrong — partial map, flips controlled/uncontrolled
const [name, setName] = useState<TranslatableValue>({ ja: "" } as TranslatableValue);

// ❌ Wrong — single string loses every other locale on every keystroke
const [name, setName] = useState("");

// ✅ Both forms work — prefer the function reference (lazy initializer) for symmetry with other pages
const [name, setName] = useState<TranslatableValue>(emptyLocaleMap()); // allowed — evaluates eagerly
const [name, setName] = useState<TranslatableValue>(emptyLocaleMap);   // preferred — lazy initializer
```

`emptyLocaleMap()` lives at `src/types/models/payload-helpers.ts` — auto-generated from `omnify.yaml`'s `locale.locales` list. Adding a new locale in YAML + running `omnify generate` updates this helper, and every form using it gets the new locale automatically.

### 2. Use the translatable input components from `@godxjp/ui`

```tsx
import { Input, Textarea, TranslatableRichText } from "@godxjp/ui";

// Single-line plain text
<Input
  translatable
  value={name}
  onChange={setName}
  placeholder="e.g. Phở bò tái"
/>

// Multi-line plain text
<Textarea
  translatable
  value={summary}
  onChange={setSummary}
  rows={3}
/>

// Rich text (Tiptap-backed — bold / italic / headings / lists / quote)
<TranslatableRichText
  value={description}
  onChange={setDescription}
/>
```

Under the hood, `translatable` wraps the input in a `<TranslatableField>` which renders a strip of locale tabs (JA / EN / VI) above the editor. Clicking a tab swaps the active locale slot; the editor below shows and edits that slot. An orange dot on a tab means the locale has content; a red dot means it has a validation error.

**Overload gotcha:** `<Input />` and `<Textarea />` have overloaded prop signatures:

- Without `translatable` — `value: string, onChange: (e: ChangeEvent) => void` (plain HTML shape)
- With `translatable` — `value: TranslatableValue, onChange: (v: TranslatableValue) => void` (whole-map shape)

The compile-time overload is picked by the presence of the `translatable` prop. You'll get a type error if you pass the wrong state shape — don't try to `as string` your way out of it.

**Prerequisite:** the app must be wrapped in `<UIProvider locales={...} defaultLocale={...} fallbackLocale={...}>`. Already done by `AppProvider` in `src/providers/app-provider.tsx:244` — no per-route wiring needed.

```tsx
// ❌ Wrong — passing a string into the translatable overload
const [name, setName] = useState("");
<Input translatable value={name} onChange={setName} />  // type error

// ❌ Wrong — the locale tabs will render but the editor cannot sync
//    because React key wars the component every tab switch
<Input
  translatable
  key={currentLocale}
  value={name[currentLocale]}
  onChange={(v) => setName({ ...name, [currentLocale]: v })}
/>
```

### 3. Submit payload uses `buildI18nPayload` + top-level mirror fields + locale fallback fill

> **Partial-fill behavior:** when a user fills only 1–2 locales, empty locales must be filled
> with the first non-empty value in `ja → en → vi` priority **before** calling `buildI18nPayload`.
> See **[i18n-locale-fill.md](i18n-locale-fill.md)** for the canonical implementation using
> `fillLocalesFallback` from `@/lib/i18n-fill`.

### 3a. Submit payload uses `buildI18nPayload` + top-level mirror fields

On form submit, convert the per-locale state into the nested wire format the backend expects, AND set the top-level mirror fields. Both pieces are required.

```ts
// ✅ Correct — fallback-fill empty locales, then build payload with effectiveName as top-level
import { buildI18nPayload, DEFAULT_LOCALE } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";

// effectiveName = first non-empty in ja → en → vi priority
const effectiveName =
  name[DEFAULT_LOCALE]?.trim() ||
  name["en"]?.trim() ||
  name["vi"]?.trim() ||
  "";

const effectiveDescription =
  description[DEFAULT_LOCALE]?.trim() ||
  Object.values(description).find((v) => v?.trim()) ||
  "";

const i18n = buildI18nPayload({
  name: fillLocalesFallback(name),                                  // required field
  description: effectiveDescription ? fillLocalesFallback(description) : description, // optional
});

// Rule 3 — strip locales whose required field is still empty (guards against all-empty submit)
for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
  if (!i18n[locale]?.name?.trim()) delete i18n[locale];
}

const payload: CreateProductInput = {
  name: effectiveName,              // ← effectiveName, NOT name[DEFAULT_LOCALE] ?? ""
  description: effectiveDescription || null,
  product_type_id: productTypeId,
  ...i18n,
};
```

```ts
// ❌ Wrong — missing top-level mirror; backend rejects NOT NULL on parent table
const payload = {
  product_type_id: productTypeId,
  ...buildI18nPayload({ name, description }),
};

// ❌ Wrong — strict DEFAULT_LOCALE for top-level; user filling only VI gets "" for JA top-level
const payload = {
  name: name[DEFAULT_LOCALE] ?? "",   // sends "" if user only filled EN or VI
  ...i18n,
};

// ❌ Wrong — no fillLocalesFallback; empty locales stay empty in the i18n block
//    → user fills JA + VI but EN translation is stored as null
const i18n = buildI18nPayload({ name, description });
const payload = { name: effectiveName, ...i18n };

// ❌ Wrong — hand-rolling the nested shape instead of using buildI18nPayload
const payload = {
  name: name[DEFAULT_LOCALE] ?? "",
  product_type_id: productTypeId,
  ja: { name: name.ja, description: description.ja },
  en: { name: name.en, description: description.en },
  vi: { name: name.vi, description: description.vi },
};
```

`buildI18nPayload` handles the nested shape consistently across every form and will be the first thing to migrate when upstream omnify-jp/omnify-go#53 lands locale fields directly in the generated Zod schemas.

**Which key Rule 3 checks, per model** — it is the model's *required* translatable
field, not always `name`:

| Module | Required translatable field | Optional translatable fields |
|---|---|---|
| Category, ProductType, Product, Material, ProductOption | `name` | `description` |
| ToppingGroup, PaymentMethod, Allergen, PostCategory, PostTag | `name` | — |
| Recipe | `name` | `description`, `instructions` |
| ProductOptionValue | `label` | — |
| Post | `title` | `excerpt`, `content` |

### 4. Hydrate form state from `record.translations` on edit pages

Edit pages receive the full translation set in `record.translations`. Convert it back into per-field `TranslatableValue` state via a hydrate helper:

```ts
// ✅ Correct — hydrate from translations, fall back to mirror field for legacy rows
import { SUPPORTED_LOCALES, DEFAULT_LOCALE } from "@/types/models/payload-helpers";

function hydrateLocaleMap(
  translations: ProductTranslations | undefined,
  fallback: string | null,
  field: "name" | "description",
): TranslatableValue {
  const map = emptyLocaleMap();
  let touchedAny = false;
  for (const locale of SUPPORTED_LOCALES) {
    const value = translations?.[locale]?.[field];
    if (value !== undefined && value !== null) {
      map[locale] = value;
      touchedAny = true;
    }
  }
  // Legacy rows without translations fall back to the top-level mirror,
  // placed in the default-locale slot so the form never opens empty.
  if (!touchedAny && fallback) {
    map[DEFAULT_LOCALE] = fallback;
  }
  return map;
}

useEffect(() => {
  if (!product || hydrated) return;
  setName(hydrateLocaleMap(product.translations, product.name, "name"));
  setDescription(hydrateLocaleMap(product.translations, product.description, "description"));
  setHydrated(true);
}, [product, hydrated]);
```

```ts
// ❌ Wrong — skips the mirror-field fallback; legacy rows open empty
useEffect(() => {
  if (!product) return;
  setName(product.translations as TranslatableValue);
}, [product]);
```

**Currently `hydrateLocaleMap` is hand-rolled per edit page** (search: `grep -rn hydrateLocaleMap web/admin/src`). Once a second edit page needs it, **promote to `src/lib/i18n-hydrate.ts` and delete the duplicate** — don't let it copy-paste across five pages before someone notices.

### 5. Display user content with the scalar field directly

Read-only views do not need any translatable wrapper, hook, or helper. The resource field is already localized for the request's `Accept-Language` header.

```tsx
// ✅ Correct — scalar field resolved server-side
<span>{product.name}</span>
<h1>{product.name}</h1>
<Badge>{category.name}</Badge>
<td>{row.original.name}</td>

// ❌ Wrong — manually picking a locale defeats Accept-Language and duplicates
//    Astrotomic's fallback logic
const { locale } = useTranslation();
<span>{product.translations?.[locale]?.name ?? product.name}</span>

// ❌ Wrong — hard-coding locale breaks non-default users
<span>{product.translations?.ja?.name}</span>
```

**Why it works:**

1. `apiFetch` automatically stamps `Accept-Language: <current locale>` on every request (see `web/admin/AGENTS.md § API calls — only apiFetch, never raw fetch` for the enforcement rule). Source: `web/admin/src/lib/api.ts:getLocale()`.
2. Backend middleware `SetLocale` reads `Accept-Language` and calls `app()->setLocale($locale)`.
3. Backend models declare translatable attributes via Astrotomic Translatable — accessing `$product->name` routes through `getTranslation()` which returns the value for the resolved locale, falling back to the configured fallback (`en`), then to the mirror column, then to NULL.
4. The API resource serializes the translated attribute as a scalar string. The frontend consumer sees `product.name` as whichever locale was requested.

Zero client-side locale awareness is required on the display path, as long as:

- The request went through `apiFetch` (enforced by `web/admin/AGENTS.md § API calls — only apiFetch, never raw fetch`).
- The backend model has the translatable attribute declared.
- The API resource serializes it as a scalar field (not nested under `translations`).

### 6. The locale-switch cache gap

**Known behaviour, plan for it:** TanStack Query caches responses by `queryKey`. When the user switches locale (`setLocale("vi")`), `apiFetch` starts sending `Accept-Language: vi` on the NEXT request, but existing cached queries retain the same `queryKey` and keep serving the previously-fetched content until staleTime elapses or the user manually refetches.

Two defensible mitigations, pick one per route and apply consistently:

```ts
// ✅ Option A — include locale in every query key factory
export const productKeys = {
  all: (locale: LocaleCode, brandSlug: string) =>
    ["hq", "products", locale, brandSlug] as const,
  list: (locale: LocaleCode, brandSlug: string, filters: ProductFilters) =>
    ["hq", "products", locale, brandSlug, "list", filters] as const,
  detail: (locale: LocaleCode, brandSlug: string, id: string) =>
    ["hq", "products", locale, brandSlug, "detail", id] as const,
};
```

```ts
// ✅ Option B — invalidate every query on locale change
// In AppProvider.setLocale:
const queryClient = useQueryClient();
const setLocale = useCallback((newLocale: LocaleCode) => {
  setLocaleState(newLocale);
  localStorage.setItem(STORAGE_LOCALE, newLocale);
  writeLocaleCookie(newLocale);
  document.documentElement.lang = newLocale;
  persistPreference("/api/v1/locale", { locale: newLocale });
  queryClient.invalidateQueries(); // ← coarse but zero per-callsite churn
}, [queryClient]);
```

Option A is clean (automatic, per-key) but requires discipline — every new `queryKeys.*` factory must accept `locale`. Option B is coarse (one-shot invalidation) but needs no per-callsite changes.

```ts
// ❌ Current state — the gap
const setLocale = useCallback((newLocale: LocaleCode) => {
  setLocaleState(newLocale);
  writeLocaleCookie(newLocale);
  persistPreference("/api/v1/locale", { locale: newLocale });
  // No queryClient.invalidateQueries() call → cached JA data keeps rendering
}, []);
```

Until one of the two options is wired up, tell users "refresh the page after switching language to see content in the new locale" — a hard reload drops the React Query cache and every subsequent request fetches fresh with the new `Accept-Language`.

## Anti-patterns

Things to never do:

- **Hard-coding user content inside `src/i18n/*.json`** — these dicts are for UI chrome only. Product names, category labels, menu section titles belong in the database `*_translations` tables.
- **Client-side locale resolution on display** (`record.translations?.[locale]?.name`) — defeats `Accept-Language`, creates drift when the resolved locale differs from the cached response, duplicates Astrotomic's fallback logic.
- **Sending the full translations payload including empty locales** — `*_translations.name` is NOT NULL on the backend; insert will 500. Always strip locales whose required field is empty before spreading.
- **Skipping the top-level mirror field** — the parent table (e.g. `products.name`) is NOT NULL. `buildI18nPayload` output alone isn't enough; you MUST set `name: effectiveName` (first non-empty in `ja→en→vi`). Do NOT use `name[DEFAULT_LOCALE] ?? ""` — that sends `""` when the user only filled a non-default locale, causing the backend to store an empty JA name.
- **Not applying `fillLocalesFallback` before `buildI18nPayload`** — without it, locales the user left empty are stored as null/empty on the backend instead of falling back to the best available value. Use `fillLocalesFallback` from `@/lib/i18n-fill` on required fields before building the payload.
- **Using `<Input translatable />` for a field that is NOT `translatable: true` in the schema YAML** — plain `<Input />` is correct for slugs, SKUs, numeric prices, booleans. Translatable wrapper is strictly for fields with per-locale content.
- **Storing per-locale state as a single string + a locale selector next to it** — works for one locale at a time but loses every other locale the moment the user switches tabs. State MUST be a `Record<LocaleCode, string>` map.
- **Hand-rolling the nested payload shape instead of using `buildI18nPayload`** — breaks on the next omnify regen when the helper adds a field or a locale.
- **Bypassing `apiFetch`** to call an endpoint that returns translatable content — raw `fetch()` drops `Accept-Language`, backend defaults to `app.locale` (ja), response ships Japanese strings regardless of user preference. Enforced by the "API calls — only `apiFetch`, never raw `fetch`" rule in `web/admin/AGENTS.md`.

## Checklist

Before shipping a new translatable field:

- [ ] Schema YAML in `schemas/Backend/` has `translatable: true` on the field.
- [ ] `npm run omnify:gen` was run and the regen committed (backend migrations + `payload-helpers.ts` update).
- [ ] The `*_translations` migration created the locale-scoped column with the correct NOT NULL constraint.
- [ ] Form state uses `Record<LocaleCode, string>` initialised via `useState<TranslatableValue>(emptyLocaleMap)`.
- [ ] Input uses `<Input translatable />` / `<Textarea translatable />` / `<TranslatableRichText />` — not a plain variant.
- [ ] `effectiveName` computed as first non-empty in `ja → en → vi` priority — used for both `canSubmit` and `name:` top-level field in payload.
- [ ] `name:` top-level in payload uses `effectiveName` (NOT `name[DEFAULT_LOCALE] ?? ""`).
- [ ] Required translatable fields pass `fillLocalesFallback(name)` into `buildI18nPayload` — empty locales are filled with `effectiveName` before the payload is built.
- [ ] Optional translatable fields (e.g. `description`) pass `fillLocalesFallback(description)` only when `effectiveDescription` is non-empty; otherwise pass the raw map so Rule 3 strips all-empty locales.
- [ ] Rule 3 (`if (!i18n[locale]?.name?.trim()) delete i18n[locale]`) applied after `buildI18nPayload` as the final guard.
- [ ] Edit page hydrates state via `hydrateLocaleMap(record.translations, record.fieldName, "fieldName")`.
- [ ] Display sites use the resource field directly (`record.fieldName`) — no `record.translations[locale]` reads.
- [ ] All API calls route through `apiFetch` (inherited rule — `AGENTS.md § API calls — only apiFetch, never raw fetch`).
- [ ] Service-layer Create/Update input interface extends a `*TranslationsInput` shape (typed `ja? / en? / vi?` blocks).
- [ ] If this is the first use of i18n in a module, locale-switch cache strategy (Option A or B in [Rule 6](#6-the-locale-switch-cache-gap)) is documented and in place for this module's query keys.
- [ ] `npx tsc --noEmit` clean in `web/admin/`.
- [ ] `npm run lint` introduces 0 new warnings.

## Related

- [Documentation Standards](documentation.md)
- [web/admin/AGENTS.md § Multi-language (i18n)](../../web/admin/AGENTS.md#multi-language-i18n) — short-form developer guideline + mandatory-review checklist item
- [web/admin/AGENTS.md § API calls — only apiFetch, never raw fetch](../../web/admin/AGENTS.md#api-calls--only-apifetch-never-raw-fetch) — the sibling rule this doc depends on for Accept-Language stamping
- [web/admin frontend-architecture skill § Hard rules](../../web/admin/.claude/skills/frontend-architecture/SKILL.md#hard-rules) — luật "Toast on every mutation" (mục 4) sống ở đây, KHÔNG còn ở `web/admin/AGENTS.md`; forms using translatable inputs are CRUD mutations, the hook-toast rule applies
- `web/admin/src/types/models/payload-helpers.ts` — `emptyLocaleMap`, `buildI18nPayload`, `SUPPORTED_LOCALES`, `DEFAULT_LOCALE` (auto-generated from `omnify.yaml`)
- `web/admin/src/app/hq/[brandSlug]/products/new/page.tsx` — canonical create-form reference
- `web/admin/src/app/hq/[brandSlug]/products/[id]/page.tsx` — canonical edit-form reference (+ current `hydrateLocaleMap` home)
- `web/admin/src/app/hq/[brandSlug]/products/components/basic-info-card.tsx` — shared translatable `BasicInfoCard` component
