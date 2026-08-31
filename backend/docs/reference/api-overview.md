---
title: API Overview Reference
category: reference
tags: [api, response-format, error-codes, pagination, query-params, conventions, soft-delete, audit, brand-slug, shop-slug, middleware]
summary: Covers API conventions, response format, error codes, query parameters, slug-based URL routing, and common endpoint patterns used across all TempoFast API resources.
related: [api-product, api-inventory, api-production, api-development]
---

# API Overview Reference

This document covers the API conventions, response format, error codes, query parameters, and common endpoint patterns used across all TempoFast API resources.

## Base URL

```text
http://localhost:5400/api/v1
```

## Authentication

All endpoints (except SSO callback and public menu endpoints) require a Sanctum Bearer token:

```text
Authorization: Bearer {token}
```

See [SSO Authentication](../guide/sso-authentication.md) for the login flow.

## Organization Scoping

All resources are scoped to the authenticated user's organization. The backend resolves `organization_id` from the user's session. Clients never send it explicitly.

## Slug-Based URL Convention

Every domain resource is mounted under exactly one of two URL prefixes. The prefix determines which scope the resource belongs to — there is no third "org-scoped" path.

### HQ (Brand-Scoped) Routes — `/api/v1/hq/{brandSlug}/...`

Catalog and master-data resources that live at the **brand** level. One brand can serve many shops, so these resources are defined once per brand and consumed by every shop underneath.

```text
/api/v1/hq/{brandSlug}/products
/api/v1/hq/{brandSlug}/product-types
/api/v1/hq/{brandSlug}/categories
/api/v1/hq/{brandSlug}/product-options
/api/v1/hq/{brandSlug}/product-option-values
/api/v1/hq/{brandSlug}/skus
/api/v1/hq/{brandSlug}/sku-units
/api/v1/hq/{brandSlug}/materials
/api/v1/hq/{brandSlug}/recipes
/api/v1/hq/{brandSlug}/menus
/api/v1/hq/{brandSlug}/master-menus
```

The `ResolveBrandFromSlug` middleware looks up `{brandSlug}` against the `brands` table, asserts the brand belongs to the caller's organization, and stores the resolved Brand on the request as `brand` / `brand_id` attributes. Controllers read those attributes — they do not re-query the brand from the slug.

See [Product Domain API Reference](api-product.md).

### Shop-Scoped Routes — `/api/v1/shops/{shopSlug}/...`

Operational resources that belong to a specific **shop** (a Branch row): physical locations, on-floor state, stock movements.

```text
/api/v1/shops/{shopSlug}/zones
/api/v1/shops/{shopSlug}/tables
/api/v1/shops/{shopSlug}/warehouses
/api/v1/shops/{shopSlug}/stock-levels
/api/v1/shops/{shopSlug}/stock-transactions
/api/v1/shops/{shopSlug}/stock-transfers
/api/v1/shops/{shopSlug}/stock-counts
/api/v1/shops/{shopSlug}/stock-alerts
/api/v1/shops/{shopSlug}/disposals
```

The `ResolveShopFromSlug` middleware resolves `{shopSlug}` to a Branch row, asserts the shop belongs to the caller's organization, and stores the resolved Shop on the request as `shop` / `shop_id` / `brand_id` attributes (the third one because every shop belongs to exactly one brand).

See [Shop Domain API Reference](api-shop.md) and [Inventory Domain API Reference](api-inventory.md).

### Service Layer Is Scope-Agnostic

Both prefixes are wired in `routes/api.php`. The middleware does the slug → model resolution **once**; controllers then read the resolved IDs from the request and pass them as plain filters to the service layer. The same `ProductService`, `MenuService`, etc. are reused under both prefixes — a brand-scoped controller and a shop-scoped controller can call the same service method, just with a different `brand_id` or `branch_id` filter. See [Controller Rules](../contributing/controller.md#scoping-from-resolved-attributes) for the pattern.

---

## Response Format

### Single Resource

```json
{
  "data": {
    "id": "019d4e6a-...",
    "name": "Example",
    "created_at": "2026-04-03T08:00:00.000000Z"
  }
}
```

### Collection (Paginated)

```json
{
  "data": [
    { "id": "019d4e6a-...", "name": "Example" }
  ],
  "links": {
    "first": "...?page=1",
    "last": "...?page=5",
    "prev": null,
    "next": "...?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 25,
    "to": 25,
    "total": 120
  }
}
```

---

## Error Responses

### 422 Validation Error

```json
{
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."],
    "sku": ["The sku has already been taken."]
  }
}
```

### 401 Unauthenticated

```json
{ "error": "UNAUTHENTICATED", "message": "Not authenticated" }
```

### 403 Forbidden

```json
{ "error": "FORBIDDEN", "message": "You do not have permission to perform this action." }
```

### 404 Not Found

```json
{ "error": "NOT_FOUND", "message": "Resource not found." }
```

### 409 Conflict (Business Rule Violation)

```json
{ "error": "INVALID_STATUS_TRANSITION", "message": "Cannot approve a cancelled transaction." }
```

---

## Common Query Parameters

| Parameter      | Type    | Description                                          |
| -------------- | ------- | ---------------------------------------------------- |
| `page`         | integer | Page number (default: 1)                             |
| `per_page`     | integer | Items per page (default: 25, max: 100)               |
| `sort`         | string  | Sort field (prefix `-` for descending, e.g. `-created_at`) |
| `search`       | string  | Full-text search across name/SKU fields              |
| `status`       | string  | Filter by status                                     |
| `is_active`    | boolean | Filter by active state                               |
| `with_trashed` | boolean | Include soft-deleted records (default: false)        |
| `include`      | string  | Comma-separated relations to eager-load              |

---

## Common Endpoint Patterns

All resource controllers follow these conventions.

### CRUD

| Method | Endpoint            | Description      |
| ------ | ------------------- | ---------------- |
| GET    | `/{resources}`      | List (paginated) |
| POST   | `/{resources}`      | Create           |
| GET    | `/{resources}/{id}` | Get detail       |
| PUT    | `/{resources}/{id}` | Update           |
| DELETE | `/{resources}/{id}` | Soft delete      |

### Soft Delete and Restore

| Method | Endpoint                    | Description          |
| ------ | --------------------------- | -------------------- |
| DELETE | `/{resources}/{id}`         | Soft delete          |
| POST   | `/{resources}/{id}/restore` | Restore soft-deleted |
| POST   | `/{resources}/bulk-delete`  | Bulk soft delete     |

### Dropdowns

Most resources provide a lightweight lookup endpoint for form selects:

```text
GET /{resources}/lookup
GET /{resources}/dropdown    # deprecated alias of /lookup
```

Returns a flat array without pagination. Supports `?include_ids[]=xxx` to force-include specific IDs (even inactive ones) for edit forms. Use `/lookup` in new code; `/dropdown` is kept only for backward compatibility.

### Status Workflow Endpoints

Resources with approval workflows:

```text
POST /{resource}/{id}/submit        # draft -> pending
POST /{resource}/{id}/approve       # pending -> approved
POST /{resource}/{id}/reject        # pending -> rejected (requires rejection_reason)
POST /{resource}/{id}/cancel        # draft|pending -> cancelled
POST /{resource}/{id}/activate      # approved -> active
POST /{resource}/{id}/deactivate    # active -> inactive
```

### Import / Export (CSV)

Resources supporting bulk CSV operations:

```text
GET  /{resources}/import/template    # Download blank CSV template with headers
POST /{resources}/import             # Upload CSV file (max 10MB)
GET  /{resources}/export             # Download current data as CSV
```

Import returns an error file with original data plus error messages for failed rows. Supports partial success.

---

## Soft Deletes

Most resources use soft deletes. `DELETE` sets `deleted_at` without removing the record. Deleted resources are excluded from list queries by default. Use `?with_trashed=true` to include them.

## UUID Primary Keys

All resources use UUID v7 (ordered) as primary keys.

## Audit Trail

All major entities track activity changes via the `AuditsActivity` trait. Audit logs record: action (created/updated/deleted), user_id, model type/id, changed fields with before/after values.

---

## Naming Conventions

| Convention   | Example                            |
| ------------ | ---------------------------------- |
| Endpoints    | kebab-case plural: `/product-types` |
| JSON fields  | snake_case: `product_type_id`      |
| Query params | snake_case: `per_page`             |
| Enum values  | snake_case: `stock_in`             |

## API Versioning

All endpoints are prefixed with `/api/v1/`. Breaking changes increment the version number.
