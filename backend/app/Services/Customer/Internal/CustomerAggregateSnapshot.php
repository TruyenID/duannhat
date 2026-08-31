<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Customer\Contracts\CustomerSnapshot;

/**
 * Ảnh chụp bất biến một hàng `customers` cho người đọc ngoài CustomerEngagement (#1550).
 *
 * `status()` là dẫn xuất, không phải cột: bảng không có cột trạng thái, nên
 * trạng thái CHÍNH LÀ việc hàng còn sống hay đã xoá mềm. Bịa thêm một cột để
 * "cho giống các aggregate khác" sẽ tạo hai nguồn sự thật cho cùng một câu hỏi.
 */
final class CustomerAggregateSnapshot implements CustomerSnapshot
{
    private function __construct(
        private readonly string $id,
        private readonly ?string $organizationId,
        private readonly ?string $branchId,
        private readonly string $status,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            (string) $customer->id,
            $customer->organization_id === null ? null : (string) $customer->organization_id,
            $customer->branch_id === null ? null : (string) $customer->branch_id,
            $customer->deleted_at === null ? 'active' : 'archived',
        );
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function branchId(): ?string
    {
        return $this->branchId;
    }

    public function status(): string
    {
        return $this->status;
    }
}
