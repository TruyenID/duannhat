<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductToppingGroupSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'topping_group_ids' => ['present', 'array'],
            'topping_group_ids.*' => [
                'uuid',
                Rule::exists('topping_groups', 'id')->where('brand_id', $this->attributes->get('brand_id')),
            ],
            'sort_orders' => ['nullable', 'array'],
            'sort_orders.*' => ['integer'],

            // Per-attachment overrides — keyed by group UUID. Either inner key
            // may be NULL ⇒ inherit group default. Service-layer validates
            // values against the group's own bounds (min ≤ group.max_select,
            // max ≥ group.min_select) since the cross-check needs the group
            // row, not just the request shape.
            'overrides' => ['nullable', 'array'],
            'overrides.*.min' => ['nullable', 'integer', 'min:0'],
            'overrides.*.max' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
