<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\VoidReason;

/**
 * Tạo lý do huỷ món (#1666, tách khỏi `HQ\VoidReasonController::store`).
 *
 * Ba mặc định dưới đây là mặc định của MIỀN, không phải của form: một lý do vừa
 * tạo là **đang dùng được**, **không bắt ghi chú**, và **xếp đầu danh sách**.
 * Chúng được áp tường minh chứ không nhờ default của schema vì payload trả về
 * client không bao giờ được mang `null` ở ba trường này — client dựng checkbox
 * và ô số theo chúng.
 *
 * `brand_id` đến từ đoạn `{brandSlug}` trên URL (`ResolveBrandFromSlug`), **không
 * bao giờ từ client**: nhận nó từ body là để một người tạo lý do huỷ cho thương
 * hiệu khác. Vì thế nó là THAM SỐ của method, không phải một khoá trong `$data`.
 */
final class VoidReasonService
{
    /**
     * @param  array<string, mixed>  $data  đã qua FormRequest
     */
    public function create(string $brandId, array $data): VoidReason
    {
        $data['brand_id'] = $brandId;
        $data['requires_note'] = $data['requires_note'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return VoidReason::create($data);
    }
}
