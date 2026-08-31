---
title: System Features
category: explanation
tags: [audit, sku-generation, organization-scope, brand-sync, soft-delete, bulk-operations, import-export, circular-reference, production-calculator, localization, row-locking]
summary: Explains cross-cutting system features -- audit trails, SKU/code generation, organization scoping, Brand SSO sync, soft deletes, bulk operations, import/export, circular reference detection, production calculation, events, artisan commands, localization, and row locking.
related: [inventory-domain, product-domain, authorization]
---

# System Features

This document explains the cross-cutting features that apply across the entire application, including audit trails, code generation, organization scoping, soft deletes, bulk operations, import/export, circular reference detection, production calculation, events, artisan commands, localization, and row locking. Read this to understand the shared behaviors that underpin every domain.

## Overview

The system provides a set of reusable traits and services that enforce consistency across all domains. These features handle concerns such as data isolation, traceability, concurrency safety, and automated code generation. Each feature is implemented once and applied uniformly to the relevant models.

---

## Audit Trail

### What it is

The audit trail automatically records every change to important entities: who changed it, what changed, when it changed, and the before/after values.

### How it works

Models that use the `AuditsActivity` trait automatically log the following events:

| Event | What is recorded |
| ----- | ---------------- |
| Created | New data |
| Updated | Old value and new value (changed fields only) |
| Deleted | Deleted data |
| Restored | Restored data |
| Status change | Custom action (e.g., "approved", "rejected") |

Each audit record contains:

| Field | Description |
| ----- | ----------- |
| `action` | Type of action (created, updated, deleted, approved, etc.) |
| `user_id` | Who performed the action |
| `model_type` + `model_id` | Which entity was changed |
| `changes` | JSON containing before/after values |
| `ip_address` | IP address of the user |
| `user_agent` | Browser or device information |
| `created_at` | Timestamp of the action |

**Asynchronous processing:** Audit logs are written through a queue (the `WriteAuditLog` job) to avoid impacting request performance. The job retries up to 3 times with a 5-second backoff.

**Cleanup:** The artisan command `audit:cleanup` automatically deletes logs older than the configured retention period (default: 90 days).

**Exclusions:** Certain fields that do not need auditing (e.g., `image_url`) are configured as exclusions in the model.

### Audited entities

Product, ProductOption, ProductOptionValue, ProductSku, Category, Material, Recipe, Menu, MenuItem, Warehouse, StockTransaction, StockTransfer, StockCount, ProductionOrder, MaterialBatch

---

## SKU Generation

### What it is

The `GeneratesSku` trait automatically generates a unique SKU code when the user does not provide one. Each entity type has its own prefix.

### Entity SKU prefixes

| Entity | Prefix | Example |
| ------ | ------ | ------- |
| Product | PR | PR-00001 |
| Category | CT | CT-00001 |
| Material | MT | MT-00001 |
| Recipe | RC | RC-00001 |

### How it works

- The SKU is auto-generated only when the user does not supply one
- Uniqueness is enforced within the organization (`organization_id` + `sku`)
- Additional uniqueness conditions are supported (e.g., unique within a product)

### Warehouse document codes

| Entity | Format | Example |
| ------ | ------ | ------- |
| Stock In | SI-YYYYMMDD-XXX | SI-20260403-001 |
| Stock Out | SO-YYYYMMDD-XXX | SO-20260403-001 |
| Transfer | TR-YYYYMMDD-XXX | TR-20260403-001 |
| Stock Count | SC-YYYYMMDD-XXX | SC-20260403-001 |
| Material Batch | MB-YYYYMMDD-XXX | MB-20260403-001 |
| Production Order | PO-YYYYMMDD-XXX | PO-20260403-001 |

Sequential numbers increment per day and per type. Codes never duplicate.

---

## Organization Scope

### What it is

The `HasOrganizationScope` trait automatically scopes every query to the current user's `organization_id`. This guarantees complete data isolation between organizations (multi-tenant isolation).

### How it works

- Models using this trait automatically append `WHERE organization_id = ?` to every query
- The `organization_id` is injected from the user context (session or token)
- No `organization_id` parameter is required in the request

All list, show, update, and delete operations access only data belonging to the current user's organization.

---

## Soft Delete and Restore

### How it works

- `DELETE` requests do not permanently remove records -- they set a `deleted_at` timestamp
- Default queries exclude soft-deleted records
- Add `?with_trashed=true` to include soft-deleted records in results
- Use `POST /{resource}/{id}/restore` to restore a soft-deleted record

### Deletion rules by status

| Status | Can be deleted? |
| ------ | --------------- |
| `draft` | Yes |
| `pending` | Depends on resource |
| `active` | No |
| `completed` | No |
| `cancelled` | Yes |

### Reference protection

A resource cannot be deleted if it is referenced by another resource. Examples:

- A `ProductSku` referenced as a material component cannot be deleted
- A `ProductOptionValue` referenced by any active SKU cannot be deleted (`ON DELETE RESTRICT`)
- A Warehouse with stock levels cannot be deleted
- A ProductType with associated products cannot be deleted

---

## Bulk Operations

### What it is

The `HasBulkOperations` trait enables deleting multiple records at once via `POST /{resource}/bulk-delete`.

### How it works

**Request:**

```json
{ "ids": ["uuid-1", "uuid-2", "uuid-3"] }
```

**Processing:**

- Each record is checked for authorization individually
- Partial failure is supported: some records may succeed while others fail
- The operation runs inside a database transaction to ensure atomicity
- The response includes the success count and a list of errors

---

## Import and Export

### Import workflow

```text
1. GET /{resource}/import/template    -- Download a CSV template with correct headers
2. Fill in data in the template file
3. POST /{resource}/import            -- Upload the file (max 10 MB)
4. The system validates each row
5. Valid rows are created or updated
6. Invalid rows are returned in an error file with detailed error messages
```

**Partial success is supported:** Some rows may succeed while others fail. The system does not roll back the entire import.

### Export workflow

```text
GET /{resource}/export -- Download a CSV file containing current data
```

Export processes data in chunks of 500 rows to prevent memory overflow.

### Supported resources

| Resource | Import | Export | Notes |
| -------- | ------ | ------ | ----- |
| Products | Yes | Yes | Created with at least one default SKU |
| Product SKUs | Yes | Yes | Imported per-combination from option values |
| Product Types | Yes | Yes | |
| Categories | Yes | Yes | |
| Materials | Yes | Yes | |
| Recipes | Yes | Yes | |

---

## Circular Reference Detection

### What it is

Materials and recipes can reference each other through their `components` (JSON). The system prevents circular references from being created.

### Example of a blocked circular reference

```text
Material A --> contains Material B
Material B --> contains Material C
Material C --> contains Material A   <-- CIRCULAR REFERENCE
```

### How it works

The system uses Depth-First Search (DFS) on the dependency graph:

1. Build a dependency graph from the `components` JSON
2. Run DFS starting from the node being created or updated
3. If a previously visited node is encountered, a cycle is detected
4. Return a 422 error with a human-readable description of the cycle path

**Owner:** `App\Exceptions\CircularReferenceException`, thrown from
`Api/V1/HQ/MaterialController` (`:163`, `:230`).

> ⚠️ Tài liệu này từng ghi *"**Service:** `MaterialGraphService` caches the
> dependency graph per organization"*. **`MaterialGraphService` CHƯA BAO GIỜ
> tồn tại** — `git log --all -S'MaterialGraphService' -- app` không trả về
> commit nào (#2049). Hành vi 422 thì có thật; chỉ cái tên là bịa.

---

## Production Calculator

### What it is

The production calculator checks feasibility before creating a production order: whether the warehouse has enough materials to produce the desired quantity.

### Input

| Parameter | Description |
| --------- | ----------- |
| `warehouse_id` | The warehouse to check stock levels in |
| `output_sku_id` | The `ProductSku` to produce |
| `planned_quantity` | The desired production quantity |

### Output

| Field | Description |
| ----- | ----------- |
| `feasible` | Whether production is possible (true/false) |
| `max_producible` | Maximum quantity that can be produced |
| `components` | List of required components with availability details |
| `bottleneck` | The component that limits production output |

Each entry in `components` includes:

| Field | Description |
| ----- | ----------- |
| `required_quantity` | Amount needed for the planned quantity |
| `available_quantity` | Amount currently in stock |
| `sufficient` | Whether stock meets the requirement (true/false) |
| `shortage` | Shortfall amount: max(0, required - available) |

### Calculation logic

```text
For each component in the recipe:
  required = ingredient.quantity * recipe_multiplier * planned_quantity
  available = stock_level.quantity (at warehouse)
  max_for_this = floor(available / (ingredient.quantity * recipe_multiplier))

max_producible = min(max_for_this) across all components
bottleneck = component with the smallest max_for_this
```

---

## Brand SSO Sync

### What it is

Brands are cache entities synced from Platform via the SSO package. The local system does not create or edit Brands directly; it receives them during the SSO sync and stores them in the local database.

### How it works

> ⚠️ **ĐÃ GỠ ở `bbc3a538e`** (migrate Tempo sang Platform SSO, #2049).
> `OrganizationAccessService` sống trong gói `backend/packages/dxs-sso` và bị
> xoá cùng gói đó. Bốn bước dưới đây mô tả một pipeline **không còn chạy** —
> giữ lại làm hồ sơ, đừng đi tìm `syncBrands` / `cacheBrands`.

~~The `OrganizationAccessService` handles Brand synchronization through the `syncBrands` method.~~ This followed the same caching pattern used for Organizations and Branches:

1. During SSO login, Platform returns the user's organization data including associated brands
2. `syncBrands` iterates over the brand data and creates or updates local Brand records
3. Each Brand record stores: `platform_id`, `name`, `slug`, `organization_id`, and metadata
4. The `cacheBrands` pattern mirrors `cacheOrganization` and `cacheBranches` -- it upserts based on `platform_id` to avoid duplicates

**When sync occurs:**

- On every SSO login (the token response includes the user's organization, brands, and branches)
- Brands that exist on Platform but not locally are created
- Brands that already exist locally are updated with the latest data from Platform
- Brands are never deleted locally (soft retention)

### Relationship to other cached entities

```text
SSO Login
+-- cacheOrganization   --> Organization record
+-- cacheBrands         --> Brand records (1:N per Organization)
+-- cacheBranches       --> Branch records (1:N per Brand)
```

---

## System Events

### SetupOrganizationDefaults

When a new organization is cached from SSO (triggered by the `OrganizationCacheCreated` event):

- Default roles are created automatically: `org-admin`, `org-manager`
- The event is logged

---

## Artisan Commands

| Command | Description |
| ------- | ----------- |
| `user:assign-admin {email}` | Assign Org Admin role to a user |
| `audit:cleanup {--days=90}` | Delete audit logs older than N days |
| `user:permissions {email}` | Display a user's permissions (useful for debugging 403 errors) |

---

## Localization

The system supports three languages: Japanese (ja), English (en), and Vietnamese (vi).

- Display names for entities (ProductType, Category, etc.) are stored per locale
- Fallback: if a translation does not exist for the current locale, English is used
- Default locale: Japanese (ja)
- Configuration is defined in `omnify.yaml`

---

## Row Locking

Critical operations use `lockForUpdate()` (row-level locking) to prevent race conditions when multiple users act on the same data simultaneously:

- Stock transaction approval (checking and deducting stock must be atomic)
- Stock transfer approval
- Stock count adjustments (adjusting stock levels)
- Production (deducting materials and adding finished goods)

All of these operations are wrapped in `DB::transaction()` to guarantee atomicity.
