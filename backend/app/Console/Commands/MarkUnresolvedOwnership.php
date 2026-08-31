<?php

namespace App\Console\Commands;

use App\Services\Payment\Settlement\UnresolvedOwnershipBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * #3084 — vỏ CLI cho {@see UnresolvedOwnershipBackfill}.
 *
 * Luật nghiệp vụ ở service; ở đây chỉ có tham số và cách in — cùng khuôn
 * `payments:consolidate-duplicate-connections` (#3070) và
 * `settlements:mark-foreign` (#2864), cùng lý do:
 * `architecture:domain-writers` chỉ công nhận writer của aggregate `payment` ở
 * tầng service.
 *
 * **Thứ tự: deploy bản vá bootstrap TRƯỚC, chạy lệnh này SAU.** Ngược lại thì
 * giữa hai bước, một connection mới vẫn ra đời mang UUID bịa và lượt dọn vừa
 * chạy đã lạc hậu.
 *
 * Mặc định DRY-RUN. Phải truyền `--apply` mới ghi, và `--apply` phải kèm
 * `--expect=<n>` là số hàng vừa thấy ở lượt dry-run — lệch một hàng thì lệnh
 * TỪ CHỐI. Lý do đầy đủ ở docblock của {@see UnresolvedOwnershipBackfill}: không
 * có phép thử nào phân biệt UUID bịa với UUID Identity thật, nên thứ duy nhất
 * đứng giữa lệnh này và việc xoá quyền sở hữu thật của ai đó là con số đó.
 */
#[Signature('payments:mark-unresolved-ownership {--apply : Ghi thật (mặc định chỉ báo cáo)} {--expect= : Số hàng mong đợi; BẮT BUỘC khi --apply}')]
#[Description('Đóng dấu "quyền sở hữu chưa phân giải" lên connection đang mang UUID bịa (#3084)')]
final class MarkUnresolvedOwnership extends Command
{
    public function handle(UnresolvedOwnershipBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply');
        $expectOption = $this->option('expect');
        $expected = $expectOption === null || $expectOption === '' ? null : (int) $expectOption;
        $result = $backfill->backfill($apply, $expected);

        if ($result['refused'] === 'expected_missing') {
            $this->error(sprintf(
                '--apply cần --expect=<n>. Lượt đo này thấy %d hàng; chạy dry-run, ĐỌC danh sách, rồi chạy lại với --expect=%d.',
                $result['marked'],
                $result['marked'],
            ));
            $this->line('Không có phép thử nào phân biệt UUID bịa với UUID Identity thật — con số đó là thứ duy nhất chặn lệnh này xoá quyền sở hữu thật.');

            return self::FAILURE;
        }

        if ($result['refused'] === 'expected_mismatch') {
            $this->error(sprintf(
                'TỪ CHỐI GHI — mong đợi %d hàng, đo được %d.',
                (int) $expected,
                $result['marked'],
            ));
            $this->line('Dữ liệu đã đổi kể từ lượt dry-run của bạn. Đo lại trước khi ghi; đừng chỉ sửa con số cho khớp.');

            return self::FAILURE;
        }

        if ($result['marked'] === 0) {
            $this->info('Không connection nào còn mang giá trị quyền sở hữu bịa — không có gì để đánh dấu.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($result['ids'] as $id) {
            $this->line(sprintf('  %s', $id));
        }

        $this->newLine();

        if (! $apply) {
            $this->warn(sprintf(
                'DRY-RUN — %d hàng sẽ được đánh dấu. ĐỌC danh sách trên rồi chạy lại: --apply --expect=%d',
                $result['marked'],
                $result['marked'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Đã đánh dấu %d hàng. Đây KHÔNG phải phân giải quyền sở hữu — nó chỉ làm việc chưa biết trở nên tra ra được.',
            $result['marked'],
        ));

        return self::SUCCESS;
    }
}
