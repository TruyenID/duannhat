# Canonical domain mutation boundaries

This amendment makes Plan 047 a single migration opportunity rather than a payment-only refactor
that leaves adjacent direct model writers to be fixed later. Whenever Plan 047 moves a payment,
order, catalog, menu, or customer path, that path must also move behind its canonical mutation
boundary in the same task. By cutover, all known runtime writers for these aggregates are migrated.

The requirement is one public mutation gateway per aggregate, not one giant class. A facade may
delegate to typed command handlers, policies, calculators, repositories, and outbox publishers, but
callers outside the domain cannot bypass it to mutate Eloquent models or owned tables.

## Canonical ownership

> ⚠️ **Bảng này là TRẠNG THÁI ĐÍCH, không phải mô tả hệ thống hôm nay** (#2049).
> Cột "Read side" nêu tên bốn service **KHÔNG TỒN TẠI** — `OrderQueryService`,
> `MenuQueryService`, `CustomerQueryService`, `PaymentQueryService`. Chỉ
> `ProductQueryService` có thật. Đừng đi tìm ba cái kia trong `backend/app`;
> đường đọc hiện tại vẫn nằm trong chính service ghi.


| Aggregate | Public mutation gateway | Owned state | Read side |
|---|---|---|---|
| Payment | `PaymentOrchestrator` | Attempts, refunds, provider events, ledger writes through `OrderPaymentLedgerWriter` | `PaymentQueryService` / projections |
| Order | `OrderService` | Order header, items, toppings, conditions/snapshots, lifecycle and settlement markers | `OrderQueryService` / reporting projections |
| Product | `ProductService` | Product, SKU, option/value and product-owned catalog configuration | `ProductQueryService` / catalog projections |
| Menu | `MenuService` | Menu, sections, schedules, menu-product/SKU placement and shop menu overrides | `MenuQueryService` / effective-menu projections |
| Customer | `CustomerService` | Customer profile, credential mutation, branch/customer linkage and lifecycle | `CustomerQueryService` / CRM projections |

Names can retain temporary compatibility facades during migration, but there is exactly one
authoritative public mutation contract per aggregate at cutover. Omnify-generated CRUD services are
not domain boundaries and must not become an alternate writer.

## T2.7 published contracts

T2.7 publishes contracts only; it does not bind them in the container or switch a runtime caller.
Each `*MutationFacade` below is the public contract that exactly one canonical concrete service will
implement during the later migration tasks. It is not permission to create a second service beside
that canonical implementation.

| Domain | Public contract | Sole canonical implementation | Internal persistence port | Separate query port |
|---|---|---|---|---|
| Order | `App\Services\Order\Contracts\OrderMutationFacade` | `App\Services\Order\OrderService` | `OrderPersistencePort` | `OrderQueryPort` |
| Product | `App\Services\Product\Contracts\ProductMutationFacade` | existing `App\Services\Product\ProductService` | `ProductPersistencePort` | `ProductQueryPort` |
| Menu | `App\Services\Menu\Contracts\MenuMutationFacade` | existing `App\Services\Product\MenuService` | `MenuPersistencePort` | `MenuQueryPort` |
| Customer | `App\Services\Customer\Contracts\CustomerMutationFacade` | existing `App\Services\Customer\CustomerService` | `CustomerPersistencePort` | `CustomerQueryPort` |
| Payment | `App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade` | `App\Services\Payment\Orchestration\PaymentOrchestrator` | `PaymentPersistencePort` | `PaymentQueryPort` |

Menu remains a first-class domain even though its existing manual implementation currently lives in
the historical `App\Services\Product` namespace. That class will implement the Menu-owned facade at
cutover; Product persistence never owns Menu writes. Mutation commands are final readonly value
objects carrying validated tenant, actor, correlation, idempotency, aggregate identity, canonical non-secret payload fingerprint,
expected revision, and typed executable payload data where applicable. Fingerprints are integrity evidence,
not payload substitutes, and commands reject any fingerprint that differs from canonical payload JSON.
Secret credential bytes use a keyed-integrity process-local carrier that cannot be serialized or debugged;
no reusable password digest is exposed on the command. Payment finalization, reconciliation, refund
reconciliation, and provider-event commands carry normalized provider-neutral evidence instead of a hash-only
placeholder. Facades return operation-specific immutable results when semantics differ, including import row
errors, payment lifecycle evidence, order creation/settlement, and customer merge. Facades expose named business operations only; generic
`update`, `save`, `delete`, `transition`, or `setStatus` lifecycle APIs are forbidden. Persistence
ports are internal adapters, while query ports contain reads only and are never an alternate writer.

### Contract inventory parity

The contract payloads cover the current writable inventory rather than a reduced create/update shape:

| Domain | Typed contract evidence carried at the boundary |
|---|---|
| Product | Localized name/description, slug, product type, tax category/type, gallery, categories, topping assignments, and complete SKU/option/value pricing, recipe, inventory, position and activation fields |
| Menu | Validity window, priority, cart timeout, service type, transition grace, master linkage, localized content, dated/active/master-linked schedules, product/SKU/tax/price placement and branch/shop overrides |
| Order | External/client identity, order/pickup/status enums, schedule/contact/customer/guest/table-set/locale/channel/device/coupon data, immutable totals and line-level menu/price/tax/promotion evidence, kitchen timestamps and split mode |
| Payment | Method, tip, tendered/change amounts, reference, till, split/debt linkage and a canonical verified refund intent; provider results remain normalized gateway evidence |
| Customer | Profile and locale plus address, tax code, note, verification evidence and organization/brand/branch linkage |

Lists in these payloads contain named value objects or validated identifiers. Arbitrary metadata arrays are
not accepted as a substitute for an inventory field; later migration tasks may add a new named value object
when source-of-truth schema inventory grows.

## Dependency direction

```text
Transport / importer / sync / job / command
        |
        +--> ProductService  --> ProductPersistence
        +--> MenuService     --> MenuPersistence
        +--> CustomerService --> CustomerPersistence
        +--> OrderService    --> OrderPersistence
        +--> PaymentOrchestrator --> OrderPaymentLedgerWriter
                                      |
                                      +--> OrderService.settleIfPaid(command)

Read dependencies:
OrderService --> ProductQueryService + MenuQueryService + CustomerQueryService
PaymentOrchestrator --> OrderQueryService + PaymentPolicyResolver
```

Payment never changes an Order model or cached `paid_amount` directly. After an idempotent ledger
finalization it invokes the Order public command. `OrderService::settleIfPaid()` locks the order,
reads the canonical ledger projection, applies the legal transition, and owns idempotent settlement
side effects. Likewise, Order reads catalog/customer data through query contracts and stores
immutable snapshots; it does not mutate Product, Menu, or Customer.

Service-to-service calls must follow this direction or use an outbox/domain event. Cyclic service
dependencies are forbidden. A transaction coordinator may compose public commands, but it cannot
reach across domains to mutate another domain's model.

## Mutation definition

The architecture guard treats all of the following as mutations:

- Eloquent `create`, `forceCreate`, `update`, `save`, `delete`, `forceDelete`, `restore`,
  `updateOrCreate`, `firstOrCreate`, `increment`, `decrement`, `touch`, and mass update/delete;
- relationship `create`, `save`, `attach`, `detach`, `sync`, `syncWithoutDetaching`, and pivot update;
- query-builder `insert`, `upsert`, `update`, `delete`, and raw write statements against owned tables;
- model events/listeners, observers, jobs, imports, console commands, webhook handlers, and sync
  consumers that cause any of those operations; and
- indirect mutation through an Omnify generic CRUD service.

Read-only Eloquent queries are initially allowed outside the mutation facade. They should move to
query services when touched, but they do not block the mutation cutover unless they bypass tenant,
authorization, snapshot, or consistency rules.

## Current writer inventory

This is the starting allowlist/debt ledger, not permission to retain the paths indefinitely.

### Product

- Canonical candidate: `backend/app/Services/Product/ProductService.php`, with current manual SKU,
  option, option-value, type, and topping services folded behind its public contract.
- Duplicate generated paths: `backend/app/Services/Omnify/ProductService.php` and
  `backend/app/Omnify/Modules/Product/Services/ProductServiceBase.php`, plus generated services for
  SKU/options/types.
- Confirmed bypass: `backend/app/Services/Import/ProductImporter.php` directly creates/updates
  `Product`; importer behavior must delegate to a bulk/import command on `ProductService` while
  preserving atomic SKU/option/category handling and row-level error reporting.

### Menu

- Canonical candidate: `backend/app/Services/Product/MenuService.php`, composing manual section and
  schedule handlers behind its public contract.
- Duplicate generated paths exist under `backend/app/Services/Omnify/` and
  `backend/app/Omnify/Modules/Menu*/Services/`.
- Confirmed bypasses include HQ `MenuController` direct `MenuProduct` updates, shop
  `ShopMenuItemSettingsController` direct `Menu` updates, and
  `MigrateMasterApprovedToActive` direct status changes.
- Menu placement, schedule, activation and shop override commands must publish/rebuild the same
  effective-menu/cache revision exactly once.

### Customer

- Canonical candidate: `backend/app/Services/Customer/CustomerService.php`.
- `CustomerAuthService` independently creates and updates Customer credentials/profile today; after
  migration it authenticates and validates but delegates every customer mutation to typed
  `CustomerService` commands.
- Generated `Services/Omnify/CustomerService.php` and its base are alternate generic writers and
  must have no runtime consumer before removal.
- Workstation/shop/HQ customer mutation and replica paths must use the same tenant/branch and
  identity rules; replay keeps its original idempotency identity.

### Order

- Existing business behavior is spread across `CustomerOrderService`, `CustomerQrOrderService`,
  `OrderClosingService`, payment services, transport controllers, Workstation handlers and jobs.
- Confirmed direct Order writes exist in customer order actions, Kiosk, Workstation payment and
  lifecycle controllers. Direct OrderItem writes exist in Workstation lifecycle paths.
- The canonical `OrderService` facade must expose typed commands for create, item mutation,
  checkout, lifecycle transition, cancel/void, table association, settlement and approved refund
  coordination. Generic `update(array)` is not a public lifecycle API.
- The existing `CustomerOrderService` can be moved behind the facade incrementally, then split into
  handlers. Its behavior cannot remain a second public writer after cutover.

### Payment

- The current `OrderPaymentService` and `StripePaymentService` are separate writers. Plan 047's
  existing inventory and tasks migrate them to `PaymentOrchestrator` and the sole
  `OrderPaymentLedgerWriter`.
- `OrderPaymentLedgerWriter` owns payment persistence only. It cannot mutate the order; settlement
  crosses the `OrderService` contract.

## Enforcement model

The architecture test starts in report mode with every existing violation recorded as:

```yaml
- aggregate: order
  path: app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php
  symbols: [save, update]
  owner: plan-047
  removal_task: T4.11
  expires_at_gate: 4
  reason: legacy transport writer awaiting OrderService command migration
```

Each task that touches an allowlisted path must remove that entry in the same commit. New entries
are prohibited. Gate 4 cannot close with an Order/Product/Menu/Customer/Payment runtime exception.
The final strict guard scans PHP tokens/AST plus table-aware query-builder calls and fails CI on:

1. owned model mutations outside its persistence boundary;
2. owned table writes through `DB::table`, raw SQL, or a generic generated service;
3. controller/provider adapter/importer/job/sync handler mutation bypasses;
4. forbidden cross-domain model imports in mutation code; and
5. an allowlist entry without owner, removal task, reason, and gate expiry.

Permitted permanent exceptions are migrations, test factories/fixtures, seeders explicitly intended
for bootstrap data, and reviewed repair/backfill commands. Repair commands must be dry-run capable,
restartable, audited, tenant-scoped and idempotent; they use a dedicated maintenance persistence
port rather than becoming a hidden runtime writer.

## Migration order

1. Freeze observable behavior and build the complete writer/side-effect/dependency manifest.
2. Introduce typed mutation facades and persistence ports without switching callers.
3. Put the architecture guard in report mode; reject every new violation immediately.
4. Migrate Product importer/CRUD writers and prove nested SKU/option parity.
5. Migrate Menu CRUD/placement/schedule/override writers and prove effective-menu/cache parity.
6. Migrate Customer auth/profile/admin/sync writers and prove credential, tenant and replay parity.
7. Migrate Order create/item/lifecycle/table/refund/settlement writers transport by transport.
8. Route Payment finalization into `OrderService::settleIfPaid()`; remove Payment-to-Order writes.
9. Turn the guard strict for each aggregate as its allowlist reaches zero.
10. Remove unused generated CRUD services and compatibility facades only after consumer scans and
    behavior/concurrency tests pass.

This order lets Plan 047 consolidate a path once. A transport is never migrated first to the new
payment engine and later again to the Order boundary.

## Transaction and concurrency rules

- A public mutation command owns its transaction; callers do not wrap provider/network calls or
  multiple opaque service commands in one long transaction.
- Commands lock the aggregate root before validating state/amount transitions and use stable
  idempotency identity for retries, imports and sync replay.
- Cross-domain side effects use idempotent outbox markers. A failure after commit resumes from the
  marker; it does not repeat the aggregate mutation.
- Bulk Product/Menu import chunks are atomic at the documented unit and return deterministic row
  errors. Partial success cannot leave orphan SKU, option, section, schedule or placement rows.
- Customer credential changes never expose password/token material in command DTO logs or events.
- Catalog/menu/customer changes never rewrite historical Order/OrderItem/Payment snapshots.

## Completion criteria

- Runtime scans find zero direct mutation of the five owned aggregate families outside approved
  persistence boundaries.
- Every controller, webhook, importer, job, command and sync consumer delegates to the canonical
  public mutation contract.
- Payment settlement reaches Order only through `OrderService`; Payment code has no Order Eloquent
  mutation dependency.
- Omnify 5.9.3+ dry-run proves generated services are not recreated; obsolete generated/manual
  compatibility services have zero consumers before deletion.
- Contract, behavior, authorization, idempotency, concurrency, snapshot and side-effect parity tests
  pass for Cloud and Workstation flows.
- The writer allowlist is empty for runtime code and CI runs in strict mode.
