<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\TillTenderCategory;

/**
 * Tạo nhóm hình thức thanh toán cho một chi nhánh (#1666, tách khỏi
 * `Shop\TillTenderCategoryController::store`).
 *
 * Luật ở đây là phép kiểm trùng `key`, và phạm vi của nó là thứ dễ viết sai
 * nhất: một chi nhánh nhìn thấy **cả nhóm của chính nó lẫn nhóm dùng chung toàn
 * tổ chức** (`branch_id IS NULL`), nên `key` phải duy nhất trên HỢP của hai tập
 * đó. Chỉ kiểm trong phạm vi chi nhánh sẽ cho tạo một nhóm che mất nhóm chung
 * và báo cáo ca thu ngân từ đó gộp hai thứ khác nhau vào một dòng.
 *
 * `key` chuẩn hoá về chữ thường TRƯỚC khi kiểm — nếu không, `CASH` và `cash`
 * lọt qua thành hai nhóm mà người vận hành đọc là một.
 *
 * Còn một khoảng đua chưa đóng, ghi ra để không ai tưởng nó kín: phép kiểm và
 * lần ghi không nằm trong một transaction, nên hai request đồng thời cùng `key`
 * đều có thể qua cửa. Với màn hình quản trị một người dùng thì chưa đáng, và
 * đóng nó đúng cách là một unique index trên `(organization_id, branch_id, key)`
 * chứ không phải một transaction.
 */
final class TillTenderCategoryService
{
    /**
     * @param  array<string, mixed>  $data  đã qua validate
     * @return array{0: ?TillTenderCategory, 1: string} `[row, key]` — `row` là
     *                                                  null khi `key` đã bị chiếm; chỗ gọi quyết định mã lỗi.
     */
    public function createUnlessKeyTaken(string $organizationId, string $branchId, array $data): array
    {
        $key = strtolower((string) $data['key']);

        $taken = TillTenderCategory::query()
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->exists();

        if ($taken) {
            return [null, $key];
        }

        return [TillTenderCategory::create([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'key' => $key,
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 100,
            'is_active' => true,
            'is_system' => false,
        ]), $key];
    }
}
