<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

use App\Omnify\Enums\ApprovalStatusEnum;

/**
 * #1567 — ảnh chụp một CÔNG THỨC, do Catalog công bố cho Inventory.
 *
 * Các trường dưới đây là **toàn bộ** những gì `MaterialBatchService` đọc — đo
 * bằng cách quét thân method, không đọc lướt. Không thêm trường "cho đủ bộ":
 * đó là luật đã ghi trong docblock của `OrderSnapshot` và nó áp ở đây y hệt.
 *
 * `ingredients` giữ NGUYÊN hình dạng thô của cột JSON:
 *
 *     {type: "material"|"variant"|"raw", material_id|variant_id, quantity, unit}
 *
 * Cố ý không "chuẩn hoá" hộ: một công thức được phép tham chiếu **SKU khác** làm
 * nguyên liệu (`variant_id`), và mọi cố gắng ép nó về một hình dạng phẳng sẽ
 * đánh mất đúng ca đó — thứ mà `ProductionOrderService` dựa vào.
 *
 * `ApprovalStatusEnum` nằm trong `App\Omnify\Enums`, vốn đã là `shared`, nên
 * mang nó ở đây không làm mất tư cách công bố.
 */
final readonly class RecipeSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $ingredients
     */
    public function __construct(
        public string $id,
        public ?string $materialId,
        public bool $isActive,
        public ApprovalStatusEnum $approvalStatus,
        public float $outputQuantity,
        public ?string $outputUnit,
        public array $ingredients,
        public float $yieldVarianceTolerancePct,
    ) {}

    public function isApproved(): bool
    {
        return $this->approvalStatus === ApprovalStatusEnum::Approved;
    }
}
