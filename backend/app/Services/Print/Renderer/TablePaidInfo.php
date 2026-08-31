<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Support\BusinessClock;

/**
 * plan-053 T5.1d (#1909) — mẩu giấy báo "bàn N vừa thanh toán" cho chạy bàn.
 *
 * Đối ứng `TablePaidInfo` bên Go (3 trường). `paidAt` là **string**, không phải
 * thời điểm — chép đúng Go: chuỗi đã định dạng sẵn ở nơi biết múi giờ chi nhánh
 * ({@see BusinessClock}). Nhận `CarbonImmutable` rồi định dạng ở
 * tầng in là mời tầng in dùng đồng hồ app, tức đúng lỗi #1091 — chín tiếng mỗi
 * ngày rơi sang ngày kinh doanh trước ở quán Nhật.
 *
 * Đây cũng là kind duy nhất của họ docs có `defaultWidth` 42 chứ không 48.
 */
final class TablePaidInfo
{
    public function __construct(
        public readonly string $tableNumber = '',
        public readonly string $orderCode = '',
        /** ĐÃ định dạng theo múi giờ chi nhánh — đừng định dạng lại ở tầng in. */
        public readonly string $paidAt = '',
    ) {}
}
