<?php

namespace App\Services\Payment\Policy\Contracts;

use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;

/**
 * Ranh giới đọc mô hình QUẢN LÝ chi nhánh — ai sở hữu thương hiệu, ai vận hành
 * chi nhánh, và bản sửa đổi (`ownership_revision`) của quyết định đó.
 *
 * Tempo **không được tự suy ra** những giá trị này từ bảng `branches` cục bộ:
 * chúng quyết định tiền thuộc về ai. Tempo chỉ được cache projection đã giải
 * quyết cùng revision mờ của nó.
 *
 * Nguồn chân lý là **Platform** (`dxs-platform/platform`) — console quản trị
 * org · brand · branch · user · role · permission. Chưa có bản cài đặt thật vì
 * Platform còn thiếu vòng đời grant + endpoint đọc; chi tiết và cách tự kiểm nằm
 * ở docblock của `UnavailableBranchManagementProjectionSource`.
 */
interface BranchManagementProjectionSource
{
    public function resolve(BranchManagementLookup $lookup): BranchManagementProjection;
}
