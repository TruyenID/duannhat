---
title: Locale Fallback Fill — Partial Multilingual Input
category: contributing
tags: [i18n, forms, translations, locale, fallback, payload, hydration]
summary: Defines the canonical behavior for the full translatable-form lifecycle. On submit, empty locales are filled with the first non-empty value in ja→en→vi priority before the payload is sent. On edit, form state is hydrated from record.translations per-locale — never from the resolved scalar.
related: [translatable-forms.md]
---

# Locale Fallback Fill — Partial Multilingual Input

## Problem

A translatable form has three locale tabs: **JA / EN / VI**. Users often fill only one or two. Two failure modes exist — one on submit, one on hydration.

### Submit failure — partial fill leaves empty locales null in DB

| User fills | Backend stores | Viewing in EN |
|---|---|---|
| only VI = "Cà chua" | JA = "Cà chua" (via `effectiveName` top-level), EN = ∅, VI = "Cà chua" | ✅ "Cà chua" |
| only VI = "Cà chua" | JA = ∅, EN = ∅, VI = "Cà chua" (via strict DEFAULT_LOCALE) | ❌ null / empty |
| JA = "白ソース", VI = "Sốt trắng" | JA = "白ソース", EN = ∅, VI = "Sốt trắng" | ❌ null / empty |

### Hydration failure — edit form reads scalar instead of per-locale translations

| Stored translations | Buggy hydration shows | Correct hydration shows |
|---|---|---|
| VI = "Sốt trắng" only (legacy) | JA = "Sốt trắng" (scalar fallback), EN = "" | JA = "", EN = "", VI = "Sốt trắng" |
| JA = "白ソース", VI = "Sốt trắng" | JA = "白ソース", EN = "" | JA = "白ソース", EN = "", VI = "Sốt trắng" |
| VI saved with fillLocalesFallback | JA = "Sốt trắng" ✅ | JA = "Sốt trắng", EN = "Sốt trắng", VI = "Sốt trắng" ✅ |

**The correct behavior:**
- **Submit:** empty locales are filled with the first non-empty value in `ja → en → vi` priority before the payload is sent.
- **Hydration:** per-locale form state is read from `record.translations[locale]`, not from the resolved scalar `record.name`.

---

## Submit — `fillLocalesFallback` before payload

### Correct workflow

#### Happy path — user fills all locales

Each locale stores its own value. No fallback needed.

```
User: JA = "白ソース", EN = "White sauce", VI = "Sốt trắng"
Stored: JA = "白ソース", EN = "White sauce", VI = "Sốt trắng"
```

#### Partial fill — user fills 1 or 2 locales

Empty locales are resolved to the **first non-empty value in `ja → en → vi` order** before sending.

```
User: JA = "白ソース", EN = ∅, VI = "Sốt trắng"
→ effectiveName = "白ソース"  (JA takes priority)
→ resolved: JA = "白ソース", EN = "白ソース", VI = "Sốt trắng"
Stored: JA = "白ソース", EN = "白ソース", VI = "Sốt trắng"
Viewing in EN: "白ソース" ✅

User: only VI = "Sốt trắng"
→ effectiveName = "Sốt trắng"  (VI is the only non-empty)
→ resolved: JA = "Sốt trắng", EN = "Sốt trắng", VI = "Sốt trắng"
Stored: all locales = "Sốt trắng"
Viewing in JA: "Sốt trắng" ✅
```

### 1. Use `fillLocalesFallback` from `@/lib/i18n-fill`

```ts
import { fillLocalesFallback } from "@/lib/i18n-fill";
```

Source: `web/admin/src/lib/i18n-fill.ts` — wraps every locale with the first non-empty fallback.

### 2. Apply before `buildI18nPayload` for **required** translatable fields

```ts
// ✅ Correct — resolve fallback BEFORE building i18n payload
const resolvedName = fillLocalesFallback(form.name);
const i18n = buildI18nPayload({ name: resolvedName });

// Rule 3 — strip locales where required field (name) is still empty:
for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
  if (!i18n[locale]?.name?.trim()) delete i18n[locale];
}

const payload = {
  name: effectiveName,       // top-level mirror = effectiveName = first non-empty
  ...i18n,
};
```

### 3. `effectiveName` — used for BOTH `canSubmit` and the top-level field

```ts
// effectiveName drives: (1) canSubmit gate, (2) top-level `name:` in payload
const effectiveName =
  form.name[DEFAULT_LOCALE]?.trim() ||
  form.name["en"]?.trim() ||
  form.name["vi"]?.trim() ||
  "";

const canSubmit = effectiveName.length > 0 && !mutation.isPending;

const payload = {
  name: effectiveName,   // ← NOT name[DEFAULT_LOCALE] ?? ""
  ...i18n,
};
```

> **Do NOT use `name[DEFAULT_LOCALE] ?? ""` for the top-level field.** That sends an empty string
> when the user only filled a non-default locale, causing the backend to store `""` for JA and
> breaking display for Japanese users. Use `effectiveName` instead.

### 4. Optional translatable fields (description) — apply `fillLocalesFallback` only if non-empty

For optional fields, only apply the fill if the user filled at least one locale. Otherwise all locales remain empty and Rule 3 strips them all (correct — no description stored).

```ts
const effectiveDescription =
  form.description[DEFAULT_LOCALE]?.trim() ||
  Object.values(form.description).find((v) => v?.trim()) ||
  "";

// Only fill if description has any content; otherwise keep all empty → Rule 3 strips
const resolvedDescription = effectiveDescription
  ? fillLocalesFallback(form.description)
  : form.description;

const i18n = buildI18nPayload({
  name: fillLocalesFallback(form.name),      // required — always fill
  description: resolvedDescription,           // optional — fill only if non-empty
});
```

### Complete submit example

```ts
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { buildI18nPayload, DEFAULT_LOCALE } from "@/types/models/payload-helpers";

// Compute at component level so canSubmit and handleSubmit share the same value
const effectiveName =
  form.name[DEFAULT_LOCALE]?.trim() ||
  form.name["en"]?.trim() ||
  form.name["vi"]?.trim() ||
  "";
const effectiveDescription =
  form.description[DEFAULT_LOCALE]?.trim() ||
  Object.values(form.description).find((v) => v?.trim()) ||
  "";

const canSubmit = effectiveName.length > 0 && !mutation.isPending;

async function handleSubmit() {
  // 1. Build i18n payload with fallback fill for empty locales
  const i18n = buildI18nPayload({
    name: fillLocalesFallback(form.name),
    description: effectiveDescription
      ? fillLocalesFallback(form.description)
      : form.description,
  });

  // 2. Rule 3 — strip locales whose required field is still empty
  //    (canSubmit gate prevents this at UI level, but guards the service call too)
  for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
    if (!i18n[locale]?.name?.trim()) delete i18n[locale];
  }

  // 3. Assemble payload
  const payload = {
    name: effectiveName,                      // top-level mirror = effectiveName
    description: effectiveDescription || null,
    ...i18n,
  };

  await mutation.mutateAsync(payload);
}
```

### Submit decision table

| Scenario | `effectiveName` | `name:` top-level | EN locale stored |
|---|---|---|---|
| JA + EN + VI filled | JA value | JA value | EN value |
| JA + VI only | JA value | JA value | JA value (filled by fallback) |
| EN + VI only | EN value | EN value | EN value |
| VI only | VI value | VI value | VI value (filled by fallback) |
| Nothing filled | `""` | `""` | stripped (Rule 3) |

---

## Edit page hydration

When **opening an edit form**, the per-locale form state must be populated from `record.translations` — not from the resolved scalar `record.name`. The scalar is resolved server-side for the current `Accept-Language` via Astrotomic fallback and does not distinguish "JA was explicitly stored" from "JA was returned as a fallback from VI".

### Correct pattern

```ts
import { emptyLocaleMap, DEFAULT_LOCALE } from "@/types/models/payload-helpers";

// ✅ Correct — read each locale directly from translations; only fall back to scalar
//    when no translations object exists at all (legacy records)
const name = emptyLocaleMap();
if (record.translations) {
  for (const locale of ["ja", "en", "vi"] as const) {
    name[locale] = record.translations[locale]?.name ?? "";
  }
} else {
  name[DEFAULT_LOCALE] = record.name ?? "";
}
```

**Why `?? ""`:** ensures every locale slot is explicitly set. An empty-string translation is a valid value and must not be skipped.

**Why NOT the scalar:** `record.name` under `Accept-Language: ja` returns the VI value as an Astrotomic fallback when the actual JA translation is null. Putting that fallback into `name["ja"]` makes the JA field appear filled when it was never stored.

### Anti-patterns

```ts
// ❌ Unconditional DEFAULT_LOCALE seed before the loop
//    JA always receives the Astrotomic fallback scalar (e.g. VI value), even when
//    the stored ja.name is null. EN stays empty if its translation is null.
//    Result when only VI was stored: JA = "Sốt trắng", EN = ""  ← wrong
const name = emptyLocaleMap();
name[DEFAULT_LOCALE] = record.name ?? "";            // ← pollutes JA with fallback scalar
if (record.translations) {
  for (const locale of ["ja", "en", "vi"] as const) {
    if (record.translations[locale]?.name) {         // ← truthy check: skips null AND ""
      name[locale] = record.translations[locale]!.name;
    }
  }
}

// ❌ Only DEFAULT_LOCALE, no translations loop
//    All EN/VI values lost — user sees only JA (or its fallback) on every edit
const name = emptyLocaleMap();
name[DEFAULT_LOCALE] = record.name ?? "";

// ❌ Cast translations directly — structure mismatch, no emptyLocaleMap baseline
setName(record.translations as TranslatableValue);
```

### Hydration decision table

| Stored translations | `name["ja"]` | `name["en"]` | `name["vi"]` | Note |
|---|---|---|---|---|
| JA = "白ソース", EN = "White sauce", VI = "Sốt trắng" | "白ソース" | "White sauce" | "Sốt trắng" | Full fill |
| VI = "Sốt trắng" only (legacy, no fillLocalesFallback at save) | "" | "" | "Sốt trắng" | Reflects actual DB state |
| VI = "Sốt trắng" (saved with fillLocalesFallback) | "Sốt trắng" | "Sốt trắng" | "Sốt trắng" | All locales filled at save |
| No translations object (scalar-only legacy record) | record.name | "" | "" | Fallback branch |

> Records saved **before** `fillLocalesFallback` was applied will show empty JA/EN on edit — this is the correct representation of what is actually in the DB. The user can then fill in the missing locales and save.

---

## What NOT to do

```ts
// ❌ Strict DEFAULT_LOCALE for top-level — sends "" when user only filled VI
const payload = { name: form.name[DEFAULT_LOCALE] ?? "", ...i18n };

// ❌ effectiveName for top-level but NO fillLocalesFallback on i18n
//    → JA shows VI text (correct), but EN stays null (wrong)
const i18n = buildI18nPayload({ name: form.name });
const payload = { name: effectiveName, ...i18n };

// ❌ fillLocalesFallback on optional field when empty
//    → stores "" for all locales instead of storing nothing
const i18n = buildI18nPayload({
  description: fillLocalesFallback(form.description), // wrong if description is all-empty
});

// ❌ Unconditional DEFAULT_LOCALE before translations loop on hydration
//    → JA always gets the Astrotomic fallback scalar; EN is left empty
name[DEFAULT_LOCALE] = record.name ?? "";
for (const locale of ["ja", "en", "vi"] as const) {
  if (record.translations[locale]?.name) { // truthy — misses empty string
    name[locale] = record.translations[locale]!.name;
  }
}

// ❌ Only scalar on hydration — EN/VI never populated
name[DEFAULT_LOCALE] = record.name ?? "";
```

---

## Files

| File | Role |
|---|---|
| `web/admin/src/lib/i18n-fill.ts` | `fillLocalesFallback(map)` utility — the implementation |
| `web/admin/src/types/models/payload-helpers.ts` | `buildI18nPayload`, `SUPPORTED_LOCALES`, `DEFAULT_LOCALE`, `emptyLocaleMap` (auto-generated) |
| `docs/contributing/translatable-forms.md` | Broader translatable-form rules (state shape, hydration, display) |

---

## Checklist — apply to every translatable create/edit form

### Submit path (create + update)

- [ ] `effectiveName` computed at component level with chain `ja → en → vi → ""` (not `[DEFAULT_LOCALE]`)
- [ ] `canSubmit` uses `effectiveName.length > 0`
- [ ] `name:` top-level in payload uses `effectiveName` (not `form.name[DEFAULT_LOCALE] ?? ""`)
- [ ] `buildI18nPayload` receives `fillLocalesFallback(form.name)` for **required** fields
- [ ] Optional fields: `fillLocalesFallback` applied only when `effectiveFieldValue` is non-empty
- [ ] Rule 3 strip (`if (!i18n[locale]?.name?.trim()) delete i18n[locale]`) applied after `buildI18nPayload`

### Hydration path (edit only)

- [ ] Form state seeded from `record.translations` via `for (locale) { name[locale] = record.translations[locale]?.name ?? "" }`
- [ ] `DEFAULT_LOCALE` fallback to scalar used **only** inside the `else` branch when `record.translations` is absent entirely
- [ ] No unconditional `name[DEFAULT_LOCALE] = record.name` before the translations loop
- [ ] Loop uses `?? ""` assignment — NOT a truthy `if (record.translations[locale]?.name)` check
