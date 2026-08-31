# workstation-app - Workstation Desktop Application

Go + Wails v3 desktop app for restaurant/shop workstation management.

## Tech Stack

- **Backend**: Go 1.25, Wails v3 (alpha.74)
- **Frontend**: React 19, TypeScript, Tailwind CSS v4, Vite
- **Database**: SQLite via `modernc.org/sqlite` (pure Go, no CGO)
- **Printers**: ESC/POS protocol (USB + TCP)

## Development

```sh
# Dev mode with hot reload
make dev
# or directly:
~/go/bin/wails3 dev

# Build for current platform
make build

# Run Go tests — ALWAYS pass a wide -timeout (#1186): internal/service alone
# measures 560-710s and Go's default is 600s, so a bare `go test ./...`
# panics at random on a loaded machine. `make test` already carries it.
make test

# Generate Wails bindings after changing Go services
~/go/bin/wails3 generate bindings
```

## Project Structure

- `cmd/workstation/main.go` - App entry point, wires all services
- `frontend.go` - Root-level embedded frontend assets (required for go:embed)
- `migrations.go` - Root-level embed for omnify-generated SQL (must live at root for go:embed)
- `api/` - OpenAPI spec
- `internal/store/migrations/` - **Hand-written** SQL migrations (versions 1..999) — source of truth for workstation-owned schema (orders, payments, auth_token_cache, etc.)
- `migrations/omnify/` - **Omnify-generated** SQL migrations (versions 1000+) — cloud-mirror tables (branches, customer_orders, products, etc.). Regenerate via `npm run omnify:gen` at the umbrella root.
- `sqlc.yaml` - sqlc configuration for code generation
- `internal/domain/` - Business types and interfaces (hand-written)
- `internal/store/` - Database layer (connection, migrations, queries)
- `internal/handler/` - HTTP handlers, routes, middleware, WebSocket, Swagger
- `internal/service/` - Business logic (order engine, print formatting, sync engine)
- `internal/printer/` - ESC/POS printer support (manager, driver, encoding)
- `internal/config/` - Config management (~/.ws-app/)
- `internal/audit/` - Audit logging
- `internal/monitor/` - Load monitoring
- `internal/discovery/` - mDNS advertisement
- `internal/generated/sqlc/` - sqlc auto-generated code (placeholder)
- `internal/generated/oapi/` - oapi-codegen auto-generated code (placeholder)
- `frontend/` - React app served by Wails

## Security Middleware Ring

Request flow into the LAN server (`internal/handler/server.go::New`):

```
ListenAndServe → lanOnly → corsMiddleware → mux → ( per-route wraps ) → handler
```

- `lanOnly` (outer ring): Rejects requests whose RemoteAddr is not RFC1918 / loopback / IPv6 ULA / link-local. The bind addr is 0.0.0.0:8080 so this is the only barrier against a misconfigured router or VPN bridge that exposes the workstation to the public internet.
- `corsMiddleware`: sets baseline security headers on every response (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` — same-origin framing is required by the `<iframe src="/vesca-bridge.html">` card-terminal bridge, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: frame-ancestors 'self'`) + LAN CORS allow-list for private/loopback origins. **`Cross-Origin-Opener-Policy: same-origin` is the one CONDITIONAL header** (#2431): it is emitted only on a *potentially trustworthy* origin — HTTPS, `localhost`, or a loopback literal. Shop tablets open `/pos` over plain `http://<lan-ip>` by design (#1169), where browsers ignore COOP and log a warning on every page load; `requestIsTrustworthyOrigin` in `middleware.go` mirrors the browser rule, and `TestCorsMiddleware_COOPOnlyOnTrustworthyOrigins` is the matrix.

Per-route wraps:

- `localOnly` (`/api/device/{pair,status,unpair}`, `/api/audit`, `/api/monitor*`): loopback-only. These are admin endpoints called only from the Wails frontend via `http://localhost:8080`.
- `pairLimiter.Middleware` (`POST /api/device/pair`): 5/min/IP, burst 5 — defense in depth against pairing-code brute force even though the endpoint is already localOnly.
- `paymentLimiter.Middleware` (`POST /api/v1/{kiosk,pos}/payments` + confirm/fail): 60/min/IP, burst 10.
- `authed = authMW.Wrap`: Bearer-token verify against Cloud with cache + stale-fallback ladder.
- `corsForBrowser` (`/api/v1/{pos,kds}/*`): per-origin allow-list for browser clients (pos-web, kds-web). Production origins must be HTTPS + match `.godx.jp` suffix.

The WS path (`/ws`) goes through `lanOnly + corsMiddleware` like everything else, then uses `authMiddlewareVerifier` (adapter in `ws.go`) so the first-message auth handshake reuses the same cache + stale ladder as HTTP. Cloud outage no longer cuts LAN realtime. WS `auth_ok` payload includes `stale: true` when the session was accepted via stale cache so clients can show a degraded-mode banner.

**A client that cannot keep up is CLOSED, not quietly under-served (#1793).**
Fan-out (`BroadcastEventScoped`) sends non-blocking into each client's 256-slot
buffer. A full buffer means that client has already missed an event, so it gets
close code **4409** (`dropForOverflow`) and reconnects with backoff — pos-web
×2/30s, kds 1s×2/30s (only `4401`/revoked token is terminal there), ws-app
frontend ×1.5/30s — refetching on the way back. The previous behaviour dropped
the EVENT and kept the connection, which left the client believing it was live
with a permanently incomplete view: dropping an event closes nothing, so there
was no reconnect to "refetch on" and nothing else resynced it. A visible
one-second gap beats an invisible permanently-wrong screen. Two implementation
constraints, both load-bearing: overflowed clients are closed **after**
`h.mu.RUnlock()` (the close does network I/O with a 1s deadline — under the read
lock it would stall register/unregister), and `dropForOverflow` must **never**
touch `hub.clients` (readPump's defer unregisters through the normal path, which
takes the write lock). The `dropped_slow` counter is in the `ws fanout` log line.

Relatedly, the hub has **no `broadcast` channel** — fan-out goes straight to each
client's buffer. The old `case <-h.broadcast` in `Run()` was dead code that
mutated `hub.clients` under a **read** lock; re-introducing it would give a
concurrent-map-write panic under load. `ws_overflow_test.go` is the tripwire.

Audit log writes go through `auditLog(r, ...)` which calls `clientIP(r)` — XFF is intentionally NOT honored (workstation has no reverse proxy; XFF would let a LAN client spoof). Audit `details` payloads use `auditDetails(map[string]any)` (json.Marshal) instead of `fmt.Sprintf` so a value containing `"` or `\` can't forge JSON keys.

5xx responses go through `writeServerError(w, r, err)` — the raw error is logged server-side via `slog.Error` with method+path correlation, the client gets a generic "internal error" body. The previous `writeError(w, 500, err.Error())` pattern leaked SQLite SQL strings to LAN clients.

`http.Server.ReadHeaderTimeout: 5s` is set as slowloris defense.

**The `/` SPA handler is the catch-all — it must not swallow API paths (#1746).**
`spaHandler` (`server.go`) is mounted at `"/"`, so every request the mux does not
match reaches it. It used to fall back to `index.html` unconditionally, which
answered a mistyped or not-yet-implemented endpoint with `200` + HTML; the caller
saw 2xx and only failed later, parsing HTML as JSON far from the cause. It now
takes two exits before the fallback: `/api*` and `/ws*` get a JSON `404`, and any
path that *looks like a file* (has an extension) gets a plain `404` — the same
extension rule the `/pos` mount uses (#1735), which keeps a webview holding a
stale `index.html` after an app update from getting HTML where it asked for
JavaScript. Extension-less client routes still fall back, so a reload on a deep
link keeps working. `/api/v1/pos/*` never reaches here at all — `routes.go` has a
catch-all proxy for it.

## Unpair guard + kept-data recovery (plan-818)

`POST /api/device/unpair` (`handleDeviceUnpair`, `localOnly`) no longer blindly wipes the transaction tables — that path silently destroyed offline cash. Now:

- **Guard first.** `unsyncedSummary()` (direct SQL on `s.db`) measures money at risk from the **`payments` table keyed on `cloud_id IS NULL/''`** — NOT `sync_queue` and NOT `payments.synced_at` (a dead column nothing ever stamps). A payment commits before its enqueue and enqueue failure is non-fatal, so `cloud_id` is the only reliable "Cloud saw it" signal. "Real money" mirrors `sumActivePaymentsForOrder` (phantom-expired pending excluded, #562). Orders/items use their reliably-stamped `synced_at`. Refunds are inferred from an unresolved `payment.refund` queue row.
- If `HasUnsynced && !force` → **HTTP 409** `unsynced_data_present` with counts + `unsynced_amount`, and **nothing changes** (no cloud self-revoke, no wipe, no token clear). The guard runs BEFORE `notifyCloudUnpair` so a blocked attempt keeps the token valid.
- `?force=true` with unsynced data → **keeps** `orders/order_items/payments/payment_refunds/sync_queue` on disk (only the branch-scoped Cloud mirror `menu_items/zones/tables/inventory_lots/devices/auth_token_cache` is wiped) + records `settings['unpair.prev_branch_id']`. Nothing unsynced → full wipe as before (start fresh). The wipe skips tables absent from the DB instead of 500ing.
- **Recovery is automatic, not via the SyncRecovery UI.** After re-pairing to the SAME branch (which clears `unpair.prev_branch_id` in `handleDevicePair`), the backfill reconcilers re-push the kept rows. `cloudPost` re-stamps the current device token for `/api/v1/workstation/*`, and a new `reconcileUnsyncedPayments()` re-enqueues a fresh `payment.create` (reusing the payment's `idempotency_key`) for workstation-origin payments with no live queue row — this also fixes the pre-existing "payment orphan" gap. All three backfill reconcilers are gated by `shouldAutoRecover()` (`prev_branch_id` empty or == current branch) so a cross-branch re-pair never pushes branch-A data onto branch-B.
- **Origin tracking:** migration `040` adds `payments.sync_target` ('workstation' | 'kiosk'), set at insert. The reconciler only heals `'workstation'` rows — kiosk payments (terminal-baked token, un-re-stampable) are a follow-up; their money still blocks unpair via the guard so it is never silently lost.

Audit: `device.unpair_blocked` / `device.unpair_forced` (with amount) / `device.unpair`.

## Cashier shift endpoints (plan-030 / 032)

`/api/v1/pos/till/sessions/*` are NOT served locally by workstation — they
fall through to the catch-all proxy in `internal/handler/routes.go` (~line
223) which forwards every unmirrored `/api/v1/pos/*` request to Cloud
verbatim, preserving the cashier's Bearer token + `X-Shop-Slug` header +
request body. This covers:

| Plan | Endpoint | Cloud handler |
|---|---|---|
| plan-030 | `POST /pos/till/sessions` (open) | `TillSessionController@store` |
| plan-030 | `POST /pos/till/sessions/{id}/{cash-events,draft,close,abandon}` | same controller |
| plan-030 | `GET /pos/till/{current,denominations,tender-types}` + `GET /pos/till/sessions/{id}` | `TillController` / `TillSessionController` |
| plan-032 | `POST /pos/till/sessions/{id}/force-abandon` | `TillSessionController@forceAbandon` (manager-only) |
| plan-032 | `POST /pos/till/sessions/{id}/manual-settle` | `TillSessionController@manualSettle` (manager-only) |
| plan-032 | `GET /pos/till/sessions/stale[?filter=…][?group_by=actor]` | `TillSessionController@stale` (manager-only) |

The TillSession shape with all plan-032 fields (`expired_at`,
`force_abandoned`, `force_abandon_reason_code`, etc.) reaches pos-web /
admin-web unchanged because the proxy doesn't transform JSON. Workstation's
Go domain enums for `TillSessionStatus`, `ExpireReason`, and
`ForceAbandonReasonCode` are regenerated via `omnify:gen` at the umbrella
root and live under `internal/domain/generated/enums/` for any future
local consumer.

Proxy guarantees verified by `internal/handler/pos_cloud_proxy_test.go`:
`TestPosCloudProxy_Plan032_*` covers POST body forwarding for
force-abandon + manual-settle, GET query-string forwarding for the stale
listing, and the actor-summary mode.

## Cashier shift — gap reconciliation (plan-044 R2)

`POST /pos/till/sessions` (open) is served **locally** by
`handleLocalPosTillOpenSession` (SQLite `till_sessions` + sync UP via
`till_session.open`), not proxied — the session id is verbatim local↔cloud
(Cloud upserts by the workstation-supplied id). Read endpoints back the
pos-web reconciliation UI, served from the LOCAL replica (see
`local_pos_till.go`) so kiosk/customer LAN payments not yet synced UP are still
visible:

| Method | Path | Handler | Purpose |
|---|---|---|---|
| GET | `/pos/till/gap-preview` | `handleLocalPosTillGapPreview` | NULL-attributed payments in the window `(prev terminal close, now]`, `is_cash = payment_method=='cash'`. |
| GET | `/pos/till/unresolved-orders` | `handleLocalPosTillUnresolvedOrders` | Orders still `paying`/`checkout` with `created_at` before previous terminal close. Same Cloud JSON. Offline-capable. |
| GET | `/pos/till/sessions/{id}/order-summary` | `handleLocalPosTillOrderSummary` | Paid orders (distinct, payment in-session) + unpaid-carry (not `closed`/`voided`, no payment). |

**Gap claim + two-way sync.** At open, `claimed_gap_payment_ids` +
`gap_cash_held_separately_ack` stamp the confirmed payments' local
`till_session_id` to the new session (eligibility mirrors gap-preview exactly;
cash included). The claim propagates UP to Cloud via the **`payment.attribute`**
sync op (`sync_service.go`): `POST /workstation/payments/{cloud_id}/attribution`
with the session id, remapping the payment local→cloud (`errDependencyNotReady`
until `payment.create` synced). A not-yet-synced claimed payment instead carries
its `till_session_id` on the create itself (send-time read in
`handlePaymentCreate`). Convergence is guaranteed: the attribute op retries until
Cloud echoes the sent session id (Cloud-authoritative R6), then adopts Cloud's
value onto the local mirror so both DBs are byte-identical. `PullTillSessions`
upserts `ON CONFLICT(id)` (no replace-all) so a locally-settled session — the
gap-window lower bound — survives the active-feed pull. Orders never carry
`till_session_id` (Cloud stamps its own open session at create per R6).

## LAN print endpoints (plan-038)

All LAN-print endpoints sit inside the `lanOnly + corsMiddleware + corsForBrowser + authed` ring (see Security Middleware Ring above). Browser-callable from pos-web (`localhost:5440` / `https://*.godx.jp`).

Source of truth is `internal/handler/routes.go` — this table is a summary and a
route added there without a row here is the table's bug, not the route's.

| Method | Path | Handler | Purpose |
|---|---|---|---|
| POST | `/api/lan/print/kitchen-ticket` | `handleLANPrintKitchenTicket` | Per-`printer_group` ticket fire; force-pulls (1.5s) when order missing locally. Broadcasts `order.kitchen_printed` to KDS. |
| POST | `/api/lan/print/kitchen-reprint` | `handleLANPrintKitchenReprint` | "In lại phiếu bếp" — paper only. **Not a flag on the row above: the two do opposite things.** Fire is dispatch (closes the delta, wakes every KDS); this one writes no `print_status` and broadcasts nothing, so reprinting a finished order can't put it back on the kitchen display as new work. Prints every non-voided line at FULL quantity (on a closed order the delta is 0 for all of them) and has no 422 delta gate. No printer ⇒ 503, not a soft error: there is no KDS fallback that makes a paperless reprint mean anything. Audit `order.kitchen_reprint`. |
| POST | `/api/lan/print/order-bill` | `handleLANPrintOrderBill` | "In phiếu order" — full-order bill + QR, on demand, no reprint limit. Falls back receipt → hall → kitchen printer so a one-printer shop still gets paper. |
| POST | `/api/lan/print/payment-receipt` | `handleLANPrintReceipt` | PAID + remaining slip. `payment_id` targets one split row; `reprint_reason` (optional, **never required**) + `actor_user_id` drive audit + ledger. |
| POST | `/api/lan/print/red-invoice` | `handleLANPrintRedInvoice` | Paid receipt + a named-customer line. Prints **PHIEU THANH TOAN / PAYMENT RECEIPT / 領収書** and carries the locked `vat_disclaimer` block — #2062 took the statutory name off it because since #1779 it is not a hoá đơn GTGT. Route id unchanged. `payment_id` targets one split payer. |
| POST | `/api/lan/print/debt-slip` | `handleLANPrintDebtSlip` | PHIEU GHI NO — rejects 422 when payment method.type ≠ `on_account`. |
| POST | `/api/lan/print/shift-report` | `handleLANPrintShiftReport` | 精算 cashier-shift settlement (Z) report — printed on shift close from `till_sessions` + orders/payments. Times → shop timezone (`shopLocation`). `report_kind=handover` prints a 引き継ぎ header (plan-046). |
| POST | `/api/lan/print/chain-report` | `handleLANPrintChainReport` | Plan-046 chain aggregate (kết ca cuối) — condensed block per shift + GRAND TOTAL, summed from each member's immutable `settlement_snapshot`. |
| POST | `/api/lan/print/shift-open-report` | `handleLANPrintShiftOpenReport` | レジ開け shift-open (opening cash count) report — printed on shift open from `till_sessions` + opening-phase `till_cash_denomination_counts`. |
| GET  | `/api/lan/print/status` | `handleLANPrintStatus` | Printer role probe + sync cursor age + optional `?order_id=` pending count. |

### Phiếu kết ca: doanh thu GỒM tiền Cloud, ngăn kéo thì KHÔNG (#2934)

Phiếu đọc bảng `payments` **local**, mà bảng đó chỉ nhận ghi từ hai đường LAN
(`local_kiosk.go`, `local_pos_phase5.go`) — **không có đường nào từ sync-DOWN**
(`sync_pull.go:8`: *"orders, payments → workstation is source of truth (UP)"*).
Nên khoản khách trả online qua customer-web (Stripe/PayPay) ghi ở Cloud và
**không bao giờ** thành hàng trong SQLite máy trạm; đơn có sync xuống nhưng chỉ
mang `orders.cloud_payment_summary` — nhãn phương thức, không có dòng tiền.

Quán đọc tổng phiếu như "doanh thu hôm nay" và thấy thiếu. Đó **không phải lỗi
mẫu in**: `ShiftPaymentLine` vốn có hàng theo từng 支払方法 và cờ
`close_report_payment_methods` mặc định BẬT — nó chỉ không có dữ liệu.

`cloud_report_payments.go` kéo tổng hợp Cloud cho cửa sổ ca và trộn vào phần
**trình bày doanh thu**, khử trùng theo payment id.

**Ranh giới phải giữ — tiền Cloud KHÔNG vào `reconcileSession`.** Tổng đối soát
là số của **NGĂN KÉO**: cụm #2876 dựng đối soát ba chân (sổ ↔ máy 釣銭機 ↔ người
đếm) trên đúng con số đó. Cộng một khoản chưa từng đi qua quầy vào đấy sẽ làm
mọi ca lệch đúng bằng doanh thu online và biến một rào tiền đang chạy thành máy
phát báo động giả — hỏng đắt hơn hẳn cái nó định chữa.


**`POST /api/lan/print/vat-invoice` was REMOVED by #1779** (2026-08-04, `a7c0655`)
along with `PullInvoices`, the `invoices` sync feed, the `customer_invoices`
mirror reads and the `POST /pos/orders/{id}/invoices` proxy. Product ruling: the
red invoice is **printed, never stored** — everything routes through
`/api/lan/print/red-invoice`, and formal invoice numbering / void / 赤伝 /
VN e-invoice went with it, knowingly.

`FormatVatInvoice`, `VatInvoiceInfo` and the print kinds `vat_invoice` /
`void_notice` are still in `internal/service` **on purpose**: the golden gate
TR-40 (plan-053) pins them. So a formatter with no caller is the expected shape
here, not a severed wire — don't "reconnect" it.

Both shift reports render via `service.Format{ShiftReport,ShiftOpenReport}` with a locale label catalog (ja/en/vi; Vietnamese ASCII-folded for Shift_JIS) — see `print_shift_report_i18n.go` / `print_shift_open_report_i18n.go`.

Dispatch goes through `printer/dispatcher.go` (`RouteKitchenItem` + `RouteReceipt`) so bar items hit `bar_printer` instead of being silently routed to `kitchen_printer`. All endpoints use `s.fireKitchenForOrder` or its receipt-side equivalents — single source of truth for the per-group loop.

**Giấy tờ TIỀN theo đúng role `receipt_printer`, KHÔNG có fallback (#2593).**
`resolveReceiptPrinter` từng rơi bậc `receipt → hall → kitchen` "để quán một máy
in vẫn có giấy". Nhưng vai trò là **cấu hình người dùng khai** ở Settings: bỏ
tick 「Hóa đơn」 nghĩa là *máy này không được in hoá đơn*, và fallback phớt lờ
điều đó — đo trên máy thật, một máy chỉ tick 「Chạy bàn」 vẫn nhả biên lai của
khách. Checkbox không đổi gì thì tệ hơn là không có checkbox. Nay nó chỉ trả về
máy mang role `receipt_printer`, `nil` nếu không có; ràng buộc này chi phối biên
lai · hoá đơn đỏ · **ngăn kéo tiền** (ngăn cắm vào chính máy quầy) · dòng ledger.
Quán một máy in vẫn được hỗ trợ — chỉ cần tick đủ vai trò.

`nil` KHÔNG phải lỗi và mỗi caller xử một kiểu có chủ đích: auto-print đứng im
**mà không đốt claim** (đốt rồi thì cắm máy vào in tay cũng bị latch chặn, và
handler LAN đọc claim đó thành "đã in rồi" rồi cũng đứng im — mất giấy trong im
lặng), endpoint LAN trả `503 no_printer`, ngăn kéo trả `no_receipt_printer`.
Rào: `print_receipt_role_strict_test.go`. **Đừng dựng lại fallback** — cả bộ 908
test pass ngay sau khi xoá nó, nên test cũ không chặn được bạn.

**Hai câu trên từng ĐÚNG trên giấy và SAI trong code, ở hai chỗ khác nhau
(#2593 vòng 2).** Cả hai đều báo THÀNH CÔNG mà không in gì — hình dạng hỏng tệ
nhất cho chứng từ tiền:

| chỗ | nó thật sự làm gì | hệ quả |
|---|---|---|
| `handleLANPrintReceipt` | `beginMoneyPrint` **đốt số copy + tạo hàng ledger TRƯỚC** mọi nil-check; `printPaymentReceipt` với `p == nil` trả `("", nil)` — không lỗi — nên `finishMoneyPrint` đóng dấu `StatusPrinted` | pos-web nhận `200 {slips_printed:1}`, sổ money-audit ghi một biên lai đã in, và tờ giấy THẬT đầu tiên sau khi sửa config in 「BẢN IN #2」 |
| `handleLocalPaymentAutoPrint` | gọi `claimAutoPrint("receipt")` **thẳng**, bỏ qua nil-guard mà `autoPrintReceiptOnce` có | kiosk tự trả / card-terminal / takeaway: kiosk báo đã in, claim latch 24h nên cắm máy receipt hay tick lại role đều KHÔNG in lại được |

Nay rào 503 chạy **trước** `beginMoneyPrint`, và caller local-payment đi qua
cùng nil-guard rồi phát `broadcastPrintStatus` **failed** để kiosk biết là không
có giấy. Rào: `TestLANPrintReceipt_NoReceiptPrinterIs503AndBurnsNothing`
(assert cả `print_jobs = 0`) và
`TestLocalPaymentAutoPrint_NoReceiptPrinterDoesNotBurnTheClaim`.

Ngoại lệ có chủ đích: `/api/lan/print/order-bill` ("In phiếu order") VẪN rơi bậc
`receipt → hall → kitchen`. Nó là phiếu vận hành, không phải chứng từ tiền.

**ĐƠN TREO không được in biên lai / hoá đơn đỏ (#2063).** Một đơn trả thiếu hoặc
ghi nợ vẫn in được hai tờ giấy tuyên bố quán ĐÃ nhận tiền. Nặng nhất là ghi nợ
toàn bộ: `on_account` cộng thẳng vào `paid_amount` nên đơn đóng bình thường và
mọi màn hình đọc ra "Hoàn thành".

Cổng trả `409 order_on_hold`, và nó đứng **cùng chỗ với rào 503** — TRƯỚC
`beginMoneyPrint`. Cùng một lý do: bước đó đốt số bản in cả khi in lỗi (đúng, vì
lượt in đã xảy ra), nhưng lượt bị TỪ CHỐI thì không có tờ giấy nào, nên đốt số ở
đó làm tờ hợp lệ đầu tiên — sau khi khách trả nợ — ra đời mang 「BAN IN #2」.

Luật hợp thành, và nó phải là HOẶC:

    treo = Cloud nói treo  HOẶC  có nợ ghi sổ LOCAL mà Cloud CHƯA thấy (`cloud_id` rỗng)

Máy trạm **không tự tính được toàn bộ**: khoản thu nợ cưỡi trên MỘT ĐƠN KHÁC và
chỉ trỏ ngược bằng `metadata.settles_payment_id`, mà bản sao local của đơn kia
chỉ có `cloud_payment_summary` — không có metadata. Tự tính thì chỉ BẬT được cờ,
không bao giờ TẮT, và đơn đã trả nợ xong sẽ vĩnh viễn không in được hoá đơn. Vế
hai hẹp đúng bằng cửa sổ tụt hậu và **tự tắt** khi payment sync xong.

`orders.is_on_hold` (migration `086`) giữ BA trạng thái: NULL = Cloud chưa nói,
0 = không treo, 1 = treo. Đọc NULL thành 0 là mở lại đúng hai nút in vừa bị chặn.

**Phiếu ghi nợ và phiếu order KHÔNG bị chặn** — chúng là chứng từ đúng của trạng
thái này. Và luật "in lại: cảnh báo, không chặn" (§4) không mâu thuẫn: luật đó
nói về in LẠI một chứng từ quán CÓ QUYỀN in; ở đây tiền chưa vào nên tờ giấy đó
chưa bao giờ được phép in, kể cả bản đầu.

Phiếu 「ĐÃ THANH TOÁN BÀN X」 (`fireTablePaidSlip`) đi `hall → kitchen → receipt`
— hall trước, vì người dọn bàn đứng ở đó. Nó chỉ bắn khi khách **tự** thanh toán
(kiosk/QR, hoặc Cloud settle rồi sync-down); đường thu ngân tại quầy
(`handleLocalPosCreatePayment`, Handy uỷ quyền vào đây) **cố ý không in** — thu
ngân đứng trước mặt khách nên đã biết. Rào: `auto_print_table_paid_test.go`.

**Reprint: warn, never block (plan-052 §4, #1166).** No LAN print endpoint may
refuse a print — not for a missing reason, not for a role, not because Cloud is
unreachable. What each one owes instead is a trail: the copy number is taken
**before** the paper moves (a failed print still burns its number), copy ≥2
carries the locked 「BAN IN #N」 mark on ALL FOUR money documents (receipt ·
red_invoice · vat_invoice · debt_slip — `service.printReprintMarker`), and every
journal row records WHO: `actor_user_id` in the body where the shape allows,
otherwise the `X-Actor-User-Id` header that `journalPrintFor` reads for every
endpoint at once. An empty actor is a supported answer (nobody signed in) and
never an error. Cloud derives `warned_without_reason` at ingest, so an offline
evening still lands audited.

**The copy number is PER KIND and PER SCOPE, and it lives in `print_jobs` (#1875).**
It used to come from `payments.metadata.print_history` — **one counter shared by
receipt + red_invoice + debt_slip on a payment**, which meant printing a receipt
made that customer's *first* red invoice come out stamped 「BAN IN #2」: the mark
claiming "copy" about an original. `AppendPrintHistory` is gone; existing
`print_history` JSON is left on disk untouched as history.

Money documents now go **`Reserve` → print → `Confirm`** (`internal/printjob/reserve.go`),
wrapped by `beginMoneyPrint` / `finishMoneyPrint`. Four things are load-bearing:

| Thing | Why |
|---|---|
| `MAX(reprint_no)+1` and the INSERT in ONE `BEGIN IMMEDIATE` | Two tablets printing the same slip otherwise both read 0 and both print #1 |
| `Pending()` skips `status='queued'` | A reservation must never reach Cloud — Cloud cannot settle one, and the row would sit claiming a print is in flight forever |
| Startup runs `SweepStaleReservations` → `needs_attention` | Crash mid-print. Never `failed`: nobody knows whether that sheet came out, and "it didn't" would have the shop reprint a slip the customer is holding |
| The number is reserved before `Connect()` | A failed print keeps its number (P-12) — the counter cannot be rewound by unplugging the printer |

**Scope** is decided in one place, `handler/resolvePrintScope` (`print_scope.go`):
the named payer when the caller targets one (chia đều / theo tiền / theo món each
create one payment per guest, so the payer's id IS the identity); else the order's
sole non-split payment (the one-payer order is printed from a dialog that sends no
`payment_id`, and both UI paths must share a counter); else the whole **order
family** (`linkedOrderIDs` — a merged table would otherwise issue #1 twice).
`lastConfirmedPaymentID` was deleted with this: an untargeted red invoice used to
borrow the *last guest's* payment to count against.

**Auto-printed receipts now journal too.** They previously called
`printPaymentReceipt` directly with a hard-coded `1` and wrote **no ledger row at
all** — the most common sheet a shop produces was the one the ledger could not
see, and it consumed no number, so the cashier's next "In biên lai" also printed
an unmarked #1. Entry point: `autoPrintPaymentReceipt`. With no printer
configured it still journals nothing (the print is a no-op; a row would be a lie).

`GET /api/lan/print/status?order_id=` answers "has this order had a red invoice
printed, and for which payer?" — `printed` + `order_scope` + `by_payment[]`, per
kind, read from local SQLite so it stays right with the internet down. There is
**no stored flag**: a boolean on `orders` cannot say WHICH guest already has
paper, and a second copy of that truth drifts from the ledger.

**Delta kitchen printing.** Fire selection, the 422 pre-check, and the `open_items_pending_print` badge count all key off `unprintedQty(item) = quantity - printed_quantity` (clamp 0) — see `handler/fire_kitchen.go`. `order_items.printed_quantity` (migration `034`) records how many units of a line have been sent to the kitchen; `MarkItemPrinted(itemID, printedQty, printedAt)` sets it to the line's full quantity after a fire. Bumping the quantity of an already-fired line reopens a delta, and `fireKitchenForOrder` prints **delta-quantity copies** so the ticket shows only the newly-added units (toppings/note carry over). The upgrade migration backfills `printed_quantity = quantity` for existing `sent_to_kitchen` lines so it never re-prints open orders.

WS event `order.kitchen_printed` is recorded into the 60s `kdsReplayBuffer` (T9.1) so KDS clients reconnecting with `?since=<RFC3339>` see missed fires before live events resume.

## 釣銭機 — phiên thu tiền sống sót qua restart (#2535 B10)

Phiên thu tiền mặt từng sống HOÀN TOÀN trong bộ nhớ (`CashChangerService.active`),
nên một lần tắt máy giữa lúc máy đã nhận tiền và trước khi `RecordCashPayment`
chạy xong để lại: **không payment, không alert, không queue item, không gì
reconcile**. Tiền thật trong ngăn kéo, hệ thống không biết nó tồn tại. #1810 đã
sửa phần POS treo spinner; phía TIỀN thì đây mới là bản vá.

Ba mảnh, và cả ba đều load-bearing:

| Mảnh | Vì sao |
|---|---|
| Hàng `cash_changer_sessions` ghi **TRƯỚC** khi gọi máy (migration `081`) | Một hàng thừa tự đóng ở lượt đối soát kế; một lượt thu KHÔNG có hàng nào là thứ không tìm lại được |
| `glory.WithTransactionStarted` đóng dấu id giao dịch **ngay khi máy trả nó** | `GetTransaction` cần id. Không có id thì lượt đối soát không hỏi được máy và chỉ còn cách báo người |
| `ReconcileUnfinishedSessions` chạy **một lần lúc khởi động** | Cách duy nhất một phiên sống lâu hơn lượt thu của nó là workstation dừng lại, nên lượt chạy này CHÍNH LÀ lượt phục hồi. Cùng khuôn `SweepStaleReservations` |

Bảng xử theo câu trả lời của máy:

| Máy trả | Việc |
|---|---|
| `finish` | ghi payment — an toàn để lặp, `RecordCashPayment` idempotent trên `glory:<txn>` |
| `cancel` | đóng hàng, không ghi gì (tiền đã trả lại khách) |
| timeout / abort / failure | alert `cash_retained` |
| **không hỏi được / chưa có id** | alert `cash_collected_not_recorded` — **không đoán** |

Hai luật đi kèm:

- **Không chạy nền, không định kỳ.** Một vòng nền hỏi máy giữa lúc đang có giao
  dịch chạy là cách dựng ra đúng loại đua mà cả luồng này đang tránh.
- **Lỗi ghi phiên KHÔNG chặn lượt thu.** Khách đang đứng trước máy: mất khả năng
  phục hồi vẫn tốt hơn mất khả năng bán. Nó log ở mức `error` và đi tiếp.

`stampRunningCashSession` tra "phiên chưa resolved mới nhất chưa có id" vì máy
chỉ có MỘT và service giữ mutex suốt lượt thu. **Có máy thứ hai thì chỗ này phải
đổi trước.**

### Sổ lượt thu đi lên Cloud (#2878)

Migration `090` mở rộng `cash_changer_sessions`, và
`PushCashDeviceTransactionsUp` đẩy nó lên `POST /api/v1/workstation/cash-device-transactions`
mỗi phút (nhịp riêng, không dùng chung với đường alert). Trước đó Cloud **không
biết máy 釣銭機 tồn tại**: `grep -rn "glory" backend/` ra đúng hai dòng comment
trong seeder, và mọi dấu vết nằm trong `order_payments.metadata` — JSON không
index. Lượt HỎNG thì không để lại gì cả, vì `order_payments` chỉ có hàng khi thu
được tiền.

Ba cột tách đôi thứ trông như một. Gộp lại là đúng bẫy #2860 (bảy cách viết cho
ba khái niệm, sống nhiều tháng, không gì đỏ):

| Tách | Vì sao |
|---|---|
| `machine_outcome` ≠ `outcome` | `outcome` là từ vựng PHỤC HỒI (`recorded`/`returned`/`retained`/`unknown` — **ta** đã làm gì); `machine_outcome` là từ vựng MÁY (`glory.Status` nguyên văn — **máy** đã làm gì). Ánh xạ lossy cả hai chiều: `unknown` có thể là abort hoặc failure và ta không phân biệt được |
| `peripheral_device_id` ≠ `server_id` | `cashChangerServerID()` ưu tiên `metadata.server_id`/`serial` — chuỗi do người lắp máy đặt. Cloud khoá theo `peripheral_devices.id`, nên quán có khai serial sẽ đẩy lên một khoá không tra được. Dùng `cashChangerDeviceID()` cho sổ |
| `synced_at` ≠ bảo đảm đúng đắn | Cloud idempotent theo `(máy, mã giao dịch)` lo phần đó; cột này chỉ để **khỏi** đẩy lại. Nhầm hai vai sẽ dẫn tới dựng một hàng đợi thứ hai cho thứ đã được bảo đảm ở đầu kia |

Điều kiện đẩy là `machine_outcome <> ''`, **không** phải `resolved_at IS NOT NULL`:
một phiên có thể bị đóng vì hết giờ mà chưa bao giờ hỏi được máy, và đẩy hàng đó
lên là đẩy một **khẳng định bịa về tiền**.

`sideChannelPost` (tách ra từ `alertCloudPost`) giữ luật #2695 ở MỘT chỗ: đường
đẩy phụ **tôn trọng** cooldown nhưng không được **tạo ra** nó — một endpoint phụ
hỏng mà đặt `cooldownUntil` toàn cục sẽ chặn đường đẩy đơn hàng và thanh toán.

### 在高 + sổ sự cố (#2879 · #2882)

Migration `091` thêm hai sổ SQLite; `PushCashObservationsUp` đẩy chúng lên
`cash-device-inventory` và `cash-device-errors` (nhịp riêng, cùng luật fail-open
và cùng `sideChannelPost` với sổ lượt thu).

**`GetInventory` / `GetStatus` đã có trong adapter từ đầu và trước #2879 KHÔNG
AI GỌI** — đo được bằng `grep -rn "GetInventory" internal --include=*.go` (trừ
file khai + test) → RỖNG. Nên chốt ca chỉ có hai chân: sổ ↔ NGƯỜI đếm.

Ba điều dễ làm sai ở phía Go:

| | |
|---|---|
| `CashErrorStatus.Cash` nằm ở struct KHÁC với `CashCount.Cash` | Đọc cái đầu mà quên cái sau là dựng đối soát trên số liệu máy **không bảo đảm**. `uncertainDenominations()` rút đúng tập đó |
| Chụp 在高 ở **ranh ca**, không giữa lượt bán | Hai API an toàn khi máy đang chạy giao dịch, nhưng hỏi chen vào lúc `CashChangerService` giữ mutex là thêm một biến vào chỗ không cần |
| MỘT LẦN XẢY RA = MỘT HÀNG | Collector poll theo `pollInterval` nên một sự cố 2 phút đi qua hàng trăm lần. `RaiseDeviceError` tra hàng ĐANG MỞ trước; `occurred_at` lấy từ hàng đó chứ không phải `now()` |

`ErrorGroupFor` chỉ nhận **bốn** nhóm; `IsBusy`/`IsNotFound`/`IsNotEnoughDeposit`
cố ý trả chuỗi rỗng = KHÔNG vào sổ. Lỗi ngoài adapter (ctx hết giờ) cũng rỗng —
cột `error_group` mang từ vựng của MÁY.

### Nhiều máy 釣銭機 (#2881)

`cashChangerDeviceID()` trả **rỗng + alert `cash_device_ambiguous`** khi có ≥2
máy `coin_changer` đang bật, thay vì đoán. Bán hàng vẫn chạy; mất khả năng quy
máy nên hàng không đẩy lên Cloud được, và mã giao dịch VẪN đóng dấu để lượt đối
soát khởi động còn hỏi lại máy.

Routing thật (client + mutex theo máy) **chưa làm** — hôm nay 0 quán hai máy.
Đừng đổi mutex mà không đổi cách chọn phiên: nó tạo ra đúng cái lỗi đang chặn,
chỉ nhanh hơn.

Toàn bộ ngữ nghĩa + phép đối soát ở Cloud:
`docs/guide/cash-device-observation.md`.

## Log điều tra — lọc tại NGUỒN, đẩy theo YÊU CẦU (#2901)

Trước bản này máy trạm **không có logger dùng chung**: `slog` mặc định ra stderr
của một tiến trình Windows không ai nhìn, `slog.SetDefault` không xuất hiện ngoài
test, và Cloud không có endpoint nào nhận log. Điều tra một sự cố nghĩa là ngồi
trước chính máy đó — mà fleet là hai máy Windows ở hai quán.

Nên việc này gồm cả **dựng chỗ ghi**, không chỉ "gửi cái đang có":

| Mảnh | Ở đâu |
|---|---|
| `slog.Handler` bọc — chuyển tiếp NGUYÊN VẸN xuống stderr rồi ghi bản đã lọc | `internal/service/log_buffer.go` |
| Bảng allowlist chạy được (nguồn chân lý là tài liệu, xem dưới) | `internal/service/log_allowlist.go` |
| Vòng hỏi–đẩy theo yêu cầu treo | `internal/service/log_sync_up.go` |
| Vòng đệm + tiến độ theo yêu cầu | migration `092` |

**"Kéo" cài thành "yêu cầu treo".** Cloud không gọi ngược vào máy trạm được (LAN
sau NAT, không địa chỉ công khai), nên chiều vận chuyển luôn là máy trạm → Cloud:
HQ ghi một yêu cầu `pending` → máy trạm thấy ở nhịp kế → lọc tại chỗ rồi đẩy.
Tinh thần giữ nguyên và đó mới là điều quan trọng: **log không rời quán cho tới
khi có người yêu cầu**. Hai đánh đổi phải nói thẳng — trễ một nhịp, và **máy
chết thì không lấy được**, đúng lúc cần nhất.

Bốn bất biến, đừng "tối ưu" cái nào:

- **ALLOWLIST, không blocklist.** 305 thông điệp khác nhau, và `"name"` xuất
  hiện ở 348 chỗ · `"note"` 77 · `"email"` 17 · `"phone"` 15 · `"qr_token"` 11.
  Blocklist fail-open: thêm một trường PII mới ở đâu đó là nó tự chảy lên Cloud.
  #2220 đã trả giá đúng kiểu đó, **revert không thu hồi được**.
- **Khai một attr là xét GIÁ TRỊ, không xét TÊN.** `err` là cái tên vô hại,
  nhưng ở `sync push failed` giá trị của nó là thân phản hồi Cloud nguyên văn
  cho một `order.create` — payload đơn hàng dội ngược kèm tên khách. Dòng đó
  khai `id`/`entity`/`retryable` và **cố ý không khai `error`**.
- **Ca thường ngày IM LẶNG.** Không yêu cầu treo ⇒ không một request đẩy nào.
- **KHÔNG BAO GIỜ chạm backpressure dùng chung** (#2695): qua `sideChannelPost`
  ở chiều POST và một client thô ở chiều GET. Tôn trọng cooldown, không tạo ra
  nó. Chiều GET cố ý **không** dùng `cloudGet` của puller: `onUnauthorized` ở đó
  **xoá device token của quán**, và một route chẩn đoán chưa deploy không được
  phép làm điều đó.

Bảng allowlist là **một nguồn cho hai đầu**:
`docs/reference/workstation-log-allowlist.md`. `TestLogAllowlist_MatchesTheSharedContractDocument`
đối chiếu HAI CHIỀU với nó — chiều "doc ⊆ Go" bắt việc khai cho Cloud mà quên
máy trạm (hỏng IM LẶNG: Cloud nhận đúng, máy trạm không bao giờ gửi), chiều
"Go ⊆ doc" bắt việc mở một dòng mà chưa ai xem lại nó mang gì.

**Deploy backend TRƯỚC.** Trong cửa sổ giữa hai lần deploy, 404 là trạng thái
BÌNH THƯỜNG: đếm vào `NotDeployed`, không phải `Failed`, không alert.

## Phát hành (#1827)

Trước #1827 repo **không có đường phát hành nào**: `on:` không nghe tag, không job
nào gọi `gh release create`, và GitHub Release duy nhất (`v0.0.1`, 2026-07-23) ra
đời **trước** bản sửa mà quán đang chờ. "Cắt release" không phải việc ai làm được.

```sh
git tag v0.3.0 && git push origin v0.3.0
```

Tag `v*` chạy **toàn bộ cổng** (lint → test → frontend → pos-web → build) rồi mới
`release`. Không phát hành từ thứ gì nhẹ hơn: điểm của việc có cổng là artifact
người ta cài đã đi qua nó.

**`ws-server` LÀ bản cài của quán, không phải bản rút gọn.** `cmd/ws-server` phục
vụ chính bộ frontend nhúng ở `/`, chính pos-web ở `/pos`, và mở database qua chính
`store.Open` (nên nó mang mọi migration). `cmd/workstation` là **cùng server đó bọc
trong webview Wails** — một cửa sổ tiện lợi, không phải sản phẩm khác. Quán chạy
binary này rồi vào `http://<ip>:<cổng>/`. Vì vậy chuyện GUI vướng cgo/toolchain
**không chặn** việc phát hành, và comment cũ trong `ci.yml` (*"the real shop release
is the GUI binary"*) là sự thật về **cái CI không dựng được**, không phải về sản phẩm.

Ba thứ dễ hỏng đã có rào trong job:

| Rào | Vì sao |
|---|---|
| Preflight `gh --version` chạy **trước** mọi thứ | `gh` không được job nào khác dùng ⇒ sự hiện diện của nó trên runner self-hosted là chưa được chứng minh. Thiếu rào này thì job chết ở bước cuối, sau khi đã tải + checksum xong, và trông như "release hỏng" chứ không phải "runner thiếu công cụ" |
| Bắt buộc đủ **5** binary trước khi publish | Phát hành thiếu 2 nền tảng trong im lặng để hai hệ điều hành không có đường nâng cấp mà không ai nhìn |
| `config.Version` đóng dấu **tên tag** khi build từ tag (thay vì `ci-<sha>`) | Đó là giá trị `GET /api/lan/health` và `/version` công bố. "Quán này đang chạy bản nào" hiện **không trả lời được** (#1822); đóng dấu tag là nửa đầu của việc bao giờ đó trả lời được |

## UI frontend — CHỈ @godxjp/ui, cấm Tailwind trần (ruling 2026-08-18)

`workstation/frontend` dùng đúng MỘT thư viện UI: **`@godxjp/ui`** (dòng npm
18.x — subpath import `general` · `admin` · `layout` · `data-display` ·
`data-entry` · `navigation` · `feedback`). Icon đi `lucide-react` (chính
@godxjp/ui dựa trên nó; emoji bị cấm toàn repo).

- **Cấm thêm thư viện UI khác** — kể cả import `@radix-ui/*` trực tiếp (Radix
  chỉ được đi QUA @godxjp/ui). Rào máy: `npm run test:ws-frontend-ui`
  (`scripts/workstation-frontend-godx-ui-only.test.mjs`) — allowlist
  dependencies + quét import; chạy trong omnify-gate trên mọi PR.
- **UI mới không tự dựng bằng Tailwind trần**: nút, card, dialog, form control
  phải là component @godxjp/ui; className chỉ dành cho layout/spacing quanh
  chúng. Màn cũ còn div Tailwind tự chế thì chuyển dần khi chạm vào — đừng
  thêm mới. (Luật này là CHỮ, không đo bằng regex được — một bộ đếm className
  không phân biệt nổi layout hợp lệ với button tự chế.)
- Tra cứu component/props: MCP server **godx-ui** (`.mcp.json`, chạy
  `npx @godxjp/ui-mcp`).
- Đổi lineage (ví dụ về bản in-tree `web/packages/godx-tempo-ui` mà pos/admin
  dùng) là quyết định kiến trúc — sửa test rào kèm lý do, đừng lách.

## Key Conventions

- Module: `github.com/dxs-platform/workstation-app`
- Config dir: `~/.ws-app/` (override: `WS_APP_CONFIG_DIR`)
- Database: `~/.ws-app/ws-app.db` (SQLite WAL mode)

## Runtime configuration (`.env`)

`config.LoadDotEnv()` runs first in both `cmd/workstation` and `cmd/ws-server`,
**before** `config.NewManager()` — the manager reads `WS_APP_*` during
construction and persists the result into `config.json` on first run, so loading
later would silently no-op on a fresh install.

Search order (first readable file wins): `$WS_APP_ENV_FILE` → `<exe dir>/.env` →
`<config dir>/.env` → `./.env`. **The real process environment always beats the
file** — a `.env` only fills gaps, which is what keeps the `make dev` /
`wails3 task dev` overrides authoritative on a machine carrying a production
`.env`. Parser handles CRLF and UTF-8 BOM (Notepad); malformed lines are skipped,
never fatal. See `.env.example` for the full variable list.

| Variable | Notes |
|---|---|
| `WS_APP_CLOUD_URL` | **First run only** — `config.json` wins afterwards |
| `WS_APP_SERVER_PORT` | 1024–65535, else reset to 8080 |
| `WS_APP_LAN_IP` | Pins the advertised LAN address; leave empty normally |
| `WS_APP_CONFIG_DIR` | Must be a real env var — it locates the `.env` itself |
| `WS_APP_TUNNEL_HOSTS` | Opt-in CORS/WS host allow-list; unset = strict |

## LAN address detection + endpoint export

`GetLANAddress()` (`internal/handler/lanaddr.go`) asks the OS which source
address it would use to reach a public host (UDP dial — no packets sent, no
internet required, only a default gateway), because virtual adapters never carry
the default route. Ranked enumeration is the fallback; `GetLANAddresses()`
returns every private IPv4, best first, with `virtual` and `preferred` flags.

This replaced a prefix test (`ip[:3] == "192"`) that would happily return
VirtualBox's `192.168.56.1` — an address no LAN client can route to.

`StartEndpointExporter()` writes `<config dir>/endpoint.json` at startup and
re-checks every 60s, logging a warning when the address changes under a running
process (DHCP lease). Same data on `GET /api/lan` (loopback-only), plus a
`pinned` flag when `WS_APP_LAN_IP` is set.
- Wails bindings: Run `wails3 generate bindings` after changing Go service methods
- Frontend calls Go via Wails bindings (not REST) for desktop UI
- Local HTTP server (port 8080) is for LAN tablet/phone clients only
