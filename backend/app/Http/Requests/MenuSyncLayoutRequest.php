<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MenuSyncLayoutRequest extends FormRequest
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
            'menu_items' => ['present', 'array'],
            'menu_items.*.section_name' => ['required', 'string', 'max:255'],
            'menu_items.*.product_ids' => ['present', 'array'],
            'menu_items.*.product_ids.*' => ['required', 'uuid', 'exists:products,id'],
        ];
    }
}
