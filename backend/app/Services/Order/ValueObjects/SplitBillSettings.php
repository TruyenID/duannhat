<?php

namespace App\Services\Order\ValueObjects;

/**
 * #962 — ba cột của `shop_order_settings` mà việc CHIA HOÁ ĐƠN cần, đã kèm sẵn
 * giá trị mặc định.
 *
 * Mặc định nằm ở đây chứ không ở người gọi là có chủ ý: `currency_code` mặc định
 * `'JPY'` là thứ định cỡ dung sai làm tròn, và #821 E3 đã ghi nhận một fallback
 * tiền tệ đặt sai chỗ sinh ra 1.99 USD doanh thu ma. Một chỗ giữ mặc định thì
 * không có chỗ thứ hai để lệch.
 */
final class SplitBillSettings
{
    public function __construct(
        public readonly string $roundingMode,
        public readonly string $currencyCode,
        public readonly float $serviceChargeRate,
    ) {}
}
