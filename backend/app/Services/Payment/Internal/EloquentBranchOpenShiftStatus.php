<?php

declare(strict_types=1);

namespace App\Services\Payment\Internal;

use App\Models\Till;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Order\Contracts\BranchOpenShiftStatus;
use App\Services\Pos\TillSessionService;

/**
 * #1687 — bản cài Eloquent của {@see BranchOpenShiftStatus}. Đặt cạnh
 * `TillSessionOpenLookup` (#1662), adapter cùng khuôn của cùng chiều đảo. Thuộc Payments
 * (module sở hữu `Till` / `TillSession`), nên nó ĐƯỢC PHÉP chạm model; cái
 * không được chạm model là hợp đồng, và hợp đồng ở `App\Services\Order\Contracts`.
 *
 * Hai truy vấn dưới đây được chép NGUYÊN HÌNH DẠNG từ chỗ chúng vừa rời đi —
 * hai method `private` của `ShopOrderSettingsController` — kể cả việc
 * `branchHasOpenChain` chỉ ủy quyền cho `TillSessionService`. Ranh giới không
 * phải chỗ để sửa hành vi: hai vị từ này quyết định 409 nào chặn một lần đổi
 * cấu hình giữa ca, và một điều kiện lọc trượt tay ở đây là một ca thu ngân
 * không đối soát được.
 */
final class EloquentBranchOpenShiftStatus implements BranchOpenShiftStatus
{
    public function __construct(private readonly TillSessionService $sessions) {}

    public function branchHasOpenShift(string $branchId): bool
    {
        return Till::query()
            ->join('till_sessions', 'tills.current_session_id', '=', 'till_sessions.id')
            ->where('tills.branch_id', $branchId)
            ->whereIn('till_sessions.status', [
                TillSessionStatusEnum::Open->value,
                TillSessionStatusEnum::Closing->value,
            ])
            ->exists();
    }

    public function branchHasOpenChain(string $branchId): bool
    {
        return $this->sessions->branchHasOpenChain($branchId);
    }
}
