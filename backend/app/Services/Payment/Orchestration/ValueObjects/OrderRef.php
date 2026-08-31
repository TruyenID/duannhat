<?php

declare(strict_types=1);

namespace App\Services\Payment\Orchestration\ValueObjects;

/**
 * #1603 — bốn neo của một đơn, đủ cho phần bootstrap cổng thanh toán.
 *
 * Ba class `Orchestration\Internal` dựng connection/policy cho một đơn đọc
 * ĐÚNG bốn trường: `id` · `organization_id` · `brand_id` · `branch_id`. Đo bằng
 * cách quét thân method, không đọc lướt — không class nào chạm tiền, trạng thái
 * hay dòng món.
 *
 * **Vì sao là value object chứ không phải bốn tham số `string`.** Bốn chuỗi
 * cạnh nhau, ba trong đó là UUID cùng hình dạng: hoán `organizationId` với
 * `branchId` là **lọc sai âm thầm** — PHP không kêu, và một connection giải ra
 * theo nhầm chi nhánh vẫn "chạy được". Tên trường là thứ chặn cú đó.
 *
 * **Vì sao KHÔNG dùng `OrderSnapshot`.** Nó là hợp đồng công bố của Ordering,
 * nhưng hiện thực (`CustomerOrderSnapshot`) nằm trong `Order\Internal` — Payments
 * không dựng được, phải đi qua `OrderQueryPort::findById()`, tức **thêm một
 * truy vấn trên đường thanh toán** để lấy dữ liệu người gọi đã cầm sẵn.
 *
 * Payments tự sở hữu kiểu này, nên nó không tạo cạnh nào sang Ordering.
 */
final readonly class OrderRef
{
    public function __construct(
        public string $orderId,
        public string $organizationId,
        public ?string $brandId,
        public string $branchId,
    ) {}
}
