<?php

use App\Jobs\CancelOverdueTakeawayOrders;
use App\Jobs\Notification\CouponExpirationScannerJob;
use App\Jobs\Notification\DeviceOfflineDetectionJob;
use App\Jobs\Notification\SendDigestJob;
use App\Jobs\ScheduledNotificationHealthCheckJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('omnify:cleanup-files --phase=1')->hourly();
Schedule::command('omnify:cleanup-files --phase=2')->daily();

// plan-012 T4.14 — re-queue scheduled notifications whose delayed job
// was evicted (redis crash, manual flush, worker restart race).
Schedule::job(ScheduledNotificationHealthCheckJob::class)->hourly();

// plan-023 M3 T3.4 — materialise + advance every due NotificationSchedule.
// 5-minute resolution is the contract surfaced to the composer copy
// ("your weekly send will fire between 09:00 and 09:04"). withoutOverlapping
// blocks a slow tick from being re-entered before it finishes; onOneServer
// keeps multi-worker deployments from running two ticks against the same
// row set (the SELECT … FOR UPDATE SKIP LOCKED in the dispatcher is a
// secondary defence, not the primary one).
Schedule::command('notifications:tick-recurring-schedules')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('notifications.recurring.tick');

// plan-023 M5 T5.6 — hourly periodic-digest dispatcher. Each tick scans
// notification_digest_preferences for users whose delivery_time matches
// the current hour in their tz AND last_sent_at < startOfDay (daily) /
// startOfWeek (weekly), then queues a NotificationDigestMail per match.
// Hourly is the right cadence — finer wastes worker time on idle ticks;
// coarser misses users in non-aligned timezones.
Schedule::job(new SendDigestJob)
    ->hourly()
    ->onOneServer()
    ->name('notifications.digest.send');
Schedule::command('payments:expire-stale')->everyMinute();

// plan-047 T2.17 — attempt/refund reconciliation sweeps. Dry-run by default;
// operator passes --execute once Gate 3 provider retrieval is wired.
Schedule::command('payments:reconcile-attempts')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('payments.reconcile-attempts');
Schedule::command('payments:reconcile-refunds')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('payments.reconcile-refunds');

// plan-054 R13 / #2445 — ask PayPay about QR attempts whose customer stopped
// polling. The webhook receiver exists (`POST /api/v1/webhooks/payment/paypay`)
// but Live delivery still needs the URL registered with PayPay merchant
// support; until that lands, this poll is what books a closed-tab payment.
// Every minute: grace only gates *retiring* unscanned CREATED codes, not the
// retrieve itself (#2445 — the old every-15m + grace-before-ask worst case was
// ~30 minutes of unbooked money). Unlike `payments:reconcile-attempts` above
// this runs for real, not as a preview. withoutOverlapping caps a slow tick
// (one PayPay round trip per attempt); onOneServer stops two workers asking
// about the same codes.
Schedule::command('payments:sweep-paypay-qr')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('payments.sweep-paypay-qr');

// plan-047 Gate 3 — re-dispatch retryable provider-event inbox rows and reclaim
// any stranded in `processing` after a worker died mid-run (expired lease), so a
// confirmed webhook always progresses and the paid order eventually settles.
Schedule::command('payments:process-provider-events')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('payments.process-provider-events');

// #1204 / #1206 — money reconciliation outbox: re-issue return documents that
// failed inside a completed reversal, recover crashed claims, and alert on age.
//
// Five minutes matches the VN queue above, and for the same reason: these rows
// represent an obligation that already exists — money handed back without its
// 適格返還請求書, or a charge held at the gateway. The two types this command
// does NOT settle on its own (stranded_charge, overpayment_rejected) rely
// entirely on the stale alert, so the interval is what decides how long a
// stranded charge can sit unnoticed.
Schedule::command('payments:redrive-reconciliation')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('payments.redrive-reconciliation');

// plan-031 T1.3.2 — every-minute auto-cancel of overdue takeaway orders.
// Scans takeaway orders with counter payment where payment_due_at < now()
// and sets status=cancelled. Dine-in orders never affected. withoutOverlapping
// prevents concurrent runs if query is slow.
Schedule::job(CancelOverdueTakeawayOrders::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('takeaway.cancel-overdue');

// plan-017 T9.5.C4 — daily expiry sweep at 07:00 Asia/Tokyo. Walks active
// MaterialLots, fires one ExpiryAlert row per (lot, threshold) crossing.
// Idempotent — re-running the same day is a no-op.
// plan-040 L11 (TF.8): withoutOverlapping caps a slow sweep from re-entering;
// onOneServer stops multi-worker double-runs.
Schedule::command('material-lots:scan-expiring')
    ->dailyAt('07:00')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('inventory.lots.scan-expiring');

// plan-018 TB1.4 — daily expiry of stale lot reservations (7-day TTL
// after expected_consume_at). Runs after the expiry scan.
Schedule::command('material-lot-reservations:expire')
    ->dailyAt('07:30')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('inventory.reservations.expire');

// plan-023 M8 T8.4 — every-5-min detector for devices that have gone offline
// (last_seen_at older than device_offline_threshold_minutes). withoutOverlapping
// guards against slow DB cursor on large device fleets.
Schedule::job(DeviceOfflineDetectionJob::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('notifications.device-offline.detect');

// plan-023 M8 T8.5 — daily coupon expiry two-pass scanner. Pass 1 fires for
// coupons expiring within 72h; pass 2 fires for coupons that expired since
// the last sweep. Runs on config('app.operations_timezone') — the head-office
// rhythm, NOT a business-time source (#1091).
Schedule::job(CouponExpirationScannerJob::class)
    ->dailyAt(config('notifications.coupon_scan_time', '08:00'))
    ->timezone(config('app.operations_timezone'))
    ->onOneServer()
    ->name('notifications.coupon.expiration-scan');

// plan-022 T6 — daily 08:00 Asia/Tokyo lot auto-expire. Active /
// quarantined lots past expiry flip to `expired` so FEFO stops picking
// them. Runs 1h after the expiry alert scan so ops gets the warning
// notification first.
Schedule::command('material-lots:auto-expire')
    ->dailyAt('08:00')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('inventory.lots.auto-expire');

// plan-032 T5.2 — hourly sweep of stale cashier shifts. Flips open/closing
// sessions with no recent payment activity to status=expired so the next
// cashier (and plan-031's currency guard) is unblocked. withoutOverlapping(5)
// caps the mutex blast radius if the job crashes between mutex-acquire and
// release; onOneServer prevents multi-worker double-runs.
Schedule::command('tills:expire-stale-shifts')
    ->hourly()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('pos.till.expire-stale-shifts');

// #2937 — đối soát BA CHÂN tiền mặt (sổ ↔ MÁY ↔ người đếm) cho các ca đã chốt.
//
// Chạy theo LỊCH chứ không hook lúc chốt ca: máy trạm đẩy 在高 theo nhịp một
// phút, nên ảnh chụp lúc đóng ca tới SAU khi ca đã settled. Gọi đối soát trong
// `close()` sẽ luôn ra `undetermined` — phép đo tự vô hiệu hoá bằng cách chạy
// quá sớm.
//
// :35 past the hour để không đụng hai job till ở :00 và :15.
Schedule::command('tills:reconcile-cash-drawers')
    ->hourlyAt(35)
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('pos.till.reconcile-cash-drawers');

// plan-032 T5.5b — hourly freshness check on the expire-stale-shifts heartbeat.
// Reads cache key `pos.tills.last_run_at`; emits ERROR log tagged
// `[pos.till] scheduler-stale` when stale > 6h. Pairs with the run command
// above; intentionally runs at :15 past the hour so a slow expire-run still
// has 45 min of headroom before this fires.
Schedule::command('tills:check-scheduler-freshness')
    ->hourlyAt(15)
    ->onOneServer()
    ->name('pos.till.scheduler-freshness');

// Plan 047 T4.9 — the money invariant every settlement path must preserve:
// customer_orders.paid_amount === OrderPayment::netCollectedForOrder().
//
// The auditor existed but ran only when somebody remembered to type it, which
// made it the one payments job with no schedule while six siblings above have
// one. A cached total that has drifted from the ledger does not heal itself and
// does not announce itself — it is discovered at reconciliation, long after the
// shift it came from.
//
// Read-only, so a nightly pass costs nothing but the scan. Off-peak (03:20 in
// the operations timezone) because it walks orders; --ledger-limit is available
// for manual runs on very large datasets. Drift emits an ERROR tagged
// `[payments.ledger_drift]`, which is what actually reaches alerting — printing
// to a cron log would have made the schedule decorative.
Schedule::command('payments:check-ledger-drift')
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone(config('app.operations_timezone'))
    ->name('payments.check-ledger-drift');

// #1255 — sweep every branch onto the current catalog-revision shape. A branch
// whose revision-rebuild job failed keeps serving the OLD immutable price map:
// stale prices, and `catalog_revision_has_toppings` false, which drops that
// branch's workstations onto the legacy unsigned path for topping orders. Safe,
// but it gives up exactly the offline evidence epic #1092 exists to produce.
//
// The recovery path existed and simply never ran on its own. The job's own
// docblock says a stuck branch stays stuck "until its next catalog edit or a
// `catalog:rebuild-revisions` sweep" — for a branch that rarely edits its menu
// that meant the next deploy, and deploys here are not a schedule.
//
// Idempotent by construction: bumpFor() hash-dedups (BR-CR02), so a branch
// already on the current shape mints nothing and a nightly run is a no-op on a
// healthy fleet. Inline rather than dispatched because no queue worker is
// provisioned in the deploy workflow — a dispatched sweep would sit unrun,
// which is the same silence being fixed. withoutOverlapping guards the case the
// runtime is larger on a real fleet than it is here.
// #1257 — voided lines whose stock was never returned to the shelf. The failure
// is already detected and alerted on ([inventory.stock_drift], raised by
// WritesCustomerOrders when compensateVoid throws), but the repair ran only when
// somebody remembered to type it — so an alert announced a drift that then sat
// there until a physical count found it.
//
// --repair is safe to automate, for reasons the command's own docblock sets out:
// compensation works off the OUTSTANDING deduction rows, so a line already
// compensated finds nothing and no-ops; and the void reason is persisted on the
// line, so the restock / waste / none decision comes out identical to the first
// attempt instead of being guessed. That is what separates it from #1204, where
// the equivalent repair would have to guess and therefore stays manual.
//
// The default 30-day window is kept: a nightly run then also heals anything that
// failed while the scheduler itself was down.
Schedule::command('stock:repair-void-compensation --repair')
    ->dailyAt('04:10')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone(config('app.operations_timezone'))
    ->name('stock.repair-void-compensation');

Schedule::command('catalog:rebuild-revisions')
    ->dailyAt('03:40')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone(config('app.operations_timezone'))
    ->name('catalog.rebuild-revisions');

// plan-034 — reaper cho TableSession treo. Đóng phiên `open` quá 4h chưa thanh
// toán để `tables.status` không kẹt `occupied` mãi (khách đi mà không báo nhân
// viên). `tills:expire-stale-shifts` là bản đối xứng về cấu trúc.
//
// #3010 — chạy MỖI 15 PHÚT, không phải mỗi giờ. Lệnh nay có ngưỡng thứ hai,
// ngắn hơn nhiều, cho phiên CHƯA CÓ ĐƠN NÀO (45 phút). Giữ nhịp một giờ thì
// ngưỡng đó gần như vô nghĩa: quét lúc 18:01 phải chờ tới 19:00 mới có lượt
// quét kế đi qua nó, tức 45 phút trên giấy thành ~1h45 ngoài quán — đúng khung
// giờ mà một bàn bị giữ oan là mất doanh thu thật.
//
// Chi phí: một truy vấn trên `table_sessions` đang mở. `withoutOverlapping` +
// `onOneServer` giữ nguyên nên nhịp dày không chồng lượt.
Schedule::command('dine-in:expire-stale-sessions')
    ->everyFifteenMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('dine-in.session.expire-stale');

// plan-050 (#1155) — daily two-direction gateway settlement reconciliation +
// pending-payout aging. Direction A: succeeded gateway payments past the
// per-provider window with no settlement row; direction B: orphan rows and
// Σ-mismatch payouts. Also auto re-matches orphans left by late offline
// payment replays (S-05/S-19). Idempotent — re-running changes no state.
// Runs on the head-office rhythm (operations_timezone, NOT branch business
// time — settlement dates follow the GATEWAY's calendar, #1091).
Schedule::command('settlements:reconcile')
    ->dailyAt('06:30')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('payments.settlements.reconcile');

// plan-052 T2.1/T2.3 (#1166) — print ledger reconciliation + alert sweep.
// Every 15 minutes because the shortest TTL in the matrix is a kitchen ticket
// at 15 minutes: a coarser tick would let a dead ticket sit past the service
// it belonged to before anyone hears about it. The sweep NEVER retries and
// never touches a ws_lan row (DESIGN §1b) — it reports those and expires only
// the Cloud-owned ones. Runs on the head-office rhythm; it is not a
// business-time decision (#1091). withoutOverlapping caps a slow sweep on a
// large ledger; the command takes a cache lock of its own for manual runs.
Schedule::command('print-jobs:reconcile')
    ->everyFifteenMinutes()
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('printing.print-jobs.reconcile');

// plan-032 T5.5d — daily fraud-signal sweep on per-manager force-abandon rate.
// Emits WARNING log tagged `[pos.till] force-abandon-rate` when a single
// manager's force_abandoned_by_id count exceeds the threshold in the trailing
// window (defaults: > 5 in 7d).
Schedule::command('tills:check-force-abandon-rate')
    ->dailyAt('06:00')
    ->timezone(config('app.operations_timezone'))
    ->onOneServer()
    ->name('pos.till.force-abandon-rate-alert');

// #2555 — `audit_logs` retention. The table had no prune of any kind and grew
// for the lifetime of the deployment; PCI DSS v4.0 Req 10.5.1 wants a stated
// window (12 months minimum), and #2554 cannot restore the caller address until
// one exists. Window + bounds are in config/audit.php.
//
// 02:50 in the operations timezone: the quietest slot, and clear of the other
// overnight table walkers (03:20 ledger drift, 03:40 catalog revisions, 04:10
// void compensation) so two large scans never overlap.
//
// The off-peak hour is margin, not the safety mechanism. The command deletes in
// bounded chunks by primary key and stops at max-rows / max-seconds, so a run
// that landed at 14:30 on a Saturday would still be a few hundred keyed deletes
// and then a clean exit. Hitting a bound is expected on the first nights after
// this ships — a backlog that took months to build drains over several runs,
// each recomputing its own cutoff.
Schedule::command('audit:prune')
    ->dailyAt('02:50')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('audit.prune');

// #2901 — hạn giữ 14 ngày của log máy trạm kéo về, cộng lượt đánh dấu yêu cầu
// hết hạn.
//
// Mốc đếm là `logged_at` (lúc dòng ra đời TRÊN MÁY TRẠM), KHÔNG phải lúc Cloud
// nhận: một quán mất mạng 10 ngày rồi mới đẩy được sẽ làm mọi dòng "trẻ lại"
// 10 ngày nếu đếm theo lúc nhận, và hạn 14 ngày lặng lẽ thành 24.
//
// 03:05 — sau `audit:prune` (02:50) và trước 03:20 ledger drift, để hai lượt
// quét bảng không chồng nhau. Nhưng giờ chỉ là biên an toàn chứ không phải cơ
// chế an toàn: lệnh xoá theo lô, theo khoá chính, và dừng ở max-rows/
// max-seconds — chạy lúc 14:30 một ngày thứ Bảy đông khách vẫn chỉ là vài trăm
// lượt xoá theo khoá rồi thoát sạch.
//
// HẰNG NGÀY chứ không hằng tuần: đây là cửa sổ PII, và mỗi ngày trễ là một
// ngày dữ liệu khách nằm lại ở Cloud lâu hơn mức đã cam kết.
Schedule::command('workstation-logs:prune')
    ->dailyAt('03:05')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('workstation-logs.prune');

// #2410 — "đã gỡ được shim payment legacy chưa?", hỏi HÀNG TUẦN thay vì chờ ai
// đó nhớ.
//
// `LegacyRemovalReadiness` được dựng chính để trả lời câu đó bằng phép đo, và
// docblock của nó nói rõ ý định: *"the day a condition finally flips, a scheduled
// run says so instead of the debt sitting for another year."* Nhưng nó CHƯA BAO
// GIỜ được đăng ký vào lịch — không ở đây, không ở workflow nào. Một dụng cụ đo
// mà không ai chạy thì tệ hơn không có: nó làm người ta tin rằng chỗ đó đang
// được canh.
//
// `--strict` thoát khác 0 khi một cổng đã ĐẠT mà mã legacy vẫn còn — tức đúng
// trạng thái đáng hành động ("giờ xoá được rồi, và không ai biết"). Lượt chạy
// theo lịch thất bại là TÍN HIỆU, không phải sự cố.
//
// Hàng tuần, không hàng ngày: điều kiện mở khoá là provisioning gateway và cửa
// sổ quan sát traffic — chúng đổi theo tuần, và một báo động hàng ngày về cùng
// một trạng thái là cách nhanh nhất để nó bị bỏ qua.
Schedule::command('payments:legacy-removal-readiness --strict')
    ->weeklyOn(1, '09:00')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('payments.legacy-removal-readiness');

// #3143 (ADR 0002, lớp 3) — soi mirror danh tính so với thư mục Platform.
//
// `--strict` để một lượt chạy thất bại LÀ tín hiệu: lệch mirror nghĩa là có
// đường ghi phía Platform không sinh sự kiện, và đó là thứ hai lớp rào kia
// KHÔNG bắt được (lớp 1 sai được trong im lặng hoàn toàn).
//
// Hàng ngày, không hàng tuần — ngược với readiness gate ở trên, và có lý do:
// cái đó báo về một TRẠNG THÁI đổi theo tuần, còn cái này báo về SỰ KIỆN bị
// mất. Mỗi ngày không soi là một ngày dữ liệu quán lệch đi mà không ai biết.
//
// ⚠️ Lệnh phân biệt BA trạng thái, và `unverified` không phải `ok`: nó cần token
// của một thành viên trong org để hỏi Platform, nên org chưa ai đăng nhập thì
// KHÔNG kiểm được. Đọc dòng tổng kết, đừng chỉ đọc mã thoát.
Schedule::command('platform:reconcile-directory --strict')
    ->dailyAt('04:40')
    ->timezone(config('app.operations_timezone'))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('platform.reconcile-directory');

// #3199 (ADR 0002) — tiêu thụ luồng danh tính từ Platform.
//
// Mỗi phút: feed này mang cả việc thu hồi quyền, và một giờ chậm trễ ở đó là
// một giờ ai đó còn quyền mà lẽ ra đã mất.
//
// Fail-closed: chưa cấu hình nguồn thì lượt chạy exit ≠ 0 và KHÔNG ack message
// nào — một message SQS đã ack là mất hẳn, nên nối lịch trước khi có hạ tầng
// (dxs-platform/platform#813) vẫn an toàn.
Schedule::command('platform:consume-identity')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('platform.consume-identity');
