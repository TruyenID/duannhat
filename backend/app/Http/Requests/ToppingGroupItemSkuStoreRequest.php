<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToppingGroupItemSkuStoreRequest extends FormRequest
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
            'product_sku_id' => ['nullable', 'uuid', 'exists:product_skus,id'],
            'extra_price' => ['required', 'numeric'],
        ];
    }
}
