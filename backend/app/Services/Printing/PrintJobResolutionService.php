<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\PrintJob;
use App\Models\PrintJobResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ghi nhận việc một quản lý xử lý tay một lệnh in hỏng (#1666, tách khỏi
 * `PrintJobController::resolve`).
 *
 * Luật ở đây là **lần xử lý ĐẦU TIÊN đứng vững**: lần gọi thứ hai đọc lại bản
 * ghi của người thắng chứ không viết đè ai đã quyết và vì sao. Đó là một phán
 * quyết về sổ sách, không phải một chi tiết của tầng HTTP — nên nó ở đây.
 */
final class PrintJobResolutionService
{
    /**
     * Trả về bản ghi xử lý của $job, tạo nếu chưa có.
     *
     * `firstOrCreate` dưới UNIQUE index `print_job_id`: hai quản lý bấm cùng
     * lúc vẫn ra ĐÚNG MỘT bản ghi, người thua đọc được bản của người thắng.
     * Transaction giữ cho lần đọc-rồi-ghi ấy là một đơn vị.
     */
    public function resolveOnce(
        PrintJob $job,
        string $resolution,
        string $reason,
        ?string $resolvedById,
    ): PrintJobResolution {
        return DB::transaction(fn (): PrintJobResolution => PrintJobResolution::query()->firstOrCreate(
            ['print_job_id' => $job->id],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $job->organization_id,
                'branch_id' => $job->branch_id,
                'resolution' => $resolution,
                'reason' => $reason,
                'resolved_by_id' => $resolvedById,
                'resolved_at' => now(),
            ],
        ));
    }

    /** Bản ghi xử lý hiện có, hoặc null. */
    public function existingFor(PrintJob $job): ?PrintJobResolution
    {
        return PrintJobResolution::query()->where('print_job_id', $job->id)->first();
    }
}
