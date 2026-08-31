<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CashDeviceInventorySnapshot;
use App\Models\TillSession;
use App\Services\Till\CashDrawerVarianceAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #2937 — quét các ca ĐÃ CHỐT và đối soát ba chân tiền mặt.
 *
 * ## Vì sao là LỆCH QUÉT, không phải hook lúc chốt ca
 *
 * Máy trạm đẩy 在高 theo nhịp **một phút**, nên ảnh chụp lúc đóng ca tới SAU
 * khi ca đã `settled`. Gọi đối soát ngay trong `close()` sẽ đọc phải một ca
 * chưa có 在高 cuối và **luôn** ra `undetermined` — tức phép đối soát tự vô
 * hiệu hoá mình bằng cách chạy quá sớm.
 *
 * Cùng khuôn `tills:expire-stale-shifts` (plan-032): quét theo lịch, mutex
 * `withoutOverlapping`, `onOneServer`.
 *
 * ## Điều kiện chọn ca — hẹp có chủ đích
 *
 * Chỉ ca có **ảnh chụp 在高 lúc CHỐT**. Không có nó thì `reconcile()` chắc chắn
 * trả `undetermined`, và quét chúng chỉ để ghi log là đốt truy vấn trên một
 * bảng sẽ lớn dần theo mỗi ca của mọi quán.
 *
 * Cửa sổ thời gian giữ hẹp vì lý do tương tự: một ca chốt từ tuần trước mà giờ
 * mới có 在高 là chuyện không xảy ra — máy trạm đẩy trong vòng một phút hoặc
 * không bao giờ.
 *
 * ## Chạy lại là VÔ HẠI
 *
 * Khoá chống lặp của thông báo là `cash-drawer-variance:<session_id>` — một ca
 * một lần, vĩnh viễn. Nên lệnh này không cần cột "đã xử lý", và một lượt chạy
 * lỡ nhịp không bỏ sót ca nào.
 */
#[Signature('tills:reconcile-cash-drawers {--hours=48 : Cửa sổ ca đã chốt cần quét} {--dry-run : Tính verdict nhưng KHÔNG gửi thông báo}')]
#[Description('Đối soát ba chân tiền mặt (sổ ↔ MÁY ↔ người đếm) cho các ca đã chốt và cảnh báo khi lệch có hành động (#2937)')]
final class ReconcileCashDrawers extends Command
{
    public function __construct(private readonly CashDrawerVarianceAlertService $alerts)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subHours($hours);

        // Chỉ ca CÓ ảnh chụp lúc chốt — xem docblock.
        $sessionIds = CashDeviceInventorySnapshot::query()
            ->where('count_phase', 'closing')
            ->where('captured_at', '>=', $since)
            ->distinct()
            ->pluck('till_session_id');

        if ($sessionIds->isEmpty()) {
            $this->info('Không có ca nào có ảnh chụp 在高 lúc chốt trong cửa sổ — không có gì để đối soát.');

            return self::SUCCESS;
        }

        $sessions = TillSession::query()
            ->with('branch')
            ->whereIn('id', $sessionIds)
            // Ca chưa chốt thì con số của người chưa tồn tại; đối soát lúc đó
            // là so với một vế trống.
            ->whereIn('status', ['settled', 'closed'])
            ->get();

        $counts = ['reconciled' => 0, 'alerted' => 0, 'quiet' => 0];

        foreach ($sessions as $session) {
            if ($dryRun) {
                // `--dry-run` KHÔNG được gửi thông báo, nhưng vẫn phải tính —
                // một dry-run không tính gì thì không xem trước được gì.
                $counts['reconciled']++;
                $this->line(sprintf('  %s  %s', $session->id, 'dry-run (không gửi)'));

                continue;
            }

            $result = $this->alerts->evaluate($session);
            $counts['reconciled']++;
            $counts[$result['alerted'] ? 'alerted' : 'quiet']++;

            $this->line(sprintf('  %s  %s%s',
                $session->id,
                $result['verdict'],
                $result['alerted'] ? '  → đã báo' : ''
            ));
        }

        Log::info('[pos.till] cash-drawer-reconcile-run', $counts + [
            'window_hours' => $hours,
            'dry_run' => $dryRun,
        ]);

        $this->info(sprintf(
            'Đối soát %d ca — %d báo, %d im.',
            $counts['reconciled'], $counts['alerted'], $counts['quiet']
        ));

        return self::SUCCESS;
    }
}
