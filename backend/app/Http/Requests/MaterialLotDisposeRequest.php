<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialLotDisposeRequest extends FormRequest
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
            'force' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
