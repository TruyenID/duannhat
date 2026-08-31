---
title: Translation Workflow (Full Stack)
category: explanation
tags: [i18n, translations, astrotomic, translatable, backend, frontend, workflow]
summary: End-to-end flow for multi-locale user content in TempoFast — from form input through API validation, database storage, and back to the screen. Covers user scenarios, backend architecture (Astrotomic, PreservesTranslatableColumns, Service, Resource), and a checklist for adding translation support to a new module.
related: [../contributing/translatable-forms.md]
---

# Translation Workflow (Full Stack)

> How user-entered multi-locale content flows from a form in `admin-web` through the API into the database and back to the screen. Use this as the authoritative reference when adding translation support to any new module.

For FE-only implementation rules (component API, state shape, `buildI18nPayload`, hydration, display) see **[contributing/translatable-forms.md](../contributing/translatable-forms.md)**. This document adds the backend architecture and concrete user scenarios that the other file omits.

---

## System overview

TempoFast supports three locales: **ja** (default), **en**, **vi**.

Every translatable model has two storage layers:

| Layer | Table | Purpose |
|---|---|---|
| Base column | `product_types.name` | Mirror/fallback — always holds a non-null value for the most-priority locale (ja → en → vi). Read when no translation row matches. |
| Translation rows | `product_type_translations` | One row per (record, locale) pair. Astrotomic reads these first; falls back to the base column when a row is absent. |

Modules using this pattern: **Category**, **ProductType**, **Product**, **ProductOption**, **ProductOptionValue**, **Material**, **MenuSection**, and any future schema with `translatable: true` fields.

---

## Database schema pattern

Every translatable model requires two tables following this shape:

```sql
-- Parent table
CREATE TABLE product_types (
  id          UUID PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,   -- base mirror column (NOT NULL)
  description TEXT,                    -- base mirror (nullable OK)
  ...
);

-- Translation table
CREATE TABLE product_type_translations (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  product_type_id UUID NOT NULL,
  locale          VARCHAR(10) NOT NULL,       -- 'ja' | 'en' | 'vi'
  name            VARCHAR(100) NOT NULL,      -- NOT NULL — required field
  description     TEXT,                      -- nullable — optional field
  UNIQUE (product_type_id, locale),
  FOREIGN KEY (product_type_id) REFERENCES product_types(id) ON DELETE CASCADE
);
```

**Critical constraint**: the `name` column in the translation table is `NOT NULL`. Sending a locale with an empty `name` causes Astrotomic to attempt an insert with `name = null`, which violates this constraint and returns a 500 error. Every layer — FE and BE — must guard against this.

---

## User scenarios

All scenarios use **ProductType** as the reference model. Category and Product behave identically.

### Scenario A — Japanese input only (most common)

```text
Input:  name.ja = 'ドリンク'    description.ja = '飲み物の種類'
        name.en = ''            description.en = ''
        name.vi = ''            description.vi = ''
```

**FE builds payload:**

```json
{
  "name": "ドリンク",
  "description": "飲み物の種類",
  "ja": { "name": "ドリンク", "description": "飲み物の種類" }
}
```

`en` and `vi` are removed by Rule 3 because `name` is empty.

**Resulting DB:**

```text
product_types:            name = 'ドリンク'
product_type_translations: ja | name='ドリンク' | description='飲み物の種類'
```

**Read back:** every locale (`ja` / `en` / `vi`) returns `'ドリンク'` because of the fallback chain: requested locale → `en` → base column.

---

### Scenario B — Enter all 3 languages

```text
Input:  name.ja = 'ドリンク'   name.en = 'Drinks'   name.vi = 'Đồ uống'
        description.ja = '飲み物'  description.en = 'Beverages'  description.vi = 'Nước uống'
```

**Payload:**

```json
{
  "name": "ドリンク",
  "description": "飲み物",
  "ja": { "name": "ドリンク",  "description": "飲み物" },
  "en": { "name": "Drinks",    "description": "Beverages" },
  "vi": { "name": "Đồ uống",   "description": "Nước uống" }
}
```

**Resulting DB:**

```text
product_types:            name = 'ドリンク'    (ja takes priority)
product_type_translations: ja | 'ドリンク'  | '飲み物'
                           en | 'Drinks'    | 'Beverages'
                           vi | 'Đồ uống'   | 'Nước uống'
```

**Read back:** `Accept-Language: en` → `'Drinks'`, `Accept-Language: vi` → `'Đồ uống'`.

---

### Scenario C — English input only (no Japanese)

```text
Input:  name.ja = ''    name.en = 'Drinks'    name.vi = ''
```

**Payload:**

```json
{
  "name": "Drinks",
  "en": { "name": "Drinks" }
}
```

The FE derives the top-level `name` from the priority `ja → en → vi` (ja is empty → take en).

**Resulting DB:**

```text
product_types:            name = 'Drinks'
product_type_translations: en | 'Drinks'
```

**Read back:** locales `ja` and `vi` have no row → fallback `en` → `'Drinks'`.

> **UX note:** `Accept-Language: ja` also returns `'Drinks'` (English) because there is no `ja` row. This is **expected behavior** per the fallback chain, but it can surprise Japanese clients — they see English content even though the UI is in `ja` mode. If this is undesirable, instruct users to always enter at least `ja.name` when the system serves Japanese customers.

---

### Scenario D — Enter ja + en, description in ja only

```text
Input:  name.ja = 'ドリンク'    name.en = 'Drinks'    name.vi = ''
        description.ja = '飲み物'    description.en = ''    description.vi = ''
```

**Payload (after Rule 3):**

```json
{
  "name": "ドリンク",
  "description": "飲み物",
  "ja": { "name": "ドリンク",  "description": "飲み物" },
  "en": { "name": "Drinks",    "description": "" }
}
```

`en` is kept because its `name` is non-empty. `en.description = ''` → Laravel `ConvertEmptyStringsToNull` → `null` → valid because description is nullable.

**Resulting DB:**

```text
product_type_translations: ja | 'ドリンク' | '飲み物'
                           en | 'Drinks'   | NULL
```

---

### Scenario E — Edit mode (updating one locale)

The user opens the edit form; the record currently has `ja` and `en`. The user edits only `en.name`.

**FE hydrates the form from `record.translations`:**

```typescript
name.ja = record.translations.ja?.name ?? ''   // 'ドリンク'
name.en = record.translations.en?.name ?? ''   // 'Drinks' (old)
name.vi = record.translations.vi?.name ?? ''   // ''
```

The user changes `en.name = 'Beverages'`.

**PUT payload:**

```json
{
  "name": "ドリンク",
  "ja": { "name": "ドリンク",   "description": "飲み物" },
  "en": { "name": "Beverages",  "description": "" }
}
```

**Astrotomic processing (updateOrCreate per locale):**
- `ja` → updates the existing row
- `en` → updates the existing row
- `vi` → not in the payload → the `vi` row is untouched

**DB after update:**

```text
product_type_translations: ja | 'ドリンク'  | '飲み物'   (unchanged)
                           en | 'Beverages' | NULL       (updated)
```

---

## Full data flow

```text
┌──────────────────────────────────────────────────────────────┐
│  FRONTEND (admin-web)                                        │
│                                                              │
│  Form State                                                  │
│  name: { ja: 'ドリンク', en: '', vi: '' }                    │
│  description: { ja: '飲み物', en: '', vi: '' }               │
│       │                                                      │
│       ▼ handleSubmit()                                       │
│  buildI18nPayload({ name, description })                     │
│       │  → { ja: {name,description}, en: {empty}, vi: {empty} }
│       ▼ Rule 3: strip locales with empty name                │
│       │  → { ja: {name: 'ドリンク', description: '飲み物'} }  │
│       ▼ assemble payload                                     │
│  {                                                           │
│    name: 'ドリンク',          ← top-level mirror              │
│    description: '飲み物',     ← top-level mirror              │
│    ja: { name: 'ドリンク', description: '飲み物' }            │
│  }                                                           │
│       │                                                      │
│       ▼ apiFetch (stamps Accept-Language header)             │
└──────────────────────────────────────────────────────────────┘
         │
         │  POST /api/v1/hq/{brand}/product-types
         │  Accept-Language: ja
         ▼
┌──────────────────────────────────────────────────────────────┐
│  BACKEND — Validation (ProductTypeStoreRequest)              │
│                                                              │
│  name          → sometimes|nullable|string|max:100           │
│  ja            → sometimes|nullable|array                    │
│  ja.name       → sometimes|nullable|string|max:100           │
│  ja.description→ sometimes|nullable|string                   │
│  en / vi       → absent → 'sometimes' → skipped             │
│                                                              │
│  withValidator: ≥1 locale must have name → pass             │
└──────────────────────────────────────────────────────────────┘
         │ validated()
         ▼
┌──────────────────────────────────────────────────────────────┐
│  BACKEND — Service (ProductTypeService::create)              │
│                                                              │
│  No derivation needed (name already top-level)               │
│  ProductType::create($data)                                  │
└──────────────────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────────┐
│  BACKEND — Model (PreservesTranslatableColumns::fill)        │
│                                                              │
│  foreach translatedAttributes as $attr:                      │
│    flat = $data[$attr]  → 'ドリンク'  → preserve            │
│  parent::fill($data)   ← Astrotomic intercepts locale keys   │
│  re-set $this->attributes['name'] = 'ドリンク'               │
└──────────────────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────────┐
│  DATABASE                                                    │
│                                                              │
│  INSERT product_types: name='ドリンク', description='飲み物'  │
│  INSERT product_type_translations:                           │
│    (product_type_id, 'ja', 'ドリンク', '飲み物')             │
└──────────────────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────────┐
│  BACKEND — Resource (ProductTypeResourceBase::schemaArray)   │
│                                                              │
│  'name'         → $this->name                                │
│                   Astrotomic resolves app()->getLocale()='ja' │
│                   → 'ドリンク'                               │
│  'translations' → getTranslationsArray()                     │
│                   → { ja: {name:'ドリンク', description:'飲み物'} }
└──────────────────────────────────────────────────────────────┘
         │
         │  JSON response
         ▼
┌──────────────────────────────────────────────────────────────┐
│  FRONTEND — Display / Hydration                              │
│                                                              │
│  List view:   <td>{record.name}</td>  → 'ドリンク'           │
│               (scalar, already locale-resolved)              │
│                                                              │
│  Edit dialog: hydrateLocaleMap(record.translations, ...)     │
│               name.ja = 'ドリンク'                           │
│               name.en = ''  (no row → empty string)          │
│               name.vi = ''                                   │
└──────────────────────────────────────────────────────────────┘
```

---

## Backend implementation

### 1. Migration

```php
// database/migrations/omnify/YYYY_MM_DD_create_{model}_translations_table.php
// AUTO-GENERATED — do not edit. Modify schema YAML + run omnify generate.

Schema::create('{model}_translations', function (Blueprint $table) {
    $table->id();
    $table->uuid('{model}_id');
    $table->string('locale');
    $table->string('name', 100)->comment('Name');       // NOT NULL
    $table->text('description')->nullable();            // nullable OK
    $table->foreign('{model}_id')->references('id')->on('{models}')->onDelete('cascade');
    $table->unique(['{model}_id', 'locale']);
    $table->index('locale');
});
```

Required fields (name, title, label, …) must be `NOT NULL`. Optional fields (description, summary, …) use `->nullable()`.

### 2. Model

```php
// app/Models/ProductType.php — SAFE TO EDIT
class ProductType extends ProductTypeBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;   // ← keeps base column in sync

    protected $useTranslationFallback = true;  // ← ja → en → vi → base column
}

// app/Omnify/Modules/ProductType/Models/ProductTypeBaseModel.php — DO NOT EDIT
class ProductTypeBaseModel extends BaseModel implements TranslatableContract
{
    use Translatable;

    public $translatedAttributes = ['name', 'description'];
}
```

`PreservesTranslatableColumns` intercepts `fill()` and does two things: (1) re-injects the top-level mirror value that Astrotomic strips from the parent attributes bag; (2) **derives** the base column from the `ja → en → vi` locale keys when no flat top-level string is present. This means the service does not need to manually compute the top-level mirror — the FE payload always provides it, and the trait acts as a safety net when it doesn't.

### 3. Request (Store + Update)

```php
// app/Http/Requests/ProductTypeStoreRequest.php
public function rules(): array
{
    $rules = $this->schemaRules();
    unset($rules['organization_id'], $rules['brand_id']);  // server-resolved

    // Top-level mirror: nullable — FE always provides a non-empty value via
    // locale priority (ja→en→vi), but the required check is on the locale keys.
    $rules['name'] = ['nullable', 'string', 'max:100'];

    // Locale keys: 'sometimes' prevents validated() from including missing
    // locales as null, which would cause Astrotomic to create empty rows.
    foreach (['ja', 'en', 'vi'] as $locale) {
        $rules[$locale]                  = ['sometimes', 'nullable', 'array'];
        $rules["{$locale}.name"]         = ['sometimes', 'nullable', 'string', 'max:100'];
        $rules["{$locale}.description"]  = ['sometimes', 'nullable', 'string'];
    }

    return $rules;
}

// Require at least one locale to have a non-empty name.
public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v) {
        $ja = trim((string) $this->input('ja.name', ''));
        $en = trim((string) $this->input('en.name', ''));
        $vi = trim((string) $this->input('vi.name', ''));
        if ($ja === '' && $en === '' && $vi === '') {
            $v->errors()->add('ja.name', 'The name field is required in at least one language (ja, en, or vi).');
        }
    });
}
```

For **Update** requests: same locale loop + `withValidator`, but only check when the client sends at least one locale key (use `$this->hasAny(['ja', 'en', 'vi'])` guard).

### 4. Service

```php
// app/Services/Product/ProductTypeService.php
public function create(array $data): ProductType
{
    // PreservesTranslatableColumns handles the base-column mirror via fill().
    // No need to manually derive — just pass $data straight to create().
    return ProductType::create($data)->load('translations');
}

public function update(ProductType $productType, array $data): ProductType
{
    $productType->update($data);  // Astrotomic upserts translation rows
    return $productType->load('translations');
}
```

If the service needs to auto-generate a slug or SKU from the name, derive it before the `create()` call using the same locale priority (`ja → en → vi`):

```php
if (empty($data['name'])) {
    foreach (['ja', 'en', 'vi'] as $locale) {
        $val = $data[$locale]['name'] ?? null;
        if (is_string($val) && trim($val) !== '') {
            $data['name'] = $val;
            break;
        }
    }
}
```

### 5. Resource

```php
// app/Http/Resources/ProductTypeResource.php
public function toArray(Request $request): array
{
    // schemaArray() already provides:
    //   'name'         → Astrotomic-resolved scalar for current locale
    //   'translations' → getTranslationsArray() → { ja: {name,desc}, en: {...}, vi: {...} }
    // Only add project-specific extra fields here.
    return array_merge($this->schemaArray($request), [
        'products_count' => $this->when(
            array_key_exists('products_count', $this->resource->getAttributes()),
            fn () => $this->products_count,
        ),
    ]);
}
```

Do NOT manually re-implement locale resolution (`getTranslation($locale, false)?->name`) — use `$this->name` which goes through Astrotomic's `$useTranslationFallback` chain automatically.

---

## Frontend implementation

See **[contributing/translatable-forms.md](../contributing/translatable-forms.md)** for the complete FE guide. Summary of the required steps:

| Step | What to do |
|---|---|
| Form state | `useState<TranslatableValue>(emptyLocaleMap)` per translatable field |
| Input component | `<Input translatable />` / `<Textarea translatable />` / `<TranslatableRichText />` |
| Submit | `buildI18nPayload({name, description})` → Rule 3 strip → spread into payload |
| Top-level mirror | `name: name[DEFAULT_LOCALE] ?? ''` alongside the i18n spread |
| Edit hydration | Define a local `hydrateLocaleMap(translations, fallback, field)` helper per form (see pattern at `products/[id]/page.tsx:60`) — not a shared import |
| Display | `record.name` directly (server-resolved via `Accept-Language`) |

### Rule 3 — strip empty locales (FE guard, mandatory)

```typescript
const i18n = buildI18nPayload({ name, description });

// Guard against NOT NULL constraint on *_translations.<required_field>.
// Replace `.name` with the model's required translatable field (see table below).
for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
  if (!i18n[locale]?.name?.trim()) delete i18n[locale];
}
```

This must appear in **every** form's submit handler that writes to a translatable model. Without it, submitting with some locales empty sends `{ en: { name: '' } }` → Laravel's `ConvertEmptyStringsToNull` converts `''` to `null` → NOT NULL constraint violation → 500.

**The key to check in Rule 3 depends on the model's required translatable field:**

| Module | Required translatable field | Check in Rule 3 |
|---|---|---|
| Category, ProductType, Product, Material, ProductOption | `name` | `i18n[locale]?.name?.trim()` |
| ToppingGroup, PaymentMethod, Allergen, PostCategory, PostTag | `name` | `i18n[locale]?.name?.trim()` |
| Recipe | `name` | `i18n[locale]?.name?.trim()` |
| ProductOptionValue | `label` | `i18n[locale]?.label?.trim()` |
| Post | `title` | `i18n[locale]?.title?.trim()` |

Optional translatable fields (`description`, `instructions`, `excerpt`, `content`) are nullable in the DB — sending `''` for them is safe because `ConvertEmptyStringsToNull` converts to `null`, which is valid.

---

## Read path — locale resolution order

When any client reads `record.name`, the value is resolved by Astrotomic in this order:

```text
1. Translation row for requested locale (Accept-Language header)
        ↓ not found
2. Translation row for fallback_locale ('en', from config/translatable.php)
        ↓ not found
3. Base column (product_types.name)    ← PreservesTranslatableColumns keeps this non-null
        ↓ null (should never happen)
4. null
```

`use_property_fallback = true` additionally fills an empty translation field from the fallback locale before checking the base column.

---

## Checklist — adding translation support to a new module

### Schema YAML

- [ ] Field has `translatable: true` in `schemas/Backend/<Group>/<Model>.yaml`
- [ ] Run `npm run omnify:gen` and commit the regen (migrations + helpers)

### Backend

- [ ] Translation migration: required field `NOT NULL`, optional field `->nullable()`
- [ ] Model: `use PreservesTranslatableColumns` + `$useTranslationFallback = true`
- [ ] StoreRequest: locale loop with `'sometimes'` + `withValidator` requiring ≥1 locale name
- [ ] UpdateRequest: same locale loop + `withValidator` with `hasAny(['ja','en','vi'])` guard
- [ ] Service: `load('translations')` on every return value
- [ ] Resource: use `schemaArray()` — do NOT hand-roll locale resolution

### Frontend

- [ ] Form state: `useState<TranslatableValue>(emptyLocaleMap)` per field
- [ ] Input: `<Input translatable />` / `<Textarea translatable />` / `<TranslatableRichText />`
- [ ] Submit: `buildI18nPayload` + Rule 3 strip + top-level mirror field
- [ ] Edit: hydrate from `record.translations` with fallback to `record.fieldName`
- [ ] Display: `record.fieldName` directly — no `record.translations[locale]` reads
- [ ] Service interface: `ja? / en? / vi?` locale blocks in `Create/UpdateInput` type
- [ ] All API calls via `apiFetch` (for `Accept-Language` stamping)
- [ ] `pnpm typecheck` clean

---

## Related

- [contributing/translatable-forms.md](../contributing/translatable-forms.md) — FE implementation rules, component API, checklist
- `backend/app/Traits/PreservesTranslatableColumns.php` — base-column mirror mechanism
- `backend/config/translatable.php` — Astrotomic locale config (`fallback_locale`, `use_property_fallback`)
- `web/admin/src/types/models/payload-helpers.ts` — `emptyLocaleMap`, `buildI18nPayload`, `SUPPORTED_LOCALES`, `DEFAULT_LOCALE`
- `web/admin/src/app/hq/[brandSlug]/product-types/components/product-type-form-dialog.tsx` — canonical reference implementation (FE)
- `backend/app/Http/Requests/ProductTypeStoreRequest.php` — canonical reference implementation (BE)
