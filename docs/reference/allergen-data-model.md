---
title: Allergen Data Model
category: reference
tags: [allergen, haccp, food-safety, material, recipe, jurisdiction]
summary: Field-by-field reference for the Allergen master-data entity, the material_allergens pivot, and the Recipe.allergen_rollup denormalized cache.
related: [approval-workflow, product-domain]
---

# Allergen Data Model

Reference doc for the allergen tracking introduced in plan-003. Read the architecture rationale in [approval-workflow](../explanation/approval-workflow.md) and the schema YAML at `schemas/Backend/Product/Allergen.yaml` for the source of truth.

## Allergen entity

Master-data lookup. Org-scoped (NOT brand-scoped) — regulatory sets are shared across brands within an organization.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID | Primary key |
| `organization_id` | UUID FK | CASCADE on org delete |
| `code` | string(60) | Stable snake_case identifier (`milk`, `egg`, `peanut`). Unique per `(code, jurisdiction)` |
| `name` | translatable string(120) | Display label. Stored in `allergen_translations` via Astrotomic. Locales: ja / en / vi |
| `jurisdiction` | enum | `jp` / `eu` / `us` — see `AllergenJurisdictionEnum` |
| `severity` | enum | `mandatory` / `recommended` — see `AllergenSeverityEnum` |
| `is_active` | boolean | Soft-retire toggle; inactive allergens stay in the DB but hide from material picker + new rollups |
| `created_by_id`, `updated_by_id` | UUID FK | Auto-populated from `Auth::id()` |
| `created_at`, `updated_at`, `deleted_at` | datetime | Soft delete enabled |

### Indexes

- `(organization_id)` — every list query scopes to org
- `(code, jurisdiction)` — unique
- `(jurisdiction)` — filter
- `(severity)` — filter

### Translation sidecar

`allergen_translations`: `{id, allergen_id, locale, name}`. Persisted via `AllergenServiceBase::flushTranslations()` after `model->fill(...)`. Astrotomic-native flat keys on input: `'name:ja' => '...', 'name:en' => '...', 'name:vi' => '...'`.

## material_allergens pivot

M2M between Material (owning) and Allergen (inverse). Sync on `Material::allergens()->sync($allergenIds)` from `MaterialService::update`.

| Column | Type |
|---|---|
| `material_id` | UUID FK CASCADE |
| `allergen_id` | UUID FK CASCADE |
| `created_at` | datetime |

Unique composite `(material_id, allergen_id)`.

## Recipe.allergen_rollup (denormalized cache)

| Column | Type | Notes |
|---|---|---|
| `allergen_rollup` | JSON | Sorted, deduped array of Allergen IDs aggregated from upstream Materials. Default `[]` |
| `allergen_rollup_updated_at` | datetime nullable | Last recompute timestamp |

### Computation rules

`AllergenRollupService::compute(Recipe $recipe)`:

1. Collect Material IDs:
   - `Recipe.material_id` (the FK Material)
   - Every `Recipe.ingredients[].material_id` (loose JSON ingredients can reference Materials too)
2. Query `Allergen` with `whereHas('materials', whereIn(materials.id, [...]))` — filters out soft-deleted Allergens
3. Return dedup'd, sorted array of `string` IDs

### Recompute triggers (synchronous, in DB transaction)

| Trigger | Hook |
|---|---|
| Recipe created | `RecipeService::create` → `recomputeForRecipe` |
| Recipe structural-field PUT (ingredients, material_id, output_quantity, output_unit) | `RecipeService::update` → `recomputeForRecipe` |
| Material allergen sync (PUT with `allergen_ids`) | `MaterialService::update` → `recomputeForDownstreamRecipes` |

### Cross-effect: auto-repend

If recompute changes the rollup AND the Recipe is `approved`, the Recipe transitions to `pending` automatically (see [approval-workflow §Two-tier re-approval](../explanation/approval-workflow.md#two-tier-re-approval-recipe-only)).

### Why cache-on-write vs compute-on-read

Per FDA HACCP guidance, allergen stale-data is a food-safety bug, not a performance nit. Compute-on-read is correct but expensive when downstream APIs (menu, storefront) hit recipe lists at high frequency. We chose synchronous cache-on-write inside the same DB transaction as the triggering change so a failure rolls back atomically.

Async invalidation via queue was rejected — it introduces a stale-data window. See DESIGN.md §Decision 3.

### SQLite vs MySQL

`AllergenRollupService::recomputeForDownstreamRecipes` uses `JSON_CONTAINS` to find Recipes whose `ingredients` JSON references a Material. SQLite (test environment) doesn't implement `JSON_CONTAINS` — the service falls back to scanning by `Recipe.material_id` only. Production runs MySQL where JSON_CONTAINS is available; tests skip the JSON-ingredient path safely.

## Regulatory seed (AllergenSeeder)

Run once per environment via `php artisan db:seed --class=AllergenSeeder`. Idempotent — matches on `(organization_id, code, jurisdiction)`.

| Jurisdiction | Count | Source |
|---|---|---|
| JP | 8 mandatory + 20 recommended | Japan CAA Food Labeling Standards (tokutei-genryou + junkan-tokutei-genryou) |
| EU | 14 mandatory | FIC Regulation 1169/2011 Annex II |
| US | 9 mandatory | FALCPA + FASTER Act 2023 (sesame added 2023) |

The same substance code (e.g. `milk`) appears once per jurisdiction with its jurisdiction-specific severity. Translations supplied for all three locales (ja / en / vi).

The seeder routes through `AllergenService::create` / `::update` — never `Allergen::create()`. Astrotomic's `$fillable` strips locale-keyed arrays before the trait sees them, so raw Eloquent silently loses the translations.

## API surface

See [api-product](../../backend/docs/reference/api-product.md) §Allergens. CRUD + restore at `/api/v1/hq/{brandSlug}/allergens`. The 409 `ALLERGEN_IN_USE` response on delete carries `used_by: [{id, name}]` so the UI can surface the blocking materials.
