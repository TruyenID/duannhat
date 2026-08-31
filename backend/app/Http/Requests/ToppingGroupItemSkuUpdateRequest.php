<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToppingGroupItemSkuUpdateRequest extends FormRequest
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
            'extra_price' => ['required', 'numeric'],
        ];
    }
}
