<?php

/**
 * TableTemplate Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\TableTemplate\Requests\TableTemplateStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * TableTemplateStoreRequest — client may only set code, name, seat_count and
 * zone_template_id. organization_id and brand_id are injected from the
 * resolved HQ brand context (ResolveBrandFromSlug).
 */
class TableTemplateStoreRequest extends TableTemplateStoreRequestBase
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
                Rule::unique('table_templates', 'code')
                    ->where(fn ($q) => $q->where('brand_id', $brandId)),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                'uuid',
                // Target branch must belong to this brand. NULL = all branches.
                Rule::exists('branches', 'id')->where(
                    fn ($q) => $q->where('console_brand_id', $this->attributes->get('brand')?->console_brand_id)
                        ->whereNull('deleted_at')
                ),
            ],
            'seat_count' => ['nullable', 'integer', 'min:1'],
            'zone_template_id' => [
                'required',
                'uuid',
                Rule::exists('zone_templates', 'id')
                    ->where(fn ($q) => $q->where('brand_id', $brandId)->whereNull('deleted_at')),
            ],
        ];
    }
}
