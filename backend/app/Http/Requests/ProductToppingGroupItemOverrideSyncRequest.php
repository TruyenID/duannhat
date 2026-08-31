<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProductToppingGroupItemOverrideSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['present', 'array'],
            'overrides.*.topping_group_item_id' => ['required', 'uuid', 'exists:topping_group_items,id'],
            'overrides.*.product_sku_id' => ['nullable', 'uuid', 'exists:product_skus,id'],
            'overrides.*.is_hidden' => ['boolean'],
            'overrides.*.override_price' => ['nullable', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $overrides = $this->input('overrides', []);
            if (empty($overrides)) {
                return;
            }

            // is_hidden=true → override_price must be null.
            foreach ($overrides as $index => $row) {
                if (! empty($row['is_hidden']) && isset($row['override_price']) && $row['override_price'] !== null) {
                    $v->errors()->add(
                        "overrides.{$index}.override_price",
                        'override_price must be null when is_hidden is true.'
                    );
                }
            }

            // No duplicate (topping_group_item_id, product_sku_id) in the same payload.
            $seen = [];
            foreach ($overrides as $index => $row) {
                $itemId = $row['topping_group_item_id'] ?? null;
                $skuId = $row['product_sku_id'] ?? null;
                $key = $itemId.'|'.($skuId ?? '__null__');

                if (isset($seen[$key])) {
                    $v->errors()->add(
                        "overrides.{$index}.topping_group_item_id",
                        'Duplicate (topping_group_item_id, product_sku_id) combination in overrides payload.'
                    );
                } else {
                    $seen[$key] = true;
                }
            }
        });
    }
}
