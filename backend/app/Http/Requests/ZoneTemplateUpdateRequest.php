<?php

/**
 * ZoneTemplate Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ZoneTemplate\Requests\ZoneTemplateUpdateRequestBase;
use Illuminate\Validation\Rule;

class ZoneTemplateUpdateRequest extends ZoneTemplateUpdateRequestBase
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brandId = $this->attributes->get('brand_id');
        $zoneTemplateId = $this->route('zoneTemplate');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('zone_templates', 'code')
                    ->ignore($zoneTemplateId)
                    ->where(fn ($q) => $q->where('brand_id', $brandId)),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                'uuid',
                // Target branch must belong to this brand. NULL = all branches.
                Rule::exists('branches', 'id')->where(
                    fn ($q) => $q->where('console_brand_id', $this->attributes->get('brand')?->console_brand_id)
                        ->whereNull('deleted_at')
                ),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
