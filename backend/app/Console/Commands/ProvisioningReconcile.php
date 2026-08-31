<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Brand;
use App\Services\Provisioning\BaselineReport;
use App\Services\Provisioning\BranchBaselineProvisioner;
use App\Services\Provisioning\BrandBaselineProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Đưa baseline của brand/branch về đúng trạng thái mong muốn (#2320).
 *
 * MỘT lệnh, hai chế độ. `--dry-run` chỉ đọc và in ra cái đang thiếu — đó cũng
 * chính là báo cáo readiness, nên không có lệnh `provisioning:readiness` thứ hai
 * để hai bên trả lời khác nhau.
 *
 * Đây là đường chạy trên production. Chạy `--dry-run`, đọc kỹ, rồi chạy thật.
 */
class ProvisioningReconcile extends Command
{
    protected $signature = 'provisioning:reconcile
        {--brand= : chỉ brand có slug này}
        {--branch= : chỉ branch có slug này}
        {--dry-run : chỉ báo cáo, không ghi gì}';

    protected $description = 'Dựng/vá dữ liệu gốc (loại thuế, Reverb, combo, cài đặt đơn hàng) cho brand và branch';

    public function handle(
        BrandBaselineProvisioner $brands,
        BranchBaselineProvisioner $branches,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $brandSlug = $this->option('brand');
        $branchSlug = $this->option('branch');

        $reports = [];
        $failed = 0;

        $brandQuery = Brand::query()->when($brandSlug, fn ($q) => $q->where('slug', $brandSlug));
        $branchQuery = Branch::query()->when($branchSlug, fn ($q) => $q->where('slug', $branchSlug));

        // Lọc theo `--brand` thì chi nhánh cũng phải theo brand đó, nếu không
        // một lượt "chỉ brand X" lại đi sờ vào chi nhánh của brand khác.
        if ($brandSlug !== null && $branchSlug === null) {
            $consoleBrandId = Brand::query()->where('slug', $brandSlug)->value('console_brand_id');
            $branchQuery->where('console_brand_id', $consoleBrandId);
        }

        foreach ($brandQuery->get() as $brand) {
            // Một chủ thể hỏng không được kéo theo phần còn lại: lượt reconcile
            // trên production quét cả nghìn hàng, và dừng ở hàng thứ ba thì
            // 997 hàng sau vẫn thiếu baseline mà không ai biết.
            try {
                $reports[] = $dryRun ? $brands->plan($brand) : $brands->ensure($brand);
            } catch (Throwable $e) {
                $failed++;
                $this->error("brand:{$brand->slug} — {$e->getMessage()}");
            }
        }

        foreach ($branchQuery->get() as $branch) {
            try {
                $reports[] = $dryRun ? $branches->plan($branch) : $branches->ensure($branch);
            } catch (Throwable $e) {
                $failed++;
                $this->error("branch:{$branch->slug} — {$e->getMessage()}");
            }
        }

        $this->render($reports, $dryRun);

        if ($failed > 0) {
            $this->error("{$failed} chủ thể lỗi — xem ở trên.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param list<BaselineReport> $reports */
    private function render(array $reports, bool $dryRun): void
    {
        $rows = [];
        $notReady = 0;

        foreach ($reports as $report) {
            if (! $report->isReady()) {
                $notReady++;
            }

            foreach ($report->entries() as $entry) {
                if ($entry['state'] === 'satisfied') {
                    continue;
                }
                $rows[] = [$report->subject, $entry['key'], $entry['state'], $entry['detail']];
            }
        }

        if ($rows === []) {
            $this->info('Baseline đầy đủ — không có gì để làm ('.count($reports).' chủ thể).');

            return;
        }

        $this->table(['chủ thể', 'mục', 'trạng thái', 'chi tiết'], $rows);

        $this->line(sprintf(
            '%d/%d chủ thể chưa đủ baseline.%s',
            $notReady,
            count($reports),
            $dryRun ? ' (--dry-run: chưa ghi gì)' : '',
        ));
    }
}
