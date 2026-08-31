# Detailed payment and mutation-boundary test cases

This document turns every scenario in `TESTS.md` into an executable specification. A case
is not complete because an endpoint returned 2xx: tests must assert persisted state, provider-call
count/identity, ledger projection, side effects, authorization boundary, and emitted telemetry where
the case lists them.

## Shared fixtures and test rules

| Fixture | Required data |
|---|---|
| `TENANT_A` / `TENANT_B` | Separate organization, HQ org unit, brand, branch, users, devices, tills, currencies, and provider objects; identifiers must be visibly different |
| `HQ_SHOP` | Identity projection says `hq_managed`, active ownership revision, HQ-owned live/test connections, no shop-owned fallback |
| `FRANCHISE_SHOP` | Identity projection says `franchise`, one active grantee, grantee-owned connections, explicit validity window |
| `AMBIGUOUS_SHOP` | Two currently active franchise grants or unresolved Branch ↔ OrgUnit mapping |
| `STRIPE_FAKE` | Records connection/account, idempotency key, request body, call time, transaction level, response/raw state; can return success, action-required, processing, timeout, 500, or exception |
| `PAYPAY_FAKE` | Implements the same contract and records merchant/store/terminal identity; refund status is retrieval-only |
| `ORDER_1000` | Order total 1000 minor units, paid 0, one served sale item, open till, deterministic tax/stock/table fixtures |
| `OFFLINE_DEVICE` | Workstation SQLite initialized with policy revision N, fixed clock, stable local IDs, sync queue, and captured outbound HTTP |
| `UNSTABLE_PROVIDER` | Returns programmable sequence: 503 → 503 → Retry-After → success; records request IDs for idempotency/key assertions |
| `WEBHOOK_FLOOD` | 1000 webhook payload events with mixed duplicate/near-duplicate keys, out-of-order timestamp, and controlled 202/409/200 outcomes |
| `BIG_DECIMAL_FIXTURES` | JPY 0-decimal, USD 2-decimal, KWD 3-decimal fixtures with edge totals near integer max and trailing-zero cases |

All money assertions use integer minor amounts. Every provider fake fails a test on an unexpected
call. Concurrency tests use separate DB connections/processes and a barrier immediately before the
contested lock/call; sequential double invocation alone is not accepted as proof of race safety.
Every negative case asserts that provider call count, ledger count, settlement marker count, and
outbox count remain unchanged. Every API case also asserts the stable typed error code and
correlation ID, not localized prose.

The architecture suite also maintains an explicit writer manifest for Payment, Order, Product,
Menu, and Customer. A negative boundary case asserts both that CI reports the exact file/symbol/table
and that the command under test leaves every owned table and outbox unchanged.

## A — Ownership and tenant isolation

| ID | Given / When | Exact assertions |
|---|---|---|
| A1 | `HQ_SHOP`, Stripe live card option enabled; resolve for POS/VND | Selected connection owner is HQ, tenant/provider/environment/currency/channel all match, explanation identifies HQ source, no alternative queried after selection |
| A2 | `FRANCHISE_SHOP` has one active PayPay connection; resolve | Selected owner/merchant is grantee; response/snapshot contains no HQ merchant/account identifier; HQ connection repository call count is zero |
| A3 | Franchise has no ready connection; prepare payment | 422 `PAYMENT_CONNECTION_REQUIRED`; zero attempt/ledger/provider call; explanation points to shop setup and does not mention fallback |
| A4 | `AMBIGUOUS_SHOP`; resolve or prepare | 409 `PAYMENT_OWNERSHIP_UNRESOLVED`; zero payment mutation; one redacted operator alert/audit includes branch and ownership revision |
| A5 | Tenant A submits Tenant B connection/option IDs to read, assign, validate, rotate, and pay endpoints | Each returns 404/403 per concealment policy; zero secret resolution/provider call/write; audit tenant remains A |
| A6 | Same branch requested by cashier, manager, HQ admin, and device tokens | Management model/owner ID/revision are byte-identical; only permitted operations differ by authorization |
| A7 | Shop manager, cashier, and device call credential/rotation endpoints and list connection | Mutation is 403; list exposes display identity only; JSON/DOM/log contain no secret reference value or credential |
| A8 | Test connection/object/key/event is supplied to live command and vice versa | `PAYMENT_ENVIRONMENT_MISMATCH`; no retrieval/finalize; uniqueness allows same provider ID only when environment/connection scope differs |
| A9 | Tenants A/B each have a global method plus branch method; Tenant A Workstation pulls | Response/SQLite contain only A rows; B IDs/codes/names absent; repeated pull replaces deterministically without deleting A unsynced selections |

## B — Policy resolution

| ID | Given / When | Exact assertions |
|---|---|---|
| B1 | Capability, connection, HQ, shop, and device all allow card/Visa/POS | `effective=true`; selected IDs and revision/hash stable; trace contains every layer in order |
| B2 | Provider marks option restricted/degraded; resolve | `effective=false`, source `provider`, typed reason and remediation returned; saved shop/device preferences unchanged |
| B3 | HQ policy is blocked while shop/device request enable | PATCH cannot widen policy; resolver remains false with HQ source; no new revision if effective output unchanged |
| B4 | HQ allows, shop disables, then shop restores inherit | Disabled revision resolves false from shop; restore resolves HQ default; exactly two effective revisions published and audited |
| B5 | Device A disables an allowed option | A false, sibling B true, shop preview true; only A override row/revision changes |
| B6 | Shop/HQ denies and device submits enable via API or stale UI | 409 `PAYMENT_POLICY_CANNOT_WIDEN`; no override/revision; effective state stays denied |
| B7 | Ready connection becomes degraded then ready | Effective false then true; preference rows unchanged; health event publishes revisions only when effective output changes |
| B8 | Parameterized currency/channel/environment/device type mismatch | Each mismatch returns false with the matching typed reason; a fully matching control resolves true |
| B9 | Same snapshot resolved repeatedly and with DB row ordering shuffled | Canonical JSON, selected IDs, trace order, hash, and revision are identical |
| B10 | Publish same effective policy twice then change one option | Retry returns same revision/hash and one row; real change increments exactly once under concurrent publishers |
| B11 | Active-looking UUID belongs to inactive or effectively disabled method; call every create transport | 422 `PAYMENT_OPTION_DISABLED`; zero attempt/ledger/provider call across POS/Kiosk/Customer/Workstation |
| B12 | Two transactions create same global code with null branch | One succeeds, one deterministic conflict; exactly one scoped key row; duplicate pre-backfill fixture is reported and migration refuses silent deletion |

## C — Attempt lifecycle and idempotency

| ID | Given / When | Exact assertions |
|---|---|---|
| C1 | Valid command against `ORDER_1000`; pause before fake call | Committed `prepared` attempt already contains immutable amount/currency/owner/connection/option/revision/request key; transaction level at provider is zero |
| C2 | Two concurrent commands share client idempotency key | Same attempt/payment IDs returned; one provider call; one ledger row; payload mismatch on same key returns `IDEMPOTENCY_PAYLOAD_MISMATCH` |
| C3 | Provider succeeds, process crashes before finalize, reconciliation runs | No initial ledger row; retrieval uses stored connection/key; one success ledger/final settlement after recovery; attempt records recovery provenance |
| C4 | Provider call times out with unknown outcome | Attempt `reconciliation_required`; no fresh create call/key; retry retrieves first and only safely re-calls when provider proves absence |
| C5 | Provider stores 500 under idempotency key; client retries | Same provider response/key observed; state stays reconcilable, never assumed failed/no-charge; operator correlation preserved |
| C6 | Sync response and webhook finalize same attempt at barrier | One legal terminal transition, one ledger row, one settlement marker; loser is an audited idempotent no-op |
| C7 | Two separate full/split attempts race for remaining 1000 | Captured+reserved never exceeds 1000; loser rejected/canceled/refunded per provider outcome; order paid cache equals ledger |
| C8 | Data set of raw Stripe/PayPay states | Each maps to expected normalized state/next action; raw provider code/status retained; terminal state never regresses |
| C9 | Adapter capability excludes requested capture/cancel/refund | `PAYMENT_OPERATION_UNSUPPORTED`; zero provider mutation; attempt/refund state and audit explain capability/version |
| C10 | Instrument transaction level in every adapter method for create/retrieve/capture/cancel/refund | Level is zero for each network call; prepare/finalize transactions are independently observable and short |

## D — Webhook inbox and ordering

| ID | Given / When | Exact assertions |
|---|---|---|
| D1 | Valid raw body/signature for known connection | HTTP 2xx within configured budget; one verified inbox row with payload hash/redacted body; processing is queued, not executed inline |
| D2 | Missing, malformed, stale, wrong-account, and wrong-secret signatures | 400/401 typed verification error; zero inbox/process/ledger rows; security metric increments without logging secret/body |
| D3 | Same provider event ID delivered twice/concurrently | Both acknowledged; one inbox identity and one processing result; duplicate count/last-seen updated |
| D4 | Success arrives before processing/created/failure | Attempt remains success; late events recorded as ignored transitions with reason; ledger unchanged |
| D5 | Webhook and synchronous confirm race | One attempt success, one ledger row, one settlement; both requests complete safely and share correlation/provider identity |
| D6 | Processor throws transient error three times | Retry count, next-at, last typed error, and exponential schedule match config; no premature dead letter or duplicate money |
| D7 | Retry budget exhausted | Inbox `dead_letter`, alert and operator list row created; redacted error/correlation visible; no automatic unbounded retry |
| D8 | No webhook after provider success; reconciliation age threshold passes | Scheduled retrieval finalizes once; missing-webhook metric increments; later webhook is a no-op |
| D9 | PayPay refund accepted then no webhook; poll returns succeeded | Refund moves pending → succeeded only after retrieval; ledger reversal appended once; no webhook dependency |
| D10 | Operator retries dead letter twice | First retry processes/finalizes once, second no-op; actor/reason/time audited; original event/payload hash retained |

## E — Ledger, refunds, and settlement

| ID | Given / When | Exact assertions |
|---|---|---|
| E1 | Cash 1000, tendered 1200, tip 50, open till | Succeeded ledger amount 1000, change 150, tip/till/operator/receipt exact; no gateway attempt/call if internal cash adapter is provider-free |
| E2 | Manual terminal attempt through pending, then parameterized confirm/fail/expiry | Only legal transition occurs; confirm affects paid cache/settlement, fail/expiry do not; duplicate commands no-op |
| E3 | Stripe full and two-slice split | One normalized attempt and captured ledger per provider intent; amounts sum exactly; immutable connection/option snapshots match |
| E4 | Mixed success/refunded original/negative refund/pending/failed/debt-settlement rows | Projection equals sale successes minus succeeded refunds only; cached paid amount repaired to projection and never over total |
| E5 | Captured 1000; refund 200 then 300 then 500 | Three refund operations/IDs, remaining 800→500→0; original history preserved; fourth refund rejected without provider call |
| E6 | Two 700 refunds race against captured 1000 | At most 1000 accepted/called; loser typed conflict or reduced only if explicitly supported; no negative remaining balance |
| E7 | Provider refund returns pending, failed, canceled, then succeeded fixture | Ledger decreases only on succeeded; retry/reconciliation retains operation identity and exact remaining amount |
| E8 | Provider dashboard refund event/retrieval repeats | One local refund operation keyed by provider refund ID; one reversal; origin recorded as provider |
| E9 | Debt 1000 issued, settled, settlement refunded partially/fully, retried | Debt endpoint totals exact at every step; settlement rows excluded from current order paid cache; `settles_payment_id` retained on reversal |
| E10 | Data provider runs cash, terminal, Stripe sync/webhook, Kiosk, Workstation | Each produces exactly one settlement marker and identical terminal order state for equal business input |
| E11 | Equal orders across every transport with stock/table/session/email/audit enabled | Side-effect manifest IDs/counts/payload hashes match: stock, genealogy, table/session, invoice/mail, `OrderPaid`, audit, outbox |
| E12 | Call settlement twice and concurrently after paid | One stock/genealogy/email/event/outbox effect; second result returns same closed order without mutation |
| E13 | Persist captured-unledgered/refund-required then restart queue workers | Rows survive, are discoverable by reconciliation, finalize/refund using original IDs, and clear alert only after proof |
| E14 | Historical ledger row has null attempt/connection/option | Resources/reports/receipt render legacy label; authorized refund uses compatibility path; no backfill invention or null error |

## F — Workstation, POS, and Kiosk

| ID | Given / When | Exact assertions |
|---|---|---|
| F1 | Workstation pulls revision N then N+1 | SQLite holds only non-secret effective options, monotonic revision/hash/source; replay of N cannot downgrade N+1 |
| F2 | Device offline selects option and queues payment | Local row/queue freezes option, connection display identity, revision, attempt/client key, amount/currency/till; no credential stored |
| F3 | Parameterized unchanged-safe revision versus disabled/owner-changed revision on reconnect | Safe case accepted only by explicit rule; unsafe returns `PAYMENT_POLICY_STALE`, no provider call, refresh/reselection instructions |
| F4 | Queue sends payment, loses response, restarts, resends | Stable local/client IDs map to one Cloud attempt/payment/provider call; local Cloud ID converges |
| F5 | Old client sends `confirmed`, new projection returns normalized success | Compatibility translator maps once, reports client version, never accepts unknown state; removal metric records old-client usage |
| F6 | POS loads effective options with only QR enabled | Only QR renders/selects; static scan finds no cash/card allowlist; disabled direct UUID rejected by server |
| F7 | Kiosk loads effective options with only PayPay QR enabled | Only matching route/tile renders; static scan finds no fixed cash/card/QR/e-money source; unsupported deep link blocked |
| F8 | Effective list empty or becomes empty before submit | Checkout/pay controls disabled, manager action/reason shown, no blank spinner and no payment request |
| F9 | Attempt prepared at revision N; N+1 disables/changes owner before provider response | Finalize uses frozen N connection/option/owner; no new charge; subsequent attempt must use N+1 |
| F10 | Offline split/refund/debt payment syncs then reports/receipt load | Cloud/local totals, labels, till/Z report, debt balance, and receipt identity match after convergence |
| F11 | Device A customizes then resets | B unchanged; A trace changes source device→shop; reset deletes override and publishes correct effective revision |
| F12 | Inspect SQLite, sync payload, logs, crash report, and API response | No secret/ref/PAN/CVV/auth header; allowlisted display identity and redacted token suffix only |

## G — Admin API and UI

| ID | Given / When | Exact assertions |
|---|---|---|
| G1 | HQ admin opens connection list/detail across desktop/mobile | Owner/environment/display identity/capability/health/coverage/last validation present and correctly localized; no secret fields |
| G2 | Provider callback delivered twice, refreshed, and opened in second tab | One connection/onboarding session; deterministic final route/state; nonce/state validated and consumed once |
| G3 | Fixture each pending/restricted/degraded/revoked/transient state | Mutually exclusive UI state, correct action/retry policy, API typed code, and no destructive action where unsafe |
| G4 | Submit invalid credential then inspect response, DOM, URL, console/network logs | Secret absent everywhere after request; field cleared/masked; server redaction test passes |
| G5 | Disconnect a connection used by three shops/five devices | Dialog lists exact impact; unsafe confirm rejected; approved staged disconnect publishes revisions and audit |
| G6 | Set HQ default-on/default-off/blocked | Labels and effective preview differ clearly; blocked control cannot be overridden downstream; keyboard/screen reader names correct |
| G7 | HQ-managed shop manager opens payment settings | Connection identity read-only; rotate/disconnect/credential controls absent; option narrowing permitted per role |
| G8 | Franchise has no connection | Setup prerequisite CTA and owner explanation shown immediately; no infinite loading; unavailable options disabled |
| G9 | Shop option rows cover allowed, shop-off, HQ-blocked, provider-restricted | Each shows capability, preference, effective state, source/reason and permitted action consistently |
| G10 | Device inherit→customize→disable→reset flow | Deep link stable, preview updates, save conflict handled, reset restores shop source, browser back/refresh preserves route |
| G11 | Inject 401/403/prerequisite/422/409/timeout/provider-action | Distinct title/action/retry behavior; validation retains non-secret input; auth errors do not masquerade as empty state |
| G12 | Delay/fail/empty/data combinations via mocked requests | Exactly one primary state renders; stale data marked during refresh; error cannot be hidden by empty state |
| G13 | Test 1440px, 768px, 375px plus direct deep links/back/forward | Desktop local nav, mobile compact tabs, focus/scroll restoration, URL/history and selected section correct |
| G14 | Keyboard-only and accessibility-tree test switches/dialog/errors | Visible focus, programmatic names/state, focus trap/return, error summary links, non-color status, WCAG contrast |
| G15 | Snapshot Japanese/English/Vietnamese at 320/375/768/1440px with long labels | No clipping/overlap/horizontal page scroll; semantic content unchanged; fallback/missing keys fail test |

## H — Security, observability, migration, and provider proof

| ID | Given / When | Exact assertions |
|---|---|---|
| H1 | Submit PAN/CVV-like keys/values through every payment/admin endpoint | 422 `PAYMENT_SENSITIVE_DATA_REJECTED`; provider/storage/log/error capture contains no value; security counter increments |
| H2 | Resolve correct/wrong tenant/environment, rotate, revoke, dual-read webhook version | Only authorized exact scope resolves; audit stores actor/version/fingerprint not value; revoked version unavailable after window |
| H3 | Provider fixture contains tokens, authorization, card/customer fields and exception | Structured logs/metrics/traces/dead-letter preview are redacted; correlation/operation IDs retained |
| H4 | Replay inside/outside timestamp tolerance during webhook secret rotation | Accepted versions/window exact; stale/wrong rejected; duplicate processing once; old secret rejected after cutoff |
| H5 | Seed stuck attempt, dead letter, failed refund, ledger drift, stale policy lag | Each metric has expected labels/value and alert threshold; no high-cardinality secret/customer/provider payload labels |
| H6 | Run reconciliation twice, concurrently, and after mid-chunk crash | Chunk checkpoint resumes, overlap lock prevents duplicate worker, second clean run no-op, results/counters/audit deterministic |
| H7 | Evaluate SBPS capability immediately before/at/after Tempo's conservative 2026-09-30 JST boundary | Partial operation allowed only before the boundary for a valid dated contract; at/after returns unsupported unless provider-confirmed newer capability explicitly overrides |
| H8 | Run shared suite against Stripe fake and `PAYPAY_FAKE` | Both satisfy create/retrieve/refund/idempotency/redaction contract; diff proves no orchestrator/ledger/settlement conditional by provider |
| H9 | Tenant fixture includes corroborated full/split Stripe originals, ambiguous method/reference signals, duplicate PI (including another tenant), malformed metadata/reference/status, already-mapped conflict/consistent rows, linked refund, orphan order PI, nonexistent organization, foreign organization/branch scope, crash/restart, active/paused apply plus same-run report, invalid `max-chunks` string/zero/negative values, overlapping same-run worker and release/takeover, completed rerun, lower-UUID same-timestamp/backdated post-snapshot inserts, an initially empty scope, permissive pre-existing artifact directories, a read/write-split configuration, a mutable PaymentMethod/orphan catalog, more than one evidence-query batch, JPY/USD/KWD amounts with trailing/excess precision and integer overflow, and optional exact evidence | Default report makes no DB/audit write; invalid scope/limit and overlap exit before audit/write; report mode takes the same no-TTL primary advisory lock and cannot open/delete an apply manifest; paused same-run report is rejected with the artifact byte-identical; the lock releases on owner exit; every start/completion/checkpoint/aggregate control read uses primary; apply changes only null corroborated snapshots and rejects a branch-scoped method on another branch; it never infers environment/ownership or creates gateway/attempt rows; row update plus checkpoint is atomic; restart reads an integrity-checked 0600 private SQLite candidate manifest under a re-verified 0700 directory containing only ledger UUID/one-way provider hash/duplicate boolean, while audit contains count/hash and no raw PI; repeatable-read UUID-keyset membership excludes all post-snapshot inserts including the empty-start case without OFFSET degradation; candidate evidence queries are bounded and duplicate detection uses one global UUID-keyset scan plus a private hash join, never repeated unindexed reference scans; completion deletes the manifest and rerun appends no audit; 0/2/3-decimal conversion is exact without float; before/after counts, ledger totals and reconciliation hash are deterministic |
| H10 | Kill traffic after provider success, webhook backlog, policy publish, and offline queue | Rollback preserves attempt/provider identity and inbox/queue data; old path cannot double write; recovery finishes with zero drift |

## I — Canonical domain mutation boundaries

| ID | Given / When | Exact assertions |
|---|---|---|
| I1 | Seed fixtures containing static, instance, relationship, query-builder/raw-table and generated-service writes for each aggregate; run architecture guard | Every mutation reports exact aggregate/file/line/symbol/table; read-only queries pass; all unlisted writes fail CI and execute no application code |
| I2 | Create/update/delete and import the same Product with SKUs, options, values and categories through old fixtures and canonical commands | Only `ProductService` persistence port writes; DB graph, validation errors, authorization, events, audit and importer row results match; rollback leaves no orphan relation |
| I3 | Create/update/activate a Menu, sections, schedules, placements and shop overrides from HQ/shop/maintenance surfaces | Only `MenuService` writes; tenant scope and resulting menu graph match; effective-menu/cache revision publishes exactly once; retry is idempotent |
| I4 | Register Customer, update profile/password from customer/admin, then replay a Workstation mutation | `CustomerAuthService` and transports make zero Customer writes; `CustomerService` writes once; password/token redacted, tenant/branch/audit exact, replay returns same identity |
| I5 | Submit equivalent dine-in/takeaway orders through Customer QR, POS, Kiosk and Workstation | Every path calls typed `OrderService` command; one Order graph per idempotency key; code, table/till/customer, price/tax/catalog snapshots and events match expected transport policy |
| I6 | Parameterize add/edit/void/refund item, topping replace, checkout, cancel/void, table merge and lifecycle transition across transports | No controller/sync direct mutation; legal transitions and authorization succeed once; illegal/generic-field transitions return typed error and leave all Order tables/outbox unchanged |
| I7 | Finalize cash, Stripe sync, webhook and reconciliation payments while instrumenting Order writes | Ledger finalizes once, then `OrderService::settleIfPaid()` is the only Order command; Payment namespaces import no Order persistence model; terminal state/side effects match |
| I8 | Barrier concurrent payment finalizers, item/lifecycle mutation and repeated settlement on separate DB connections | Documented lock order holds, no deadlock/lost update; one ledger result, one legal Order terminal transition and one of each irreversible side effect |
| I9 | Close an order, then edit/delete Product/Menu placement and update/merge Customer profile | Historical order item name/SKU/price/tax/menu/customer display snapshot, receipt and payment identity remain byte-identical; current catalog/profile projections change normally |
| I10 | Submit arbitrary immutable/status/tenant/price fields through public service/controller payloads | No generic `update(array)` bypass; command allowlist rejects fields with 422/409/403 as appropriate; no owned-table or event mutation |
| I11 | Analyze service dependency graph and execute cross-domain Product→Menu→Order→Payment journey | Graph is acyclic; cross-domain mutation occurs only through declared command/outbox edge; no adjacent domain Model import exists in mutation handler |
| I12 | Dry-run/apply/restart a maintenance backfill, then attempt to resolve its persistence port from runtime container | Dry run writes zero and reports exact plan; apply is tenant-scoped/checkpointed/audited/idempotent; runtime resolution is impossible outside command scope |
| I13 | Touch each legacy writer in a task, retain/add/expire allowlist entries, then run CI | Same-commit removal passes; new entry and expired gate fail with owner/task/reason diagnostics; allowlist monotonically decreases to zero |
| I14 | Run Omnify 5.9.3 generation check, scan consumers, remove generated/compatibility service, boot container/routes/queues | No service regenerated; pre-delete consumer count zero; container resolves canonical facade; route/job/listener tests pass with no class-not-found fallback |
| I15 | Replay importer, console status migration, scheduled job, webhook, listener and sync fixtures before/after migration | Persisted graph, error/result contract, audit/events/outbox and retry identity match; each moved implementation delegates and owns no Model write |
| I16 | Create and mutate Order/Customer/Payment offline, lose Cloud response, restart and replay twice | Stable local IDs map to one Cloud aggregate/payment; canonical services dedupe; local/Cloud state converges; no duplicate money, item, customer or side effect |
| I17 | Inject exception at every nested Product/Menu/Customer/Order/Payment write and outbox boundary | Documented atomic unit fully rolls back or retains committed aggregate plus recoverable outbox marker; no orphan or half-updated relation/credential/ledger row |
| I18 | Run final strict scan across controllers, requests, imports, jobs, commands, listeners, observers, webhooks, services and sync | Zero runtime direct writers for five aggregate families; only reviewed migration/factory/seeder/maintenance exceptions remain with evidence |

## J — Reliability, load, and operability

| ID | Given / When | Exact assertions |
|---|---|---|
| J1 | `UNSTABLE_PROVIDER` fails repeatedly for same connection during create attempts | Circuit breaker transitions to open after policy-threshold; attempts return typed `PAYMENT_PROVIDER_CIRCUIT_OPEN`; zero additional provider calls during open window; health event and admin notice recorded |
| J2 | Provider sends `Retry-After: 120` on 429 and create is retried | Provider receive count includes only one immediate request; next check is scheduled after >=120s; no duplicate charges while waiting; failure reason includes provider-supplied retry hint |
| J3 | Retry policy tested with 1, 2, 4, 8 second schedule over a single attempt | Next-check timestamps monotonic with jitter within documented bounds; total attempts <= configured max + 1; no immediate tight-loop retry logs |
| J4 | `WEBHOOK_FLOOD` sends repeated event IDs for same attempt with interleaving near-duplicates | One `verified` inbox row per unique provider event ID; one transition application; duplicate/near-duplicate rows are flagged only as duplicates and not executed against provider |
| J5 | Kill reconcile worker after writing half of chunk and restart | Rows from already-read chunk are idempotently skipped; new run resumes from checkpoint; total processed count and settlement marker count are unchanged |
| J6 | Start two reconciliation jobs with same scope simultaneously | Advisory lock keeps one runner active; second runner returns lock/no-op and exits before processing rows |
| J7 | Provider success callback never arrives; 5 minutes pass; provider retrieval job runs on schedule | Attempts move from `reconciliation_required` to proven terminal state via retrieval; dead-letter only after configured terminal timeout and operator visibility is present |
| J8 | Retry request with same idempotency key but modified `capture_amount` | Same response code/type for `IDEMPOTENCY_PAYLOAD_MISMATCH`; no created attempt, provider call, or row mutation |
| J9 | API receives zero/negative capture request on POS, Kiosk, and Workstation | 422 domain typed error; zero provider calls; error payload includes supported min-amount rule; audit log contains correlation and actor only |
| J10 | Create captures and refunds with `BIG_DECIMAL_FIXTURES` including high-value totals | Integer arithmetic remains exact (no float); currency-specific rounding exact; ledger/reconciliation totals match exact conversion |
| J11 | Trace a request through payment prepare → provider call → webhook processing → reconciliation | Same correlation/request ID appears in provider row metadata, inbox row headers, reconciliation logs; mismatch IDs are treated as observability bug in test |
| J12 | Activate kill-switch while 5 in-flight attempts are open | New start endpoints return `PAYMENT_ROUTING_DISABLED`; existing attempts remain discoverable and continue to reconcile; no provider identity is regenerated |
| J13 | Disable kill-switch after 3 minutes and replay protected in-flight attempts | Attempts resume with original IDs, existing attempt/payment IDs, and same provider keys; no duplicate operation created due to re-route |
| J14 | POS/Kiosk receive stale policy revision update while Workstation uses same endpoint and data | Both clients receive identical safe/unsafe stale handling rules and same status code (`PAYMENT_POLICY_STALE`), same user message |
| J15 | Start partial refund and debt settlement concurrently on same order | One canonical side-effect ordering is preserved: refund does not create settlement inversion markers; stock/migration/audit remain monotonic; paid cache reflects immutable projection |
| J16 | Toggle payment route feature flag from new↔legacy and run reconciliation on in-flight order | Route-change action is written to rollback audit; operation identity preserved; no fallback operation is created for already-owned attempts |

## Current pre-refactor characterization evidence

The following tests are the frozen evidence used by T0.5 before any writer is moved. They are not a
substitute for the new provider-neutral scenarios above.

| Boundary | Executable evidence | What is frozen |
|---|---|---|
| Canonical create/confirm/fail | `backend/tests/Feature/Shop/OrderPaymentIdempotencyTest.php`, `Customer/PaymentConfirmCloseRaceTest.php`, `Customer/PaymentFailLockTest.php`, `Payment/PaymentArchitectureCharacterizationTest.php` | Idempotency, lock transition, partial/final events, close behavior |
| Stripe direct writer/refund/webhook | `Customer/StripePaymentTest.php`, `StripeRefundTest.php`, `StripeWebhookTest.php` | Current intent/refund units, sync+webhook dedupe, signature behavior and direct ledger shape |
| Debt and refund projection | `Shop/DebtPaymentFlowTest.php`, `Audit/Issue821DebtRefundTest.php`, `Payment/PaymentArchitectureCharacterizationTest.php` | Debt exclusion, settlement/refund recovery, positive-original plus negative-refund arithmetic |
| Till attribution/reporting | `Pos/Till/RefundReconcileCrossSessionTest.php`, `OrderTillSessionAttributionTest.php`, `TillCloseNetReconcileTest.php` | Sale/refund shift attribution and reconciliation totals |
| Workstation replay | `Workstation/WorkstationPaymentsTest.php` plus Go `internal/handler/local_pos_payment_*`, `internal/service/sync_reconcile_payments_test.go`, and `sync_push_regressions_test.go` | Local status/IDs, create retry, Cloud mapping, refund replay, dead-letter recovery |

T0.5 baseline command passed 102 tests with 336 assertions. After the independent-review fixture
guard, the new characterization file passes 4 tests with 33 assertions. Existing suite warnings
and Stripe fake undefined-property notices are
recorded as cleanup debt; there were no test failures after a valid process-local test `APP_KEY` was
provided.

Targeted Workstation payment/refund/till/sync/dead-letter tests passed in all three Go packages
(`internal/handler`, `internal/service`, and `internal/store`). The broader unfiltered Go service
package is not green at this pinned submodule commit: 16 existing receipt/kitchen/red-invoice print
format tests fail on Vietnamese/ESC-POS text assertions. They are outside the payment lifecycle
change and were not modified or hidden; full release verification must either fix or re-baseline
those print failures before treating the entire Workstation package as green.
