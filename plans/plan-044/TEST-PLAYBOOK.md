# Plan-044 — Manual test playbook (UI + DB)

> Cashier-shift gap reconciliation (R2). Two topologies:
> **Cloud-direct** (pos-web → Cloud MySQL) and **LAN** (pos-web → workstation SQLite → sync UP).
> Each scenario notes which topology it applies to. Replace `sjk` with your shop slug.

---

## 0. Fastest confidence check — run the automated suites

```sh
# Backend (Cloud) — native
cd backend && php -d memory_limit=-1 vendor/bin/pest --compact --filter='Till|Gap|Attribution|Reconcile'

# Workstation (Go)
cd workstation-app && go test ./internal/handler/ ./internal/service/ -count=1 2>&1 | grep -E '^ok|FAIL'

# pos-web (vitest)
cd pos-web && npx vitest run src/app/shift src/services/till-service.test.ts
```
All green ⇒ the logic is proven. The UI scenarios below verify the wiring end-to-end.

---

## 1. Setup

- Backend up: `docker compose up -d` (Cloud at :5400, MySQL, phpMyAdmin at the tools profile).
- pos-web: `pnpm dev:pos` → http://localhost:5440
- **LAN scenarios only:** rebuild + run the workstation (the pos-device auth fix is Go code):
  `cd workstation-app && make build && ./bin/workstation-app` — then pair pos-web to the shop.
- A shop/branch with: a **till**, **denominations** (for the cash count), a **cash** payment method.

---

## 2. DB inspection cheatsheet

### Cloud (MySQL) — via tinker
```sh
# Sessions of the shop's till
docker compose exec app php artisan tinker --execute '
  $b = App\Models\Branch::where("slug","sjk")->first();
  App\Models\TillSession::where("branch_id",$b->id)->latest()->get(["id","session_code","status","opened_at","closed_at","opening_float_amount"])->each(fn($s)=>print("$s->session_code  $s->status  open=$s->opened_at  closed=$s->closed_at\n"));
'

# Payments + their shift attribution (the column reconcile actually reads)
docker compose exec app php artisan tinker --execute '
  $b = App\Models\Branch::where("slug","sjk")->first();
  App\Models\OrderPayment::where("branch_id",$b->id)->latest()->take(10)->get(["id","amount","status","till_session_id","created_at"])->each(fn($p)=>print("¥$p->amount  $p->status  till=".($p->till_session_id ?? "NULL")."  $p->created_at\n"));
'

# Orders + status + (display-only) attribution
docker compose exec app php artisan tinker --execute '
  $b = App\Models\Branch::where("slug","sjk")->first();
  App\Models\CustomerOrder::where("branch_id",$b->id)->latest()->take(10)->get(["order_code","status","total_amount","paid_amount","till_session_id"])->each(fn($o)=>print("$o->order_code  $o->status  total=$o->total_amount paid=$o->paid_amount  till=".($o->till_session_id ?? "NULL")."\n"));
'

# Audit trail of a gap claim
docker compose exec app php artisan tinker --execute '
  App\Models\AuditLog::whereIn("action",["till_session.gap_claim","till_session.gap_claim_sync"])->latest()->take(5)->get(["action","details","created_at"])->each(fn($a)=>print("$a->action  $a->details\n"));
'
```
> Or use **phpMyAdmin** (docker tools profile) for point-and-click on `till_sessions`, `order_payments`, `customer_orders`.

### Workstation (SQLite) — LAN only
```sh
DB=~/.workstation-app/workstation-app.db
sqlite3 "$DB" "SELECT session_code,status,opened_at,closed_at FROM till_sessions ORDER BY opened_at DESC LIMIT 5;"
sqlite3 "$DB" "SELECT id,amount,status,COALESCE(till_session_id,'NULL'),created_at FROM payments ORDER BY created_at DESC LIMIT 10;"
sqlite3 "$DB" "SELECT id,order_code,status FROM orders ORDER BY opened_at DESC LIMIT 10;"   -- note: NO till_session_id column (by design)
sqlite3 "$DB" "SELECT entity_type,operation,synced_at FROM sync_queue WHERE operation IN ('attribute','open') ORDER BY id DESC LIMIT 10;"
```

---

## 3. UI scenarios

### Scenario A — Prerequisite fix: pos-web loads the shop (LAN)
Was: `localhost:5440/shop/sjk` → *"Không tải được ca làm việc / Thiết bị không có quyền truy cập"* (403).
1. Rebuild + run the workstation (§1), re-open `localhost:5440/shop/sjk`.
2. **Expect:** page loads → redirects to `/shop/sjk/shift/open` (no open shift yet). No 403.
3. If still 403: confirm the workstation binary is the freshly-built one (`git log -1` in workstation-app shows the `fix(workstation): admit paired pos-web device token` commit).

### Scenario B — NO_OPEN_SHIFT gate (money-safety, T5.2)
**Applies:** LAN (the new local gate) + Cloud (existing gate).
1. Ensure **no** open shift (settle/abandon any open one).
2. In pos-web, try to reach the POS order screen / create an order.
3. **Expect (UI):** you're redirected to the **Open-shift** screen — you cannot ring up a sale with no open shift.
4. **Direct API check (LAN)** — proves the backstop 409:
   ```sh
   curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/api/v1/pos/orders \
     -H "Authorization: Bearer <pos_device_token>" -H "X-Shop-Slug: sjk" -H "Content-Type: application/json" -d '{}'
   ```
   **Expect:** `409`, body `{"code":"NO_OPEN_SHIFT"}`.
5. **DB check:** no new row in `customer_orders`/`orders`.
> Kiosk/customer stay UNGATED — a QR/kiosk order can still be paid with no shift (that's the gap payment, Scenario D).

### Scenario C — Open a shift + baseline payment attribution
1. On the Open-shift screen: enter an opening cash count (e.g. 5× ¥1000 = ¥5000). Submit.
2. **DB check:** a `till_sessions` row appears `status='open'`, `opening_float_amount=5000`.
3. Create an order and pay it (cash) on the POS.
4. **DB check (Cloud):** the new `order_payments` row has `till_session_id` = the open session id (NOT NULL).
   **DB check (LAN):** `payments.till_session_id` = the open session id.
> This is the "normal" path — every payment during an open shift is attributed at creation.

### Scenario D — 🌟 Gap reconciliation (the headline R2 feature)
**The core test.** A payment taken while NO shift is open, reconciled at the next open.

**D1 — create the gap payment.** During the close→open gap the POS is gated, so a gap payment comes from an **ungated** surface (kiosk/customer QR) OR simulate it in the DB:
```sh
# Simulate a ¥800 cash payment taken AFTER the previous shift settled, with NO shift attribution.
docker compose exec app php artisan tinker --execute '
  $b = App\Models\Branch::where("slug","sjk")->firstOrFail();
  $pm = App\Models\PaymentMethod::where("organization_id",$b->console_organization_id)->where("code","cash")->firstOrFail();
  $o = App\Models\CustomerOrder::where("branch_id",$b->id)->latest()->firstOrFail();
  $p = App\Models\OrderPayment::factory()->succeeded()->create([
    "customer_order_id"=>$o->id, "payment_method_id"=>$pm->id, "amount"=>800,
    "till_session_id"=>null, "refund_of_id"=>null,
    "branch_id"=>$b->id, "brand_id"=>$o->brand_id, "organization_id"=>$b->console_organization_id,
  ]);
  print("gap payment id: {$p->id}\n");
'
```
> Prereq: the previous shift must already be **settled** so `prev_end` bounds the gap window, and the payment `created_at` must be AFTER that close (the factory stamps "now", so settle first, then insert).

**D2 — verify gap-preview.** Go to **Open a new shift**.
- **Expect (UI):** an amber **"Đối chiếu thanh toán trong lúc chuyển ca"** panel lists the ¥800 cash payment, tagged **"Tiền mặt — giữ riêng"**.
- **API check:** `GET /api/v1/pos/till/gap-preview` returns `data.totals.count = 1`, `payments[0].is_cash = true`, `amount = 800`.

**D3 — claim it.**
- Tick the ¥800 cash row → a **cash callout** appears + a required **"held-separately" ack** checkbox.
- Try to submit WITHOUT the ack → the open button stays disabled (gate works).
- Check the ack, finish the cash count, submit.

**D4 — verify attribution moved.**
- **DB check (Cloud):** the ¥800 payment's `till_session_id` is now the **new** session id (was NULL).
- **DB check (audit):** an `till_session.gap_claim` row with the payment id + `held_separately_ack`.
- **DB check (LAN, if claimed on workstation):** local `payments.till_session_id` updated + a `sync_queue` row `operation='attribute'` (then `synced_at` set once it reaches Cloud).

**D5 — verify cash-flow closure.** Close the new shift → the ¥800 now appears in this shift's **cash sales**; the drawer expected-cash includes it. (Gap cash was held separately by staff → dropped into the drawer on confirm.)

### Scenario E — Close-screen order summary (paid vs unpaid-carry, T-R2.13)
1. In an open shift, create **2 orders**: pay order #1, leave order #2 unpaid.
2. Go to **Kết ca (close)**.
3. **Expect (UI):** an **"Tóm tắt đơn hàng"** card: **1** paid (with total) · **1** unpaid-carry ("Tự động chuyển sang ca tiếp theo").
4. Settle the shift.
5. **DB check:** order #2 is still `status='open'/'paying'` (NOT closed) → it carries.
6. Open the next shift → order #2 is still active/servable. No attribution chase, no data loss.

### Scenario F — Two-way sync convergence (LAN, advanced)
1. On the workstation LAN, claim a gap payment at open (Scenario D on the workstation).
2. **Workstation SQLite:** `payments.till_session_id` = new session; `sync_queue` has an `attribute` op.
3. Wait for sync (or watch logs). **Cloud MySQL:** the SAME payment's `order_payments.till_session_id` converges to the same session id.
4. **Assert identical:** the local and Cloud `till_session_id` for that payment match after a sync cycle.
> If Cloud's session hasn't synced yet, the `attribute` op retries (errDependencyNotReady) until it lands — it never dead-letters or double-stamps.

---

## 4. What each scenario proves

| Scenario | Guarantee |
|---|---|
| B | Money-safety gate: no POS sale without an open shift |
| C | Every in-shift payment is attributed (baseline) |
| **D** | Gap payments are reconciled manually + correctly; cash is claimable with the held-separately ack |
| E | Unpaid orders carry naturally; close counts only paid orders |
| F | Backend ⇄ workstation attribution always converges |

## 5. Reset between runs
```sh
# Settle/abandon any open shift from admin-web (/shop/sjk/till/sessions) or:
docker compose exec app php artisan tinker --execute '
  $b=App\Models\Branch::where("slug","sjk")->first();
  App\Models\TillSession::where("branch_id",$b->id)->where("status","open")->update(["status"=>"abandoned","closed_at"=>now()]);
  App\Models\Till::where("branch_id",$b->id)->update(["current_session_id"=>null]);
'
```
