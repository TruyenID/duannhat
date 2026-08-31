<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerOrderInitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'table_ids' => ['nullable', 'array'],
            'table_ids.*' => ['uuid', 'exists:tables,id'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
