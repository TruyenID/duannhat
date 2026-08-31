<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;

/**
 * Mặc định lúc chạy: LUÔN trả `unresolved`, fail-closed có chủ ý (plan-047 T2.5).
 *
 * ## Nguồn chân lý là PLATFORM (`dxs-platform/platform`)
 *
 * Platform là console quản trị `Organization` · `Brand` · `Branch` · `Location` ·
 * `User` · `Employee` · `EmployeeBranchAssignment`, cùng toàn bộ `Role` /
 * `Permission`. Chuỗi xác thực: admin-web → backend Tempo → `SSO_ISSUER`
 * (Platform). Tempo mirror `console_organization_id` / `console_brand_id` /
 * `console_branch_id` từ đó.
 *
 * **Hai vế của câu hỏi quyền sở hữu ĐÃ CÓ SẴN ở Platform**
 * (`backend/app/Core/Models/{Brand,Branch}.php`):
 *
 *   Brand  → belongsTo Organization (console_organization_id)  ← SỞ HỮU thương hiệu
 *   Branch → belongsTo Organization (console_organization_id)  ← VẬN HÀNH chi nhánh
 *
 * Bằng nhau ⇒ `hq_managed`. Khác nhau ⇒ nhượng quyền. Đúng ánh xạ
 * `brand_owner_org_unit_id` / `operator_org_unit_id` trong
 * `tests/Fixtures/Payment/branch-management-contract.json`.
 *
 * ## Vậy còn thiếu gì mà chưa thay được class này
 *
 * Chỉ hai thứ, và cả hai đều nhỏ hơn nhiều so với "dựng một domain mới":
 *
 *   1. **Vòng đời grant** — `status` (active · suspended · revoked) và cửa sổ
 *      `validFrom` / `validTo`. Platform hiện có QUAN HỆ nhưng không có trạng
 *      thái hay hạn, nên chưa phân biệt được nhượng quyền còn hiệu lực với
 *      nhượng quyền đã bị thu hồi.
 *   2. **Endpoint đọc** trả mô hình đã giải quyết + `ownership_revision` đơn
 *      điệu để Tempo cache và so sánh bằng đẳng thức.
 *
 * Tempo **không được tự suy ra** từ dữ liệu cục bộ, kể cả khi hai cột trên đã
 * mirror sẵn: đây là quyết định tiền thuộc về ai, và một bản mirror trễ nhịp thì
 * âm thầm sai. Chờ Platform công bố, rồi viết adapter đọc.
 *
 * ## Cách tự kiểm, để không ai phải đo lại
 *
 *   grep -n "belongsTo" ~/Herd/id/backend/app/Core/Models/{Brand,Branch}.php
 *   grep -n "SSO_ISSUER" backend/.env
 *   ls ~/Herd/id/backend/app/Models | grep -E "Role|Permission|Branch|Brand"
 *
 * ## Hệ quả khi class này còn hiệu lực
 *
 * `PaymentPolicyResolver` xét Ownership ở tầng ĐẦU TIÊN, nên mọi ứng viên chết
 * tại đây và **không branch nào có effective payment option** ở bất kỳ môi
 * trường nào. Đo trên dev sau khi đã dựng đủ chuỗi (keyring · secret ·
 * `validateConnection` → `health=ready` · capability `verified` · bật policy cho
 * 17/17 branch): 0 option được phép, lý do `ownership_source_unavailable`.
 *
 * Dây chuyền: không effective option ⇒ plan-055 Gate 2 không đạt ⇒ Gate 6 không
 * tới ⇒ cổng `legacy_payment_method_code_path` không đóng ⇒ plan-047 T7.6 không
 * đóng. **Thêm dữ liệu KHÔNG mở được nút này.**
 */
final class UnavailableBranchManagementProjectionSource implements BranchManagementProjectionSource
{
    public function resolve(BranchManagementLookup $lookup): BranchManagementProjection
    {
        return BranchManagementProjection::unresolved($lookup, 'ownership_source_unavailable');
    }
}
