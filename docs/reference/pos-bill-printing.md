---
title: POS Bill Printing
category: reference
tags: [pos, printing, workstation, kitchen-ticket, payment-receipt, debt, kds, escpos]
summary: Field-by-field reference for the workstation-app `/api/lan/print/*` namespace introduced in plan-038 — kitchen-ticket, payment-receipt (with split-aware reprint), debt slip, status probe, and the KDS realtime broadcast contract.
related: [api-orders, api-kds, api-payment-methods]
---

# POS Bill Printing

Plan-038 shipped five LAN endpoints, two thermal slip formats, one
WebSocket event, and three new database surfaces. This page documents
their I/O contracts so pos-web / godx-kds / mobile-handy clients can
integrate without spelunking through Go source.

**Scope, read this first.** This page is the *plan-038 slice*, not the current
LAN print surface. Since it was written the namespace gained `order-bill`,
`red-invoice`, `shift-report`, `chain-report` and `shift-open-report`, and
**lost `vat-invoice`** (removed by #1779 on 2026-08-04 — the red invoice is
printed, never stored). For the live list see
[`workstation/CLAUDE.md`](../../workstation/CLAUDE.md) §LAN print
endpoints, whose own source of truth is `internal/handler/routes.go`.

For the cashier-facing UX runbook see
[`docs/runbooks/plan-038-smoke.md`](../runbooks/plan-038-smoke.md). For
the design decisions see
plan-038 DESIGN (đã archive — xem git history).

## Prerequisites

| Item | Where set | Required for |
|---|---|---|
| `VITE_WORKSTATION_API_URL` | `web/pos/.env.local` | Every endpoint below |
| Workstation paired with Cloud | Wails `/devices` | Kitchen-ticket, payment-receipt |
| `kitchen_printer` role assigned to a device | Wails `/devices` | `POST /api/lan/print/kitchen-ticket` |
| `bar_printer` role assigned (optional) | Wails `/devices` | bar items in kitchen-ticket; falls back to kitchen_printer when missing |
| `hold_printer` role assigned (optional) | Wails `/devices` | runner ticket alongside kitchen fire |
| `receipt_printer` role assigned | Wails `/devices` | `POST /api/lan/print/payment-receipt`, `/debt-slip` |
| `customer_id` on the order | pos-web cart / kiosk | Debt — backend 422 otherwise |

The workstation's `printer.Dispatcher`
(`workstation/internal/printer/dispatcher.go`) resolves
`printer_group → role` per item with a fallback ladder of
`kitchen` → `kitchen_printer`, `bar` → `bar_printer`, `hold` →
`hold_printer`, default → `kitchen_printer`. Empty or unknown groups
emit a warn log to `slog`.

## Endpoint surface

All four sit inside the workstation middleware ring `lanOnly +
corsMiddleware + corsForBrowser + authed` (see `workstation/CLAUDE.md`
§Security Middleware Ring). Browser CORS allow-list covers
`localhost:5440`, `localhost:5430`, `localhost:5460` plus
`https://*.godx.jp`.

| # | Method | Path | Owner test |
|---|---|---|---|
| 1 | POST | `/api/lan/print/kitchen-ticket` | `lan_print_test.go` |
| 2 | POST | `/api/lan/print/payment-receipt` | `lan_print_test.go` |
| 3 | POST | `/api/lan/print/debt-slip` | `lan_print_test.go` |
| 4 | GET  | `/api/lan/print/status` | `lan_print_test.go` |
| 5 | WS   | `/ws?since=<RFC3339>` | `kds_replay_test.go` |

### 1. `POST /api/lan/print/kitchen-ticket`

Fires the kitchen ticket(s) for an open order. Items grouped by
`printer_group` then dispatched per-group through the shared
dispatcher.

Request body:

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | UUID | ✓ | Cloud or local id; resolved across `linkedOrderIDs`. |
| `idempotency_key` | UUID v4 | optional | Cached 5 min; same key → same response. |

Response shapes:

- **200 OK happy path**
  ```json
  {
    "status": "ok",
    "printed": 5,
    "groups": [
      {"printer_group": "kitchen", "ticket_no": 42, "items": 4},
      {"printer_group": "bar",     "ticket_no": 18, "items": 1}
    ]
  }
  ```
- **200 OK partial** — at least one group printed, at least one errored
  ```json
  {
    "status": "partial",
    "printed": 4,
    "groups": [{"printer_group": "kitchen", "ticket_no": 42, "items": 4}],
    "errors": [{"printer_group": "bar", "reason": "device offline"}]
  }
  ```
- **400** `{"message": "order_id required"}`
- **401** `{"message": "not authenticated"}`
- **404** `{"message": "order not found"}` — local missing AND
  `PullOrderNow` returned `ErrOrderNotFoundOnCloud`
- **422** `{"message": "no unprinted items"}` — every item already
  `print_status='sent_to_kitchen'`
- **503** `{"status": "no_printer", "detail": "no_printer:kitchen_printer"}`
- **504** `{"message": "force-pull timed out", "retry_after_ms": 1500}`

Side effects:

- `order_items.print_status` flips to `sent_to_kitchen` per item printed
- One audit-log row written, action `order.fire`, details
  `{"source":"pos-web","printed":N,"errors":M}`
- WebSocket broadcast `order.kitchen_printed` (see §6)
- Replay buffer push so KDS reconnects within 60 s see the event

### 2. `POST /api/lan/print/payment-receipt`

Prints `DA THANH TOAN` + optional `PHAN CON LAI` for a payment.

Request body:

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | UUID | ✓ |
| `payment_id` | UUID | optional | Target one specific split row. When omitted, legacy "last confirmed payment" behaviour runs. |
| `reprint_reason` | string ≤ 256 | optional | Default `"auto"`. Pos-web sends `"manual reprint"` on "In lại". |
| `idempotency_key` | UUID v4 | optional | NOT applied when `reprint_reason != "auto"` — manual reprints are explicitly allowed. |

Response shapes:

- **200 OK** `{ "status":"ok", "slips_printed":N, "reprint_no":N, "remaining_amount":"0" }`
- **400** `{"message": "reprint_reason too long"}`
- **404** `{"message": "payment not found"}` — when `payment_id` set
  and not found on this order
- **409** `{"message": "payment not confirmed"}` — refunded or pending
- **503** as above

Side effects:

- `payments.metadata.print_history[]` appended via
  `OrderEngine.AppendPrintHistory` (atomic SQLite transaction with
  SQLITE_BUSY retry); `reprint_no` is 1-indexed.
- Audit `payment.receipt_printed` with `{payment_id, reprint_no, reason}`.

`split_mode` projection rules (see
`workstation/internal/handler/print_receipt.go::paidSlipInputs`):

- `by_items` — slip lists only the row's allocated items + per-row
  subtotal/tax/total.
- `by_amount` (plan-038 T2.4) — slip lists NO items; carries
  `Khach: <label> (i/N)` + `Tong: <amount>` + `Da thanh toan: <amount>`.
  `Phan con lai = order remaining`.
- `equal` and unset — slip lists every order item + the order-level
  breakdown plus `Da thanh toan` + `Con lai`.

### 3. `POST /api/lan/print/debt-slip`

Prints `PHIEU GHI NO` for an `on_account` payment.

Request body:

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | UUID | ✓ |
| `payment_id` | UUID | ✓ | Must reference a confirmed payment whose `payment_methods.type='on_account'`. |
| `reprint_reason` | string ≤ 256 | optional |

Response:

- **200 OK** `{ "status":"ok", "slips_printed":1, "reprint_no":N }`
- **404** `{"message": "order not found"}` or `"payment not found"`
- **409** `{"message": "payment not confirmed"}`
- **422** `{"message": "payment_method_not_on_account"}` — caller picked
  the wrong payment row
- **503** as above

The slip carries the customer block (name + phone + tax_code) +
signature line + the disclaimer "Khach hang xac nhan da nhan no".

Audit: `payment.debt_slip_printed` with `{payment_id, reprint_no, reason}`.

### 4. `GET /api/lan/print/status[?order_id=…]`

Probe used by pos-web's `KitchenFireButton` to render the printer
status pill and the pending-print badge count.

Response:

```json
{
  "printer_roles": {
    "kitchen_printer":  {"configured": true,  "online": true},
    "bar_printer":      {"configured": true,  "online": false, "last_error": "device reported error"},
    "hold_printer":     {"configured": false},
    "receipt_printer":  {"configured": true,  "online": true}
  },
  "sync": {
    "last_pulled_at": "2026-06-19T10:20:55Z",
    "cursor_age_s": 3
  },
  "order": {
    "id": "...",
    "in_local": true,
    "open_items_pending_print": 2
  }
}
```

`order` block only when `order_id` is supplied.

### 5. WebSocket `/ws?since=<RFC3339>`

The existing workstation Hub now broadcasts an additional event type
relevant to KDS:

`order.kitchen_printed` payload:

```json
{
  "order_id": "01...",
  "order_code": "OC-001",
  "table_no": "T-12",
  "groups": [{"printer_group": "kitchen", "ticket_no": 42, "items": 3}],
  "items": [
    {
      "id": "01...",
      "name": "Phở bò",
      "qty": 2,
      "note": "không hành",
      "printer_group": "kitchen",
      "print_status": "pending"
    }
  ],
  "fired_at": "2026-06-20T10:21:11Z",
  "source": "pos-web",
  "branch_id": "01..."
}
```

The `?since=` query is parsed as RFC3339; matching events in the 60 s
ring buffer are replayed BEFORE live events flow. Malformed `since` is
silently skipped — the client falls back to its periodic poll.

Existing events (`order_created`, `order_updated`, `order_paid`,
`order_item.status_changed`) are unchanged.

## Sync feeds

Plan-038 added two feeds to the slow loop (≤ 60 s tick). One survives:

- **`payment_methods`** carries the `type` column post-plan-038 so the
  `on_account` (debt) method shows in pos-web's payment grid.

**`PullInvoices` was removed by #1779** together with the `invoices` feed, its
sync-manifest entry, the `customer_invoices` mirror reads and the `voidNoticeCb`
auto-print of `BIEN BAN HUY HOA DON`. The local `customer_invoices` table and its
migration are still on disk (unused-harmless — dropping a migration mid-chain was
judged the riskier move).

## Troubleshooting

| Symptom | Likely cause | Action |
|---|---|---|
| `no_printer` toast on every fire | `kitchen_printer` role not assigned | Wails `/devices` → assign role |
| `sync_pending` toast / 504 | Cloud reachable but slow OR cloud just returned 5xx during force-pull | Retry; check workstation logs for `force-pull` errors |
| Workstation unreachable (status=0 ApiError) | `VITE_WORKSTATION_API_URL` not set OR LAN dropped | Check env var, ping workstation |
| KDS stops receiving live events but periodic poll works | WS reconnect happened past the 60 s replay window | Expected; periodic poll catches up |
| Bar items printing on kitchen | Older workstation build still inlines `GetPrinterByRole(TypeKitchenPrinter)`; T1.3 refactor not deployed | Update workstation binary |
| `Khach n/N` shows wrong label on by_amount split | metadata.label missing — pos-web bug or old client | Update pos-web to plan-038 T5.2+ |
| Audit log shows `errors=1` after each fire | Printer connection error mid-print | Check Wails logs; could be paper roll empty |

## See also

- Plan-038 README (đã archive — xem git history) — scope + Q1–Q11 decisions
- Plan-038 DESIGN (đã archive — xem git history) — alternatives + rejected approaches + risks
- [Workstation CLAUDE.md](../../workstation/CLAUDE.md) — LAN print endpoints section + Security Middleware Ring
- [Pos-web CLAUDE.md](../../web/pos/CLAUDE.md) — Bill printing section (service surface)
- [Smoke runbook](../runbooks/plan-038-smoke.md) — cashier-facing scenarios
