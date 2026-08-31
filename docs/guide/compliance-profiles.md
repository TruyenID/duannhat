---
title: Compliance profiles — operating country (JP/VN), without forking core
category: guide
tags: [compliance, country, invoice, registration-number, vn, jp, platform, sso]
summary: >
  The ORGANIZATION's operating country (not the brand's) decides the compliance
  profile: the tax registration number format, the 返還インボイス exemption
  threshold, and so on. The source of truth is Platform
  (organizations.country, immutable once created); Tempo mirrors it through the
  SSO contexts payload. Core stays international — ONE invoice model, ONE tax
  engine; a profile only parametrizes it, and forking into VnInvoice/JpInvoice
  is forbidden.
related:
  - guide/tax-types.md
status: country layer shipped 2026-07-28 (#1153); VN CQT adapter ĐÃ GỠ 2026-08-04 (#1779)
---

# Compliance profiles (operating country)

## Architectural principles

- **Country is an attribute of the ORGANIZATION** (the legal entity), not of the
  brand — a brand is only a trade name. Four INDEPENDENT axes; never infer one
  from another: compliance country ≠ currency ≠ timezone ≠ print locale.
- **Source of truth = Platform** (`dxs-platform/platform`,
  `organizations.country`, ISO 3166-1 alpha-2): chosen when the org is CREATED in
  Admin (the 事業国 select), and **immutable afterwards** (updates are
  `prohibited` — following the precedent of Stripe's `account.country`). The IdP
  `GET /api/sso/organizations` returns `country` per row.
- **Tempo mirrors it read-only**: `organizations.operating_country` (default JP),
  which `UserProvisioner` adopts if present on every login — an older Platform
  that lacks the field **never** resets an already-mirrored value.
- **Core stays international**: one invoice/credit-note model, one tax engine,
  one document pipeline. A profile only **parametrizes** the shared machinery.
  Forking the model per country is a review blocker.

## Resolution

`ComplianceProfileResolver->forOrganization($consoleOrgId)` returns
`JpComplianceProfile` or `VnComplianceProfile` (`app/Services/Compliance/`). It
fails safe to JP: a missing org row, an old row without the column, or a country
with no profile all behave exactly as every tenant did before #1153, with no
warning (a compliance posture is a fact about the tenant, not something to nag
about). The resolver is deliberately **not a singleton** — it memoizes per
instance, so a long-running worker cannot pin an outdated profile through a lazy
adopt at login.

## What the profile parametrizes today

| Parameter | JP | VN |
|---|---|---|
| Registration number (brand/branch settings) | `T` + 13 digits (インボイス) | MST `\d{10}` or `\d{10}-\d{3}` |
| Return-document exemption threshold | < ¥10,000 (消令70条の9③二, JPY only) | None — a document is always required |

Call sites already migrated (behaviour-preserving for JP):
`HqBrandSettingsController` and `ShopBranchSettingsController` (the
`invoice_registration_number` regex). `PosReturnInvoiceService`
(`threshold_exempt`) cũng từng đọc profile này nhưng **đã bị gỡ ở #1779** cùng
toàn bộ đường phát hành hoá đơn — xem mục bên dưới. The admin-web client-side pre-check accepts BOTH formats
(the client does not know the country; the server is the authority).

### The country now reaches the workstation (#1490)

`GET /api/v1/workstation/branch` ships `settings.operating_country`, resolved
through the same resolver — never through a second read of
`organizations.operating_country`, because two read paths are two chances to
drift. It sits in the `settings` block on purpose: `SyncPuller.PullBranch`
flattens `data.settings.*` generically into the local `shop_settings` table, so
every workstation already in the field stores the key on its next pull with no
Go build, exactly the way `seller_registration_number` (#1152) travelled.

This exists because **which legal document a shop prints follows the country the
shop is in**, and the device had no other way to know it — `FormatVatInvoice`
was branching on the cashier's UI language, so a Vietnamese shop set to Japanese
printed 適格簡易請求書 and a Japanese shop set to Vietnamese printed a hoá đơn GTGT
(#1459). Four axes stay independent: compliance-country ≠ currency ≠ timezone ≠
print locale.

One trap for whoever consumes the key: **`JP` carries two different meanings** —
"this shop is in Japan" and "nobody ever told us". The column defaults to `JP`
and the resolver fails safe to `JP`, so the feed never sends an empty value and
absence is not distinguishable from a real answer. A consumer that needs to
degrade gracefully must branch on the key being **missing entirely** (an old
Cloud, or a device that has not pulled yet), not on its value.

## VN CQT e-invoice transmission — ĐÃ GỠ 2026-08-04 (#1779)

> **Mục này mô tả một đường truyền KHÔNG CÒN TỒN TẠI.** Giữ lại làm hồ sơ
> thiết kế, không phải mô tả hệ thống đang chạy.

Ngày 2026-08-04, theo quyết định của chủ dự án (#1779), toàn bộ pipeline bị gỡ:
`PosInvoiceService`, `Services/Compliance/VnEinvoice/*`, `TransmitVnEinvoiceJob`,
lệnh `vn-einvoice:redrive`, các controller HQ/Shop, cùng cờ
`compliance.vn_einvoice.*` và module/schema tương ứng.

Còn lại trong hệ thống:

- `ComplianceProfile::requiresEInvoiceTransmission()` — **chỉ còn mô tả nghĩa vụ
  pháp lý theo quốc gia**, không kích hoạt hành vi nào. `true` ở đây KHÔNG có
  nghĩa là đã truyền.
- Các bảng dữ liệu — **giữ có chủ đích** vì nghĩa vụ lưu chứng từ 7/10 năm, canh
  bởi `backend/tests/Feature/Architecture/InvoiceTablesAreNotDroppedTest.php`
  (#1797). Đừng viết migration xoá chúng.

Việc dựng lại (nếu có) theo dõi ở **#1153**. Phần mô tả thiết kế cũ bên dưới
vẫn đúng về mặt *yêu cầu pháp lý*, nhưng mọi câu ở thì hiện tại nói về mã nguồn
đều đã sai.

<details><summary>Hồ sơ thiết kế cũ (không còn phản ánh mã nguồn)</summary>


A pipeline that transmits e-invoices to the tax authority (CQT) through a
licensed provider (Viettel SInvoice / VNPT / MISA / FPT — the provider signs with
its HSM on your behalf, so no USB token is needed). **Off by default across the
whole system** (`VN_EINVOICE_ENABLED=false`, the #1088 posture): turn it on only
once there is a provider contract and the number series (form code plus serial)
has been registered with the CQT — and the payload MUST be certified against a
real sandbox first (the golden fixture is the diff point).

- **Three gates**: the global flag, the VN profile (org country), and an enabled
  `vn_einvoice_settings` row (two tiers: brand default and branch override, with
  credentials encrypted at rest and write-only through the API).
- **Fail-open with respect to selling**: issuing an invoice or 赤伝 is never
  blocked by the transmission layer — a pending `vn_einvoice_transmissions` row
  plus a queued job (`TransmitVnEinvoiceJob`, afterCommit) handles it, with
  backoff on transient errors (1/5/15/60/240 minutes), a `vn-einvoice:redrive`
  every 5 minutes that re-dispatches and reclaims work from dead workers, and a
  `vn_einvoice_stale_pending` alert.
- **No-double-issue in three layers**: a unique `(document_type, document_id)`,
  an atomic pending→submitting claim, and `transactionUuid` = the row id
  (idempotency on the provider's side).
- **The official number belongs to the CQT series**, NOT to an internal counter:
  an accepted result is adopted back onto `customer_invoices.vn_einvoice_no` /
  `_lookup_code` / `_status` (and the corresponding return table).
- **Split bills transmit PER BILL (#1236)**: a transmission is keyed on
  `(document_type, document_id)`, so each customer's invoice is its own CQT
  document — three customers splitting a bill produce three documents, not one.
  A downward adjustment hangs off
  `customer_return_invoices.customer_invoice_id`, and that now points at the
  invoice of the customer actually being refunded (before #1236 it took the
  order's most recent invoice, so refunding customer B filed the adjustment
  against customer A's CQT number, recording A as the buyer on a return A never
  received).
- **赤伝 → downward adjustment invoice**: transmitted only AFTER the original has
  a CQT number (the payload references the original's OFFICIAL number); if the
  original is voided before it can be transmitted, both are rejected with a
  reason (`document_voided_before_transmission` /
  `original_never_transmitted`) — there is nothing at the CQT to adjust.
- **Rejected is a compliance incident**: log it loudly and never delete the row;
  ops fixes the cause and then revives it through
  `POST /shops/{slug}/vn-einvoice/transmissions/{id}/retry`.
- Endpoints: HQ `GET/PATCH /hq/{brand}/settings/vn-einvoice` (the admin-web tab
  already exists; a non-VN org sees "not applicable") · shop
  `GET/PATCH/DELETE /shops/{slug}/vn-einvoice/settings` plus the transmissions
  index and retry.


</details>

## Not built yet (design-only, awaiting a decision — the #1153 remainder)

The Decree 70/2025 cash-register mandate decision (per receipt vs. summary
listing — RE-CHECK the regulation at the time a real VN customer exists), form
04/SS-HĐĐT (cancelling an invoice that ALREADY has a CQT code), the VNPT/MISA/FPT
adapters (each is one class plus one registry line), the shop-side transmissions
board UI, and per-country print labels.

## Pins

Platform: `OrganizationCountryTest` (17) — the persist/default/validation matrix
(case, alpha-3, empty, numeric), immutability even for the same value,
idempotency replay plus payload-mismatch 409, country preserved across the
lifecycle, the audit entry, and multi-org contexts. Tempo:
`backend/tests/Feature/Compliance/OperatingCountryComplianceTest.php` (5).

`VnEinvoiceTransmissionTest`, `VnEinvoiceSerializationTest` and the golden
fixture `tests/Fixtures/vn_einvoice_golden.json` are **GONE** — removed with the
pipeline itself on 2026-08-04 (#1779, see the section above). Nothing pins CQT
transmission today because nothing transmits. Rebuilding is tracked at #1153;
whoever does it writes those pins fresh.
