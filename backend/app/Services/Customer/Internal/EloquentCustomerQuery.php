<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Customer\Contracts\CustomerQueryPort;
use App\Services\Customer\Contracts\CustomerSnapshot;

/**
 * Phía ĐỌC của ranh giới Customer (#1550).
 *
 * Bốn method, và sự khác nhau giữa chúng KHÔNG phải trang trí: hai loại khách
 * sống chung một bảng, phân biệt bằng việc có mang tenant hay không
 * (`CustomerScopeEvidence`: `GlobalAccount` cấm mang tenant id; `TenantCrm`
 * bắt buộc đủ org+brand+branch).
 *
 * Nên tra một tài khoản đăng nhập mà không lọc `organization_id IS NULL` sẽ
 * trúng bản ghi CRM của một cửa hàng nào đó có trùng email — và ngược lại.
 */
final class EloquentCustomerQuery implements CustomerQueryPort
{
    public function findGlobalAccountById(string $customerId): ?CustomerSnapshot
    {
        $row = Customer::query()
            ->whereNull('organization_id')
            ->whereNotNull('password')
            ->whereKey($customerId)
            ->first();

        return $row === null ? null : CustomerAggregateSnapshot::fromModel($row);
    }

    public function findGlobalAccountByEmail(string $email): ?CustomerSnapshot
    {
        $row = Customer::query()
            ->whereNull('organization_id')
            ->whereNotNull('password')
            ->where('email', $email)
            ->first();

        return $row === null ? null : CustomerAggregateSnapshot::fromModel($row);
    }

    public function findTenantCustomerById(string $organizationId, string $branchId, string $customerId): ?CustomerSnapshot
    {
        $row = Customer::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->whereKey($customerId)
            ->first();

        return $row === null ? null : CustomerAggregateSnapshot::fromModel($row);
    }

    /**
     * Khách gắn với một ĐƠN — chỉ ràng theo tổ chức.
     *
     * Cố ý KHÔNG ràng theo chi nhánh: đơn của chi nhánh A có thể trỏ tới bản ghi
     * khách được tạo ở chi nhánh B trong cùng tổ chức, và ràng thêm sẽ làm ảnh
     * chụp đơn mất tên khách ở đúng những ca chuỗi nhiều cửa hàng.
     */
    public function findForOrderSnapshot(string $organizationId, string $customerId): ?CustomerSnapshot
    {
        $row = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereKey($customerId)
            ->first();

        return $row === null ? null : CustomerAggregateSnapshot::fromModel($row);
    }
}
