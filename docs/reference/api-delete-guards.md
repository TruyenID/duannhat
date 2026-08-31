---
title: Delete-guard 409 codes
category: reference
tags: [api, delete, 409, conflict, guard, plan-042, hq, shop]
summary: "Every 409 code the backend returns when a DELETE is blocked by a referential constraint — the plan-042 open-order guards plus the catalog/master-data IN_USE family: condition, response shape, and what the client should do."
related:
  - api-topping-groups
  - api-customers
---

# Delete-guard 409 codes

When a client tries to `DELETE` a resource that something live still references,
the backend refuses with **HTTP 409 Conflict** and a machine code instead of
cascading or orphaning data. This page lists every such code that exists in the
code today (measured 2026-08-08 against `backend/app`), what triggers it, and
what the client should do next.

Two things to know before parsing responses:

1. **The JSON key carrying the machine code is not uniform.** The plan-042
   guards and newer code use `code`; the catalog IN_USE exceptions use `error`;
   `TenderTypeController` uses `error_code`. Each table below states the key
   per code. A tolerant client reads `code ?? error ?? error_code`.
2. **One 409 has no machine code at all** — deleting an order with
   served items (`backend/app/Services/Order/Internal/Concerns/WritesCustomerOrders.php:737`)
   returns a plain `abort(409, message)`. Match on status only.

## Open-order guards (plan-042)

An entity referenced by an **open** order cannot be deleted. "Open" is the
published Ordering vocabulary
(`backend/app/Services/Order/Contracts/OrderStatusVocabulary.php:22`):
`pending · awaiting_confirmation · confirmed · open · dining · checkout ·
paying`. Terminal statuses (`closed`, `voided`) never block. All rows below use
the JSON key **`code`** and are soft-delete guards — the DB FK cannot catch
them, so they live in the service layer.

| Code | Route | Blocks when | Client should |
|---|---|---|---|
| `TABLE_DELETE_BLOCKED_OPEN_ORDER` | `DELETE /api/v1/shops/{shopSlug}/tables/{table}` | Table is occupied (`current_order_id` set) or any open order carries its `table_id` — `backend/app/Services/Shop/TableService.php:120-128` | Close or move the order, then retry |
| `ZONE_DELETE_BLOCKED_OPEN_ORDER` | `DELETE /api/v1/shops/{shopSlug}/zones/{zone}` | Any table in the zone has an open order (the zone delete cascades to its tables) — `backend/app/Services/Shop/ZoneService.php:104-114` | Close or move those orders, then retry |
| `PRODUCT_SKU_DELETE_BLOCKED_OPEN_ORDER` | `DELETE /api/v1/hq/{brandSlug}/skus/{sku}` | SKU is referenced by an open order as a line item **or a topping** — `backend/app/Services/Product/ProductSkuService.php:279-284` | Wait for / close the open orders; use `GET /skus/{sku}/check-usage` first (`backend/routes/api/hq/catalog.php:128`) |
| `PRODUCT_DELETE_BLOCKED_OPEN_ORDER` | `DELETE /api/v1/hq/{brandSlug}/products/{product}` | Any of the product's SKUs is in an open order (checked before the cascade to SKUs/options) — `backend/app/Services/Product/Internal/EloquentProductPersistence.php:748-756` | Same as SKU: close the referencing orders first |
| `CUSTOMER_DELETE_BLOCKED_OPEN_ORDER` | `DELETE /api/v1/shops/{shopSlug}/customers/{customer}` | Customer has at least one open order — `backend/app/Services/Customer/CustomerService.php:350-356` | Close their orders first |
| `DENOMINATION_DELETE_BLOCKED_OPEN_SHIFT` | `DELETE /api/v1/shops/{shopSlug}/denominations/{denomination}` | Any till in the organization has an **open cashier shift** — a mid-shift denomination change would corrupt the per-shift cash reconciliation (same rationale as the plan-031 currency guard) — `backend/app/Services/Omnify/DenominationService.php:29-41` | Close all open shifts, then retry |

Route sources: `backend/routes/api/shops/shop.php:66,85`,
`backend/routes/api/hq/catalog.php:87,125`,
`backend/routes/api/shops/customers.php:32`,
`backend/routes/api/shops/denominations.php:24`.

plan-042 has a workstation half too: when Cloud deletes one of these entities
anyway (reseed, DR restore) and a workstation later pushes a row referencing
it, the push is dead-lettered instead of retried forever — see
[Workstation sync recovery](../guide/workstation-sync-recovery.md).

## HQ layout template guards (#890)

Shops may deactivate — but never delete — tables/zones copied from the brand's
HQ default layout. JSON key **`code`**.

| Code | Route | Blocks when | Client should |
|---|---|---|---|
| `TABLE_DELETE_BLOCKED_HQ_TEMPLATE` | `DELETE /api/v1/shops/{shopSlug}/tables/{table}` | `table_template_id` is set (HQ-origin table, BR-T09) — `backend/app/Services/Shop/TableService.php:106-112` | Offer "deactivate" instead of delete |
| `ZONE_DELETE_BLOCKED_HQ_TEMPLATE` | `DELETE /api/v1/shops/{shopSlug}/zones/{zone}` | `zone_template_id` is set, or the zone still holds HQ-origin tables (BR-Z04) — `backend/app/Services/Shop/ZoneService.php:82-96` | Offer "deactivate" instead of delete |

## Catalog / master-data IN_USE guards

These block deleting a definition that other rows still reference. Most render
via a dedicated exception class in `backend/app/Exceptions/` and use the JSON
key **`error`** unless noted; several carry a payload naming the blockers so
the UI can show exactly what to detach first.

| Code | Key | Route | Blocks when | Extra payload | Client should |
|---|---|---|---|---|---|
| `PRODUCT_TYPE_IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/product-types/{productType}` (apiResource — `backend/routes/api/hq/catalog.php:32`) | Products still attached — thrown at `backend/app/Services/Product/ProductTypeService.php:97`, rendered by `backend/app/Exceptions/ProductTypeInUseException.php:16` | — | Reassign or delete the products first |
| `OPTION_IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/product-options/{option}` | SKUs reference the option via `option_value{N}_id` — `backend/app/Services/Product/ProductOptionService.php:395`, `backend/app/Exceptions/OptionInUseException.php:32` | `blocking_skus[]` (`{id, sku}`) | Remove/regenerate the listed SKUs first |
| `OPTION_VALUE_IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/product-option-values/{value}` | Same, per value — `backend/app/Services/Product/ProductOptionValueService.php:112,134`, `backend/app/Exceptions/OptionValueInUseException.php:31` | `blocking_skus[]` | Same |
| `TOPPING_GROUP_IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/topping-groups/{group}` (also surfaced per-row in bulk delete — `backend/app/Http/Controllers/Api/V1/HQ/ToppingGroupController.php:74-80`) | Products still linked via `product_topping_groups` — `backend/app/Services/Topping/ProductToppingGroupService.php:286`, `backend/app/Exceptions/ToppingGroupInUseException.php:26` | `used_by[]` (`{id, name}` products) | Detach the listed products first |
| `ALLERGEN_IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/allergens/{allergen}` | Materials still reference it via `material_allergens` — `backend/app/Services/Omnify/AllergenService.php:33`, `backend/app/Exceptions/AllergenInUseException.php:26` | `used_by[]` (`{id, name}` materials) | Detach the listed materials first |
| `TAX_TYPE_IN_USE` | `code` | `DELETE /api/v1/hq/{brandSlug}/tax-types/{taxType}` (bulk delete reports it per-row — `backend/app/Http/Controllers/Api/V1/HQ/TaxTypeController.php:22`) | Referenced by a product, a menu-line override, a branch default, or held as the brand default — `backend/app/Services/Tax/TaxTypeService.php:245,380`, `backend/app/Exceptions/TaxTypeInUseException.php:31` | `meta` usage counts (`products`, `menu_products`, `branch_defaults`, +`brand_default`) | Deactivate instead of delete (plan-043 Decision 5), or re-point every reference first |
| `UNIT_IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/materials/{material}/units/{unit}` | Lots or stock transactions were recorded in that unit (TC-UNIT-108) — `backend/app/Http/Controllers/Api/V1/HQ/MaterialUnitController.php:146-151` | — | Cannot be freed retroactively; keep the unit |
| `TENDER_IN_USE` | `error_code` | `DELETE /api/v1/hq/{brandSlug}/tender-types/{id}` | Any payment ever used the tender — deleting would orphan the ledger — `backend/app/Http/Controllers/Api/V1/HQ/TenderTypeController.php:179-186,222-229` | `payment_count` | Set `is_active = false` instead |
| `TENDER_CATEGORY_IN_USE` | `code` | `DELETE /api/v1/shops/{shopSlug}/tender-categories/{tenderCategory}` | Tender types still assigned to the category in the shop's scope — `backend/app/Http/Controllers/Api/V1/Shop/TillTenderCategoryController.php:108-121` | — | Move or delete the tender types first |
| `IN_USE` | `error` | `DELETE /api/v1/hq/{brandSlug}/notifications/templates/{id}` | A notification rule's `action.template_key` points at the template (TC-NOTIF-TPL05) — `backend/app/Http/Controllers/Api/V1/HQ/NotificationTemplateAdminController.php:179-183` | — | Repoint or delete the rule first |

Note `ComponentInUseException` is deliberately absent: it renders **422**, not
409 (`backend/app/Exceptions/ComponentInUseException.php:25-28`).

## Restore guards

The mirror image exists on restore: restoring a table template whose zone
template is still soft-deleted is refused with 409 (no machine code —
`backend/app/Http/Controllers/Api/V1/HQ/TableTemplateController.php:204-214`).
Restore the zone template first.

## Related but not delete-guards

Other 409 families share the "conflict" status but guard **updates**, not
deletes, and are documented elsewhere: the open-shift settings guards
(`CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT` et al., frozen list in
`backend/app/Http/Controllers/Api/V1/Shop/ShopOrderSettingsController.php:47`)
and the order unit-price drift guard
(`backend/app/Services/Order/Internal/UnitPriceDriftGuard.php`).
