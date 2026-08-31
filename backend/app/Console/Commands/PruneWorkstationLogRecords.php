<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use App\Services\Device\Internal\WorkstationLogArchive;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * #2901 — hạn giữ 14 ngày của `workstation_log_records`, cộng lượt đánh dấu
 * yêu cầu hết hạn.
 *
 * ## Vì sao mốc là `logged_at` chứ không `created_at`
 *
 * Đây là phần dễ làm sai nhất của cả tính năng. `created_at` là lúc **Cloud
 * nhận**; `logged_at` là lúc dòng ra đời **trên máy trạm**. Một quán mất mạng
 * 10 ngày rồi mới đẩy được sẽ làm mọi dòng "trẻ lại" 10 ngày nếu đếm theo lúc
 * nhận — hạn 14 ngày lặng lẽ thành 24, và không ai khai điều đó. Chủ dự án
 * chốt "14 ngày tính theo dấu thời gian của chính bản ghi", nên lệnh này đếm
 * theo `logged_at`.
 *
 * Đây KHÔNG phải vi phạm #1091: hạn lưu trữ là thời gian TRÔI QUA, không phải
 * ngày nghiệp vụ — không múi giờ chi nhánh nào đổi được "14 ngày là bao lâu".
 *
 * ## Trần, không phải sàn
 *
 * `audit:prune` từ chối chạy khi cửa sổ **ngắn hơn** sàn PCI (giữ đủ lâu là
 * nghĩa vụ tuân thủ). Ở đây ngược lại: lệnh từ chối khi cửa sổ **dài hơn**
 * `retention_max_days`, vì nghĩa vụ là *đừng giữ quá lâu*. Hai bảng, hai nghĩa
 * vụ ngược nhau — và từ chối chứ không tự cắt xuống, vì im lặng "sửa hộ" một
 * cấu hình sai là cách chắc nhất để không ai biết nó sai.
 *
 * ## Xoá theo lô, khoá chính
 *
 * Cùng khuôn `audit:prune`: một `DELETE ... WHERE logged_at < ?` giữ khoá trên
 * một khoảng không giới hạn. Ở đây fetch khoá chính theo lô rồi xoá theo khoá,
 * dừng ở `max-rows`/`max-seconds`. Dừng sớm là kết cục BÌNH THƯỜNG — mốc được
 * tính lại từ đồng hồ ở lượt sau.
 *
 * ## Lượt đánh dấu hết hạn đi kèm, không tách lệnh
 *
 * Một yêu cầu quá `expires_at` mà chưa ai trả lời phải chuyển sang `expired`,
 * nếu không màn hình HQ sẽ hiện mãi một hàng `pending` không bao giờ về. Đúng
 * đắn KHÔNG phụ thuộc vào lượt chạy này (cả đường đọc lẫn đường ghi đều so
 * `expires_at` với đồng hồ tại chỗ) — nó chỉ làm cột `status` nói thật.
 */
#[Signature('workstation-logs:prune
    {--dry-run : Đếm và in ra, không xoá gì}
    {--days= : Ghi đè cửa sổ giữ, tính bằng ngày (vẫn bị từ chối nếu vượt trần)}
    {--chunk= : Số hàng xoá mỗi lô}
    {--max-rows= : Trần cứng số hàng xoá trong lượt này}
    {--max-seconds= : Ngân sách thời gian của lượt này}')]
#[Description('Xoá workstation_log_records quá hạn theo logged_at, và đánh dấu yêu cầu hết hạn (#2901)')]
class PruneWorkstationLogRecords extends Command
{
    public function __construct(private readonly WorkstationLogArchive $archive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = $this->intOption('days') ?? (int) config('workstation_logs.retention_days');
        $ceiling = (int) config('workstation_logs.retention_max_days');

        if ($days > $ceiling) {
            $this->error("Từ chối chạy: cửa sổ giữ {$days} ngày, vượt trần {$ceiling} ngày của #2901.");
            $this->line('Hạ WORKSTATION_LOG_RETENTION_DAYS (hoặc --days) chứ đừng nâng trần — trần này là cam kết về PII, không phải một tham số hiệu năng. Không xoá gì cả.');

            return self::FAILURE;
        }

        if ($days < 1) {
            $this->error("Từ chối chạy: cửa sổ giữ {$days} ngày. Dưới 1 ngày thì lượt kéo log vừa xong đã bị xoá trước khi ai kịp đọc.");

            return self::FAILURE;
        }

        $chunkSize = max(1, $this->intOption('chunk') ?? (int) config('workstation_logs.prune.chunk_size'));
        $maxRows = max(0, $this->intOption('max-rows') ?? (int) config('workstation_logs.prune.max_rows'));
        $maxSeconds = max(1, $this->intOption('max-seconds') ?? (int) config('workstation_logs.prune.max_seconds'));
        $pauseMs = max(0, (int) config('workstation_logs.prune.pause_ms'));

        // `logged_at`, KHÔNG `created_at` — xem docblock.
        $cutoff = Carbon::now('UTC')->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line("workstation-logs:prune — giữ {$days} ngày, mốc {$cutoff->toIso8601String()} (UTC, theo logged_at)");

        if ($dryRun) {
            $eligible = $this->eligible($cutoff)->count();
            $expiring = $this->staleRequests()->count();

            $this->line("dry-run: {$eligible} dòng quá hạn sẽ bị xoá; {$expiring} yêu cầu sẽ chuyển sang expired.");

            return self::SUCCESS;
        }

        $expired = $this->expireStaleRequests();

        $startedAt = microtime(true);
        $deleted = 0;
        $batches = 0;
        $stoppedBy = 'drained';

        while (true) {
            if ($maxRows > 0 && $deleted >= $maxRows) {
                $stoppedBy = 'max-rows';
                break;
            }

            if (microtime(true) - $startedAt >= $maxSeconds) {
                $stoppedBy = 'max-seconds';
                break;
            }

            $take = $maxRows > 0 ? min($chunkSize, $maxRows - $deleted) : $chunkSize;

            $ids = $this->eligible($cutoff)
                ->orderBy('logged_at')
                ->limit($take)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            // Xoá theo khoá chính: câu SELECT ở trên đã quyết định tập, nên
            // lệnh ghi không chạm gì mà lượt quét chưa đồng ý.
            // Lệnh ghi đi qua `WorkstationLogArchive` — nó là biên giới ghi duy
            // nhất của aggregate `workstation_log` trong
            // `config/domain-mutation-guard.php`.
            $deleted += $this->archive->deleteRecords(array_map(strval(...), $ids));
            $batches++;

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $duration = round(microtime(true) - $startedAt, 2);
        $remaining = $this->eligible($cutoff)->count();

        $this->line("đã xoá {$deleted} dòng trong {$batches} lô, {$duration}s; dừng vì: {$stoppedBy}");
        $this->line("còn quá hạn: {$remaining} dòng · đã đánh dấu hết hạn: {$expired} yêu cầu");

        Log::info('[workstation-logs.prune] run', [
            'retention_days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'deleted' => $deleted,
            'batches' => $batches,
            'stopped_by' => $stoppedBy,
            'remaining_eligible' => $remaining,
            'expired_requests' => $expired,
            'duration_seconds' => $duration,
        ]);

        if ($stoppedBy !== 'drained') {
            $this->warn("Chạm giới hạn ({$stoppedBy}), còn {$remaining} dòng quá hạn. Lượt sau tính lại mốc từ đồng hồ và chạy tiếp.");
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<WorkstationLogRecord>
     */
    private function eligible(Carbon $cutoff): Builder
    {
        return WorkstationLogRecord::query()->where('logged_at', '<', $cutoff);
    }

    /**
     * @return Builder<WorkstationLogRequest>
     */
    private function staleRequests(): Builder
    {
        return WorkstationLogRequest::query()
            ->where('status', WorkstationLogRequestStatusEnum::Pending->value)
            ->where('expires_at', '<=', Carbon::now('UTC'));
    }

    private function expireStaleRequests(): int
    {
        return $this->archive->expireStaleRequests(CarbonImmutable::now('UTC'));
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return ($value === null || $value === '') ? null : (int) $value;
    }
}
