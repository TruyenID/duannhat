<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1909) — phiếu ghi nợ.
 *
 * Đối ứng `DebtSlipInfo` bên Go — **đúng 5 trường**, và sự nhỏ bé đó là điều
 * đáng ghi: phiếu nợ in dòng món, tổng tiền và số đã trả từ `order`/`items`
 * ({@see PrintRenderOrder}), KHÔNG từ struct này. Struct này chỉ mang phần
 * riêng của người mắc nợ cộng số dư.
 *
 * Đừng thêm trường vào đây khi thấy phiếu nợ in thứ gì đó chưa có — nhiều khả
 * năng nó đã có ở ô `order`, và nhân đôi nguồn là mở đường cho hai con số nợ.
 */
final class DebtSlipInfo
{
    public function __construct(
        public readonly string $customerName = '',
        public readonly string $customerPhone = '',
        public readonly string $customerTaxCode = '',
        public readonly int $debtAmount = 0,
        public readonly int $reprintNumber = 0,
    ) {}
}
