<?php

/**
 * Table Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\Branch;
use App\Omnify\Modules\Table\Requests\TableStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * TableStoreRequest — client may set code, name, seat_count, zone_id.
 * organization_id, branch_id, status (=free), is_active (=true) and qr_token
 * are injected/minted server-side. zone_id is constrained to the resolved shop.
 */
class TableStoreRequest extends TableStoreRequestBase
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
                "unique:tables,code,NULL,id,branch_id,{$branchId}",
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'seat_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'zone_id' => [
                'required',
                'uuid',
                Rule::exists('zones', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at')),
            ],
        ];
    }
}
