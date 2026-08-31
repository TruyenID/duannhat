<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * #2901 — đọc các dòng log đã kéo về.
 *
 * Cùng permission với {@see WorkstationLogRequestPolicy} (`shop.manage`) chứ
 * KHÔNG phải một trục quyền thứ hai: ai yêu cầu được log thì đọc được log.
 * Tách hai trục cho cùng một tài sản là chỗ để chúng lệch nhau về sau — cùng
 * lý lẽ mà `TransactionController` gắn quyền tra cứu vào chính `OrderPayment`.
 *
 * Policy riêng chỉ vì Laravel phân giải theo TÊN MODEL; hành vi thì cố ý giống
 * hệt, và giống hệt là điều cần khẳng định chứ không phải trùng lặp cần gỡ.
 */
class WorkstationLogRecordPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'shop.manage');
    }
}
