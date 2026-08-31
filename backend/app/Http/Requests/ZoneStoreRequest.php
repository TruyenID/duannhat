<?php

/**
 * Zone Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\Branch;
use App\Omnify\Modules\Zone\Requests\ZoneStoreRequestBase;

/**
 * ZoneStoreRequest — client may only set code, name, description, display_order.
 * organization_id and branch_id are injected from the resolved shop context.
 */
class ZoneStoreRequest extends ZoneStoreRequestBase
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Branch|null $shop */
        $shop = $this->attributes->get('shop');
        $branchId = $shop?->id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-]+$/',
                "unique:zones,code,NULL,id,branch_id,{$branchId}",
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
