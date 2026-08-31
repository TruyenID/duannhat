---
title: Cashier shift recovery (plan-032)
category: guide
tags: [pos, cashier, shift, till, runbook, ops]
summary: How operators recover a stuck cashier shift — when to use force-abandon vs scheduler-expire vs manual-settle, plus emergency overrides.
related:
  - explanation/observability.md
---

# Cashier shift recovery runbook

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

> Plan-032 ships three exit doors on top of plan-030's `open → settled / abandoned` state machine. Pick the right one for the situation; each leaves a different audit trail.

## At a glance

| Situation | Action | Where | Audit trail |
|---|---|---|---|
| Manager needs to unblock the till **right now** (cashier forgot, device dead, ca treo blocking currency change) | **Force-abandon** | admin-web `/shop/<slug>/till/sessions` | `force_abandoned=true` + `force_abandoned_by_id` + `force_abandon_reason_code` + audit log `till_session_force_abandoned` |
| Nobody's at the keyboard. Session opened > 48h ago with no recent payment | **Wait — scheduler will expire it within 1 hour** | (hands-off) | `status='expired'` + `expire_reason='no_activity'` + `expire_threshold_hours=48` + audit log `till_session_expired` (no `closed_by_id` — system action) |
| Manager later recovers a paper count for an expired session and wants the revenue dashboard to show "settled" | **Manual-settle** | admin-web `/shop/<slug>/till/sessions` → expired session → "Manual settle" | `status='settled'` + audit log `till_session_manual_settled` (with `prior_status='expired'`); separate audit row for any opening_counts_override / post_hoc_cash_events |

## Business date on open (#2781)

Opening a shift stamps `till_sessions.business_date` from the **branch** timezone via `BusinessClock::businessDateAt`, never the UTC calendar date. A workstation payload with `opened_at=2026-08-12T19:00:00Z` at `Asia/Ho_Chi_Minh` is business day **2026-08-13**. The ratchet is in `WorkstationTillSessionServiceTest`. The one-clock rule lives in [Business time](business-time.md).

## Force-abandon (immediate manager exit)

Use when:

- Cashier forgot to close their shift and the next cashier can't open (409 `SHIFT_ALREADY_OPEN`).
- POS device died mid-shift, cashier never reached close-page.
- Admin tried to flip currency and hit plan-031's `409 CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT`.

**Required role:** `org-admin`, `org-manager`, or `shop-manager`.

**Required input:**
- `reason_code` (categorical): one of `cashier_forgot_to_close`, `cashier_unavailable_today`, `pos_device_failure`, `network_outage`, `end_of_employment`, `other`.
- `reason_detail` (free text): required (≥ 20 chars) only when `reason_code='other'`.

**Side effects:**
- Session flips to `abandoned`, `force_abandoned=true`, `force_abandoned_by_id=manager.id`.
- `till.current_session_id → NULL` → next cashier can open immediately; plan-031 currency guard releases.
- Payments retain `till_session_id` (revenue reports stay intact).
- **NOT** `expired` — see Decision 2: manager-driven exits are categorically distinct from system-driven expires.

**Reversibility:** None. Force-abandon is terminal.

## Scheduler expire (hands-off)

The `tills:expire-stale-shifts` Artisan command runs hourly in production. It scans for sessions where:

```
status IN ('open', 'closing')
AND opened_at < now() - POS_SHIFT_STALE_TIMEOUT_HOURS (default 48)
AND NO OrderPayment in the last POS_SHIFT_STALE_ACTIVITY_WINDOW_HOURS (default 6)
```

For each match, it calls the service-layer `expire()`, which re-checks the activity window **inside a locked transaction** so a payment landing between the outer SELECT and the inner UPDATE is a skip-and-no-op, not an orphaned payment on an expired session.

**Tunables** (env vars, read in `config/pos.php`):

| Env var | Default | What |
|---|---|---|
| `POS_SHIFT_STALE_TIMEOUT_HOURS` | `48` | Session age before it becomes a candidate. Reduce to 24 for daily-business shops. |
| `POS_SHIFT_STALE_ACTIVITY_WINDOW_HOURS` | `6` | Trailing window the EXISTS clause checks. Generous enough for natural lulls (3am dead hour); tight enough that a genuinely dead session is reaped within ~54h. |

**Local dev:** umbrella's docker-compose does NOT include a scheduler service. Test manually:

```sh
docker compose exec app php artisan tills:expire-stale-shifts --dry-run
docker compose exec app php artisan tills:expire-stale-shifts
```

**Production:** scheduler already runs (precedent: `payments:expire-stale->everyMinute()` in `routes/console.php` has been live since plan-008). No deployment action required.

## Manual-settle (post-hoc reconciliation)

Use when a previously-expired session needs to be reconciled — typically because the manager later recovers the paper count from the cashier or counts the drawer manually.

**Required role:** manager+ (same as force-abandon).

**Required input:**
- `closing_counts` — same shape as `close()`.
- `tender_details` — same shape as `close()` (can be `[]` if no payments).
- `manual_settle_reason` — required, min 20 chars.

**Optional input (Decision 8b):**
- `opening_counts_override` — replaces the session's recorded opening counts with the manager's recount. When supplied, a SEPARATE audit row `till_session_opening_overridden` is written with the before+after payload.
- `post_hoc_cash_events` — inserts cash events the cashier never logged (drops/paid-in/paid-out). Each marked `manual_adjustment=true` with `performed_by_id=manager.id`.

**Side effects:** identical to `close()` — variance computed, settlement details persisted, status → `settled`. Audit row `till_session_manual_settled` carries `prior_status='expired'`, the override/post-hoc flags, and the manager identity.

## Emergency: disable the scheduler

If the scheduler misbehaves (false positives, bad config, infra problem), there are three escalating overrides:

1. **Set the threshold to a value > realistic shift age.** `POS_SHIFT_STALE_TIMEOUT_HOURS=720` (30 days) effectively disables expiration without code change.
2. **Stop the scheduled command.** Comment out the `Schedule::command('tills:expire-stale-shifts')` block in `backend/routes/console.php` and redeploy.
3. **Stop the entire scheduler.** Stop the production cron / k8s CronJob that runs `php artisan schedule:run`. Side effect: ALL ~12 scheduled commands stop (`payments:expire-stale`, `notifications:tick-recurring-schedules`, `material-lots:scan-expiring`, etc.). Only use as a last resort.

## Shift report: online money is in the REVENUE block, never in the drawer count (#2934)

A shop reported "the close-out slip only prints cash, the Stripe money is
missing". It was not a template bug. The slip reads the workstation's **local**
`payments` table, and that table only ever receives LAN writes
(`local_kiosk.go`, `local_pos_phase5.go`) — there is no write path from
sync-DOWN, which `sync_pull.go:8` states outright: *"orders, payments →
workstation is source of truth (UP)"*. Money a guest pays online through
customer-web is recorded in Cloud and never becomes a row on the workstation;
the order does sync down, but carries only `orders.cloud_payment_summary` —
method labels, no per-method amounts. `ShiftPaymentLine` already knew how to
print Stripe; it simply had nothing to print.

`cloud_report_payments.go` now pulls the Cloud-side totals for the shift window
and merges them into the **revenue presentation**, deduped by payment id.

**The boundary that must hold: Cloud money stays out of `reconcileSession`.**
That total is the *drawer* figure, and the #2876 three-way reconciliation (book
↔ 釣銭機 ↔ human count) is built on it. Folding in money that never crossed the
counter would make every shift short by exactly the online revenue and turn a
working money guard into a false-alarm generator — a more expensive failure than
the one it set out to fix.

So the slip answers two different questions in two different blocks: *what did
we sell* (includes online) and *what should be in the drawer* (does not).
## Bàn bị giữ vì một lượt quét QR hỏng — hai ngưỡng, không phải một (#3010)

Quét QR bàn lật `free → occupied` và mở `TableSession` **trước khi có đơn nào**
(`CustomerTableSessionService`). Bàn đó đang có người thật, nên trạng thái ấy
đúng — nhưng nếu lượt đặt món sau đó hỏng, hoặc khách đổi ý, bàn kẹt `occupied`
mà chưa từng có gì xảy ra trên nó.

Đo được ở 本郷店 lúc 18:04 CN 16/08/2026, giữa ca tối: C-1 `occupied` không đơn,
và tab bên cạnh của chính máy đó hiện `betoya.jp | 524`. Với ngưỡng cũ (4 giờ,
quét mỗi giờ) bàn ấy mất **cả buổi tối**.

`dine-in:expire-stale-sessions` nay có **hai** ngưỡng:

| phiên | ngưỡng | vì sao |
|---|---|---|
| đã từng có đơn | `--hours=4` | khách đang ăn. Cắt ngắn là đuổi họ khỏi bàn của chính họ trên màn hình |
| **chưa có đơn nào** | `--empty-minutes=45` | quét rồi thôi — chưa có gì xảy ra để mà bảo vệ |

Ranh giới là **"đã từng có đơn"**, không phải "còn đơn sống": khách trả xong ngồi
lại uống nốt vẫn là khách đang ngồi, và đuổi họ sau 45 phút vì đơn đã đóng sẽ sai
đúng vào ca đông nhất.

**Nhả sớm rẻ vì nó tự chữa** — khách còn ngồi mà quét lại thì `joinOrStart` lật
`occupied` lần nữa và mở phiên mới. Nhả muộn thì bàn nằm chết. Hai chiều sai
không cân nhau, nên rào lệch về phía nhả.

Lịch chạy đổi từ **mỗi giờ → mỗi 15 phút**. Giữ nhịp một giờ thì ngưỡng 45 phút
gần như vô nghĩa: quét lúc 18:01 phải chờ tới 19:00, tức ~1h45 ngoài quán.

Điều kiện nhả bàn **không đổi** (#2524): còn đơn sống — của phiên này *hoặc* của
bàn — thì không nhả, dù phiên đã hết hạn.

## Observability

Plan-032 ships log-based signals (Sentry is not wired in this project as of 2026-06-10). DevOps alerting filters on the tag prefix `[pos.till]`:

| Level | Tag | Emitter | Trigger |
|---|---|---|---|
| INFO | `[pos.till] expire-run` | `ExpireStaleShifts` | End of every run, with candidate/expired/skipped/duration counters |
| ERROR | `[pos.till] scheduler-stale` | `CheckTillsSchedulerFreshness` (hourly) | Heartbeat cache key `pos.tills.last_run_at` is older than 6h or missing |
| WARNING | `[pos.till] expire-spike` | `ExpireStaleShifts` | One run expired > 20 sessions |
| WARNING | `[pos.till] expire-slow` | `ExpireStaleShifts` | One run took > 5 min |
| WARNING | `[pos.till] force-abandon-rate` | `CheckForceAbandonRate` (daily 06:00 JST) | Any single `force_abandoned_by_id` exceeded 5 force-abandons in trailing 7 days — fraud signal |

## When to NOT use any of these

- **Cashier is still actively working** but the system flipped them to expired. This shouldn't happen — the activity window prevents it. If it does, file a bug, don't routinely manual-settle on top of it.
- **The shift was settled and now you want to "open it back up"**. Settled is irreversible by design (BR-TS07). Open a new shift and treat the next sales window as a fresh day.

## Gap reconciliation at shift open (plan-044 R2)

Between one shift's **close (精算)** and the next shift's **open (レジ開け)** there is no open cashier shift — yet payments can still be taken (a customer pays while the drawer is being counted). Those payments land with `till_session_id = NULL` ("gap payments"). Plan-044 R2 handles this **manually at the next open** — there is deliberately **no background carry-over queue**.

What the operator sees and does:

- **Unpaid orders need no action.** They simply stay open and carry into the next shift naturally. The close (精算) counts **only paid orders**; the close screen shows a "paid vs unpaid-carry" summary (`GET /pos/till/sessions/{id}/order-summary`) so the cashier can see what's carrying.
- **Gap payments are reconciled at open.** The open screen shows a panel listing every NULL-attributed gap payment (`GET /pos/till/gap-preview`). The cashier ticks the ones that belong to the new shift; those stamp onto the new session at open.
- **Số hiện trên panel là NET, và khoản hoàn MỘT PHẦN vẫn nằm trong danh sách** (#2744). Cùng lý do đã ghi ở §"vị ngữ tiền" bên dưới: sổ giữ hàng gốc `+X` rồi flip `refunded` kể cả khi chỉ hoàn một phần, nên `status = succeeded` từng loại mất một khoản 5000 đã hoàn 1000 — và **4000 thật trong ngăn kéo không gắn vào ca nào**, chỉ lộ ra thành variance lúc đóng. Panel hiện `4000` (kèm `gross_amount` để đối chiếu), vì đó là số tiền thu ngân đếm được trong tay. Hoàn HẾT thì biến mất — không còn gì để gán. Vị ngữ này dùng **chung một hàm** cho cả đường đọc (`gapPreview`) lẫn đường ghi (`claimGapPayments`): lệch nhau nghĩa là panel mời tích một khoản mà lượt claim từ chối đóng dấu.
- **Claim đóng dấu CẢ hàng hoàn, không chỉ hàng gốc.** `reconcile()` (#523) tính kỳ vọng ngăn kéo bằng `SUM(amount)` trên các hàng mang `till_session_id` của ca. Đóng dấu mỗi hàng gốc `+1.000` mà bỏ hàng hoàn `−300` lại NULL thì ca bị đòi **1.000** trong khi thu ngân ack **700** và cầm 700 thật ⇒ ca short đúng bằng số đã hoàn, và −300 vĩnh viễn không ca nào gánh. Đường LAN (`POST /workstation/payments/{id}/attribution`) đã đóng dấu hàng hoàn từ trước; bản Cloud-direct nay khớp với nó.
- **Chỉ hàng hoàn còn `till_session_id IS NULL` mới trừ vào NET.** Hoàn xảy ra SAU khi ca kế đã mở thì `reconcile()` đã trừ −Y vào ngăn kéo của **chính ca đó**; trừ lần nữa ở preview là đếm hai lần, và tiền giữ riêng thật lúc ấy là GROSS.
- **Gap cash is claimable.** Cash taken during the gap was physically **held aside by staff** (not folded into the opening float), so it is real money owed to the new shift. Ticking any cash row requires a **"held-separately" acknowledgement** — this gates the open submit and is audited (`till.gap_claim` / `till_session.gap_claim`). Do NOT tick cash that was already dropped into the drawer as opening float, or it double-counts.

**No cash-flow risk.** Cloud `reconcile()` computes the 過不足 (over/short) from `order_payments.till_session_id` only — never from order attribution — so removing the queue changes no money (regression-locked by `ReconcileOrderAttributionIndependenceTest`). A gap payment left unclaimed is not lost: it stays NULL and simply re-appears in the *next* shift's gap-preview to be claimed then.

**Backend ⇄ workstation always converges.** When a shift is opened on the workstation (LAN), the claim propagates to Cloud via the `payment.attribute` sync op → `POST /workstation/payments/{id}/attribution` (endpoint D). It is idempotent, branch-guarded (R6), never dead-letters, and retries until Cloud has the session — after which the workstation adopts Cloud's authoritative value, so the two databases end byte-identical.

## Đơn còn treo tiền ở lần mở ca kế (#2696)

Anh em của mục trên, **cùng màn hình, khối riêng** — và đừng gộp hai bảng lại:

| | gap-preview | unresolved-orders |
|---|---|---|
| Đo | khoản thu `till_session_id IS NULL` — **tiền đã vào**, chưa gắn ca | **đơn** còn `paying`/`checkout` — tiền có thể **chưa vào** |
| Thu ngân làm gì | tick để gắn khoản thu vào ca mới | truy xem khách đã trả chưa → đóng hoặc huỷ đơn |
| Endpoint | `GET /pos/till/gap-preview` | `GET /pos/till/unresolved-orders` |

Trộn chung sẽ khiến thu ngân "gắn ca" cho một khoản **chưa hề tồn tại**.

**Ngưỡng là RANH CA, không phải số giờ** (ruling chủ dự án 2026-08-13). Đơn tính
là kẹt khi nó còn `paying`/`checkout` mà đã sống qua lần đóng ca gần nhất. Đơn
kẹt 20 phút nhưng vắt qua lúc đóng ca **vẫn** hiện; đơn mở 18:00 và đóng 22:00
trong CÙNG một ca thì không. Ngưỡng theo giờ phải bịa một con số, và sai theo
hướng nào cũng dạy người ta bỏ qua cảnh báo.

Cửa sổ quét có **đúng MỘT ranh, và là ranh TRÊN**: `created_at < mốc`, exclusive.
**Không có ranh dưới** — đơn sinh trước lần đóng ca gần nhất vẫn phải hiện, vì
đơn kẹt càng cũ càng nguy. `mốc` = lúc mở ca đang chạy (đơn sinh trong ca đang
chạy là việc của ca này), hoặc `now()` khi chưa ca nào mở; nó không bao giờ lùi
trước `prev_end`. `prev_end` chỉ là **sàn của mốc**, KHÔNG phải ranh dưới của
`created_at` — cài nó thành ranh dưới là tái sinh đúng bug #2723.

Vị ngữ đầu tiên (`created_at < prev_end`) bỏ sót đúng nhóm dễ mất nhất: đơn
web/tablet sinh ra **sau khi két đóng** — tức TRONG cửa sổ gap — rồi trả một
phần. Chúng vô hình trọn một ca.

**Két nào định ranh ca**: đường quét ĐƠN giải theo till thật của chi nhánh
(#2724), vì `open()` nhận `till_code` tự do còn ranh ca trước đây đóng đinh
`MAIN`. Chi nhánh chỉ chạy `SUB` thì trước bản vá preview luôn ra rỗng.

**`gapPreview` thì KHÔNG tự giải két — và đó là chủ ý.** Nó chỉ theo `till_code`
do caller truyền, bỏ trống ⇒ `MAIN`. Lý do là cặp ĐỌC/GHI: `claimGapPayments`
chạy trong `open()` và đo cửa sổ theo két mà `open()` khoá. Nếu đọc tự đoán `SUB`
còn ghi mặc định `MAIN`, thu ngân sẽ tích một khoản rồi lượt claim **từ chối
đóng dấu** — tiền được xác nhận trên màn hình mà không vào ca nào, không lỗi nào
ném ra. Muốn quét két khác thì client truyền `till_code` cho **cả** preview lẫn
open. (Cùng họ với #2730 phía máy trạm.)

**Và nó KHÔNG tạo két** (#2745). `?till_code=` tới thẳng từ caller, nên
`firstOrCreate` ở đường đọc biến một `GET` thành lệnh dựng hàng `tills` với mã
bịa. Mã không có thật ⇒ preview **rỗng**, không phải 422: cặp đọc/ghi này vốn đã
trả đúng hình dạng ấy cho "chưa có ca nào trước đó", nên hợp đồng thành công
không đổi. Đường GHI (`resolveTillForBranch`, gọi trong `open()`) **vẫn**
`firstOrCreate` — thu ngân phải mở được ca ở chi nhánh mới mà không chờ admin
dựng két. Hai đường, hai hàm: `existingTillForBranch()` cho đọc.

Không phải chuyện sạch sẽ hình thức: két rác lọt vào comparator "két có ca kết
thúc gần nhất" (`shiftBoundaryTillForBranch`) và bẻ ranh ca của cả chi nhánh —
đúng lớp lỗi #2724 vừa vá ở ngay trên.

**HAI cửa, không phải một** (#2745 vòng 2). `GET /pos/till/unresolved-orders`
cũng nhận `?till_code=` từ caller, đi qua `shiftBoundaryTillForBranch()` — và
nhánh caller-supplied của hàm đó cũng `firstOrCreate`. Vòng 1 chỉ vá
`gapPreview()` rồi khai nó là đường DUY NHẤT; review probe ra cửa còn lại vẫn
mở. Cả hai nay dùng `existingTillForBranch()`; `shiftBoundaryTillForBranch()`
trả `?Till` và `unresolvedOrdersPreview()` trả preview rỗng khi `null`.

Cửa thứ hai **nặng hơn** cửa đầu: nếu chi nhánh chưa có két nào, hàng rác trở
thành két **duy nhất**, nên lượt quét sau (không truyền `till_code`) rơi vào
shortcut `count === 1` và lấy chính nó làm mốc ranh ca cho cả chi nhánh — không
cần ai gõ lại mã bịa lần thứ hai.

### Ruling #2776 — bootstrap két MAIN từ đường ĐỌC là CỐ Ý

Ba đường đọc còn `firstOrCreate`: `GET /pos/till/current`,
`/pos/till/denominations` (khi thiếu `?currency`), và `GET /workstation/till/current`.
Chúng **không** cùng lớp với #2745, và ranh giới là thứ đo được:

| | được phép | cấm |
|---|---|---|
| mã két đến từ | **hằng MAIN trong mã nguồn** | **caller** (`?till_code=`) |
| số hàng tối đa sinh ra | 1 mỗi chi nhánh | không chặn trên |
| hàng sinh ra là | đúng két quán sẽ dùng | rác mang mã người gọi bịa |

Luật *"đường đọc không có tác dụng phụ ghi"* tồn tại để chặn **việc tạo hàng
không có chặn trên do người gọi điều khiển** — đó là vector bơm rác, và ở đây nó
còn bẻ ranh ca cả chi nhánh qua comparator "két có ca kết thúc gần nhất". Bootstrap
MAIN không có tính chất nào trong số đó: nó bị chặn trên bởi chính lược đồ
(`branch_id` + hằng `till_code`), nó idempotent, và hàng nó tạo là hàng quán cần
dù sao đi nữa.

Chiều ngược lại có giá thật: bỏ `firstOrCreate` đi thì một chi nhánh mới toanh
trả 404 ở `GET /current`, và **màn POS chết trước khi ai kịp mở ca** — đúng thứ
mà đường phục hồi này sinh ra để tránh. Đổi lấy điều đó để tuân một luật vốn
nhắm vào chuyện khác là trả giá sai chỗ.

**Ranh giới cưỡng chế bằng máy**, không bằng trí nhớ: `resolveTillForBranch()`
chỉ được truyền **tham số thứ hai** (mã két) từ đường GHI. Mọi chỗ khác gọi một
tham số ⇒ luôn là MAIN. Ghim ở
`tests/Feature/Architecture/TillBootstrapBoundaryTest.php`.

Thêm một đường đọc mà cần két thì dùng bản **không tạo**
({@see TillSessionService::shiftBoundaryTillForBranch}) trừ khi nó thật sự
bootstrap MAIN — và nếu bootstrap MAIN thì đừng nhận mã từ caller.

**Hai nhóm, đừng gộp**: `outstanding_count` là đơn còn NỢ tiền;
`pending_close_count` là đơn đã thu ĐỦ mà kẹt `paying` — chỉ cần đóng đơn
(#2721). Trước khi tách, chuông báo "còn thiếu **0**" định kỳ mỗi lần mở ca.

Ca **chỉ-cần-đóng** (`outstanding_count === 0`) render bằng bản copy riêng
`till.unresolved_orders.pending_close` (#2737 seed, #2754 nối dây). Chọn qua
`NotificationRequest::templateKey`, **không** qua `type` — `type` là thứ mọi bộ
lọc khác ăn theo (`DEFAULT_PRIORITIES`, preference, digest), và một type mới sẽ
bị chúng bỏ sót *im lặng*, đúng lớp lỗi slug đã trả giá bốn lần
(#2451/#2456). Một sự kiện ⇒ một `type`; sắc thái copy đi đường riêng.

**`{{order_codes}}`** hiện tối đa 5 đơn rồi thêm hậu tố `+N` khi còn nữa
(#2739) — bản cắt trần đọc y hệt danh sách đầy đủ, nên một ca treo 40 đơn nhìn
như treo 5. Nhãn chỉ mang số tiền khi đơn **thật sự còn thiếu**: đơn
chỉ-cần-đóng ra mã trần, không ra `T-042 (0)`, vì bản copy pending-close cố ý
không nói về tiền.

**Tiền đã thu tính bằng `OrderPayment::netCollectedForOrder()`** (#2718), không
phải `status='succeeded' AND refund_of_id IS NULL`. Sổ refund giữ hàng gốc ở
**+X rồi flip sang `refunded`** kể cả khi hoàn MỘT PHẦN, nên vị ngữ cũ đọc một
đơn hoàn-một-phần thành đã-thu-0. Đừng viết vị ngữ tiền thứ hai ở đây.

**Phép quét đi từ ĐƠN, không từ BÀN.** Đây không phải chi tiết cài đặt: khởi từ
`tables.current_order_id` sẽ bỏ sót đúng ca tệ nhất. Đo trên production
2026-08-13: ORD-2026-0191 kẹt `checkout` **17 giờ** với ¥700 trong khi bàn đã về
`free` và `current_order_id` đã rỗng — không chặn gì, không hiện gì, không ai
thấy. Mỗi dòng mang cờ `table_released` để nhân viên nhận ra đơn mồ côi.

Mở ca mà còn đơn treo thì phát `till.unresolved_orders` cho `shop-manager` +
`org-admin`, khử trùng theo business date. **Fail-open tuyệt đối**: hỏng ở tầng
thông báo không được chặn lượt mở ca — thu ngân không mở được ca thì quán không
bán được, tệ hơn hẳn một cảnh báo trượt.

### Hai bản copy: "còn thiếu tiền" và "chỉ cần đóng đơn" (#2737)

Đơn kẹt `paying` mà **đã thu đủ** không phải chuyện tiền, mà là chuyện dọn sổ —
nói với thu ngân rằng "còn thiếu 0 JPY" thì đúng cú pháp nhưng vô nghĩa với
người đọc, và một cảnh báo vô nghĩa dạy người ta bỏ qua cảnh báo thật. Nên có
**hai** template key, ja/en/vi:

| Ca | Template key | Giọng |
|---|---|---|
| còn thiếu tiền thật | `till.unresolved_orders` | cảnh báo, priority thường |
| mọi đơn đã thu đủ | `till.unresolved_orders.pending_close` | trung tính, "chỉ cần đóng đơn", priority `low` |

Hai chỗ dễ sai:

- **`needs_close_only` KHÔNG phải tham số template.** Nó là cờ của **từng dòng
  đơn** trong preview (pos-web đọc nó để thôi tô đỏ). Tham số cấp thông báo là
  `pending_close_count` + `outstanding_order_count`. Khai một token không tồn
  tại thì renderer trả **chuỗi rỗng + `Log::warning`**, không ném gì.
- **Bản copy pending-close cố ý không nhắc "còn thiếu"/`未収` kể cả ở thể phủ
  định** — để bài test ghim được nó một cách dứt khoát.

## Shift handover + chain-of-shifts (plan-046)

A **chain** is an ordered run of shifts on one till, linked by **handovers** and terminated by a **final close**. It lets multiple cashiers share one drawer across a business day while keeping a running total.

- **Bàn giao ca (handover)** — settles the current shift but **keeps the chain open**. Prints a single-shift 引き継ぎ slip. The next open on the same till **continues the same chain** (`chain_id` inherited, `chain_sequence + 1`) with a **blind re-count** opening float (per-cashier variance isolation — the prior counted cash is never auto-carried).
- **Kết ca cuối (final close)** — the existing settle, now `settlement_kind = final`. **Ends the chain** and prints the **aggregate slip**: one condensed block per shift + a GRAND TOTAL (Σ of the immutable per-shift `settlement_snapshot`, per-rate tax summed per bucket). The next open starts a **brand-new chain**. A final close with no prior handover = a **chain of one** = today's single 精算 slip (no regression).

Model (no new table): `till_sessions.{chain_id, chain_sequence, settlement_kind (handover|final), settlement_snapshot (immutable JSON)}`. The aggregate **never re-derives** from live orders — a later refund of an earlier shift's order can't retro-change a settled block.

**Recovery interactions:**
- **Abandon / expire / manual-settle mid-chain END the chain** — none of them set `settlement_kind`, so the next open starts a fresh chain, and the aggregate counts only shifts that carry a snapshot (settled via handover/final). A force-abandoned/expired shift keeps its own single-shift report but is never a chain-aggregate member.
- **Currency / rounding is locked for the whole chain** — a handover clears `Till.current_session_id`, so the plan-031 config guard is extended (`branchHasOpenChain`) to keep blocking a currency/rounding flip until the chain's **final** close.
- **Offline-first** — the workstation owns the settle locally (SQLite) and prints the chain slip offline by summing its local snapshots; Cloud recomputes the authoritative snapshot on sync-UP and the workstation adopts it from the response.

## Related code

- Service: `backend/app/Services/Pos/TillSessionService.php` — `handover()`, `settleShift()`, `chainSummary()`, `buildSettlementSnapshot()`, `previousTerminalSessionForTill()`; `forceAbandon()`, `expire()`, `manualSettle()`; plan-044 `gapPreview()`, `orderSummary()`, `claimGapPayments()`; #2696 `unresolvedOrdersPreview()`.
- Guard: `backend/app/Http/Controllers/Api/V1/Shop/ShopOrderSettingsController.php` — `branchHasOpenShift()` + delegated `branchHasOpenChain()` (plan-046 R8; the predicate itself moved to `TillSessionService::branchHasOpenChain` by #1130). The three 409 codes are FROZEN and named inconsistently by history — match the exact set, never a suffix pattern: `CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT` · `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT` · `TAX_ROUNDING_LOCKED_OPEN_SHIFT`.
- Pre-flight (#1130): `GET /shops/{slug}/till/current` returns `has_open_shift` + `has_open_chain` + `settings_locked`; admin-web locks the currency/tax/rounding controls on the OR of both so the UI can never enable a control the PATCH will 409 (the post-handover window used to slip through). Pin: `ShopTillStatusTest` "exposes the open-chain window".
- Workstation: `workstation/internal/handler/local_pos_till.go` (`settleLocalShift`, chain-continuation open), `internal/handler/lan_chain_report.go` (`buildChainReport`), `internal/service/print_chain_report.go` (`FormatChainReport`).
- Pos-web: `web/pos/src/app/shift/close-page.tsx` (handover + final buttons), `hooks/api/use-till.ts` (`useHandoverShift`, `useChainSummary`); #2696 `unresolved-orders-panel.tsx` on the open screen (separate from `GapReconcilePanel`).
- Plan reference: `plans/plan-046/DESIGN.md`.
- Command: `backend/app/Console/Commands/ExpireStaleShifts.php`.
- Policy: `backend/app/Policies/TillSessionPolicy.php` — role gates.
- Workstation: `workstation/internal/handler/local_pos_till.go` (gap-preview / order-summary / claim), `internal/service/sync_service.go` (`payment.attribute` op).
- Pos-web: `web/pos/src/app/shift/gap-reconcile-panel.tsx`, `close-page.tsx` order summary.
- Plan reference: plan-032 DESIGN (recovery rationale — đã xoá khỏi cây #2188, git history), `plans/plan-044/DESIGN.md` (gap-reconciliation R2 model).

## Từ vựng tender — cái gì sửa được, cái gì không (#1881)

`till_tender_types` là **từ vựng tiền** của một tổ chức. `order_payments.tender_key`
chụp khoá đó lên từng chứng từ, và `reconcile()` so khớp theo nó — nên phần lớn
thao tác trông như quản trị bình thường thật ra là thao tác trên dữ liệu bất biến.

| Thao tác | Được? | Vì sao |
|---|---|---|
| Đổi **nhãn hiển thị** | ✅ luôn | Có `till_tender_type_translations`; nhãn không nằm trên chứng từ |
| Đổi **`tender_key`** | ❌ không bao giờ | Payment cũ sẽ trỏ vào một từ không còn nghĩa |
| Đổi **`parent_tender_key`** | ⚠️ chỉ khi chưa có payment | `till_settlement_tender_details` lưu `tender_key` chứ **không** lưu nhóm cha — nhóm được áp lúc ĐỌC, nên sửa bây giờ viết lại cách gom của mọi 精算 cũ |
| **Xoá** | ⚠️ chỉ khi chưa có payment | Xoá tender đã dùng làm mồ côi sổ cái; nghĩa vụ lưu chứng từ ở JP là 7/10 năm |
| **Tắt** (`is_active = false`) | ✅ luôn | Biến khỏi lựa chọn MỚI, chứng từ và báo cáo cũ vẫn tra được |

Muốn gom nhóm khác cho một tender đã dùng: **tạo `tender_key` mới**. Đó là cách
mã thuế hoạt động trong mọi hệ kế toán, và là lý do không có endpoint rename.

**Ai sửa:** HQ đặt từ vựng (`branch_id IS NULL`); chi nhánh chỉ bật/tắt cái HQ đã
định nghĩa. `routes/api/shops/tender-types.php` trả 403 cho mọi thao tác ghi lên
hàng org-wide — cùng ranh giới đã chốt ở #1370 cho settlement.

API: `GET|POST /hq/{brandSlug}/tender-types`, `PATCH|DELETE .../{id}`. Ba thao tác
bị chặn trả **409** kèm `payment_count` và một trong ba mã: `TENDER_KEY_IMMUTABLE`,
`TENDER_GROUP_IMMUTABLE_ONCE_USED`, `TENDER_IN_USE`. Payload danh sách trả sẵn
`key_editable` / `group_editable` / `deletable` để UI nói sự thật **trước** khi
người dùng gõ.

Đường nâng cấp nếu ngày nào cần đổi nhóm linh hoạt: chụp `parent_tender_key` lên
`order_payments` giống cách thuế đã được chụp lên dòng đơn. Đó là migration +
đổi đường ghi — ràng buộc bất biến hiện tại đạt cùng mục tiêu với chi phí bằng 0.

## Refund và tiền ca — HAI biểu diễn, một quy tắc đọc (#2580)

Cloud và máy trạm ghi refund khác nhau, và màn 精算 mà thu ngân thật sự đối chiếu
được phục vụ bởi **SQLite của máy trạm**, không phải Cloud (`/pos/shop/{branch}/shift/close`;
Cloud chỉ nhận kết quả qua `settleFromWorkstation()`).

| tầng | biểu diễn một khoản hoàn |
|---|---|
| **Cloud** (`order_payments`) | hàng MỚI: `status='succeeded'`, `amount` ÂM, `refund_of_id` → gốc; hàng gốc chuyển `status='refunded'` |
| **Máy trạm** (`payments`), từ #2656 | hàng MỚI ký hiệu âm, **giống Cloud** — nhưng hàng gốc **giữ `succeeded`**, xem dưới |
| Máy trạm, TRƯỚC #2656 | cột `refunded_amount` tích luỹ TRÊN hàng gốc (migration 006); không có khái niệm hàng refund |

Từ #2580 tầng 1, mọi đường ĐỌC tiền ca ở máy trạm dùng **đúng quy tắc Cloud đối
soát** (`TillSessionService::reconcile`), nên nó đúng dưới **cả hai** biểu diễn:

```
   (refund_of_id IS NULL     AND status IN (pending,confirmed,succeeded,refunded))
OR (refund_of_id IS NOT NULL AND status = 'succeeded')
```

Ba chỗ giữ bản sao của nửa-trạng-thái này và **phải đổi cùng lượt** — lệch nhau là
đúng cái lỗi mà docstring của hai chỗ đầu đang cảnh báo:

- `internal/handler/lan_shift_report.go` `paidPaymentsPredicate` (reconcile session ·
  Z-report · settlement snapshot · phiếu chain);
- `internal/service/pricing.go` `paidOrderIDsSQL` (消費税内訳);
- `internal/handler/local_pos_till.go` order-summary của màn kết ca — không gọi được
  predicate chung vì nó quy thuộc theo `till_session_id` ĐƠN THUẦN, không cửa sổ dự phòng.

Hai điều dễ hiểu nhầm:

1. **Hàng gốc của một đơn đã hoàn toàn bộ KHÔNG bị loại.** Nó đã vào ngăn kéo lúc
   bán; loại nó là co lại một Z-report đã chốt. Cloud chưa bao giờ loại. Hệ quả kèm
   theo: một đơn bị hoàn toàn bộ **ở lại** trong 消費税内訳 của ca đã bán nó (net 0)
   thay vì biến mất — trước #2580 dòng tiền net 0 nhưng bảng thuế rụng cả đơn.
2. **`refunded_amount` vẫn nằm trong biểu thức tiền, cố ý.** Từ #2656 **không ai
   ghi nó nữa**, nhưng một binary bị lùi qua release đó **vẫn ghi**, và migration
   `083` sẽ không chạy lại để chuyển thứ nó viết. Hai biểu diễn không bao giờ cùng
   tồn tại trên một hàng (083 zero cột trong cùng transaction tạo hàng âm; đường ghi
   mới không chạm cột), nên biểu thức đúng dưới cả hai.

## Đường GHI từ #2656 — và vì sao hàng gốc giữ `succeeded`

`handleLocalPosRefundPayment` nay **chèn một hàng ký hiệu âm** (`amount<0`,
`refund_of_id` → gốc, `status='succeeded'`, mang tender/order của gốc và ca đang mở)
thay vì sửa hàng gốc. Sổ cái tài chính là append-only (ADR #1151): cột tích luỹ không
biểu diễn nổi hai lần hoàn một phần ở hai thời điểm bởi hai người, và nó xoá dấu vết.

**Hàng gốc giữ `succeeded`, KHÔNG chuyển `refunded`** — đây là quyết định gánh việc,
không phải chi tiết. Binary cũ chạy
`SUM(amount - COALESCE(refunded_amount,0)) WHERE status IN ('pending','confirmed','succeeded')`:

| gốc sau chuyển đổi | binary cũ đọc ra |
|---|---|
| `refunded` | gốc **rụng** khỏi filter, hàng âm ở lại ⇒ `-X` ⇒ **ca không chốt được** |
| `succeeded` + `refunded_amount=0` | `amount + (-X)` = **đúng net** |

Hệ quả kèm theo: `local_pos.go:1083` (rào thu thừa) và `:1106` (số đã capture) chưa
từng nghe tới `refund_of_id` mà **vẫn đúng, không đổi một dòng SQL** — hàng refund là
`succeeded` với amount âm nên filter sẵn có tự net. Đọc `refunded` vẫn được hỗ trợ:
Cloud vẫn ghi nó.

### Năm truy vấn KHÔNG nhắc `refunded_amount` nhưng vẫn phải sửa

Chúng vỡ vì **thấy một hàng âm**, không vì cột. Đây là phần lớn hơn danh sách reader,
và là thứ dễ bỏ sót nhất khi đọc issue:

| chỗ | nếu để nguyên |
|---|---|
| `routes.go:2046` rào unpair | refund **không bao giờ** có `cloud_id` (nó đi bằng op `payment.refund`; chỗ set `cloud_id` là `sync_service.go:1210`, đường `payment.create`) ⇒ mọi khoản hoàn đọc là tiền chưa sync **vĩnh viễn**, không thiết bị nào unpair được |
| `sync_service.go:2188` manifest chốt ca | Cloud chỉ settle khi mọi `idempotency_key` có ở `order_payments` ⇒ 503 `RECONCILE_PENDING` mãi mãi, **ca không chốt được** |
| `sync_service.go:2630` reconcile | đẩy lại hàng refund dưới dạng `payment.create` ⇒ khoản âm thứ hai trên Cloud |
| `print_scope.go:69` | refund bị đếm là người trả thứ hai ⇒ đơn một người trả thành bill chia, sai đánh số bản in |
| `print_receipt.go:127` · `lan_local.go:481` | refund là hàng MỚI nhất nên thắng phép chọn "thanh toán gần nhất" ⇒ metadata split rỗng, số "đã trả" âm khi in lại |

**Cột `refunded_amount` CHƯA bị drop, cố ý** (expand/contract). Rào #2659 cấm
`DROP COLUMN` để giữ bất biến "binary N đọc được schema N+1", và ở đây nó đúng: sau
rollback, binary cũ vẫn `SELECT` cột đó. Việc drop tách sang issue riêng, chỉ mở khi
không còn binary nào đang chạy đọc nó.

## Cloud TỪ CHỐI hoàn tiền của một ngăn kéo đang mở (#2657)

Lỗ ban đầu: pos-web gọi refund qua `apiFetch`, và `web/pos/src/lib/api.ts:334-348`
có lưới auto-mode **lỗi mạng LAN ⇒ thử lại trên Cloud một lần** (timeout LAN 3s).
Không caller nào truyền `forceCloud` (`api.ts:296`) và không có tuỳ chọn
ghim-vào-LAN. Nghẽn LAN đúng lúc bấm hoàn tiền ⇒ refund chỉ sống trên Cloud, ca
chốt trên máy trạm với số **trước khi hoàn**.

Không mang refund xuống được: `sync_pull.go:2749` cấm dữ liệu payment của Cloud
thành hàng trong `payments` (tiền online sẽ tự nhận là tiền mặt đòi được). Nên
chặn ở đầu vào:

**`Shop/OrderPaymentController::refund` trả 409 `REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT`**
khi khoản thu có `channel='workstation'` **và** ca của nó còn `{open, closing}`.
Body mang `{message, code, payment_id, till_session_id}`; client bắt theo **`code`**,
không match theo hậu tố (quy ước mã đóng băng, giống họ `*_BLOCKED_OPEN_SHIFT`).

Ba điều dễ làm sai, đã chốt:

1. **Rào đặt ở CONTROLLER, không ở service.**
   `Workstation/OrderLifecycleController::refundPayment` dùng CHUNG
   `OrderPaymentService::refund`. Rào trong service sẽ chặn luôn đường sync-UP —
   tức chặn đúng cách hoàn tiền **duy nhất đúng**, tệ hơn con bug. Có test ghim
   riêng cho việc đặt sai chỗ này.
2. **Tập ca là `{open, closing}`** (`scopeInProgress`), không phải `scopeOpen`.
   Ngăn kéo vẫn động tiền lúc `closing` — đó là ca **xấu nhất**, không phải ngoại
   lệ: thu ngân đang đếm tiền đúng lúc đó.
3. **Chỉ `channel='workstation'` bị chặn.** `pos` · `kiosk` · `customer_web` ·
   `self_regi` · `server_to_server` sinh thẳng trên Cloud, chưa máy trạm nào giữ
   chúng, hoàn ở Cloud không tạo lệch. Khoản thu máy trạm có
   `till_session_id = NULL` (gap payment) cũng không bị chặn — chưa từng vào ngăn
   kéo nào. **Không** có nhánh cho `channel` NULL: #2612 đã bịt nguồn sinh NULL, và
   rào chỉ nổ khi ca còn chạy nên hàng cũ tự hết hạn theo ca của chúng.

Hệ quả vận hành cần biết: nhân viên gặp 409 này **mà chưa hề chọn Cloud** — lưới
auto-mode tự chuyển hướng. Bịt tận gốc thì cần một `forceLan` ghim lời gọi refund
vào LAN; chưa làm.
