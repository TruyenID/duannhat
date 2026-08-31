# Documentation

> TempoFast — inventory, production, and menu management system.

## Guide

Step-by-step instructions to accomplish specific tasks.

- [Setup with Docker](guide/setup-docker.md) — **con trỏ** → bản chuẩn ở [`docs/guide/setup-docker.md`](../../docs/guide/setup-docker.md) (compose file nằm ở gốc umbrella)
- [Setup without Docker (Herd)](guide/setup-local.md) — Run natively on macOS with Laravel Herd
- [Platform SSO Authentication](guide/sso-authentication.md) — Reference tích hợp Platform: biến `SSO_*`, luồng BFF, catalog `config/authz.php`. Muốn dựng SSO cho dev thì xem [`docs/guide/sso-authentication.md`](../../docs/guide/sso-authentication.md)

## Explanation

Domain knowledge, concepts, and business rules.

- [Product Domain](explanation/product-domain.md) — Brands, products, options, SKUs, units, categories, materials, recipes, menus
- [Inventory Domain](explanation/inventory-domain.md) — Brand-Shop-Warehouse hierarchy, stock levels, transactions, transfers, counts, disposals, production
- [Product Workflow](explanation/product-workflow.md) — Approval lifecycle, menu sync, recipe cost calculation
- [Stock Management](explanation/stock-management.md) — Stock rules, auto-approval, alerts, unit conversion
- [Production Flow](explanation/production-flow.md) — Material batches, production orders, auto stock transactions
- [Authorization and Access Control](explanation/authorization.md) — Brand-level vs shop-level roles, permissions, warehouse access control, approval matrix
- [System Features](explanation/system-features.md) — Audit trail, SKU generation, org scoping, Brand SSO sync, import/export, circular reference detection
- [Customer Domain](explanation/customer-domain.md) — Customer records, order lifecycle, line-item pricing, payment, and automatic stock deduction on completion
- Module boundaries — declared ownership, the Deptrac ratchet, and the runtime module kernel: [`docs/explanation/module-boundaries.md`](../../docs/explanation/module-boundaries.md) in the umbrella repo (it spans more than backend)

## Reference

Technical specifications for lookup.

- [Architecture](reference/architecture.md) — Project structure, Brand/Shop middleware, tech stack, authentication modes
- [Interfaces](reference/interfaces.md) — Inventory of UI surfaces (Web app, REST API, planned Mobile) and dev URLs
- [Test Users](reference/test-users.md) — How test users are provisioned via console SSO sync; role matrix
- [API Overview Reference](reference/api-overview.md) — Response format, error codes, pagination, brand- vs shop-scoped routing, conventions
- [API: Product](reference/api-product.md) — Brand-scoped: products, options, SKUs, categories, menus, materials, recipes (`/api/v1/hq/{brandSlug}/...`)
- [API: Shop](reference/api-shop.md) — Shop-scoped: zones, tables, runtime status, QR token rotation (`/api/v1/shops/{shopSlug}/...`)
- [API: Inventory](reference/api-inventory.md) — Shop-scoped: warehouses, stock, transfers, counts, alerts, disposals (`/api/v1/shops/{shopSlug}/...`)
- [API: Production](reference/api-production.md) — Material batch and production order endpoints

## Contributing

Rules and standards for all contributors (human and AI).

- [Documentation Standards](contributing/documentation.md) — **con trỏ** → bản chuẩn ở [`docs/contributing/documentation.md`](../../docs/contributing/documentation.md) (áp cho toàn monorepo)
- [API Development](contributing/api-development.md) — Service Layer architecture, naming, error handling
- [Controller Rules](contributing/controller.md) — Controller patterns, traits, authorization
- [Service Rules](contributing/service.md) — Service patterns, DB transactions, audit logging, read-validate-write locking, eager-load ↔ `whenLoaded()`, cột `*_by_id` nullable. **Bản chuẩn cho cả monorepo**
- [Policy Rules](contributing/policy.md) — Authorization policies, role matrix
- [Route Rules](contributing/route.md) — Route organization, naming, middleware
- [Testing Rules](contributing/testing.md) — Pest test patterns, coverage requirements
- [Swagger / OpenAPI Documentation Rules](contributing/swagger.md) — How to annotate controllers with OpenAPI 3 attributes for the auth/brand/shop docs
