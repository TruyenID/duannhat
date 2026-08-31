# Documentation Manifest

> This file is auto-loaded by Claude Code. Use it to find the right doc to read for any task.
> All docs are at `docs/` relative to project root. Read the relevant file(s) before implementing.

## Guide (how-to)

| File | Summary |
|------|---------|
| `guide/setup-docker.md` | **Con trỏ** — bản chuẩn ở `docs/guide/setup-docker.md` (umbrella): `docker compose up`, không cần PHP/MySQL trên host; cổng, volume, MinIO bucket, lệnh thường dùng |
| `guide/payment-go-live.md` | Runbook bật thanh toán prod: orchestrator + 5 transport đã mặc định TRUE (không còn thang rollout), STRIPE_*/PAYPAY_* đổ vào ~/apps/tempo/.env, tự kiểm bằng GET /customer/stripe/config, đường lùi PAYMENT_ORCHESTRATOR_RUNTIME=false, circuit breaker, soak workstation offline 24h; §8 nợ legacy có mốc: gate drain `order_payments.status='confirmed'` = 0, LegacyGlobalStripeConnection là đường Stripe DUY NHẤT của customer-web, sunset payment-methods 2027-01-01 khai ở DeprecatedApiHeaders:16 (umbrella docs/guide) |
| `explanation/payment-gateway-architecture-proof.md` | Plan-047 Gate 8 (#968 T8.4) provider-neutrality proof: the 0-provider-conditional measurement over orchestrator/commands/ledger-writer/OrderService, the 5 things a new adapter touches, the residual `isStripeCanonicalMethod` compat conditional (#1087), SBPS 2026-09-30 partial-refund sunset, and the ops items no repo measurement can cover (umbrella docs/explanation) |
| `guide/gateway-settlement.md` | Plan-050 shop ↔ gateway settlement sub-ledger: payment_settlements/gateway_payouts schema, Stripe balance-txn ingest (refund/dispute/payout), settlements:reconcile two-direction sweep, pending-payout aging, L1 estimate contract (never booked), S-24 immutability, ops runbook (umbrella docs/guide) |
| `guide/setup-local.md` | Setup without Docker via Laravel Herd (macOS native): PHP 8.4, MySQL 8, .env config, herd secure, mail/S3/queue fallbacks |
| `guide/sso-authentication.md` | Configure OAuth2 SSO with Platform IDP: env setup, service registration, console vs standalone modes |

## Explanation (domain knowledge)

| File | Summary |
|------|---------|
| `explanation/product-domain.md` | Brands (cache from Platform, 1:N with shops), products, variants (3-level options), units (conversion ratios), categories (hierarchy), materials (components, circular ref detection), recipes (cost calc), menus (master-branch sync). All product entities scoped by brand_id |
| `explanation/inventory-domain.md` | Brand→Shop→Warehouse hierarchy. Warehouses (types, auto-approve, threshold), stock levels (non-negative), transactions (SI/SO codes), transfers (in_transit, receive), counts (snapshot, reconciliation), disposals (waste report), production (batch/order → 2 auto transactions), movements (immutable ledger). Stock scoped to shop, not brand |
| `explanation/product-workflow.md` | Product approval: draft→pending→approved→active. Menu approval + master→branch sync. Recipe cost calculation. Business rules BR-P01..P10, BR-M01..M04, BR-R01..R03 |
| `explanation/stock-management.md` | Stock rules: non-negative (BR-S01), mutually exclusive item type (BR-S02), unit conversion (BR-S03). Transaction rules: auto-code, auto-approve, completion side effects, row locking. Transfer: 3-step + cancel reversal. Count: snapshot + adjustment. Disposal: threshold. Alerts: auto-trigger |
| `explanation/production-flow.md` | Material batch (semi-finished) and production order (finished) workflows: draft→pending→approved→in_progress→completed. Auto stock transactions on complete. Yield variance. Component calculation from recipe. BR-PD01..PD09 |
| `explanation/authorization.md` | 3-layer auth: authentication, roles (org-admin/manager/staff), 35+ permissions across 12 groups. Brand-level vs shop-level roles. Permission matrices per domain. Self-approval prohibition. Disposal threshold. Auto-approve conditions |
| `explanation/system-features.md` | Audit trail (async queue, cleanup). SKU generation (PR/CT/MT/RC prefixes, SI/SO/TR/SC/MB/PO codes). Org scope (multi-tenant isolation). Soft delete + restore. Bulk ops (partial failure). Import/export CSV. Circular reference DFS. Production calculator. Events. Artisan commands. Localization (ja/en/vi). Row locking |

## Reference (lookup)

| File | Summary |
|------|---------|
| `reference/architecture.md` | Project structure (Laravel root layout), tech stack (Laravel 13, Omnify), auth modes |
| `reference/api-overview.md` | Response format (data/meta/links), error codes (401/403/404/422/409), pagination, query params, slug-based URL convention (/brands/{slug}/, /shops/{slug}/), soft delete, audit trail, naming conventions |
| `reference/api-product.md` | All endpoints: products (CRUD + workflow + sync-variants + import/export), product-types, categories, variants (per-product + org-wide + check-usage), variant-units, materials (+ check-usage + circular ref), recipes, menus (branch + master + items + sync + current) |
| `reference/api-inventory.md` | All endpoints: warehouses (+ members + settings + toggle), stock-levels (+ movements), stock-transactions (+ submit/approve/cancel), stock-transfers (+ submit/approve/receive/cancel), stock-counts (+ add-items/start/update/submit/approve), stock-alerts (+ summary), disposals (+ waste-report), production-calculator |
| `reference/api-production.md` | All endpoints: material-batches (+ submit/approve/start/complete/cancel + preview-components), production-orders (same workflow + variants-with-recipe) |
| `reference/api-payment-methods.md` | **Deprecated** legacy payment-methods list + HQ CRUD; RFC 8594 Deprecation/Sunset/Link headers; sunset 2027-01-01; points to effective-payment-options |
| `reference/api-payment-gateways.md` | Plan-047 gateway admin (HQ connections/options/coverage), shop/device policy, effective options (POS/kiosk/workstation), runtime payment commands and error codes |

## Contributing (rules)

| File | Summary |
|------|---------|
| `contributing/documentation.md` | **Con trỏ** — bản chuẩn ở `docs/contributing/documentation.md` (umbrella): frontmatter bắt buộc, Diataxis, đặt tên file, cấu trúc, mẫu theo loại, checklist review |
| `contributing/api-development.md` | Service Layer pattern (Request→FormRequest→Controller→Service→Model→Resource), folder structure, naming conventions, response format, error handling, middleware stack, merge checklist |
| `contributing/controller.md` | Controller template: HasOrganizationContext + HasBulkOperations traits, CRUD/workflow/dropdown method signatures, 2-step authorization, return types, anti-patterns |
| `contributing/service.md` | Service template: constructor injection, list (filters + when() + paginate), create/update (DB::transaction), workflow (assertStatus + audit), dropdown, row locking (lockForUpdate), read-validate-write phải khoá mọi dòng đã kiểm, eager-load khớp `whenLoaded()`, cột `*_by_id` nullable, anti-patterns, review checklist |
| `contributing/policy.md` | Policy templates: standard (org-scoped) + warehouse-scoped (ChecksWarehouseContext trait). Permission matrices for product domain and inventory domain. Disposal threshold-aware approval |
| `contributing/route.md` | Route structure: api.php includes api/product.php + api/inventory.php. Naming: api.v1.{entity}.{action}. Full route examples. Rules: dropdown-before-resource, POST for workflow, max 1 nesting level |
| `contributing/testing.md` | Pest test templates: CRUD (index/store/show/update/destroy), workflow (submit/approve/reject), authorization (unauth/wrong-org/wrong-role). Factory usage. Assertion cheatsheet. Coverage requirements |
