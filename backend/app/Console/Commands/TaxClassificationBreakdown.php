<?php

namespace App\Console\Commands;

use App\Models\TaxType;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\TaxClassificationEnum;
use App\Support\BusinessClock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #1367 — doanh thu tách theo TRỤC PHÂN LOẠI thuế, không phải theo thuế suất.
 *
 * ## Vì sao lệnh này tồn tại, chứ không chỉ thêm một cột
 *
 * #1367 cảnh báo thẳng: *"Đừng chỉ thêm cột rồi dừng… một trục phân loại không
 * có nơi tiêu thụ cũng cùng loại nợ đó"* (#1311/#1312). Đây là nơi tiêu thụ.
 *
 * Mọi báo cáo hiện có nhóm theo **thuế suất**. Với 課税 thì đủ, nhưng ba loại 0%
 * — 非課税 / 不課税 / 免税 — gộp chung thành một dòng "0%", trong khi trên tờ
 * khai chúng nằm ở ba chỗ khác nhau. Lệnh này nhóm theo trục.
 *
 * ## Không cần cột snapshot mới
 *
 * `customer_order_items.tax_type_id` đã được chụp lại trên từng dòng, với FK
 * RESTRICT (BR-TT02) nên loại thuế không thể bị xoá cứng khi còn đơn tham
 * chiếu. Nghĩa là phân loại của MỌI dòng lịch sử tra ngược được bằng một phép
 * join — không phải thêm cột, không phải backfill.
 *
 * Đánh đổi phải nói rõ: `rate` thì snapshot (sửa thuế suất KHÔNG viết lại lịch
 * sử — spec §6.3), còn `classification` thì join, nên **sửa phân loại SẼ đổi
 * cách các đơn cũ được xếp nhóm**. Đó là hành vi ĐÚNG cho trục này: phân loại
 * là một sự thật pháp lý về mặt hàng, và sửa nó nghĩa là "trước giờ mình xếp
 * sai" — lúc đó tờ khai cũ cũng phải xếp lại. Thuế suất thì ngược lại: nó thay
 * đổi theo thời gian một cách hợp lệ, nên phải đóng băng.
 *
 * ## CHƯA PHÂN LOẠI hiện thành một dòng riêng, cố ý
 *
 * Không dồn vào `taxable`, không lặng lẽ bỏ qua. Một tờ khai xếp 不課税 vào nhóm
 * 非課税 vẫn in ra bình thường, vẫn cân, và sai — nên con số chưa phân loại phải
 * đập vào mắt người đọc. Lệnh thoát khác 0 khi còn dòng chưa phân loại.
 *
 *     php artisan tax:classification-breakdown --branch=<uuid> --from=2026-07-01 --to=2026-07-31
 *     php artisan tax:classification-breakdown --branch=<uuid> --from=2026-07-01 --to=2026-07-31 --json
 *
 * Khoảng ngày là **ngày kinh doanh của chi nhánh** ({@see BusinessClock}), không
 * phải ngày UTC: một ca mở 00:00–09:00 JST thuộc ngày kinh doanh hôm trước, và
 * dùng nhầm mốc là lệch doanh thu đúng chín tiếng mỗi ngày (#1091).
 */
#[Signature('tax:classification-breakdown {--branch= : UUID chi nhánh} {--from= : Ngày kinh doanh bắt đầu (YYYY-MM-DD)} {--to= : Ngày kinh doanh kết thúc} {--json : Xuất JSON}')]
#[Description('#1367 — doanh thu tách theo 課税/非課税/不課税/免税 thay vì gộp mọi loại 0% thành một dòng.')]
class TaxClassificationBreakdown extends Command
{
    public function handle(): int
    {
        $branchId = (string) $this->option('branch');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($branchId === '' || $from === '' || $to === '') {
            $this->error('Cần đủ --branch, --from và --to.');

            return self::FAILURE;
        }

        // Nửa mở [start, end) theo NGÀY KINH DOANH của chi nhánh — helper tự
        // cộng một ngày vào `until`, nên `--to` là ngày cuối CÓ TÍNH.
        [$start, $end] = BusinessClock::utcRangeForBusinessDates($branchId, $from, $to);
        if ($start === null || $end === null) {
            $this->error('--from/--to phải là ngày hợp lệ dạng YYYY-MM-DD.');

            return self::FAILURE;
        }

        $rows = $this->breakdown($branchId, $start, $end);

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Phân loại', 'Thuế suất', 'Doanh thu', 'Thuế', 'Số dòng'],
                array_map(static fn (array $r): array => [
                    $r['classification'] ?? '⚠ CHƯA PHÂN LOẠI',
                    number_format((float) $r['rate'], 2).'%',
                    number_format((float) $r['taxable'], 2),
                    number_format((float) $r['tax'], 2),
                    $r['lines'],
                ], $rows),
            );
        }

        $unclassified = array_filter($rows, static fn (array $r): bool => $r['classification'] === null);

        if ($unclassified !== []) {
            $lines = array_sum(array_column($unclassified, 'lines'));
            $this->newLine();
            $this->warn("⚠ {$lines} dòng đơn dùng loại thuế CHƯA PHÂN LOẠI — bảng trên chưa dùng để khai được.");
            $this->line('  Phân loại chúng ở màn Loại thuế (HQ), rồi chạy lại. Danh sách cần xử lý:');
            foreach ($this->unclassifiedTypes($branchId) as $type) {
                $this->line(sprintf('    %-12s %-24s rate %s', $type->code, $type->name ?? '', $type->rate));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{classification: string|null, rate: string, taxable: string, tax: string, lines: int}>
     */
    public function breakdown(string $branchId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return DB::table('customer_order_items as i')
            ->join('customer_orders as o', 'o.id', '=', 'i.customer_order_id')
            // LEFT join: một dòng có `tax_type_id = NULL` (kế thừa mặc định, hoặc
            // dữ liệu trước plan-043) vẫn phải hiện ra, chứ không biến mất khỏi
            // tổng — một báo cáo thuế thiếu dòng thì tệ hơn một báo cáo xấu.
            ->leftJoin('tax_types as t', 't.id', '=', 'i.tax_type_id')
            ->where('o.branch_id', $branchId)
            // Nửa mở: `whereBetween` bao gồm cả đầu mút phải, nên một đơn mở
            // đúng 00:00:00 của ngày kế sẽ bị đếm vào CẢ HAI kỳ.
            ->where('o.opened_at', '>=', $start)
            ->where('o.opened_at', '<', $end)
            // Đơn đã xoá mềm không vào doanh thu. `customer_order_items` KHÔNG
            // soft-delete — món bị bỏ mang `status = voided`, và đó mới là bộ
            // lọc đúng ở đây (cùng luật với `StockDeductionService`: voided
            // không bao giờ được tính).
            ->whereNull('o.deleted_at')
            ->where('i.status', '!=', OrderItemStatusEnum::Voided->value)
            ->groupBy('t.classification', 'i.tax_rate')
            ->orderByRaw('t.classification is null desc')
            ->orderBy('t.classification')
            ->orderBy('i.tax_rate')
            ->selectRaw('t.classification as classification, i.tax_rate as rate, sum(i.subtotal) as taxable, sum(i.tax_amount) as tax, count(*) as lines')
            ->get()
            ->map(static fn ($r): array => [
                'classification' => $r->classification,
                'rate' => (string) $r->rate,
                'taxable' => (string) $r->taxable,
                'tax' => (string) $r->tax,
                'lines' => (int) $r->lines,
            ])
            ->all();
    }

    /** @return Collection<int, TaxType> */
    private function unclassifiedTypes(string $branchId): Collection
    {
        return TaxType::query()
            ->whereNull('classification')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('customer_order_items as i')
                ->join('customer_orders as o', 'o.id', '=', 'i.customer_order_id')
                ->whereColumn('i.tax_type_id', 'tax_types.id')
                ->where('o.branch_id', $branchId))
            ->orderBy('code')
            ->get();
    }

    /** Bốn giá trị hợp lệ — dùng cho thông điệp trợ giúp và test. */
    public static function classifications(): array
    {
        return array_column(TaxClassificationEnum::cases(), 'value');
    }
}
