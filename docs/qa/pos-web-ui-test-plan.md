---
title: POS-web manual UI test plan
category: guide
tags: [qa, test-plan, pos-web, manual]
summary: "Manual through-the-UI test plan covering all of pos-web, with the detailed cases kept alongside in pos-web-ui-test-cases.csv."
related: []
---

# POS-web — manual UI test plan

> Scope: **manual testing through the user interface** for all of `pos-web`
> (React/Vite, `:5440`).
> Code-based tests (vitest unit/integration) are **out of scope for now** and
> will be added later.
> The detailed case set: [pos-web-ui-test-cases.csv](pos-web-ui-test-cases.csv).

## 1. Goals

- Verify that every main business flow of the POS terminal works end to end
  through the UI: pair the device → open the shift → sell → take payment → close
  the shift → report.
- Catch defects in rendering, button states, toasts, navigation, i18n (ja/en/vi)
  and the error branches (negative and edge cases).
- Check offline-first behaviour: LAN routing (workstation) versus Cloud fallback.

## 2. Environment and prerequisites

| Item | Value |
|----------|---------|
| App | `cd pos-web && pnpm dev` → http://localhost:5440 |
| Backend | `docker compose up -d` (`:5400`) or Herd at `https://dxs-product.test` |
| Default shop | `VITE_SHOP_SLUG` (falls back to `van-phong-chinh`) |
| Device | A device plus a pairing code (6 characters, valid 15 minutes) created in Admin-web → Devices |
| Workstation | Run `workstation-app` on the LAN to test LAN routing; stop it to test the Cloud fallback |
| Data | A menu whose schedule covers today, with variant/topping/combo products, tables and zones, and a coupon plus a happy hour to test against |
| Browser | Chrome (desktop plus a touch screen if available), with one secondary browser also checked |

## 3. Modules under test

1. **Pairing** — pair the device with the 6-character code; handle wrong and
   expired codes.
2. **Auth guard / routing** — redirect when unpaired, the default shop, and the
   `*` route.
3. **Shift open** — count cash by denomination, choose who opens, currency, open
   and print, and error cases.
4. **Shift gate** — block entry to the POS while no shift is open.
5. **Tables overview** — statistics, opening a table tab, creating an order.
6. **Create order** — order type, table selection, guest count, phone number,
   notes.
7. **Menu catalog** — select a menu, search, add items, combos, and the no-menu
   case.
8. **Product options** — variants, toppings (min/max/required), kitchen notes,
   editing a line.
9. **Cart** — subtotal/discount/tax/service/total, editing a line, notes.
10. **Void** — void an item, void an order (reason mandatory).
11. **Guest count** — set and change the guest count.
12. **Table operations** — assign / change / merge / unmerge tables (including
    unmerging while pending).
13. **Kitchen fire** — send to the kitchen, printer status (online/offline/stale),
    and the no-printer → KDS path.
14. **Checkout** — block while items are not yet served; confirm the order.
15. **Payment** — tendered/change, exact amount, recording debt (underpayment),
    walk-in must pay in full, payment methods.
16. **Split bill** — split evenly / by item / by amount, receipts, drift,
    cancelling a split.
17. **Coupon** — apply and remove, the error codes, field locking after use.
18. **Promotion (happy hour)** — the badge, and coupon ↔ happy-hour conflicts.
19. **Debt** — look up debt, record debt (in full or partial), fold an older debt
    into a payment.
20. **VAT invoice** — issue an invoice, validate the tax number, reprint.
21. **Receipt** — print and reprint a receipt.
22. **Connection status** — Auto / Workstation / Cloud, the connection test, the
    badge.
23. **Shift close** — the closing count, terminal reconciliation, discrepancies
    that require a reason, saving a draft, settling.
24. **Cash event** — paid in and paid out during a shift.
25. **Abandon shift** — discard a shift opened by mistake (before any payment).
26. **Revenue report** — by time and by product, date ranges, KPIs, charts,
    pagination.
27. **i18n / theme** — switching ja/en/vi and light/dark.
28. **Logout** — unpair the device.

## 4. Priority and classification

- **High** — the selling, payment and shift flows (these must never break).
- **Medium** — supporting operations, variants, reports.
- **Low** — presentation, i18n, receipt reprints, rare edge cases.
- Every module has **Positive**, **Negative** (errors/validation) and **Edge**
  (boundary) cases.

## 5. Execution process

1. Record results in the `Status` column of the CSV: `Pass` / `Fail` / `Blocked`
   / `N/A`.
2. The `Evidence` column is **mandatory** for every case that was run: a link to
   a screenshot, a video or a bug report. A `Fail` case must have both evidence
   and reproduction steps.
3. Run the modules in order (pairing → shift open → pos → payment → shift close →
   report) so that state can be reused.
4. Regression: re-run the **High** group before every release.

## 6. Out of scope (this round)

- Automated code-based tests (vitest, Playwright/E2E) — to be added later.
- Testing the backend API directly (a separate set already covers that, for
  example `kiosk-payment-test-cases.csv`).
- In-depth ESC/POS printer hardware testing (only the status and the messages
  shown in the UI are verified).
