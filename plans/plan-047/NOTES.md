# Plan 047 — Research notes

Research was performed on 2026-07-22 using project code and schemas, the Omnify restaurant-chain
domain pack, official provider documentation, PCI SSC guidance, and established admin/POS patterns.

## Project findings

### Two payment engines

- `backend/app/Services/Customer/OrderPaymentService.php` is the POS/Kiosk/Workstation lifecycle
  service. It locks orders/payments, enforces overpayment/idempotency, handles pending/succeeded/
  failed/refund transitions, projects `paid_amount`, attributes till sessions, and delegates closing.
- `backend/app/Services/Customer/StripePaymentService.php` is 1,100+ lines and separately creates
  PaymentIntents, writes `OrderPayment`, generates codes, updates `paid_amount`, checks overpayment,
  closes orders, and issues refunds.
- `backend/app/Services/Customer/OrderClosingService.php` owns canonical inventory, table/session,
  order-status, mail/event, and related fully-paid side effects.
- Customer Stripe controllers/webhooks call the Stripe service; shop/workstation controllers call
  `OrderPaymentService`. This is the architectural split the plan removes.

### Schema and generation

- `schemas/Backend/Product/OrderPayment.yaml` owns the shared ledger and already defines per-order
  idempotency and till indexes.
- `schemas/Backend/Product/PaymentMethod.yaml` owns display/configuration but still declares
  `service: {}` even though root `omnify.yaml` now has `service.enable: false`.
- `PaymentMethod.type` is acknowledged by `PaymentMethodResource` as migration-owned and absent from
  the schema.
- `schemas/Shared/Enum/PaymentStatus.yaml` is Cloud truth, while Workstation paths still commonly use
  `confirmed` alongside `succeeded`.
- The root workspace generates Laravel/TypeScript/Go artifacts; generated bases/migrations must not
  be edited. Payment domain schemas should live in a dedicated group, while orchestration services
  remain manual because auto service generation is disabled.

### Ownership gap

The local `Branch` schema has `is_headquarters` and `is_standalone`, but repository search found no
authoritative `franchise`, `corporate_owned`, or management-model field. `is_headquarters` identifies
the HQ branch; `is_standalone` is not a sufficient legal merchant-ownership contract. Plan 047
therefore treats the Console/SSO management model as a hard prerequisite, not a local guess.

### Client configuration gaps

- HQ payment-method UI currently lives under
  `admin-web/src/app/hq/[brandSlug]/settings/payment-methods/` and edits only code, translated name,
  auto-confirm, tendered requirement, and active state.
- Shop settings fetch payment methods but render them read-only in
  `admin-web/src/app/shop/[shopSlug]/settings/page.tsx`.
- Device form configures name/type/branch/notes only.
- POS `payment-dialog.tsx` filters selectable methods to `cash` and `card_terminal`.
- Kiosk `payment-method-grid.tsx` hard-codes cash/card/QR/e-money.
- Admin/POS already use `@godxjp/ui`; Kiosk uses `@godxjp/ui-native`.

### Confirmed correctness gaps

- `OrderPaymentStoreRequest` checks organization and branch/global scope but does not require
  `is_active = true`; direct callers can submit an inactive method UUID.
- `OrderPaymentService` resolves `PaymentMethod` by ID after request validation instead of resolving
  the effective policy at the mutation boundary.
- `PaymentMethodStoreRequest` treats code uniqueness differently from the YAML intent, while MySQL
  nullable composite uniqueness can permit multiple global rows with the same code.
- `PaymentMethodReplicaController` selects branch-specific rows or every `branch_id = null` row
  without an organization predicate. The Workstation replace-all pull can therefore ingest another
  organization's global methods.
- Stripe configuration exposes one global publishable/secret-key pair and auto-provisions a global
  Stripe method during payment handling, which cannot represent HQ/franchise merchant isolation.
- Refund provider calls currently occur while the payment row transaction/lock is open. Stranded
  charge refund defaults off and failure is log-only rather than a durable recovery state.

### Offline and reporting constraints

Workstation is an offline ledger with a durable sync queue. Payment changes must preserve client
idempotency, local-to-Cloud identity mapping, cashier/till attribution, receipts, dead-letter
behavior, and convergence. Till/Z-report, debt, split-bill, refund, tax, receipt, and revenue code
contains legacy status/method assumptions and requires a reader-by-reader compatibility audit.

## Provider and standards findings

### Stripe

- Charge type determines merchant/funds/refund/dispute liability. Direct charges live on the
  connected account; destination/separate charges have different platform liability and visibility.
- Stripe recommends one PaymentIntent per order/session and stable idempotency keys. Replaying an
  idempotent request can return the stored first result, including a 500, so timeout/500 requires
  retrieval/reconciliation rather than a fresh charge identity.
- Webhooks can duplicate, retry for days, and arrive out of order. Signature verification needs the
  raw body; processing should be asynchronous and acknowledge quickly.
- PaymentIntent and refund states are multi-step; provider raw state must not be collapsed into a
  single boolean.

Sources:

- https://docs.stripe.com/connect/charges
- https://docs.stripe.com/connect/direct-charges
- https://docs.stripe.com/connect/destination-charges
- https://docs.stripe.com/api/idempotent_requests
- https://docs.stripe.com/payments/payment-intents
- https://docs.stripe.com/payments/paymentintents/lifecycle
- https://docs.stripe.com/refunds
- https://docs.stripe.com/webhooks

### PayPay

- Merchant payment IDs are unique/idempotent identities; merchant context can require
  `assumeMerchant`, while store and terminal identity are separate metadata.
- Status may require Get Payment Details/polling in addition to webhooks.
- Refund events are not delivered as normal webhooks in the documented integration, so refund
  reconciliation must query provider state.
- Authorization/capture/cancel/refund capabilities and time limits differ; partial refund is
  supported but must be modeled as an operation.

Sources:

- https://www.paypay.ne.jp/opa/doc/jp/v1.0/dynamicqrcode
- https://www.paypay.ne.jp/opa/doc/jp/v1.0/direct_debit.html
- https://developer.paypay.ne.jp/products/docs/webpayment
- https://integration.paypay.ne.jp/hc/en-us/articles/4414061759887-What-is-AssumeMerchant
- https://integration.paypay.ne.jp/hc/en-us/articles/4414061749775-What-types-of-webhooks-does-PayPay-notify
- https://integration.paypay.ne.jp/hc/en-us/articles/4414048518159-Is-partial-refund-possible

### SB Payment Service

- Provider identity includes merchant ID and service ID; order IDs must be unique in that scope.
- SBPS exposes authorize/capture/cancel/refund/status operations across hosted and API integration
  styles; some full specifications require contract access.
- Notification retries and abnormal-flow status lookup reinforce the need for an inbox plus polling.
- SBPS documentation says credit-card partial settlement and partial refund are scheduled to end on
  2026-09-30, recommending an amount-change flow. Capability logic must be dated/versioned rather
  than assumed permanent.
- SBPS offers one-time-token/hosted mechanisms and EMV 3-D Secure; raw card data should bypass Tempo.

Sources:

- https://developer.sbpayment.jp/en/system-specifications/link-type/2517/
- https://developer.sbpayment.jp/en/system-specifications/api-type/5721/
- https://developer.sbpayment.jp/en/system-specifications/api-type/6513/
- https://developer.sbpayment.jp/en/payment-service/credit/3767/
- https://developer.sbpayment.jp/en/system-specifications/api-type/5911/
- https://developer.sbpayment.jp/en/system-specifications/api-type/10283/

### PCI DSS

Provider-hosted fields/tokenization reduce exposure but do not automatically remove all PCI
obligations. SAQ A eligibility depends on payment-page elements and merchant-site script security.
The final classification must be confirmed with the acquirer/QSA.

Sources:

- https://www.pcisecuritystandards.org/faqs/1438/
- https://www.pcisecuritystandards.org/faqs/1588/
- https://www.pcisecuritystandards.org/documents/Tokenization_Guidelines_Info_Supplement.pdf

## UI reference patterns

- Stripe Connect payment method configurations expose parent/default preference, downstream
  overridability, and resolved value—useful for explaining source and effective state:
  https://docs.stripe.com/connect/multiple-payment-method-configurations
- Adyen uses company → merchant → store → terminal inheritance and exposes inherited configuration:
  https://docs.adyen.com/point-of-sale/design-your-integration/determine-account-structure/configure-features
- Shopify POS supports default accepted methods with per-device deactivation; a device cannot enable
  a method excluded upstream:
  https://help.shopify.com/en/manual/sell-in-person/getting-started/setup-payment-method/enable-payments
- GOV.UK form validation retains input and pairs an error summary with field errors:
  https://design-system.service.gov.uk/patterns/validation/

## Omnify/domain-pack guidance applied

- The restaurant-chain pack confirms corporate-versus-franchise data isolation and hierarchical
  policy needs. The payment-specific gap required official provider/PCI research.
- Omnify business entities remain YAML-owned, with explicit `onDelete`, indexes, display names, and
  comments. Manual Laravel services stay outside generated bases.
- Laravel Boost is installed in `backend/composer.json`, but no callable Boost `search-docs` or
  database inspection tool was exposed in this session. The plan therefore relies on repository
  code/tests and flags implementation-time framework/database verification instead of inventing it.

## Decisions proposed for approval

| Decision | Proposal | Reason |
|---|---|---|
| Deployment shape | Payment module inside current modular monolith | Transactional consistency and current scale do not justify a new service boundary |
| Ownership | Console/SSO management model + explicit connection legal owner | Avoids a second shop ownership truth |
| Gateway hierarchy | Provider → connection → option/capability → shop preference → device restriction | Separates vendor, merchant, rail/brand, and operational policy |
| Network boundary | Prepare/call/finalize + reconciliation | Prevents long DB locks and survives ambiguous outcomes |
| Ledger writer | One orchestrator-owned writer | Eliminates Stripe/POS divergence |
| Refund model | Independent append-only refund operations | Supports repeated partial refund and durable provider state |
| Webhook model | Verified durable inbox + async normalization + polling | Handles duplicates, disorder, missing events, and retries |
| Client config | Effective option API + revision snapshot | Server enforcement and offline convergence |
| UI ownership | Tempo admin-web | Payment operations are downstream business configuration, not Console identity/entitlement |
| Service generation | Keep `service.enable: false`; hand-write payment services | Preserves the explicit repository decision to disable generated services |

## Remaining blockers

1. Authoritative Console/SSO shop management field/API is not present in this checkout.
2. Stripe Connect merchant-of-record/charge-model decision needs finance/legal approval.
3. Secret-store implementation and rotation ownership are not yet selected.
4. Provider sandboxes/contracts determine whether PayPay or SBPS is the second-adapter proof.
5. Authenticated rendered UI and provider sandbox behavior remain untested during planning.

## T0.1 / T0.2 — nguồn quyền sở hữu là PLATFORM

**Hệ nguồn: Platform (`dxs-platform/platform`).** Đây là console quản trị
`Organization` · `Brand` · `Branch` · `Location` · `User` · `Admin` · `Employee` ·
`EmployeeBranchAssignment`, cùng toàn bộ `Role` / `Permission` / `RolePermission` /
`RoleUser` / `ServiceRole` / `OrgServiceRole` / `TeamPermission` / `OAuthScope`.
Chuỗi xác thực: admin-web → `TEMPO_BACKEND_URL` → backend Tempo → `SSO_ISSUER`
(Platform). Tempo mirror `console_organization_id` / `console_brand_id` /
`console_branch_id` từ đó.

**Platform ĐÃ CÓ cả hai vế của câu hỏi quyền sở hữu** (`backend/app/Core/Models`):

```
Brand  → belongsTo Organization (console_organization_id)   ← SỞ HỮU thương hiệu
Branch → belongsTo Organization (console_organization_id)   ← VẬN HÀNH chi nhánh
Branch → belongsTo Brand        (console_brand_id)
```

Bằng nhau ⇒ `hq_managed`. Khác nhau ⇒ nhượng quyền. Ánh xạ đúng
`brand_owner_org_unit_id` / `operator_org_unit_id` của fixture.

### Còn thiếu đúng hai thứ

1. **Vòng đời grant** — `status` (`active` · `suspended` · `revoked`) và cửa sổ
   `validFrom` / `validTo`. Platform có QUAN HỆ nhưng không có trạng thái hay hạn,
   nên chưa phân biệt được nhượng quyền còn hiệu lực với nhượng quyền đã thu hồi.
2. **Endpoint đọc** trả mô hình đã giải quyết + `ownership_revision` đơn điệu để
   Tempo cache và so sánh bằng đẳng thức.

### Ngữ nghĩa — đúng bất kể ai cài đặt

Đã mã hoá ở `backend/tests/Fixtures/Payment/branch-management-contract.json`:
grant chỉ dùng được khi `status = active`, `validFrom <= now`, và `validTo` rỗng
hoặc còn hạn; `suspended`, `revoked`, hết hạn, thiếu, cross-tenant, và **nhiều
grant active mơ hồ** đều về `unresolved` — resolver thanh toán fail closed. Tempo
được cache projection đã giải quyết cùng `ownership_revision`, nhưng **không được
tự tạo ra hay tự suy ra** — kể cả khi hai cột trên đã mirror sẵn. Đây là quyết
định tiền thuộc về ai, và một bản mirror trễ nhịp thì âm thầm sai.

### Cách tự kiểm — đọc cái này thay vì tin đoạn văn trên

```sh
grep -n "belongsTo" ~/Herd/id/backend/app/Core/Models/Brand.php \
                    ~/Herd/id/backend/app/Core/Models/Branch.php
ls ~/Herd/id/backend/app/Models | grep -E "Role|Permission|Branch|Brand|Employee"
grep -n "SSO_ISSUER" backend/.env
grep -n "TEMPO_BACKEND_URL" admin-web/.env.development
```

> ⚠️ Mục này từng khẳng định một hệ KHÁC là nguồn chân lý, kèm liên kết tới issue
> ở đó. Khẳng định ấy cũ đi mà không ai thấy; một phiên làm việc sau đi theo nó và
> mất một buổi. Bản sửa đầu tiên lại quá tay theo hướng ngược — ghi "chưa hệ nào
> sở hữu" trong khi Platform đã có sẵn hai phần ba lời giải. Đó là lý do mục này
> mang **cách kiểm**, không chỉ mang một cái tên: tên thì cũ đi lặng lẽ, lệnh thì
> chạy lại được.

Theo cổng cứng của plan, schema quyền sở hữu ở Gate 1 không được bắt đầu trước khi
Platform công bố hai thứ còn thiếu.

## 2026-07-22 — T0.3 payment architecture decisions

Recorded the accepted implementation boundaries in [ADR.md](ADR.md): merchant of record and Stripe
Connect charge model are explicit per connection and immutable per attempt; franchise connections
never fall back to HQ; provider calls use prepare/call/finalize transactions; stale offline policy
cannot start a new external operation; PayPay is the second-provider contract proof.

Two operational gates remain explicit rather than guessed: finance/legal must approve the merchant
and charge model for each real connection, and operations must approve the server-only versioned
secret-store implementation before reusable credentials are migrated.

## 2026-07-22 — T0.4 current-state inventory

Recorded the exact Cloud and Workstation writers, transitions, provider calls, transport routes,
refund/replay paths, reporting readers, client configuration, and settlement side effects in
[INVENTORY.md](INVENTORY.md). The inventory confirms two independent Cloud ledger/settlement
writers, provider calls inside transactions, a tenant leak risk in the Workstation method replica,
legacy status divergence, and report sensitivity to the positive-original plus negative-refund row
shape.

## 2026-07-22 — T0.5 characterization tests and detailed scenarios

Added `backend/tests/Feature/Payment/PaymentArchitectureCharacterizationTest.php` to freeze partial
versus final event behavior and the ledger projection across a partial refund, ignored pending/failed
rows, and a debt-settlement row. Added [TEST-CASES.md](TEST-CASES.md) with fixtures, actions, exact
DB/provider/event/API assertions, negative-call guarantees, and real concurrency requirements for
the original 92 payment scenarios. The accepted domain-boundary amendment adds 18 detailed cases,
bringing the plan total to 110.

Verification after installing locked Composer dependencies in the isolated worktree:

- Payment/Stripe/refund/debt/till/Workstation baseline: 102 tests, 336 assertions, no failures.
- New characterization suite after review remediation: 4 tests, 33 assertions, no failures.
- Pint passed for the new PHP test file.
- Targeted Workstation Go payment/refund/till/sync/dead-letter tests passed in handler, service, and
  store packages.

The suite emits existing PHPUnit warnings and Stripe fake undefined-property notices. The worktree
has no `.env`, so verification used a process-local non-production `APP_KEY`; no environment file or
credential was copied into the worktree.

The unfiltered Workstation `internal/service` package has 16 pre-existing failures in receipt,
kitchen-ticket, and red-invoice print-format tests at the pinned submodule commit. They are unrelated
to the new characterization code but remain a release-suite blocker; T0.5 records rather than masks
them.

## 2026-07-22 — T0.6 state machines and typed errors

Recorded the normalized attempt, append-only refund, and durable provider-event inbox transition
tables in [STATE-MACHINES.md](STATE-MACHINES.md), including legal evidence/effects, terminal-state
guards, reconciliation semantics, a stable API error envelope, and 30 typed public error codes with
HTTP/retry/action behavior. Provider raw status/error remains stored evidence and cannot expand the
normalized vocabulary implicitly.

## 2026-07-22 — T0.7 provider capabilities

Added [CAPABILITIES.md](CAPABILITIES.md) as the fail-closed capability contract across provider,
product, rail, brand, channel, currency, environment, merchant entitlement, version, and effective
time. It separates provider potential from verified connection capability and prevents shop/device
policy from widening upstream support.

The baseline encodes Stripe web and Terminal separately, PayPay OPA merchant/store/terminal and
cancel-window behavior, and the SBPS 2026-09-30 termination boundary for the existing partial-sale
and partial-refund functions. Unknown, contract-only, uncertified, overlapping, or expired rows are
unavailable and make no provider call. Production rows require account-specific evidence rather
than relying on provider-wide documentation.

## 2026-07-22 — T0.8 rollout SLOs

Approved [ROLLOUT.md](ROLLOUT.md) as the engineering release contract: zero ledger drift and duplicate
money movement, exact provider/ledger and canonical-settlement parity, bounded webhook and
reconciliation age, five-minute new-route shutdown, fifteen-minute stable rollback, deterministic
ramp cohorts, and explicit error-budget actions.

Full routing requires 14 consecutive clean days, at least 1,000 external attempts, and seven
provider settlement/reconciliation cycles per materially different slice. Legacy deletion requires
30 consecutive clean days after the final slice. Low traffic extends observation rather than
silently lowering evidence, and every promotion requires Payment, SRE, Finance, Security, support,
and release artifacts applicable to its scope.

## 2026-07-22 — Gate 0 independent review remediation

The fresh review correctly found that the checkout was still locked to Omnify 5.9.0 and therefore
could not support the claimed global service opt-out. The workspace now pins Omnify 5.9.3 and sets
the Laravel target's `service.enable: false`. `omnify validate` accepts all 164 schemas and
`omnify generate --project api --check --verbose` reports every generated file current without
creating service changes.

The review also made the Gate 0 evidence more honest: T0.1 is an investigation/proposal pending
Identity #67, T0.5 explicitly combines existing suites with added gap tests, the ownership fixture
now includes missing/revoked/cross-tenant cases and is executable, the SBPS midnight boundary is
labeled as Tempo's conservative fail-closed choice rather than provider-confirmed timing, and the
README reflects the ADR's accepted offline and PayPay decisions.

## 2026-07-22 — T0.10 single-migration domain boundary amendment

The user expanded Plan 047 so payment transports are not migrated twice. Added
[DOMAIN-BOUNDARIES.md](DOMAIN-BOUNDARIES.md) and ADR Decision 7: Payment, Order, Product, Menu, and
Customer each have one public mutation gateway, while cohesive typed handlers/repositories remain
internal. Payment persistence cannot mutate Order and must call `OrderService::settleIfPaid()`;
Order only reads Product/Menu/Customer through query contracts and stores immutable snapshots.

Confirmed direct/alternate writer families were added to [INVENTORY.md](INVENTORY.md). Tasks now
introduce the canonical facades and report-mode architecture guard before payment cutover, migrate
Product/Menu/Customer/Order writers in the same touched transport, remove unused generated services,
and finish Gate 4 with an empty runtime allowlist plus strict CI enforcement. Tests I1–I18 specify
AST/query-builder detection, import/auth/order transport parity, concurrency/locking, snapshot
immutability, offline replay, exception atomicity, consumer-safe deletion, and the final zero-writer
scan. Total detailed scenarios increase from 92 to 110.
## 2026-07-22 — T1.1 shared payment vocabulary

Added eleven Omnify-owned shared enums for provider, environment, legal owner scope, connection
health, rail, channel, policy preference, attempt operation/state, refund state, and durable provider
event state. Values follow the approved ADR, capability matrix, and state machines. Comments make
the fail-closed boundaries explicit: environment cannot cross, only ready connections are eligible,
device/shop policy cannot widen an upstream deny, ambiguous money remains reconcilable, and
unverified provider events never enter the inbox.

## 2026-07-22 — T1.2 provider and option catalogs

Added the provider/adapter catalog and the versioned provider option capability catalog. Provider
identity is unique by typed code; option identity is unique per provider/code and carries explicit
rail, channel, device class, currency/minor-unit, workflow, operation, limit, recovery, API version,
revision, and effective-window data. Both schemas explicitly forbid credentials and raw provider
payloads; actual merchant approval belongs to the connection option added in T1.3.

The Omnify MCP validator is bound to the original worktree index and therefore reported the newly
added enum/provider targets as missing when validating these files individually. The branch-local
Omnify 5.9.3 CLI loaded the complete worktree dependency graph and validated all 177 schemas. This
context limitation is retained as execution evidence rather than weakening association/EnumRef
types to satisfy an incomplete index.

## 2026-07-22 — T1.3 merchant connection and verified capabilities

Added explicit merchant connections and account-specific option capability rows. Connections bind
the Tempo tenant/brand, canonical Identity brand/operator/brand-owner OrgUnit IDs, owner scope,
opaque ownership revision, provider/environment, legal charge model, safe merchant identities,
health, and hidden opaque secret references. Franchise ownership also records a restrictive local
owner-branch FK; application/constraint tests must prove its `console_branch_id` equals the operator
org-unit id published by whichever system ends up owning branch management, and that HQ scope has no
franchise owner branch.

The intended shape, still unimplemented anywhere reachable: Tempo `Branch.console_branch_id` IS the
canonical org-unit id, the source returns the resolved legal operator, and the ownership revision is
monotonic and produced by that source. No `is_headquarters`, standalone, route, or role inference is
persisted here.

Connection options intersect the catalog with verified account currencies/channels/operations,
limits, effective window, evidence, and capability revision. All associations that carry financial
history use `RESTRICT`; unknown/contract-required/certification-required/restricted capabilities
fail closed. Branch-local Omnify 5.9.3 validates all 179 schemas.

## 2026-07-22 — T1.4 shop/device policy and immutable revisions

Added one deterministic preference row per `(branch, gateway option)`, one device override per
`(device, shop option)`, and append-only branch policy publications unique by `(branch, revision)`.
Shop rows record a selected eligible connection without creating a fallback; every tenant/brand/
branch/connection relationship remains subject to resolver and constraint tests. Device preference
is DB-limited to `inherit|disabled`, so a device cannot widen the shop/upstream set even if a caller
bypasses UI validation.

Policy publications store the Identity ownership token, canonical secret-free snapshot, SHA-256
hash, effective-option count, trigger source, and monotonic branch revision. Hash lookup makes
unchanged publication idempotent; new revisions are reserved for effective output changes. Omnify
5.9.3 validates the complete 182-schema graph.

## 2026-07-22 — T1.5 durable attempts, refunds, and provider inbox

Added durable prepare/call/finalize attempts, append-only refund operations, and the verified
provider-event inbox. Attempts snapshot tenant/order, immutable connection/capability/policy,
Identity operator/revision, provider/environment/channel, currency/minor amount, stable caller and
provider request identities, request fingerprint, normalized/raw status, retry schedule, version,
and lifecycle timestamps. Provider object identity is unique per connection/environment.

Refunds own an independent positive minor amount, fingerprint, stable request/refund identity,
reservation state, retry schedule, and terminal timestamps; the original succeeded attempt is not
rewritten. The inbox deduplicates `(connection, environment, provider event ID)`, detects same-ID/
different-body conflicts by SHA-256, supports delivery counts, worker leases, retry/dead-letter and
operator resolution, and accepts rows only after signature verification.

All provider payload/error fields are explicitly redacted/allowlisted and hidden where diagnostic;
raw bodies, credentials, signature secrets, PAN, and CVV have no schema property. Omnify 5.9.3
validates the complete 185-schema graph.

## 2026-07-22 — T1.6 restore PaymentMethod schema ownership

Moved the existing plan-038 `payment_methods.type` string(20) vocabulary/default/index into
`PaymentMethod.yaml` as an inline enum (`cash`, `card`, `transfer`, `qr`, `voucher`, `on_account`,
`other`) and removed `service: {}`. This preserves current debt semantics and existing migration
shape while ensuring future generated model/request/resource/type artifacts include the field and
Omnify 5.9.3 does not recreate the deleted CRUD service layer.

The manual model fillable/resource serialization compatibility remains until T1.9 regenerates the
checked-in bases; its comments now identify that bounded removal point. Omnify validates all 185
schemas with the pre-existing `default` connection warning only.

The focused PaymentMethod feature suite passes 12 tests / 29 assertions with existing warnings
when supplied a test-only `APP_KEY`. The first run without that environment value stopped in the
pre-existing Brand observer with `MissingAppKeyException` before any assertion; no `.env` or key was
written to the worktree.

## 2026-07-22 — T1.7 additive OrderPayment gateway history

Extended the existing financial ledger with nullable references to the durable payment attempt,
merchant gateway connection, and selected catalog option. Added nullable immutable snapshots for
provider, environment, ISO currency, integer minor-unit amount, provider-neutral attempt state, and
the allowlisted raw provider status code/name. Restrictive foreign keys retain financial history
when catalog or merchant configuration is disabled.

Every new column is nullable and the existing amount, status, payment method, reference, metadata,
refund, Stripe compatibility, and cash behavior remain unchanged. This permits a later restartable
backfill without rewriting historical semantics. Omnify 5.9.3 validates all 185 schemas and reports
the expected 35 additive branch changes; the only warning remains the pre-existing unknown
`default` connection. The payment architecture characterization suite passes 4 tests / 33
assertions with existing warnings and a test-only `APP_KEY`.

## 2026-07-22 — T1.8 MCP and complete-graph schema validation

Validated every T1.1–T1.7 schema with Omnify MCP and validated the complete branch-local graph with
Omnify 5.9.3 CLI. MCP passes the eleven independent enums, `PaymentPolicyRevision`, and the corrected
`PaymentMethod`; dependent new schemas report only missing new targets/EnumRefs because MCP remains
indexed to the original worktree. A separate branch-local audit resolves all 26 unique named
Association/EnumRef targets, and CLI validates all 185 schemas with only the pre-existing unknown
`default` connection warning.

MCP caught three standalone problems missed by the CLI: two generated MySQL index names over 64
characters (`OrderPayment` and `PaymentGatewayConnectionOption`) and ineffective `length` metadata
on the inline `PaymentMethod.type` enum. Explicit short index names and removal of the ineffective
metadata now pass MCP. Full evidence is recorded in [SCHEMA-VALIDATION.md](SCHEMA-VALIDATION.md).

## 2026-07-22 — T1.9 deterministic generation and rollback review

Upgraded the pinned generator to Omnify 5.9.4 after fixing unsafe ALTER rollback ordering upstream
in omnify-jp/omnify-go issue #121 and merged PR #122. Regeneration now emits twelve additive
create/translation migrations plus one nullable `order_payments` ALTER; all pass PHP lint, fresh
SQLite migration, and targeted rollback. The already-deployed manual `payment_methods.type`
migration was adopted instead of retaining a duplicate generated ALTER.

Generated bases now own PaymentMethod `type`, so the temporary manual fillable/resource overrides
are gone. Connection resources omit both opaque secret-reference fields. Validation passes all 185
schemas, generation check is current, and no generated service file was created or changed under
`Services/`; the global service opt-out remains effective. Full review evidence, including the
pre-existing historical rollback and backend-suite baseline failures, is recorded in
[CODEGEN-REVIEW.md](CODEGEN-REVIEW.md).

## 2026-07-22 — T1.10 database constraint ownership

Added executable database tests for provider/option uniqueness, merchant environment isolation,
connection capability uniqueness, attempt idempotency and provider object identity, refund command
and provider identity, webhook inbox identity, required tenant parents, and restrictive financial
references. The suite uses direct inserts so failures prove database behavior independently of
future resolver/orchestrator code.

[DATABASE-CONSTRAINTS.md](DATABASE-CONSTRAINTS.md) records the non-overlapping responsibility split:
the database owns durable identity and parent existence, while the canonical writer must enforce
cross-row tenant/provider/environment equality and refund capacity under an attempt lock. This keeps
aggregate refund safety honest instead of pretending a uniqueness key can enforce a changing sum;
the E5/E6 concurrency suite becomes executable with T2.16.

## 2026-07-22 — T1.10a deterministic PaymentMethod scope

Replaced nullable `(organization_id, branch_id, code)` uniqueness with non-null
`(organization_id, scope_key, code)`, where scope is exactly `global` or the branch UUID. The field
is schema-owned, hidden, non-fillable, and database-generated with
`COALESCE(branch_id, 'global')`; no model event or hand-written migration owns it.

Upstream Omnify issue #123 / PR #124 added stored generated column support and shipped in 5.9.5.
Integration then found that SQLite cannot add a stored generated column through ALTER; issue #125 /
PR #126 added the SQLite `virtualAs` fallback in 5.9.6. A second integration pass found that Laravel
Blueprint has no `getConnection()` method; issue #127 / PR #128 moved driver resolution to the
connection-aware Schema builder and shipped in 5.9.7. Final integration review found that a failed
unique-index statement could leave an unrecorded partial ALTER; issue #129 / PR #130 added
connection-aware, independent retry guards for columns, primary/unique indexes, foreign keys, and
standalone indexes in 5.9.8. All upstream Go suites and fresh reviews passed before each release.

The final 5.9.8-generated ALTER derives legacy rows automatically, installs the new unique index,
then removes the nullable index. Duplicate legacy rows cause the database to reject the unique index
without silently deleting history. Focused scenarios prove global collision, global/branch
coexistence, per-branch collision, caller-forgery resistance, exact legacy derivation, and duplicate
refusal and data-only retry recovery on SQLite.

## 2026-07-22 — T1.11 legacy identity blocker

Implementation audit found that the current legacy data cannot prove a historical gateway
connection or attempt identity. Global Stripe configuration contains credentials and a fallback
currency but no merchant account, environment, Identity owner/revision, charge model, rotation
history, connection option, policy revision, channel, or provider request key. A PaymentIntent ID
also does not distinguish test from live. Creating connections or attempts from those fields would
invent financial identity.

The design says that a compatibility PaymentMethod maps to an effective gateway option, but the
current schema has no PaymentMethod-to-GatewayOption association or mapping table. ShopPaymentOption
cannot substitute for it because that entity represents mutable shop policy, not legacy identity.

Safe T1.11 scope requires an explicit decision: report PaymentMethod classifications and backfill
only corroborated nullable OrderPayment Stripe snapshots, while leaving environment and all gateway
foreign keys null unless a reviewed per-PaymentIntent evidence manifest points to existing verified
connection/option rows. The command must never synthesize credentials, ownership, connections,
options, attempts, refunds, events, ledger rows, or read/write cutover. T1.11 remains unchecked until
this correction is approved and mirrored into DESIGN.md, TESTS.md, and TEST-CASES.md.

## 2026-07-22 — T1.11 safe legacy identity backfill implementation

Implemented `payments:backfill-gateway-identities` as a command-local maintenance boundary. Report
mode is the default and writes neither payment rows nor audit rows. Apply mode requires an existing
organization, validates optional branch ownership, and holds a primary-connection database advisory
lock (or SQLite flock) for the process lifetime without a TTL. Every audit control-plane read is likewise
pinned to the primary so replica lag cannot replay a run. A repeatable-read UUID-keyset snapshot streams
exact candidate UUIDs, one-way provider identity hashes, and duplicate booleans into a 0600 private
SQLite manifest; raw PaymentIntent IDs never enter that artifact
or generic audit metadata. The start audit retains only manifest count/hash plus frozen PaymentMethod/
orphan summaries. Existing artifact directories/files are re-verified as 0700/0600 on create and resume.
Candidate evidence queries are bounded to 250 IDs. Duplicate detection scans the global Stripe ledger
exactly once by primary-key keyset and hash-joins into the private manifest, avoiding repeated unindexed
`reference_no` scans. Every row-lock/write/checkpoint chunk commits atomically. Completion deletes the
short-lived manifest; a completed rerun returns its
original report without appending another audit.

Report mode acquires the same advisory lock and rejects any run ID already owned by an apply run, so it
cannot race, open, or delete a paused manifest. `--max-chunks` is parsed before locking/auditing and only
accepts integers greater than or equal to one; strings, zero, and negative values fail closed.

Only corroborated full or split Stripe originals can fill null provider/state/status and evidence-backed
currency/minor-amount snapshots. Branch-scoped methods must match the payment branch. The shared exact
minor-unit helper handles zero-, two-, and three-decimal currencies, trailing zeros, excessive precision,
and integer overflow without floats. The maintenance persistence port is deliberately absent from the
runtime container, and the command creates no connection, option, attempt, ownership, environment, or
runtime read/write cutover.

Final independent review passed after six hardening rounds. The focused T1.11 suite covers 21 cases and
123 assertions; the broader Gate 1 regression suite is recorded with the task commit evidence. The only
test warnings are the worktree's known missing-`.env` fixture warnings.

## 2026-07-22 — T2.1 provider-neutral gateway boundary values

Added the manual `App\Services\Payment\Gateway` boundary for create/retrieve/capture/cancel,
refund/retrieve-refund, and webhook verification. Commands, results, money, connection identity,
request identity, provider references, normalized/raw states, and typed client next actions are
immutable and contain no Eloquent model or provider SDK type. Local-only states and successful
results without provider identity/money evidence are rejected at construction.

Capability snapshots now carry the complete fail-closed contract: immutable ID/revision,
provider/product/API, rail/method/brand, channel/device, currency minor units, environment,
workflow, closed predicate AST, operation support, limits, recovery, merchant identity,
effective window, verification state, and typed evidence. Conditional support accepts only known
facts/operators; unknown facts fail closed. Evidence certification/review windows intersect the
capability window. Workflow/operation/limit/recovery contradictions, including poll/retrieve or
webhook mismatches, are rejected before an adapter can advertise them.

Raw webhook bodies, header values, and payment-source handoff material live in process-local
ephemeral carriers rather than object properties and cannot be serialized, debugged, or exported.
Client next-action payloads use explicit type-specific factories and an explicit authenticated-client
conversion; generic result serialization exposes only the action type. Diagnostic data uses fixed
field/code validation and rejects credential patterns, nested objects, floats, `INF`, and `NAN`.

Independent review passed after five rounds. The focused suite covers 21 cases and 119 assertions,
including invalid URLs/control characters, secret export attempts, evidence time boundaries,
capability contradictions, impossible results, and the architecture dependency scan.

## 2026-07-22 — T2.2 payment gateway contract

Defined the provider-neutral `PaymentGatewayContract` for capability discovery, payment prepare/retrieve,
capture, cancel, refund/retrieve-refund, and verified webhook intake. Boundary failures are typed and never
chain provider SDK exceptions. Unsupported or unverified capabilities fail before a provider call, and raw
provider state is retained only as safe evidence beside normalized states.

Added a reusable, non-overridable provider contract suite with provider-specific hooks only for adapter
construction, fault injection, call evidence, raw statuses, and webhook signing. The suite proves semantic
idempotent replay, rejects drift across every provider-affecting command field, scopes mutation identity and
remote objects by connection, deduplicates webhook events per connection, rejects conflicting event bodies,
redacts secrets, and normalizes declines, authentication failures, and ambiguous timeouts. Declines and
timeouts replay without a second provider call; ambiguous outcomes require reconciliation.

The in-memory adapter is test infrastructure rather than a runtime driver. It deliberately returns the same
refund reference across connections so the shared tests prove connection isolation instead of relying on
globally unique fake identifiers. Independent review passed after seven rounds. The focused T2.1/T2.2 suite
covers 31 cases and 372 assertions. The combined Gate 1/payment-boundary regression covers 70 cases and 562
assertions with 39 known missing-`.env` warnings and exit code zero.

## 2026-07-22 — T2.3 explicit payment gateway registry

Added an application-singleton `PaymentGatewayRegistry` backed only by the explicit
`payments.gateway_drivers` provider-code-to-container-service map. The default map is deliberately empty
until a runtime adapter is implemented, so deployments fail closed instead of guessing a driver or falling
back to another provider/merchant. Registry input accepts the generated provider enum, resolves by the
non-secret connection provider, validates map keys and service identities, and returns only implementations
of `PaymentGatewayContract`.

An unconfigured provider raises `PAYMENT_GATEWAY_PROVIDER_UNSUPPORTED` with the request correlation ID and
safe configured-provider evidence. Invalid, unresolvable, or contract-mismatched mappings raise the typed
`PAYMENT_GATEWAY_DRIVER_INVALID` configuration failure without exposing container service names. Tests cover
explicit resolution, no-fallback/no-resolution behavior, deterministic map ordering, malformed mappings,
safe resolution failures, real Laravel singleton binding, empty default configuration, and config loading at
first resolution. Independent review passed, including the follow-up automated binding coverage. The focused
T2.1–T2.3 suite covers 37 cases and 401 assertions; combined Gate 1/payment-boundary regression covers 76
cases and 591 assertions with 41 known missing-`.env` warnings and exit code zero.

## 2026-07-22 — T2.4 server-only encrypted gateway secret store

The product owner approved the XServer-compatible dedicated encrypted database store. Payment master keys
are independent from `APP_KEY` and loaded only from an absolute, canonical, non-symlink keyring file outside
the repository/web root, owned by the dedicated PHP service account with owner-only permissions. The
versioned keyring supports decrypting existing rows after changing the active encryption key; operational
provisioning, backup, restore, provider rotation, master-key rotation, and compromise response are recorded
in `SECRET-STORE-RUNBOOK.md`.

Added server-only secret-store contracts, an application-singleton connection resolver, and an encrypted
database implementation. XChaCha20-Poly1305 authenticates tenant, connection, provider, environment,
purpose, version, opaque reference, and master key ID as associated data. Keyed fingerprints are recomputed
after decryption and compared in constant time. Copied master-key bytes and derived fingerprint keys are
zeroed after cryptographic use. Resolution returns process-local non-serializable carriers and maps missing,
revoked, expired, mismatched, tampered, or undecryptable material to one safe typed failure.

Rotation locks the authoritative connection and atomically inserts an immutable encrypted version, updates
the connection's hidden opaque reference, retires/revokes old versions, and appends a value-free audit row.
API credentials cut over immediately. Webhook rotation accepts at most the active version and its immediate
predecessor for an explicit overlap capped at 24 hours; a third rotation revokes any older retiring version.
Dangling active references fail closed rather than being silently replaced. Revoke removes the active
reference and makes all active/retiring versions unavailable.

Audit rows contain actor, correlation, scope, versions, keyed old/new fingerprints, key ID, reference hashes,
and overlap deadline, never secret value/ciphertext/nonce/reference. Omnify owns the two hidden internal
schemas and generated DDL while an idempotent deployment command installs SQLite, MySQL, or MariaDB
triggers rejecting audit UPDATE/DELETE. Runtime rotation and revocation verify that protection and fail
closed when it is absent or the database driver is unsupported.

Independent review passed after the security findings around repeated webhook rotation, fingerprint
authentication, MariaDB protection, and keyring ownership were closed. After moving DDL ownership to
Omnify and adding deployment-trigger coverage, the focused T2.4 suite covers 14 cases and 102 assertions.
Combined Gate 1 and T2.1–T2.4 regression covers 90 cases and 693 assertions with 52 known missing-`.env`
warnings and exit code zero.

## 2026-07-22 — T2.5 fail-closed payment policy resolver

Implemented the manual, provider-neutral `PaymentPolicyResolver` as a pure intersection over the
authoritative ownership projection, provider/catalog state, exact merchant connection,
account-verified capability, owner/HQ policy, shop preference, device restriction, and runtime
health. The result contains a deterministic ordered explanation trace, stable reason and public
error codes, and only safe connection/owner identifiers. Missing, foreign, stale, ambiguous,
expired, inactive, unverified, or conflicting input denies the option. An explicit shop selection
can disambiguate connections only after exact tenant, brand, branch, Identity owner, opaque
ownership revision, and environment matching.

HQ-managed resolution accepts only an exact HQ-owned connection. Franchise resolution accepts
only the exact franchise operator and local owner branch; an available HQ connection is not a
fallback. Downstream `enabled`/`inherit` values cannot widen an owner/HQ deny, and a device has only
`inherit` or `disabled`. The resolver catches ownership-source failures and returns
`PAYMENT_OWNERSHIP_UNRESOLVED` before inspecting or exposing a candidate connection.

The authoritative runtime adapter remains an integration hard gate. This checkout contains no
branch-management projection client — and no system currently owns that model to write one against —
so the container deliberately binds the ownership port to an unavailable adapter and all default
runtime resolutions fail closed. No
local `Branch.is_headquarters`, `is_standalone`, route, or actor-role inference was added. The local
ownership fixture is still labeled `proposal-v1`, and no authoritative persisted HQ-default
policy source exists yet, so the trusted persistence adapter is also intentionally deferred.
Additionally, the ADR contract treats
`ownership_revision` as an opaque equality token while the generated connection schema/request
currently applies a numeric rule. The resolver preserves opaque revisions byte-for-byte and never
coerces or orders them. Installing the upstream adapter and aligning that schema rule are required
before enabling a payment route, but do not weaken the completed resolver boundary.

Focused unit and application-binding tests cover HQ success, exact franchise selection, no HQ
fallback, unavailable/throwing/mismatched/ambiguous ownership, stale and cross-scope candidates,
explicit connection disambiguation, deterministic row-order output, every policy layer, currency,
channel, device-class, operation and environment filters, downstream non-widening, runtime health,
opaque revision preservation, and the default unavailable binding. After review hardening, the
focused suite passes 16 tests and 306 assertions. The combined T2.1–T2.5 boundary regression passes
51 tests and 707 assertions. The only warnings are the checkout's known missing-`.env` test fixture.

The review hardening replaces the loose account fields with one immutable connection-approved
capability record. It must match the exact catalog capability ID, revision, SHA-256 fingerprint,
integration product, API version, rail, and method type; its approved brands, device classes,
currencies, channels, operations, and configured merchant identities can only narrow the catalog.
Every identity and account dimension has a negative fail-closed test. Branch-management projections
now carry the source organization and must match the lookup organization before candidate selection.
Ownership revisions use bounded, control-character-free opaque bytes with no trimming, character-set
restriction, numeric coercion, or case folding; whitespace and punctuation remain significant.
Requested payment brand/network is normalized and scoped on both the request and candidate.
`account_configured` is catalog metadata only and is never accepted as account evidence. A requested
brand must be a real member of the connection approval and, for fixed catalogs, the catalog list;
empty or extra approvals fail closed. Brandless options require the explicit three-way contract of a
null request, null candidate brand, and empty catalog/account brand lists.

## 2026-07-22 — T2.6 effective-policy revision publisher

Added a manual serializer and publisher over the T2.5 resolver result. Canonical JSON recursively
sorts object keys, sorts options by UUID, rejects floats/objects/resources, and hashes schema version,
exact branch scope, byte-preserved ownership token, a caller-supplied safe configuration digest, and
the full safe option reason/error/connection/owner/trace semantics. It intentionally omits correlation
IDs, publication timestamp, allocated revision, trigger metadata, and every provider secret. Effective
option identifiers are revalidated as UUIDs at the snapshot boundary, so a secret-shaped string cannot
be smuggled through a hand-built resolver DTO. Snapshot hashes can be independently verified and a
tampered stored latest snapshot fails closed.

Publication uses explicit clock and persistence ports. The Eloquent adapter validates the local
organization/brand/branch primary keys by resolving the Organization first, then matching Brand and
Branch through their distinct Console organization/brand IDs. It locks the branch row, locks and
verifies the latest revision, returns
that row for an identical latest hash, or appends exactly `latest + 1`. Reverting to a historical output
creates a new revision rather than reusing history. The existing unique `(branch_id, revision)` index is
the race backstop. Trigger source is a closed enum and remains audit metadata, so a changed trigger or
timestamp cannot create a revision when effective output is unchanged.

The snapshot is branch/shop-base only. `configuration_hash` can reflect device-policy changes and
therefore trigger a branch revision, while no device ID or device-specific policy is persisted in the
snapshot; device projections remain T6.3/T6.4. There is no route, event subscriber, runtime reader,
Workstation sync, or payment cutover in T2.6, and no Identity value is invented.

The application model rejects ordinary Eloquent update/delete APIs after publication. This is
application-level immutability only: database/query-builder writer enforcement remains part of T2.8,
so this implementation does not claim a database trigger prevents privileged direct SQL mutation.
Focused T2.6 coverage has 8 cases and 50 assertions: 4 pure serializer cases pass and 4 database cases
execute with the checkout's known missing-`.env` warnings. Coverage includes canonical ordering,
correlation/secret omission, full hash semantics, tamper detection, replay, change, reversion, branch
isolation, unique race backstop, scope rejection, model immutability, corrupt-latest rejection, and
default container bindings. Production-engine multi-process stress remains an end-to-end B10 gate.

## 2026-07-22 — T2.6 integration onto current dev

The integration regenerated the combined schema with Omnify 5.9.8 so the existing Branch/Menu/MenuSection
localization and the payment relations remain in one authoritative snapshot. Omnify version 78 records
those pre-existing translatable property changes, but its six duplicate translation/table-alter migrations
were omitted because `dev` already ships the byte-equivalent DDL as migrations 141–143 and 2026-03-13.
Keeping both sets would attempt to create `branch_translations`, `menu_translations`, and
`menu_section_translations` twice on a fresh database. The generated-file check remains clean against the
version-78 snapshot after adopting the existing migration history.
## 2026-07-22 — T2.8 canonical mutation architecture guard

Added `architecture:domain-writers`, a read-only PHP-AST scan over application code for the five
Plan 047 aggregate families. The scanner resolves owned model types through imports, parameters,
typed properties, assignments and foreach values; detects static/instance Eloquent mutations,
relationship mutations, model and table query-builder writes, raw SQL writes against owned tables,
and Omnify generated CRUD service surfaces. The reviewed T1.11 maintenance persistence port is the
only current production exemption; Product/Menu/Customer facade candidates remain exact allowlisted
debt until their internal persistence boundaries exist and are cut over. Eloquent and table queries
that contain no mutation remain permitted.

Report mode freezes 396 writer occurrences across 250 exact signatures and 107 paths on the integrated
T2.6 `dev` snapshot. The follow-up inventory includes
Floating Section placement/schedule/branch-override state in Menu and topping group/item/SKU state
in Product, plus Product-owned `VariantUnit` and Order-owned `OrderCodeCounter`; Recipe remains a
separate aggregate and is intentionally excluded. It also uses the canonical DDL pivot names (`product_category`, `menu_menu_sections`,
`menu_promotion_category`, and `menu_promotion_product`) and inventories owned Product, Menu, and
Payment translation models so static Eloquent and generated-service writers cannot bypass table-based
detection. Every occurrence is counted, so copying an already-allowlisted writer in the same file
fails CI. The scanner also resolves indirect Omnify CRUD calls, aliases/FQCNs, scoped ordered
assignments and untyped constructor properties without confusing same-basename vendor DTOs.
Dynamic model/query calls, `DB::table(...)` mutation targets, and dynamic/concatenated raw mutation
SQL are reported as non-allowlistable unknown findings; dynamic table reads remain permitted. Each
allowlist path has an owner,
removal task, reason and Gate 4 expiry. Known debt is printed and exits zero; any unmatched writer,
stale entry, duplicate signature, missing metadata, unknown aggregate or expired gate exits non-zero.
The baseline source states that additions are prohibited, while move-on-touch tasks remove the exact
signature in the same commit. Generated service bases are inventoried once as a generic CRUD surface
rather than duplicating each generated method implementation.

The focused Pest coverage exercises static and inferred model writes, relationships, the complete
guarded Laravel mutator set, model/table query builders, raw SQL through the facade and explicit DB
connections, dynamic calls, read-only queries, direct and indirect generated services, canonical
boundaries, duplicate occurrence counts, scoped/order-aware dataflow, FQCN collisions, and strict
known/new/stale governance. Governance rejects empty/wrongly typed signature maps, whitespace,
noncanonical or missing paths, malformed task references, and entries expiring at the current gate.
A repository-level architecture test freezes occurrence/signature/path counts and runs the Artisan
report contract.

## 2026-07-22 — T2.7 canonical mutation contracts

Published separate typed mutation facades, internal persistence ports, query-only ports, and aggregate
snapshots for Order, Product, Menu, Customer, and Payment. Every mutation accepts a final readonly command
with validated organization/correlation/idempotency identity; revision-sensitive operations require an
expected version. Executable mutations carry explicit typed immutable payloads and retain SHA-256
fingerprints as integrity evidence rather than substituting fingerprints for mutation data. Customer
credential bytes use a non-serializable, debug-redacted, process-local carrier. The public method sets
name business operations explicitly and contain no generic lifecycle update API.

The contracts map to exactly one canonical implementation per domain: the existing manual Product,
Menu, and Customer services, plus the planned OrderService and PaymentOrchestrator. Menu contracts are
domain-owned under `App\Services\Menu` while preserving the existing manual MenuService namespace as the
future implementation location. No interface was container-bound and no controller, job, importer, model,
provider adapter, or runtime call path was cut over in this task. Payment persistence has no Order model or
Order persistence dependency; later orchestration must reach settlement through the typed
`OrderMutationFacade::settleIfPaid()` command.

`tests/Unit/Services/DomainMutationContractsTest.php` records the contract evidence: exact facade method
sets, typed command/result signatures, immutable concrete command classes, mutation/query/persistence
separation, generic-operation rejection, revision/payment/customer invariants, idempotency-key log
redaction, framework/provider/model dependency exclusions, Payment-to-Order persistence exclusion, and
the absence of premature service-container bindings. Plan 048 was inspected read-only; it currently has no
published mutation facade with which to merge, so its future branch must preserve these boundaries during
integration.

A blocking contract review identified that the first T2.7 revision exposed fingerprints without the
payloads required to execute Product, Menu, Customer, and Order mutations. The follow-up adds typed Product
and SKU definitions, deterministic import rows, Menu section/item layouts and shop overrides, Customer
profiles and ephemeral credentials, and Order drafts/lines/toppings. No array, mixed, framework model, or
generic update bag enters a facade. Product import now returns typed per-row outcomes; Payment prepare,
finalize/reconcile, refund, and provider-event operations return state/evidence-specific results; Order
create/settle and Customer merge likewise return operation-specific identities and outcomes. Reflection
tests lock every payload and materially distinct result type so the canonical service never needs an
out-of-band mutable request to fulfill its contract.

A second blocking review removed the remaining hash-only execution gaps. Every non-secret Product, Menu,
Customer profile, and Order payload now has deterministic canonical JSON and derives its SHA-256 fingerprint
from that representation; each command verifies equality at construction and rejects mismatch. Customer
credential commands expose neither raw bytes nor a reusable digest: the carrier keeps both bytes and a
per-process keyed integrity proof outside object properties and rejects serialization while redacting debug
output. Payment finalize/reconcile commands now carry `GatewayPaymentResult`, refund reconciliation carries
`GatewayRefundResult`, and provider-event processing carries `VerifiedGatewayEvent`, giving persistence the
normalized provider-neutral state, references, money/next-action evidence, verified event identity, and safe
payload evidence required to apply the mutation without an out-of-band lookup.

A final inventory-parity pass expanded those executable shapes against the current generated models,
requests and manual services. Product now carries localization, slug, tax/type/gallery/topping and
complete SKU/option state; Menu carries validity/service/master/schedule and shop product/SKU price/tax
overrides; Order carries source/contact/table-set/coupon/status, frozen pricing evidence, kitchen lifecycle
and split fields; Payment carries tender/tip/change/reference/till/split/debt data and a fingerprint-verified
refund intent; Customer carries address/tax/note/verification/linkage. The parity matrix and reflection test
make these named typed fields an explicit prerequisite for T2.9–T2.16 instead of leaving each migration task
to redesign its command payload.

Final hardening made verification capabilities process-local and issuable only by exact named final adapter
classes explicitly configured per port and scope; the default configuration is empty and fail-closed.
Customer persistence now accepts opaque authority-verified capabilities. Order pricing/offline evidence,
payment preparation/tender/refund evidence, and their internal persistence commands reject forged or
deserialized values and bind organization, branch, order, actor, correlation, idempotency, version, money,
currency, and operation-specific payload identity. Product, Menu, Customer, variant-unit, layout, and
online/offline Order routes carry explicit action discriminators so a command cannot be replayed through a
different persistence operation. Canonical command fingerprints fail closed for unsupported hidden object
state; credential identity remains process-local, keyed, non-serializable, and debug-redacted.

Main-agent review passed after adversarial forge, cross-tenant/cross-branch/cross-order, context drift,
route-replay, refund-field drift, credential-collision, illegal kitchen transition, and negative money/debt
tests. The focused T2.7 suites pass 96 tests and 2,983 assertions. The full `tests/Unit/Services` regression
passes 139 tests and 3,713 assertions with 133 known missing-`.env` fixture warnings and exit code zero.
