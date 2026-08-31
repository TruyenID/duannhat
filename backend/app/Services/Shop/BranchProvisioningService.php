<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Brand;
use App\Services\Provisioning\BranchBaselineProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dựng một chi nhánh mới (#1666, tách khỏi `HQ\ShopController::store`).
 *
 * Ba thứ ở đây là quyết định nghiệp vụ chứ không phải hình dạng của một request:
 *
 *   · `console_branch_id` **do máy chủ sinh**, không bao giờ nhận từ client —
 *     đó là danh tính chi nhánh ở phía Platform;
 *   · `console_organization_id` / `console_brand_id` lấy từ **ngữ cảnh đã xác
 *     thực**, không lấy từ body. Nhận chúng từ client là để một người tạo được
 *     chi nhánh cho tổ chức khác;
 *   · `is_headquarters = false` và `is_active = true` là mặc định của MIỀN —
 *     một chi nhánh mới không bao giờ là trụ sở, và mở ra là dùng được ngay.
 *
 * Danh sách trường ở đây cố ý liệt kê tường minh thay vì `$request->validated()`
 * nguyên khối: FormRequest quyết định cái gì HỢP LỆ, service quyết định cái gì
 * được GHI. Gộp hai câu hỏi đó lại thì thêm một trường vào rule là vô tình mở
 * một cột cho client ghi.
 */
final class BranchProvisioningService
{
    public function __construct(
        private readonly BranchBaselineProvisioner $baseline,
    ) {}

    /**
     * @param  array<string, mixed>  $data  đã qua FormRequest
     */
    public function create(Brand $brand, string $consoleOrganizationId, array $data): Branch
    {
        // #2320 — hàng `branches` và baseline của nó nằm trong CÙNG một
        // transaction. Nửa vời là trạng thái tệ nhất ở đây: một chi nhánh có
        // mặt trong danh sách, mở ra được, nhưng không có `shop_order_settings`
        // nên không biết bán bằng tiền gì. Baseline hỏng ⇒ không có chi nhánh
        // nào ra đời.
        return DB::transaction(function () use ($brand, $consoleOrganizationId, $data): Branch {
            $branch = $this->createRow($brand, $consoleOrganizationId, $data);

            $this->baseline->ensure($branch);

            return $branch;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createRow(Brand $brand, string $consoleOrganizationId, array $data): Branch
    {
        return Branch::create([
            'console_branch_id' => (string) Str::uuid(),
            'console_organization_id' => $consoleOrganizationId,
            'console_brand_id' => $brand->console_brand_id,
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'currency' => $data['currency'] ?? null,
            'locale' => $data['locale'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'logo' => $data['logo'] ?? null,
            'img_branches' => $data['img_branches'] ?? null,
            'banner_desktop' => $data['banner_desktop'] ?? null,
            'banner_tablet' => $data['banner_tablet'] ?? null,
            'banner_mobile' => $data['banner_mobile'] ?? null,
            'seat_capacity' => $data['seat_capacity'] ?? null,
            'business_hours' => $data['business_hours'] ?? null,
            'weekly_hours' => $data['weekly_hours'] ?? null,
            'is_headquarters' => false,
            'is_active' => true,
        ]);
    }
}
