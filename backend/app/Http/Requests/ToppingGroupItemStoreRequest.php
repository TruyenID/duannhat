<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToppingGroupItemStoreRequest extends FormRequest
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
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'sort_order' => ['nullable', 'integer'],
            // is_default — pre-checked in customer-web for the "comes-with"
            // pattern (Burger has cheese by default; Latte has ice). Validated
            // against the group's selection_type at service layer because we
            // need to count siblings.
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
