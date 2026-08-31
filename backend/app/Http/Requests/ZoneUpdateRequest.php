<?php

/**
 * Zone Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\Branch;
use App\Omnify\Modules\Zone\Requests\ZoneUpdateRequestBase;

class ZoneUpdateRequest extends ZoneUpdateRequestBase
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Branch|null $shop */
        $shop = $this->attributes->get('shop');
        $branchId = $shop?->id;
        $zoneId = $this->route('zone')?->id ?? $this->route('zone');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-]+$/',
                "unique:zones,code,{$zoneId},id,branch_id,{$branchId}",
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
