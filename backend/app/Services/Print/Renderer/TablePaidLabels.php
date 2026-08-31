<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 2 (#1932) — nhãn của mẩu giấy "bàn N vừa thanh toán".
 *
 * Đối ứng của `tablePaidLabels` (workstation `print_table_paid_i18n.go`). Tách
 * khỏi {@see PrintLabels} vì bên Go tách: đây là một tờ giấy nội bộ cho chạy
 * bàn, không phải chứng từ cho khách, và nó có vòng đời riêng.
 *
 * `order` CỐ Ý trùng khít nhãn `orderNo` của phiếu bán hàng ("伝票" / "Order#" /
 * "So HD") — chạy bàn phải đối chiếu được mẩu giấy này với biên lai của khách
 * bằng CÙNG một con số. Sửa một trong hai mà không sửa cái kia là bỏ mất khả
 * năng đối chiếu đó, và không có gì đỏ khi làm vậy.
 *
 * Tiếng Việt gấp về ASCII (không dấu) như mọi catalog nhãn khác: bộ mã của đầu
 * in là Shift_JIS, không mã hoá được dấu tiếng Việt.
 */
final class TablePaidLabels
{
    private function __construct(
        /** Tiêu đề lớn canh giữa — 会計済 / PAID / DA THANH TOAN. */
        public readonly string $title,
        /** Tiền tố trước số bàn. */
        public readonly string $table,
        /** Tiền tố mã đơn — TRÙNG KHÍT `orderNo` của phiếu bán hàng. */
        public readonly string $order,
    ) {}

    /** Locale lạ rơi về ja, giống `tablePaidLabelsFor` bên Go. */
    public static function forLocale(string $locale): self
    {
        return match (strtolower(trim($locale))) {
            'en' => new self(title: 'PAID', table: 'TABLE', order: 'Order#'),
            'vi' => new self(title: 'DA THANH TOAN', table: 'BAN', order: 'So HD'),
            default => new self(title: '会計済', table: 'テーブル', order: '伝票'),
        };
    }
}
