# Current payment state and side-effect inventory

This inventory freezes the pre-refactor behavior for Plan 047. Line numbers refer to the submodule
and backend commits checked out by the plan worktree on 2026-07-22. Generated Omnify files are
listed as ownership boundaries only; they must not be edited manually.

## Persistence and status vocabulary

| Concern | Current source | Observed contract |
|---|---|---|
| Cloud ledger schema | `schemas/Backend/Product/OrderPayment.yaml`; generated migration `backend/database/migrations/omnify/2000_01_01_000100_create_order_payments_table.php` and later alter migration | `order_payments` is both payment lifecycle row and accounting projection; refund rows point at `refund_of_id` and use negative amounts |
| Cloud method schema | `schemas/Backend/Product/PaymentMethod.yaml`; generated migration `backend/database/migrations/omnify/2000_01_01_000059_create_payment_methods_table.php` | Provider, rail, tender behavior, activity, and branch policy are collapsed into one record |
| Cloud statuses | `backend/app/Omnify/Enums/PaymentStatusEnum.php:13-18` | `pending`, `succeeded`, `failed`, `refunded` |
| Cloud net projection | `backend/app/Models/OrderPayment.php:48-76` | Sums positive originals in `succeeded/refunded` plus negative succeeded refund rows; excludes debt-settlement rows |
| Workstation ledger | `workstation-app/internal/store/migrations/006_payments.sql`, `014_payment_metadata.sql`, `026_payments_full_parity.sql`, `040_payments_sync_target.sql`, `041_payments_captured_at.sql` | SQLite owns a local payment copy plus Cloud identity/sync metadata |
| Workstation statuses | `workstation-app/internal/domain/payment.go:8-29` | Uses local `pending`, `confirmed`, `failed`, and `refunded`; `confirmed` translates to Cloud `succeeded` |
| Workstation SQL writers | `workstation-app/internal/store/queries/payments.sql:1-51` | Create, status update, Cloud-ID update, sync timestamp, and method replace/upsert operations |

The vocabulary mismatch is intentional legacy behavior and must be characterized before the new
normalized state machine replaces it.

## Cloud ledger writers and transitions

| Writer/entry point | Exact implementation | Writes and transitions | Side effects/recovery behavior |
|---|---|---|---|
| Canonical POS/Kiosk/Workstation create | `backend/app/Services/Customer/OrderPaymentService.php:56-430` | Creates `pending` or auto-confirmed `succeeded`; updates order to `paying`; recalculates paid cache; can close fully paid order | Idempotency lookup, split/overpayment guards, till attribution, `OrderPaymentRecorded` for partial payment, `OrderClosingService` for full payment |
| Manual/terminal confirm | `OrderPaymentService.php:668-731` | Locked `pending -> succeeded`, sets `paid_at`, refreshes paid/tip cache | Rejects every non-pending source state; closes order if paid enough |
| Terminal fail | `OrderPaymentService.php:786-821` | Locked `pending -> failed`; merges failure metadata and refreshes cache | Racing confirm/fail is serialized by the payment-row lock |
| Staff refund | `OrderPaymentService.php:845-977` | Locked original `succeeded -> refunded`; appends negative `succeeded` refund row | Stripe refund is called while the DB transaction/lock is open; one refund per original row; stable provider idempotency key; card kill switch and cap |
| Stripe dashboard/webhook refund sync | `OrderPaymentService.php:998-1080` | Dedupe by Stripe refund ID; append negative row; mark original refunded when fully refunded | Caps cumulative refund at original amount and refreshes paid cache; no durable provider-event inbox |
| Paid/tip cache writer | `OrderPaymentService.php:1173-1204` | Reprojects `customer_orders.paid_amount` and `total_tip` from eligible ledger rows | Excludes rows whose metadata settles another payment debt |
| Customer full Stripe writer | `backend/app/Services/Customer/StripePaymentService.php:473-651` | Directly creates Stripe ledger row, increments cached paid amount, writes order/table/session state | Duplicates parts of closing service, sends takeaway mail, and performs stranded-charge refund after commit |
| Customer split Stripe writer | `StripePaymentService.php:683-852` | Directly creates ledger row, increments paid cache, may close order/table session | Uses order lock and overpayment guard but still duplicates settlement logic |
| Stripe ledger insertion | `StripePaymentService.php:863-967` | Finds/creates global Stripe method, creates `succeeded` `OrderPayment` keyed by intent reference | Has a second payment-code generator and duplicate-key retry path |
| Stale pending reaper | `backend/app/Console/Commands/ExpireStalePendingPayments.php:32-75`; scheduled at `backend/routes/console.php:46` | Locked expired `pending -> failed` every minute | No provider retrieval is performed before failure |
| Till gap attribution | `backend/app/Services/Pos/TillSessionService.php:436-458`; Workstation endpoint `backend/app/Http/Controllers/Api/V1/Workstation/PaymentController.php:283-324` | Mutates `order_payments.till_session_id` after payment creation | Only a valid same-branch session can replace attribution; invalid sync is a no-op to avoid dead-lettering money |

No other non-generated Cloud class directly creates an `OrderPayment`. A generic generated CRUD
writer still exists at `backend/app/Omnify/Modules/OrderPayment/Services/OrderPaymentServiceBase.php`
and is inherited by `backend/app/Services/Omnify/OrderPaymentService.php`; repository search finds no
runtime consumer. It is dead-capable surface, not an authorized payment lifecycle path, and must be
removed/blocked by the later architecture test rather than restored or extended.

## Provider calls and credentials

| Operation | Exact implementation | Current identity/transaction behavior |
|---|---|---|
| Stripe client construction | `backend/app/Services/Customer/StripePaymentService.php:30-42`; config `backend/config/services.php` | One process-global secret key; no organization/shop/connection/environment selection |
| Retrieve/reuse intent | `StripePaymentService.php:66-253` | Reads/stores the intent ID on the order and may cancel/recreate when stale or amount changes |
| Split intent creation | `StripePaymentService.php:279-359` | Creates provider operation with split metadata; caller holds an order DB transaction in `CustomerOrderController.php:164-175`, so this network call currently occurs inside a lock/transaction |
| Synchronous confirm/retrieve | `StripePaymentService.php:384-434` | Retrieves a succeeded intent and routes it into the direct Stripe writer |
| Webhook verification | `StripePaymentService.php:441-450` | Verifies raw payload using one global webhook secret |
| Staff refund provider call | `OrderPaymentService.php:871-912`; `StripePaymentService.php:1010-1028` | Refund call occurs inside the ledger transaction; stable key is derived from original payment ID |
| Stranded-charge refund | `StripePaymentService.php:1050-1082` | Best-effort post-commit call; failure is logged but has no durable attempt/refund recovery row |
| Currency audit retrieval | `backend/app/Console/Commands/AuditStripeCurrencyMismatch.php:33-147` | Read-only optional Stripe verification; not scheduled and does not repair or refund |

There are no PayPay/SBPS adapter calls and no server-side secret-store abstraction in the current
repository.

## Transport entry points

| Surface | Routes/controllers | Current behavior |
|---|---|---|
| Shop/POS | `backend/routes/api/shops/orders.php:42-46`, `backend/routes/api/pos.php:166-172`; `backend/app/Http/Controllers/Api/V1/Shop/OrderPaymentController.php:47-219` | List/create/confirm/refund through `OrderPaymentService`; POS create requires open till middleware |
| Kiosk | `backend/app/Http/Controllers/Api/V1/Kiosk/KioskController.php` payment methods | Creates/confirms/fails/status-checks through `OrderPaymentService`; accepts a constrained method vocabulary |
| Workstation Cloud bridge | `backend/routes/api/workstation.php`; `backend/app/Http/Controllers/Api/V1/Workstation/PaymentController.php:58-361` | Device-scoped create/status/confirm/fail/attribution; method lookup is active + organization + branch/global scoped |
| Workstation refund replay | `backend/routes/api/workstation.php:126-155`; `backend/app/Http/Controllers/Api/V1/Workstation/OrderLifecycleController.php:777-836` | Idempotent refund request delegates to `OrderPaymentService` after cumulative checks |
| Customer Stripe | `backend/routes/api/customer.php:102-140`; `backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php:67-270` | Public opaque-order intent/full/split/confirm/zero-due actions; bypasses canonical payment service for Stripe writes |
| Stripe webhook | `backend/app/Http/Controllers/Api/V1/Customer/StripeWebhookController.php:27-68` | Synchronously verifies and handles succeeded intents or charge refunds in the HTTP request; no durable inbox/dedupe record for the event itself |

## Workstation local writers and replay

| Concern | Exact implementation | Current behavior |
|---|---|---|
| LAN create/settle | `workstation-app/internal/handler/local_pos.go:660-933` | Resolves local method, validates tender/overpayment, inserts local payment, updates local order, enqueues `payment.create`; auto-confirm is local `confirmed` |
| Local method resolution | `local_pos.go:940-995` | ID/code lookup; legacy fallback can synthesize `cash` when the table is unavailable |
| Local collected projection | `local_pos.go:1003-1024` | Sums active local statuses and ignores expired pending rows |
| Sync dispatch | `workstation-app/internal/service/sync_service.go:186-210` | Maps `payment.create`, `confirm`, `fail`, `attribute`, and `refund` operations to handlers |
| Create replay | `sync_service.go:1167-1317` | Selects Kiosk/Workstation Cloud route, remaps local order/till IDs, sends stable identity, writes Cloud ID/status back |
| Confirm/fail/attribution replay | `sync_service.go:1320-1414` | Waits transiently for Cloud ID, posts lifecycle action, and updates local state/attribution |
| Refund replay | `sync_service.go:2793-2839` | Waits for order/payment Cloud IDs and posts the Workstation nested refund endpoint |
| Missing-create recovery | `sync_service.go:2261-2404` | Periodically re-enqueues unsynced payment rows and handles kiosk-to-workstation rehoming/dead-letter avoidance |
| Local refund | `workstation-app/internal/handler/local_pos_phase5.go:14-181`; item-refund logic in `workstation-app/internal/service/order_service_refund.go` | Creates a local refund record and queues Cloud replay; must preserve original/refund mapping |
| Till/report projection | `workstation-app/internal/handler/local_pos_till.go:794-1215` | Attributes gap payments, groups confirmed payments by method, separates drawer expectation, and builds reconciliation |

## Settlement side effects

`backend/app/Services/Customer/OrderClosingService.php:71-337` is the intended canonical fully-paid
boundary. A successful, first-time close can perform all of the following:

1. lock and re-read the order, confirm it is paid enough, set `closed`, `paid_at`, and normalize the
   cached paid amount (`:71-140`);
2. close the table session when all linked orders are terminal (`:142-162`);
3. release coupon usage and use nested transaction/savepoint handling for stock deduction
   (`:164-196`);
4. set the configured table status (`:198-207`);
5. send takeaway confirmation mail after commit (`:209-220`);
6. dispatch `OrderPaid` for realtime clients/audit (`:222-229`);
7. deduct product/material/lot stock and record sales genealogy (`:243-337`, `:435-790`).

`StripePaymentService.php:473-852` duplicates only a subset of these effects. That divergence is the
main cutover risk: a Stripe-paid order can update order/table/session and mail while missing or
ordering inventory, coupon, genealogy, and event behavior differently.

## Readers and downstream accounting assumptions

| Reader | Exact implementation | Assumption to preserve/test |
|---|---|---|
| Customer outstanding/debt summary | `backend/app/Services/Customer/CustomerService.php:59-100`; `backend/app/Http/Controllers/Api/V1/Shop/CustomerController.php` | Cached `paid_amount < total_amount` defines debt-bearing orders |
| Debt detail/settlement lookup | `backend/app/Http/Controllers/Api/V1/Shop/DebtController.php:63-103` | Reads `order_payments` and `metadata.settles_payment_id` directly |
| Order history/payment status | `backend/app/Services/Customer/CustomerOrderHistoryService.php`; `backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderSplitStatusController.php`; customer order resources | Uses cached paid amount and succeeded/refunded row filters |
| POS revenue | `backend/app/Services/Pos/PosRevenueService.php:328-410` | Aggregates ledger rows/method identity for revenue reporting |
| Till lifecycle/reconciliation | `backend/app/Services/Pos/TillSessionService.php:293-458,747-771,924-946,1690-1760` | Gap adoption, pending-close guard, gross/refund split, method grouping, and cash-tip attribution depend on current row/status shape |
| Shop dashboard/Z report/tax | `backend/app/Services/Shop/ShopTillTrackingService.php:129-239,438-490,737-867,947-1055` | Gross includes original rows even when status becomes refunded; refund rows are separate negatives; taxes derive order IDs from eligible payments |
| Shift expiry safety | `backend/app/Console/Commands/ExpireStaleShifts.php:70-86` | Recent payment existence prevents automatic shift expiry |
| API/UI resources | `backend/app/Http/Resources/OrderPaymentResource.php`, `CustomerOrderResource.php`, `KioskOrderResource.php`, `Customer/CustomerOrderDetailResource.php`, `Customer/CustomerOrderSummaryResource.php` | Clients consume legacy status/method/paid fields during compatibility window |

## Method configuration and clients

| Surface | Exact files | Current limitation |
|---|---|---|
| HQ admin | `admin-web/src/app/hq/[brandSlug]/settings/payment-methods/**`; `admin-web/src/services/payment-method-service.ts` | CRUDs flat `PaymentMethod`; no provider connection, owner, capability, or resolved policy |
| Shop admin/order | `admin-web/src/app/shop/[shopSlug]/orders/components/payment-method-dialog.tsx` | Consumes available flat methods |
| POS | `pos-web/src/app/pos/components/payment-dialog.tsx`; hooks/services under `pos-web/src/hooks/api/use-payment-methods.ts` and `src/services/order-payment-service.ts` | UI filters allowed codes and relies on `is_auto_confirm`/`requires_tendered` |
| Kiosk | `godx-kiosk/src/components/ui/payment-method-grid.tsx`; `src/hooks/use-payment.ts`; `app/payment/**` | Hard-coded cash/card/QR/e-money navigation and terminal flow |
| Customer web | `customer-web/components/stripe-card-section.tsx`; `lib/stripe-config.ts`; dine-in `payment-view.tsx` | Stripe-specific public config and intent/confirm calls |
| Workstation replica | `backend/app/Http/Controllers/Api/V1/Workstation/PaymentMethodReplicaController.php:19-37`; `workstation-app/internal/service/sync_pull.go`; local method SQL | Feed includes branch rows plus every global row without an organization predicate, so another tenant's global method can be replicated |

## Confirmed cutover hazards

1. Two Cloud writers can create ledger rows and settle orders.
2. Customer split intent creation performs a provider call while holding a DB transaction.
3. Staff Stripe refund performs a provider call while holding the original payment lock.
4. Global Stripe credentials/methods cannot isolate HQ/franchise merchants or environments.
5. `PaymentMethodReplicaController` global rows are not organization-scoped.
6. `OrderPaymentStoreRequest` allows a direct inactive method UUID; the service does not resolve an
   effective policy at the mutation boundary.
7. The nullable organization/branch/code uniqueness model can allow duplicate global method codes.
8. Webhook processing has no durable inbox, event-level dedupe, backoff, dead letter, or operator
   resolution state.
9. Workstation and Cloud status names differ, and the local cash fallback can bypass a missing
   method projection.
10. A partial refund flips the original row to `refunded`; every reader must include the original
    positive row and the appended negative row or its totals drift.
11. Stripe closing duplicates only part of `OrderClosingService`, so side effects can diverge.
12. Best-effort stranded-charge refunds can fail with log-only recovery.

## Adjacent aggregate mutation bypasses added by the scope amendment

Plan 047 now migrates adjacent writers when it touches their path instead of scheduling a second
refactor. The authoritative boundary inventory and enforcement rules live in
[DOMAIN-BOUNDARIES.md](DOMAIN-BOUNDARIES.md). Confirmed starting violations include:

| Aggregate | Canonical candidate | Confirmed alternate/direct writers |
|---|---|---|
| Product | `Services/Product/ProductService.php` | Omnify generic Product/SKU/Option services; `Services/Import/ProductImporter.php` direct create/update |
| Menu | `Services/Product/MenuService.php` | Omnify Menu/placement services; HQ `MenuController` direct `MenuProduct` update; shop settings controller and status-migration command direct Menu updates |
| Customer | `Services/Customer/CustomerService.php` | `CustomerAuthService` direct create/save/update; Omnify generic Customer service; Workstation/admin mutation surfaces |
| Order | New `OrderService` facade over current handlers | `CustomerOrderService`, `CustomerQrOrderService`, `OrderClosingService`, payment services, Customer/Kiosk/Workstation controller Order/OrderItem writes and lifecycle jobs |
| Payment | `PaymentOrchestrator` + `OrderPaymentLedgerWriter` | Existing `OrderPaymentService`, `StripePaymentService`, webhook and Workstation local/Cloud lifecycle paths |

Read-only model queries are not counted as mutation violations unless they bypass tenant or
consistency rules. The implementation inventory must additionally detect relationship writes,
query-builder/raw owned-table writes, observers/listeners, jobs, commands, importers and indirect
generated-service writes before the report-mode allowlist is accepted.

These hazards define the minimum characterization and parity suite; none may be removed merely by
changing schema names.
