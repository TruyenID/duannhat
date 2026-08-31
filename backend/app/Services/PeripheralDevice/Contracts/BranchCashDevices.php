<?php

declare(strict_types=1);

namespace App\Services\PeripheralDevice\Contracts;

use App\Services\PeripheralDevice\PeripheralDeviceService;

/**
 * #2878 — PlatformIntegration công bố: **chi nhánh này có những máy 釣銭機 nào?**
 *
 * `CashDeviceTransactionIntake` hỏi câu đó để từ chối một lượt thu khai máy của
 * chi nhánh khác — ranh giới chi nhánh, xem `docs/explanation/branch-isolation.md`.
 * Trước bản vá nó tự `PeripheralDevice::where('branch_id', …)`, tức Payments đọc
 * thẳng model của PlatformIntegration.
 *
 * Trả DANH SÁCH id chứ không phải phép hỏi từng cái, vì chỗ gọi xử theo LÔ (tối
 * đa 50 hàng mỗi lượt đẩy) và một truy vấn mỗi hàng trên đường chạy mỗi phút ở
 * mọi quán là cái giá không cần trả.
 *
 * `type = 'coin_changer'` là từ vựng THẬT của registry
 * ({@see PeripheralDeviceService}) — không phải
 * `cash_changer`, dù tên miền nghiệp vụ hay được gọi là "máy thu tiền mặt".
 */
interface BranchCashDevices
{
    /** @return list<string> id của mọi máy 釣銭機 đang hoạt động ở chi nhánh. */
    public function activeCashDeviceIds(string $branchId): array;
}
