<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MenuAddProductsRequest extends FormRequest
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
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['required', 'uuid', 'exists:products,id'],
            'menu_section_id' => ['nullable', 'uuid', 'exists:menu_sections,id'],
        ];
    }
}
