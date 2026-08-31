<?php

/**
 * ZoneTemplate Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ZoneTemplate\Requests\ZoneTemplateStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * ZoneTemplateStoreRequest — client may only set code, name, description,
 * display_order. organization_id and brand_id are injected from the resolved
 * HQ brand context (ResolveBrandFromSlug).
 */
class ZoneTemplateStoreRequest extends ZoneTemplateStoreRequestBase
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brandId = $this->attributes->get('brand_id');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('zone_templates', 'code')
                    ->where(fn ($q) => $q->where('brand_id', $brandId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                'uuid',
                // Target branch must belong to this brand. NULL = all branches.
                Rule::exists('branches', 'id')->where(
                    fn ($q) => $q->where('console_brand_id', $this->attributes->get('brand')?->console_brand_id)
                        ->whereNull('deleted_at')
                ),
            ],
            'description' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
