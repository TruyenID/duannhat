<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Inventory\Contracts\CustomerNotifiableDirectory;
use Illuminate\Support\Collection;

/**
 * #962 — hiện thực {@see CustomerNotifiableDirectory}.
 *
 * Chép nguyên truy vấn từ `RecallService::notify()`, kể cả nhánh "danh sách
 * rỗng thì không chạm DB" vốn đã có ở đó dưới dạng `$customerIds->isEmpty()`.
 */
final class EloquentCustomerNotifiableDirectory implements CustomerNotifiableDirectory
{
    public function notifiablesForIds(array $customerIds): Collection
    {
        if ($customerIds === []) {
            return collect();
        }

        return Customer::query()->whereIn('id', $customerIds)->get()->values();
    }
}
