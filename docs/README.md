---
title: Documentation Index
category: reference
tags: [index, navigation, documentation]
summary: Navigation index for every document under docs/ — grouped by business domain, with a one-line description each.
related: [contributing/documentation.md]
---

# Documentation

> TempoFast — a restaurant and store management platform: multiple brands, device
> pairing, and an offline-first workstation.

This index lists **every single file** in `docs/`. Adding, deleting or renaming a
doc without updating this file breaks the index and forces the next person to `grep`
the whole tree — which is exactly how 41 of 72 files once disappeared from it
(#1322).

**Grouped by business domain, not by Diataxis.** The directories still follow
`guide/` · `explanation/` · `reference/` · `contributing/` per the
[documentation standard](contributing/documentation.md), but people look things up
by *"I am working on payments"*, not by *"I need an explanation"*. The real tree
also has five directories outside the standard (`qa/`, `runbooks/`, `operations/`,
`evidence/`, `decisions/`), so the four Diataxis groups no longer describe the
structure.

`decisions/` is the newest and is deliberately its own directory rather than more
files under `explanation/`: an ADR is **append-only and numbered** — superseded
records stay so the reasoning survives — which is a different contract from an
explanation page that gets edited to stay current. See
[decisions/README.md](decisions/README.md).

**Documentation elsewhere** — this set is only the umbrella's:

| Where | Content |
|---|---|
| [`backend/docs/`](../backend/docs/README.md) | Laravel: the business domains, the API reference, and the rules for writing controllers, services, policies, routes and tests. Wired in directly by `backend/CLAUDE.md` and `backend/.claude/docs-manifest.md` |
| `workstation/docs/` (34 md) · `app/kiosk/docs/` (15) · `app/kds/docs/` (10) · `web/pos/docs/` (4) · `app/tms/docs/` (3) · `app/handy/docs/` (3) · `web/customer/docs/` (3) | Each app's own documentation, in-tree. `web/admin/` has no `docs/` — its rules live in `web/admin/AGENTS.md` |
| `plans/` | Implementation plans, numbered plan-NNN |

---

## Start here

| Doc | Content |
|---|---|
| [Setup with Docker](guide/setup-docker.md) | Bring up the whole dev stack with `docker compose up` — no PHP or MySQL on the host. **The canonical copy**; `backend/docs` only points here |
| [Local config vs production](guide/local-config.md) | Every flag, port and env file, plus the boundary that must never be crossed in a commit |
| [SSO Authentication](guide/sso-authentication.md) | Setting up and testing the SSO sign-in flow for the backend and admin-web: the dev bypass, the hard IdP requirements, and common incidents |
| [API as Boundary](explanation/api-as-boundary.md) | Why every client goes through the API and no client touches the database directly |
| [Module boundaries](explanation/module-boundaries.md) | The ownership ledger for nine bounded contexts, the coupling measurement (1,218 edges at Phase 0, 805 after six reclassification stages — 75% of it was never debt), and the Deptrac ratchet that keeps the number going down (#962) |
| [Architecture decision records](decisions/README.md) | Where architecture decisions live, the MADR format, and when a change needs an ADR instead of a doc edit |
| [ADR 0001 — Modular monolith](decisions/0001-modular-monolith.md) | One deployable and one database; the dependency rule, event policy and transaction ownership; and the five conditions that must ALL hold before a module becomes its own service |
| [ADR 0002 — Đồng bộ danh tính Platform → Tempo](decisions/0002-platform-tempo-identity-sync.md) | Accepted — outbox trên Platform + SNS fanout + SQS per-consumer, hợp đồng SCIM 2.0 / OpenID CAEP; vì sao KHÔNG Kafka, vì sao KHÔNG tự dựng broker trên VPS, và vì sao phần khó là bắt cho hết đường ghi phía Platform chứ không phải transport |
| [Operational baseline](operations/architecture-baseline.md) | The DORA + golden-signal numbers #962 will be judged against, measured 2026-08-01 — including a 47% CI red rate and a 41% deploy-attempt failure rate |
| [Branch isolation](explanation/branch-isolation.md) | The recorded decision on isolating data per branch (#904) |
| [Observability](explanation/observability.md) | Sentry, CSP and logging — the design and how it is deployed |

## Ways of working

| Doc | Content |
|---|---|
| [The automated issue loop (tal)](guide/agent-issue-loop.md) | **The mechanism**: an atomic lease on a git ref, the state machine, and the batched merge gate |
| [Running the full suite so the result is provable](guide/full-suite.md) | Why `vendor/bin/pest` on the shared tree cannot prove what it looks like it proves — and the runner that pins a commit, verifies every cross-tree file the golden tests read, and **reconciles discovered tests against executed tests** |
| [The issue-loop skills](guide/agent-loop-skills.md) | **The practice**: `issue-work` / `issue-review` — the roles, which rules the machine enforces and which are only words, the eight pitfalls already paid for, the incident runbook, and running the loop under Claude Code or Codex |
| [Cổng xanh/đỏ vì nó KHÔNG CHẠY](guide/cong-xanh-do-vi-khong-chay.md) | Năm lớp gặp đủ trong một ngày — `paths:` không phủ thứ guard canh, guard mồ côi, hết quota Actions (0 step, không log), tracker nói "xong" trong khi `dev` chưa có gì, và máy chạy cổng hỏng (đỏ ngắt quãng, đọc y hệt flake). Kèm bước tra nói thật cho từng lớp |
| [Khôi phục manifest trang tải — chạy tay](guide/khoi-phuc-manifest-workstation.md) | Đường lùi của quán, **không phụ thuộc CI**: sáu bước từ kéo manifest đang phục vụ, kiểm kê đĩa production bằng `stat`+`sha256sum`, dựng bản mới, tới đối chiếu lại. Fleet là hai máy Windows cài tay nên đây là bản đồ duy nhất khi cần lùi. Kèm bảng version→commit và hai nguồn tra đúng |
| [Luật làm việc — ghi công, commit, PR](contributing/work-policy.md) | KHÔNG ghi công Claude ở bất cứ đâu (commit, PR, docs). Chốt bởi chủ dự án 2026-08-14; cưỡng chế phía repo bằng hook `commit-msg`, còn nguồn sinh trailer nằm NGOÀI repo này |
| [Documentation standards](contributing/documentation.md) | Diataxis, frontmatter, templates and the review checklist. **The canonical copy for the whole monorepo** |
| [Service Layer Patterns](../backend/docs/contributing/service.md) | Transaction boundaries, pessimistic locking, avoiding N+1, nullable audit columns — kept in `backend/docs` because they are Laravel rules (a [pointer](contributing/service.md) remains here for old links) |
| [Translatable Forms](contributing/translatable-forms.md) | Building admin forms that edit multilingual content |
| [Emitting Notifications](contributing/emitting-notifications.md) | Wiring a domain event into the notification platform: the dispatch contract, idempotency, and the morph requirements |
| [Locale fallback fill](contributing/i18n-locale-fill.md) | How partially filled locales are backfilled |

## Payments and money

**The way in: [Payments overview](guide/payments-overview.md)** — a "to learn X, read
Y" table for all twelve docs below, opening with one sentence distinguishing a
gateway from a tender. For architectural depth, continue to
[Payment topology & tender model](guide/payment-topology-and-tender-model.md).

| Doc | Content |
|---|---|
| [Bật thanh toán trên production](guide/payment-go-live.md) | Runbook go-live: orchestrator đã mặc định BẬT (không có thang rollout), key Stripe/PayPay đổ vào đâu, cách tự kiểm bằng một lệnh curl, đường lùi một dòng env, quy trình soak workstation offline |
| [Payment topology & tender model](guide/payment-topology-and-tender-model.md) | The shapes in which a store takes money (a cloud-only POS, customer-web Stripe, paying at the counter, the workstation LAN, the 釣銭機) and the plan-048 cutover map |
| [Tender configuration](guide/tender-configuration.md) | The vendor-neutral tender model (#1156): org-level vocabulary → each device's `metadata.accepts` → per-branch toggles |
| [Gateway settlement & payout](guide/gateway-settlement.md) | The store's sub-ledger against the gateway (plan-050): real per-transaction fees, two-way payout reconciliation, and the age of stuck payouts |
| [PayPay certification evidence](guide/payment-gateway-paypay-certification.md) | The PayPay gateway certification file (plan-047 Gate 8) |
| [PayPay dynamic QR (customer-web)](guide/paypay-customer-web-qr.md) | The PayPay dynamic QR flow on the guest side |
| [Stripe Terminal (card_present)](guide/stripe-terminal-card-present.md) | Server-driven card payments, fail-closed pending certification |
| [POS card terminal — Verifone P400](guide/pos-card-terminal-p400-vesca.md) | The P400 card reader through VescaJS |
| [釣銭機 (Glory) adapter](guide/cash-changer-glory-adapter.md) | The design and interface of the change machine |
| [Glory YRT-R08-MN easy-interface spec (PDF)](reference/vendor/glory/YRT-R08-MN-easy-interface-spec.pdf) | Vendor source: 簡単インターフェース仕様書 — network/RAS §3.3, Web API |
| [Async payment methods](guide/async-payment-methods.md) | Konbini / 銀行振込: a pending row awaiting the webhook, with the reaper cancelling the intent first |
| [Takeaway payment policy](guide/takeaway-payment-policy.md) | The takeaway payment policy plus email and phone validation (plan-035) |
| [Money ledger architecture](explanation/money-ledger-architecture.md) | ADR #1151 — one sub-ledger per domain; `order_payments` is AR and takes no further transaction types; suppliers get their own AP module; the GL is deliberately deferred |
| [Payment gateway architecture proof](explanation/payment-gateway-architecture-proof.md) | Plan-047 Gate 8 (#968 T8.4) — the measurement showing a new provider is additive, the exact files an adapter touches, the one residual provider conditional in the compat layer, and the provider gaps still open (SBPS, PayPay certification, Stripe Terminal) |
| [Dine-in Stripe payment logic](explanation/dine-in-stripe-payment-logic.md) | The Stripe money flow for dine-in orders |
| [Payment Gateways API](reference/api-payment-gateways.md) | Gateway administration, store and device policy, effective options, and the runtime payment commands |
| [Payment Methods API](reference/api-payment-methods.md) | **Deprecated** — the old tender list, sunset 2027-01-01; use effective options instead |

## Cashier shifts and tills

| Doc | Content |
|---|---|
| [Cashier shift recovery](guide/cashier-shift-recovery.md) | The shift state machine, the three exit doors for a stuck shift, gap-payment reconciliation, handovers and settling a chain of shifts (plan-030/031/032/044/046) |
| [Manager till tracking](guide/manager-till-tracking.md) | The manager's till-monitoring runbook |
| [Cash device observation](guide/cash-device-observation.md) | Ba sổ quan sát máy 釣銭機 ở Cloud (lượt thu **kể cả lượt hỏng**, 在高, sự cố) + đối soát BA CHÂN sổ ↔ MÁY ↔ người đếm; ngưỡng lệch theo brand (cụm #2876) |

## Tax and compliance

| Doc | Content |
|---|---|
| [Tax types](guide/tax-types.md) | 消費税 by tax type (軽減税率 / インボイス): the resolution order, rounding per rate group, and the backfill |
| [Tenant provisioning](guide/tenant-provisioning.md) | What a new brand/branch must carry before it can trade, the four entrypoints into one idempotent baseline, and lazy-vs-baseline (#2320) |
| [Consumption tax — the operations handbook](guide/thue-tieu-thu-van-hanh.md) | The operations-facing version of the document above |
| [Compliance profiles](guide/compliance-profiles.md) | Compliance profiles by operating country (JP/VN) |
| [Split bill](guide/split-bill.md) | Chia bill là chia HÌNH THỨC THANH TOÁN, không đổi order: ba chế độ `even`/`by_items`/`by_amount`, một từ vựng cho mọi app, và `split_mode` nằm trong chữ ký đơn offline (#2856, #2860) |

## Printing

| Doc | Content |
|---|---|
| [Printing — ledger, capability, reprint](guide/printing.md) | The `print_jobs` journal, the per-kind retry matrix and TTL, printer capability profiles, and the money-document reprint gate (plan-052) |
| [Print template registry](guide/print-templates.md) | Three-tier print templates (system → brand → shop), per-field merging, immutable versions, and checksummed sync DOWN (plan-053) |
| [Print template JSON — tra cứu cấu hình](guide/print-template-json.md) | Định dạng `definition`: envelope, 6 loại block, 3 mức quyền của 46 block, hai allow-list, 24 mã lỗi validate và các lỗi thường gặp |
| [POS bill printing](reference/pos-bill-printing.md) | The specification for printing a bill on the POS |
| [Workstation serves pos-web at /pos](guide/workstation-serves-pos-web.md) | Why the workstation embeds and serves the pos-web bundle same-origin over LAN http, the build/embed pipeline, the route-parity manifest, and the multi-machine shop setup (#1169) |
| [Workstation Cloud API — feed pull-DOWN](reference/workstation-cloud-api.md) | The `/api/v1/workstation/*` endpoint groups, how to COUNT them at source instead of trusting a copied number, why pairing is not in this namespace, and the endpoints that were never implemented (#2303) |
| [Deploy web lên Amplify — hồ sơ trước khi gộp monorepo](reference/deploy-web-amplify.md) | Amplify app ids, production domains, secrets, IAM and the trigger chain for the three web apps — recorded before the child repos are archived, plus the trap that Amplify pulls source from the repo it is attached to (#2306) |
| [Dev environment — new web app, pcov coverage](guide/dev-environment.md) | The six steps to add a web app to the pnpm workspace, and the three non-obvious pcov decisions (flag off by default, Herd ini path, .so copied out of Cellar) (#2303) |
| [Workstation sync recovery](guide/workstation-sync-recovery.md) | The `sync_queue` dead-letter states, the seven `dead_letter_reason` codes, the three recovery paths in the SyncRecovery UI, and what heals automatically (#2195) |
| [Allowlist log máy trạm](reference/workstation-log-allowlist.md) | Bảng `message → attr được phép` mà CẢ HAI đầu cùng đọc khi kéo log máy trạm về Cloud: máy trạm lọc trước khi gửi, Cloud kiểm lại lúc nhận; vì sao allowlist chứ không blocklist, và vì sao đợt đầu không khai trường lỗi tự do (#2901) |

## Orders

| Doc | Content |
|---|---|
| [Order Domain](explanation/order-domain.md) | Order types (spot, dine_in, takeaway), the status lifecycle, and the table-assignment flow |
| [Split-by-items](explanation/split-by-items.md) | Splitting a bill by item: the metadata shape, the four 422 codes, and the rounding contract (plan-033) |
| [Share-bill dine-in](explanation/share-bill-dine-in.md) | Propagating a bill split from customer-web to the kiosk |
| [Item edit & void policy](guide/item-edit-and-void-policy.md) | Items editable only while pending, an immutable SKU, and voids requiring a real reason (#1148) |
| [Offline-order evidence](guide/offline-order-evidence.md) | How Cloud decides whether to believe the money a device took while offline (#1092) |
| [Orders API](reference/api-orders.md) | The store-scoped order endpoint specification |

## Catalog, menus and products

| Doc | Content |
|---|---|
| [HQ Catalog & Menu workflow](guide/hq-catalog-menu-review.md) | Building and approving the catalog and menus at HQ |
| [Approval Workflow](explanation/approval-workflow.md) | The shared four-step approval state machine used by Recipe and Product |
| [Topping Domain](explanation/topping-domain.md) | The five topping entities, the selection rules, the menu guards, and the outstanding Phase 2 work |
| [SKU Expand Workflow](explanation/sku-expand-workflow.md) | Expanding SKU variants — the analysis and the fix plan |
| [Topping Groups API](reference/api-topping-groups.md) | The 17 HQ endpoints for topping groups, items, per-SKU price overrides and product assignment |
| [Allergen Data Model](reference/allergen-data-model.md) | The allergen entities, the `material_allergens` pivot, and the recipe rollup |
| [Menu localization production gate](operations/menu-localization-production-gate.md) | The menu multilingual gate before going to production |
| [Menu Set Timeout button](explanation/menu-set-timeout-button.md) | Design record for the cart grace period when a menu schedule ends — `cart_timeout_minutes`, shipped |
| [Menu schedules — days and window](guide/menu-campaign-window.md) | Which days a schedule covers (weekly / monthly / named dates, #1979) inside a calendar window every surface now applies (#1970 reverses #1237), what a shop may override, and the four readers that must stay in step |

## Devices and client apps

| Doc | Content |
|---|---|
| [Device & payment management](guide/device-and-payment-management.md) | Device classification and the payment options available per device |
| [Device Management & TMS API](device-management.md) | The device lifecycle, pairing, and the TMS endpoints. ⚠️ **Out of date** — being fixed in #1323 |
| [Setup KDS Device](guide/setup-kds-device.md) | Provisioning a kitchen tablet: pairing, installing the PWA, troubleshooting |
| [KDS Domain](explanation/kds-domain.md) | The kitchen item lifecycle, the state machine, the Reverb realtime broadcast, and convergence across several machines |
| [Devices Shared Infrastructure API](reference/api-devices.md) | Three device-neutral endpoints: identity verification, Reverb configuration, and broadcast channel auth |
| [Kiosk API](reference/api-kiosk.md) | The self-service endpoints: pairing, listing orders, creating and polling a payment |
| [KDS API](reference/api-kds.md) | The kitchen endpoints: identity, active orders, and idempotent item bumps |
| [Customers API](reference/api-customers.md) | The store-scoped customer endpoints, including upsert from the POS and the outstanding-debt list |
| [Delete-guard 409 codes](reference/api-delete-guards.md) | Every 409 code returned when a DELETE is blocked — plan-042 open-order guards plus the catalog IN_USE family, the non-uniform JSON keys, and what the client should do (#2195) |
| [Customer email verification](guide/customer-email-verification.md) | Why a self-registered customer cannot log in until they follow the emailed link, and the backfill that must run at deploy (#1680) |
| [POS-web direct-to-cloud auth](explanation/pos-web-cloud-auth.md) | Design record for authenticating pos-web straight against Cloud on `/api/v1/pos/*` instead of only through the workstation LAN — shipped |
| [Workstation role & failover](explanation/workstation-role-and-failover.md) | ADR #2689 — workstation stays an offline-capable LAN hub (never a dumb agent), print jobs go push-with-a-poll-floor, and the LAN circuit breaker trips on three consecutive failures with an automatic half-open probe; the money path still owes an exposure cap |
| [Cloud-first — kiểm kê vai của workstation](explanation/workstation-cloud-first-survey.md) | Bản ghi khảo sát #2210 — mỗi vai của workstation kèm lệnh đo lại được, vai nào đã ở Cloud (ranh giới `cloudOnlyPOSRoutes` + rào parity manifest), vai nào KHÔNG chuyển được (Cloud không mở socket vào LAN, bán offline có union ĐÓNG chặn ở pos-web, same-origin http, mDNS), và năm thứ **chưa đo** |

## Notifications

| Doc | Content |
|---|---|
| [Notification Platform](explanation/notifications.md) | A normalized two-table inbox, polymorphic actor/subject/recipient through the morph map, dispatch only through the service, and the HQ audit (plan-008) |
| [Notification Rules](explanation/notification-rules.md) | The workflow-driven notification rule builder |
| [Notifications API](reference/notifications-api.md) | Your own inbox (`/me/notifications/*`) and the HQ audit (`/hq/{brandSlug}/notifications/*`) |
| [Cloud realtime — `BROADCAST_CONNECTION=log`](guide/realtime-broadcast-state.md) | Why every `broadcast()` on production stops at a log line, why the per-brand Reverb credentials are dead configuration, the nine events falling there and what each one actually loses, the three places still listening for Cloud realtime, and the checklist for turning it on (#2565) |

## Multilingual content

| Doc | Content |
|---|---|
| [Translation Workflow (full stack)](explanation/translatable-workflow.md) | The path multilingual content takes from the form → the API → Astrotomic → the database → the screen |

## Business time

| Doc | Content |
|---|---|
| [Business time — one clock](guide/business-time.md) | Business time is always `branches.timezone`, the `BusinessClock` helper, and the 3-timezone test matrix (#1091) |

## Store operations

Written for operators, not engineers.

| Doc | Content |
|---|---|
| [Store operations handbook](guide/van-hanh-cua-hang.md) | **The contents page** — a 33.5k-word handbook split into 11 parts by reader role (#1324); start here and open the part you need |
| [New-store rollout](guide/van-hanh/rollout-quan-moi.md) | §0-2 understanding the system, preparing, and the A-to-Z route |
| [Admin — settings](guide/van-hanh/admin-cai-dat.md) | §3-4 signing in, the menu map, the six settings tabs |
| [Admin — menus, tables, devices](guide/van-hanh/admin-menu-ban-thiet-bi.md) | §5-7 menus, tables and QR codes, device pairing |
| [Admin — shift monitoring](guide/van-hanh/admin-giam-sat-ca.md) | §16 monitoring cashier shifts |
| [Workstation + printers](guide/van-hanh/workstation-may-in.md) | §8-9 installing the workstation and the printers |
| [POS — setup and opening a shift](guide/van-hanh/pos-cai-dat-mo-ca.md) | §10-11 |
| [POS — selling and payment](guide/van-hanh/pos-ban-hang-thanh-toan.md) | §12-13 |
| [POS — closing the shift and reports](guide/van-hanh/pos-ket-ca-bao-cao.md) | §14-15 |
| [KDS · kiosk · TMS · handheld · guest QR](guide/van-hanh/kds-kiosk-tms-handy-qr.md) | §17 the remaining faces |
| [Maintenance and troubleshooting](guide/van-hanh/bao-tri-su-co.md) | §18-20 |
| [Appendices A-D](guide/van-hanh/phu-luc.md) | The quick reference of ports, URLs and environment variables, plus the known pitfalls |
| [Release plan — 10/08/2026](guide/van-hanh/release-plan-2026-08-10.md) | A DATED one-off, not part of the §-numbered handbook above: the deployment window in both JST and ICT, what shipped per system (POS · admin-web · customer-web · workstation · devices), and the staff permissions to set afterwards |

## QA and runbooks

| Doc | Content |
|---|---|
| [POS-web UI test plan](qa/pos-web-ui-test-plan.md) | The manual through-the-UI test plan for POS-web |
| [Kiosk payment test plan](qa/kiosk-payment-test-plan.md) | The test plan for the guest payment flow at the kiosk |
| [Plan-038 smoke runbook](runbooks/plan-038-smoke.md) | The manual smoke scenarios for plan-038 |

The `evidence/` directory holds screenshots attached to issues; it is not a
document to read.

**ĐÃ XOÁ #2485 — `superpowers/specs/` và `testing/`.** Hai design spec
(2026-04-20 notification platform, 2026-04-24 VescaJS WebView) và hai sổ coverage
JSON (#959, #965). Ruling chủ dự án: chưa release thì không giữ hồ sơ quá khứ
trong cây — git history đủ. Đừng dựng lại; cần nội dung cũ thì
`git log --diff-filter=D -- docs/superpowers docs/testing`.
