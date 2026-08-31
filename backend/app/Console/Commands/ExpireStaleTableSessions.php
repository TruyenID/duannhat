<?php

namespace App\Console\Commands;

use App\Models\CustomerOrder;
use App\Models\TableSession;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Shop\Contracts\TableOccupancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * plan-034 — close TableSessions that have been `open` for longer than
 * the configured threshold without ever paying. Keeps `tables.status`
 * from getting stuck on `occupied` indefinitely when a guest walks out
 * without flagging staff. Mirror of the plan-032 cashier-shift reaper:
 *
 *   - ngưỡng mặc định: 4 giờ (`--hours`). KHÔNG có config `dine_in.*` —
 *     docblock cũ nhắc `dine_in.session_stale_after_hours`, file đó chưa bao
 *     giờ tồn tại (`grep` toàn repo: 0 kết quả ngoài chính dòng này).
 *   - **#3010 — phiên CHƯA CÓ ĐƠN NÀO có ngưỡng RIÊNG, ngắn hơn nhiều**
 *     (`--empty-minutes`, mặc định 45). Xem docblock của `handle()`.
 *   - flips session.status `open → expired`, stamps `closed_at = now()`
 *   - flips table.status back to `free` (và xoá `current_order_id`) qua cổng
 *     {@see TableOccupancy}, TRỪ khi phiên còn một đơn đang sống — xem
 *     `releaseTableFor()` về ca #2524
 *
 * Lịch chạy ở `routes/console.php` (KHÔNG phải `Kernel.php` — Laravel 11+ bỏ
 * file đó; ghi lại vì tôi đã grep nhầm chỗ và suýt kết luận "lệnh không bao giờ
 * chạy").
 */
class ExpireStaleTableSessions extends Command
{
    protected $signature = 'dine-in:expire-stale-sessions
                            {--hours=4 : Sessions open longer than this are expired}
                            {--empty-minutes=45 : Sessions with NO order at all expire after this}
                            {--dry-run : Report what would be closed without writing}';

    protected $description = 'Expire dine-in TableSessions stuck open without payment.';

    public function handle(TableOccupancy $tables): int
    {
        $hours = (int) $this->option('hours');
        $emptyMinutes = (int) $this->option('empty-minutes');
        $cutoff = now()->subHours($hours);
        $emptyCutoff = now()->subMinutes($emptyMinutes);
        $dryRun = (bool) $this->option('dry-run');

        // #3010 — HAI ngưỡng, vì hai thứ này không giống nhau:
        //
        //   - phiên CÓ đơn = khách đang ăn. 4 giờ là đúng; cắt ngắn là đuổi
        //     khách khỏi bàn của chính họ trên màn hình.
        //   - phiên KHÔNG có đơn nào = quét QR rồi thôi. Bàn bị giữ mà chưa
        //     từng có gì xảy ra trên đó.
        //
        // Ca thật (本郷店, 18:04 CN 16/08/2026, giữa ca tối): C-1 `occupied`
        // không đơn, trong khi tab bên cạnh của chính máy đó hiện
        // `betoya.jp | 524`. Một lượt quét hỏng SAU khi đã lật trạng thái để
        // lại đúng hình dạng này, và 4 giờ giữa ca tối là mất một bàn cả buổi.
        //
        // Nhả sớm RẺ vì nó tự chữa: khách còn ngồi đó mà quét lại thì
        // `joinOrStart` lật `free → occupied` lần nữa và mở phiên mới. Ngược
        // lại thì bàn nằm chết tới 4 giờ. Hai chiều sai KHÔNG cân nhau.
        //
        // Điều kiện nhả bàn vẫn nguyên vẹn ở `releaseTableFor()`: còn đơn sống
        // (của phiên này HAY của bàn) thì KHÔNG nhả, dù phiên đã hết hạn.
        $stale = TableSession::where('status', TableSession::STATUS_OPEN)
            ->where(function ($q) use ($cutoff, $emptyCutoff) {
                $q->where('opened_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($emptyCutoff) {
                        $q2->where('opened_at', '<', $emptyCutoff)
                            ->whereNotExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('customer_orders')
                                    ->whereColumn('customer_orders.table_session_id', 'table_sessions.id');
                            });
                    });
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale sessions to expire.');

            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} session(s) — open > {$hours}h, "
            ."or > {$emptyMinutes}m with no order at all.");

        if ($dryRun) {
            foreach ($stale as $session) {
                $this->line("  would expire {$session->id} (opened {$session->opened_at})");
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($stale, $tables) {
            foreach ($stale as $session) {
                $session->update([
                    'status' => TableSession::STATUS_EXPIRED,
                    'closed_at' => now(),
                ]);

                $this->releaseTableFor($session, $tables);
            }
        });

        $this->info("Expired {$stale->count()} session(s).");

        return self::SUCCESS;
    }

    /**
     * #2524 — trả bàn về `free` khi phiên hết hạn, và NÓI RA khi không trả được.
     *
     * Bản cũ ghi thẳng `Table::where(id)->where(status,'occupied')->update(...)`
     * rồi không đọc lại gì. Trên production 2026-08-11 (本郷店 C-1 · A-3 · B-3)
     * phiên đã `expired` mà bàn vẫn `occupied` và `updated_at` **không** nhích —
     * tức lượt ghi khớp 0 hàng — nhưng không có một dòng log nào cho biết điều
     * đó, nên tới giờ vẫn chưa ai dựng lại được nguyên nhân. Ba thay đổi ở đây,
     * theo thứ tự quan trọng:
     *
     * 1. **Đọc lại sau khi ghi.** Không về `free` thì ghi ERROR kèm trạng thái
     *    trước/sau. Lần sau nó tái diễn thì có bằng chứng thay vì phải đoán.
     * 2. **Đi qua cổng `TableOccupancy`** — chỗ DUY NHẤT được phép chạm
     *    `tables.status`/`current_order_id`. `releaseByIds()` xoá luôn
     *    `current_order_id`, thứ mà lượt ghi cũ để nguyên và chính là nửa còn
     *    lại của bàn ma.
     * 3. **Phiên còn đơn SỐNG thì KHÔNG nhả bàn.** Đây là đổi hành vi có chủ ý:
     *    bản cũ nhả bàn cho mọi phiên quá 4 tiếng, kể cả phiên còn đơn chưa
     *    thanh toán — trả bàn về `free` trong khi đơn còn mở là mời khách sau
     *    ngồi vào một đơn đang sống. Ca đó giữ `occupied` và ghi log.
     */
    private function releaseTableFor(TableSession $session, TableOccupancy $tables): void
    {
        $context = [
            'session_id' => $session->id,
            'table_id' => $session->table_id,
            'opened_at' => $session->opened_at?->toIso8601String(),
        ];

        if (CustomerOrder::where('table_session_id', $session->id)->active()->exists()) {
            Log::warning('Expired stale table session — table kept occupied, order still open', $context);

            return;
        }

        $before = $tables->snapshotById((string) $session->table_id);

        if ($before === null) {
            Log::warning('Expired stale table session — table row not found', $context);

            return;
        }

        // Bàn đang giữ một đơn SỐNG mà đơn đó không thuộc phiên này — ví dụ
        // nhân viên mở đơn thẳng trên POS cho bàn đã có một phiên QR treo từ
        // trước. Nhả bàn ở đây sẽ xoá `current_order_id`, tức CẮT một đơn khách
        // đang ngồi khỏi bàn của họ; đúng loại sự cố mà `CatalogSnapshotSeeder`
        // suýt gây ra (xem CLAUDE.md §"deploy được seed danh mục hệ thống").
        //
        // Kiểm phiên là chưa đủ: câu hỏi phải hỏi về CÁI BÀN, vì thứ sắp bị xoá
        // là cột của bàn.
        if ($before->currentOrderId !== null
            && CustomerOrder::whereKey($before->currentOrderId)->active()->exists()) {
            Log::warning('Expired stale table session — table kept occupied, another live order holds it', $context + [
                'held_by_order_id' => $before->currentOrderId,
            ]);

            return;
        }

        if ($before->rawStatus !== TableStatusEnum::Occupied->value) {
            Log::warning('Expired stale table session', $context + [
                'table_status' => $before->rawStatus,
                'released' => false,
            ]);

            return;
        }

        $tables->releaseByIds([(string) $session->table_id]);

        $after = $tables->snapshotById((string) $session->table_id);

        if ($after?->rawStatus !== TableStatusEnum::Free->value) {
            // Đây chính là ca đã xảy ra trên production và trôi qua im lặng.
            Log::error('Expired stale table session — table did NOT return to free', $context + [
                'status_before' => $before->rawStatus,
                'status_after' => $after?->rawStatus,
                'current_order_id_after' => $after?->currentOrderId,
            ]);

            return;
        }

        Log::warning('Expired stale table session', $context + [
            'table_status' => $before->rawStatus,
            'released' => true,
        ]);
    }
}
