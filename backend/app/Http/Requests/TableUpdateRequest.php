<?php

/**
 * Table Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\Branch;
use App\Omnify\Modules\Table\Requests\TableUpdateRequestBase;
use Illuminate\Validation\Rule;

class TableUpdateRequest extends TableUpdateRequestBase
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Branch|null $shop */
        $shop = $this->attributes->get('shop');
        $branchId = $shop?->id;
        $tableId = $this->route('table')?->id ?? $this->route('table');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-]+$/',
                "unique:tables,code,{$tableId},id,branch_id,{$branchId}",
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seat_count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'zone_id' => [
                'sometimes',
                'uuid',
                Rule::exists('zones', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at')),
            ],
        ];
    }
}
