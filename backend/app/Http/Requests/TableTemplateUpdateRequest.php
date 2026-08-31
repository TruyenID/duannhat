<?php

/**
 * TableTemplate Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\TableTemplate\Requests\TableTemplateUpdateRequestBase;
use Illuminate\Validation\Rule;

class TableTemplateUpdateRequest extends TableTemplateUpdateRequestBase
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brandId = $this->attributes->get('brand_id');
        $tableTemplateId = $this->route('tableTemplate');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('table_templates', 'code')
                    ->ignore($tableTemplateId)
                    ->where(fn ($q) => $q->where('brand_id', $brandId)),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                'uuid',
                // Target branch must belong to this brand. NULL = all branches.
                Rule::exists('branches', 'id')->where(
                    fn ($q) => $q->where('console_brand_id', $this->attributes->get('brand')?->console_brand_id)
                        ->whereNull('deleted_at')
                ),
            ],
            'seat_count' => ['sometimes', 'integer', 'min:1'],
            'zone_template_id' => [
                'sometimes',
                'uuid',
                Rule::exists('zone_templates', 'id')
                    ->where(fn ($q) => $q->where('brand_id', $brandId)->whereNull('deleted_at')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
