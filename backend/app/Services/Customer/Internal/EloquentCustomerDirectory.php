<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Customer\Contracts\CustomerDirectory;
use App\Services\Customer\Contracts\CustomerDirectoryEntry;

/**
 * #1993 — hiện thực {@see CustomerDirectory}.
 *
 * `withTrashed()` là CÓ CHỦ ĐÍCH, không phải sót: xem docblock của cổng. Khoản
 * nợ đã được phía tiền xác định là còn mở trước khi tới đây; việc duy nhất còn
 * lại là gọi tên người thiếu nó.
 *
 * Cách ghép tên chép nguyên từ câu SQL cũ trong `DebtController`
 * (`NULLIF(TRIM(CONCAT(first_name, ' ', last_name)), '')`) — kể cả việc coi
 * chuỗi rỗng là `null`. POS tạo khách bằng số điện thoại nên `first_name` thường
 * là chỗ giữ chỗ và `last_name` trống; ghép thẳng sẽ ra một cái tên có dấu cách
 * thừa ở đuôi mà màn hình không cách nào biết là rỗng.
 */
final class EloquentCustomerDirectory implements CustomerDirectory
{
    public function entriesByIds(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $entries = [];

        Customer::withTrashed()
            ->whereIn('id', $customerIds)
            ->get(['id', 'first_name', 'last_name', 'phone', 'tax_code'])
            ->each(function (Customer $customer) use (&$entries): void {
                $entries[(string) $customer->id] = new CustomerDirectoryEntry(
                    customerId: (string) $customer->id,
                    displayName: $this->displayName($customer),
                    phone: $customer->phone === null ? null : (string) $customer->phone,
                    taxCode: $customer->tax_code === null ? null : (string) $customer->tax_code,
                );
            });

        return $entries;
    }

    private function displayName(Customer $customer): ?string
    {
        $name = trim(((string) $customer->first_name).' '.((string) $customer->last_name));

        return $name === '' ? null : $name;
    }
}
