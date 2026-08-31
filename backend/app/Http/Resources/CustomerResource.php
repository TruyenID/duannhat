<?php

/**
 * Customer Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Customer\Resources\CustomerResourceBase;
use Illuminate\Http\Request;

/**
 * CustomerResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class CustomerResource extends CustomerResourceBase
{
    public function toArray(Request $request): array
    {
        $data = $this->schemaArray($request);
        $data['full_name'] = $this->full_name;
        $data['orders'] = $this->whenLoaded('customerOrders', fn () => CustomerOrderResource::collection($this->customerOrders));

        // #1700 — chỉ có mặt khi truy vấn xin `withSum` (xem
        // `CustomerService::list`). `null` nghĩa là khách chưa có bút toán nào,
        // không phải "chưa biết" — nên quy về 0, chứ để null thì màn hình phải
        // đoán giữa hai thứ đó.
        if (array_key_exists('point_balance', $this->resource->getAttributes())) {
            $data['point_balance'] = (int) ($this->resource->getAttribute('point_balance') ?? 0);
        }

        return $data;
    }
}
