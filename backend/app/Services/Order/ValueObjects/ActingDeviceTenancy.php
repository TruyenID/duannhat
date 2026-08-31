<?php

declare(strict_types=1);

namespace App\Services\Order\ValueObjects;

/**
 * #962 — org/branch của THIẾT BỊ đang thao tác trên một đơn.
 *
 * `KdsBusinessRules::assertBranchOwnership` chỉ cần đúng hai chuỗi này, nhưng
 * trước đây nhận nguyên `App\Models\Device` — tức Ordering phải biết model của
 * PlatformIntegration để so hai cột. Cạnh đó không mua được gì: quy tắc không
 * đọc thêm thuộc tính nào khác, không lưu, không ghi.
 *
 * Khai ở `App\Services\Order\ValueObjects` vì namespace này được publish (mọi
 * module thấy được), và giữ nó KHÔNG có model nào để
 * `PublishedContractsAreModelFreeTest` còn nghĩa.
 *
 * Dựng ở tầng Composition (controller), nơi đã cầm `Device` trong tay.
 */
final readonly class ActingDeviceTenancy
{
    public ?string $organizationId;

    public ?string $branchId;

    public function __construct(?string $organizationId, ?string $branchId)
    {
        $this->organizationId = $this->normalize($organizationId);
        $this->branchId = $this->normalize($branchId);
    }

    /**
     * Đọc từ bất cứ thứ gì phơi ra `organization_id` / `branch_id` — model
     * Eloquent, mảng, hay object thường. Cố ý KHÔNG type-hint `Device`: chính
     * cái type-hint đó là cạnh đang gỡ.
     */
    public static function of(mixed $device): self
    {
        if (is_array($device)) {
            return new self(
                isset($device['organization_id']) ? (string) $device['organization_id'] : null,
                isset($device['branch_id']) ? (string) $device['branch_id'] : null,
            );
        }

        if (! is_object($device)) {
            return new self(null, null);
        }

        return new self(
            isset($device->organization_id) ? (string) $device->organization_id : null,
            isset($device->branch_id) ? (string) $device->branch_id : null,
        );
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
