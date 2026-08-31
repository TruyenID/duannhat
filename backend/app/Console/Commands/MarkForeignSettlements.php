<?php

namespace App\Console\Commands;

use App\Services\Payment\Settlement\ForeignSettlementMarker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * #2864 phần 2 — vỏ CLI cho {@see ForeignSettlementMarker}.
 *
 * Luật nghiệp vụ và lý do nằm ở service; ở đây chỉ có tham số và cách in.
 *
 * **Lệnh này chạy lại được, cố ý.** #2188 bảo backfill xong thì bỏ lệnh, nhưng
 * tiền đề "chưa release" của ruling đó đã hết đúng (chủ dự án chốt 2026-08-15,
 * xem #2872) — và có lý do vận hành cụ thể hơn: bản vá chặn tại nguồn (#2867)
 * nằm ở `dev`, **chưa lên production**, nên hàng foreign vẫn đẻ thêm mỗi ngày
 * cho tới lượt promote. Ops cần chạy lại được, không phải một phát rồi thôi.
 *
 * Mặc định là DRY-RUN. Phải truyền `--apply` mới ghi.
 */
#[Signature('settlements:mark-foreign {--connection= : Giới hạn trong một payment gateway connection} {--limit=500 : Trần số hàng xử lý một lượt} {--apply : Ghi thật (mặc định chỉ báo cáo)}')]
#[Description('Đánh dấu hàng settlement là tiền của merchant khác trên tài khoản Stripe dùng chung (#2864)')]
final class MarkForeignSettlements extends Command
{
    public function handle(ForeignSettlementMarker $marker): int
    {
        $apply = (bool) $this->option('apply');

        $connectionId = $this->option('connection') !== null && $this->option('connection') !== ''
            ? (string) $this->option('connection')
            : null;

        $result = $marker->sweep($connectionId, (int) $this->option('limit'), $apply);

        foreach ($result['marked'] as $row) {
            // #2981 — hai loại dòng đi chung một danh sách: hàng có intent
            // (tiền của merchant khác) và hàng không có intent (phí Stripe).
            // In đúng định danh mà mỗi loại thật sự mang, thay vì để một khoá
            // vắng mặt biến thành chuỗi rỗng lúc điều tra.
            $isFee = ($row['reporting_category'] ?? null) === 'fee';

            $this->line(sprintf(
                '  %s  gross=%d fee=%d  %s',
                $isFee ? $row['balance_transaction'] : $row['payment_intent'],
                $row['gross_minor'],
                $row['fee_minor'],
                $apply
                    ? ($isFee ? '→ fee_adjustment' : '→ foreign')
                    : '(dry-run)',
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — quét %d hàng orphan: foreign=%d (gross=%d, phí=%d) · fee_adjustment=%d · tempo=%d · unknown=%d · lỗi=%d',
            $apply ? 'ĐÃ GHI' : 'DRY-RUN',
            $result['scanned'],
            $result['foreign'],
            $result['gross_minor'],
            $result['fee_minor'],
            $result['fee_adjustment'],
            $result['tempo'],
            $result['unknown'],
            $result['errors'],
        ));

        if ($result['tempo'] > 0) {
            // Một hàng orphan hoá ra CỦA TA là tin đáng đọc, không phải dòng
            // thống kê: nó nghĩa là có tiền thật chưa khớp được về đơn.
            $this->warn(sprintf(
                '%d hàng orphan là CỦA TEMPO — đó là tiền thật chưa khớp về đơn, cần đối soát riêng.',
                $result['tempo'],
            ));
        }

        return self::SUCCESS;
    }
}
